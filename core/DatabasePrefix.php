<?php
declare(strict_types=1);
namespace Core;

require_once __DIR__ . '/Environment.php';

/** Logical SQL names remain stable; each connection captures its namespace. */
final class DatabasePrefix
{
    private array $tables = [];
    private array $objects = [];
    public readonly string $prefix;
    public readonly bool $scoped;

    public function __construct(?string $prefix = null, ?bool $scoped = null)
    {
        $configured = Environment::get('DBPREFIX');
        $this->prefix = self::validate($prefix ?? ($configured === false ? '' : $configured));
        $this->scoped = $scoped ?? ($prefix !== null || $configured !== false);
        static $definitions = null;
        if ($definitions === null) {
            $definitions = require __DIR__ . '/DatabaseIdentifiers.php';
            foreach (array_merge(glob(__DIR__ . '/../database/*.sql'), glob(__DIR__ . '/../database/migrations/*.sql')) as $file) {
                $sql = (string) file_get_contents($file);
                preg_match_all('/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)/i', $sql, $matches);
                foreach ($matches[1] as $name) $definitions['tables'][strtolower($name)] = true;
                preg_match_all('/\b(?:CONSTRAINT|TRIGGER|PROCEDURE|FUNCTION)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)/i', $sql, $matches);
                foreach ($matches[1] as $name) $definitions['objects'][strtolower($name)] = true;
            }
            foreach (glob(__DIR__ . '/../modules/*/module.json') as $file) {
                $manifest = json_decode((string) file_get_contents($file), true);
                foreach ($manifest['database']['tables'] ?? [] as $name) $definitions['tables'][$name] = true;
            }
        }
        $this->tables = $definitions['tables'];
        $this->objects = $definitions['objects'];
    }

    public static function validate(string $prefix): string
    {
        if ($prefix !== '' && preg_match('/^[a-z][a-z0-9_]{0,14}_$/D', $prefix) !== 1) {
            throw new \InvalidArgumentException('DBPREFIX: от 2 до 16 символов a-z, 0-9 и _, начинается с буквы и заканчивается _.');
        }
        return $prefix;
    }

    public function name(string $logical): string
    {
        if ($this->prefix === '') return $logical;
        $physical = $this->prefix . $logical;
        return strlen($physical) <= 64 ? $physical : substr($physical, 0, 51) . '_' . substr(hash('sha256', $physical), 0, 12);
    }

    public function logical(string $physical): string
    {
        foreach ($this->tables as $table => $_) if ($this->name($table) === $physical) return $table;
        return $physical;
    }

    public function ownsTable(string $physical): bool
    {
        return isset($this->tables[$this->logical($physical)]) && $this->name($this->logical($physical)) === $physical;
    }

    /** SQL values, comments and placeholders are never identifier substitutions. */
    public function sql(string $sql, bool $schema = false): string
    {
        if (!$this->scoped && $this->prefix === '') return $sql;
        preg_match_all('~\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|`(?:``|[^`])*`|--[^\r\n]*|\#[^\r\n]*|/\*[\s\S]*?\*/|[a-zA-Z_][a-zA-Z0-9_]*|\s+|.~s', $sql, $matches);
        $tokens = $matches[0];
        $significant = [];
        foreach ($tokens as $i => $token) if (trim($token) !== '' && !preg_match('~^(--|\#|/\*)~', $token)) $significant[] = $i;
        $metadata = stripos($sql, 'information_schema.') !== false;
        $expect = false;
        $tableList = false;
        $previous = '';
        $onTable = false;
        foreach ($significant as $position => $i) {
            $token = $tokens[$i];
            if ($token === '') continue;
            $next = $tokens[$significant[$position + 1] ?? -1] ?? '';
            if ($token[0] === "'" || $token[0] === '"') {
                $quote = $token[0];
                $value = str_replace([$quote . $quote, '\\' . $quote, '\\\\'], [$quote, $quote, '\\'], substr($token, 1, -1));
                $beforeLiteral = strtoupper(implode('', array_slice($tokens, max(0, $i - 5), min(5, $i))));
                if ($metadata && preg_match('/(?:TABLE_NAME|CONSTRAINT_NAME|EVENT_OBJECT_TABLE)\s*=\s*$/', $beforeLiteral) && (isset($this->tables[$value]) || isset($this->objects[$value]))) {
                    $tokens[$i] = $quote . $this->name($value) . $quote;
                } elseif ($schema && preg_match('/^\s*(?:ALTER|CREATE|DROP|SELECT|UPDATE|INSERT|DELETE)\b/i', $value)) {
                    $rewritten = $this->sql($value, true);
                    $tokens[$i] = $quote . str_replace(['\\', $quote], ['\\\\', '\\' . $quote], $rewritten) . $quote;
                }
                $previous = $token;
                continue;
            }
            $identifier = trim($token, '`');
            $lower = strtolower($identifier);
            if (strtolower($token) === 'information_schema' && $next === '.') {
                $objectIndex = $significant[$position + 2] ?? -1;
                $object = strtolower(trim($tokens[$objectIndex] ?? '', '`'));
                $column = match ($object) {
                    'tables', 'columns', 'statistics', 'key_column_usage', 'views' => 'TABLE_NAME',
                    'triggers' => 'EVENT_OBJECT_TABLE',
                    'routines' => 'ROUTINE_NAME', 'events' => 'EVENT_NAME',
                    default => null,
                };
                if ($this->scoped && $column !== null) {
                    $names = in_array($object, ['routines', 'events'], true) ? array_keys($this->objects) : array_keys($this->tables);
                    $list = implode(',', array_map(fn ($name) => "'" . $this->name($name) . "'", $names));
                    $tokens[$i] = '(SELECT * FROM information_schema.' . $object . ' WHERE ' . ($list === '' ? '1=0' : $column . ' IN (' . $list . ')') . ')';
                    $tokens[$significant[$position + 1]] = '';
                    $afterObject = $tokens[$significant[$position + 3] ?? -1] ?? '';
                    $hasAlias = preg_match('/^[a-zA-Z_`]/', $afterObject) && !in_array(strtoupper($afterObject), ['WHERE', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'ON', 'ORDER', 'GROUP', 'LIMIT', 'UNION', 'SET'], true);
                    $tokens[$objectIndex] = $hasAlias ? '' : ' AS ' . $object;
                }
            }
            $known = isset($this->tables[$lower]) || isset($this->objects[$lower]) || str_starts_with($lower, '__workspace_restore_');
            if ($known && ($expect || $next === '.' || (isset($this->objects[$lower]) && strtoupper($previous) === 'KEY')) && !in_array($previous, [':', '@'], true)) {
                $tokens[$i] = '`' . $this->name($lower) . '`';
            }
            if (in_array(strtoupper($token), ['TRIGGER', 'INDEX'], true)) $onTable = true;
            if (in_array(strtoupper($token), ['FROM', 'JOIN', 'INTO', 'UPDATE', 'TABLE', 'TABLES', 'REFERENCES', 'TRUNCATE', 'DESCRIBE', 'TRIGGER', 'CONSTRAINT', 'PROCEDURE', 'FUNCTION', 'CALL', 'EVENT'], true) || (strtoupper($token) === 'ON' && $onTable)) {
                $expect = true;
                if (strtoupper($token) === 'ON') $onTable = false;
                $tableList = in_array(strtoupper($token), ['FROM', 'TABLE', 'TABLES'], true);
            } elseif ($expect && in_array(strtoupper($token), ['IF', 'NOT', 'EXISTS', 'ONLY'], true)) {
                // Optional grammar before the table name.
            } elseif ($token === ',' && $tableList) {
                $expect = true;
            } else {
                if ($next !== '.') $expect = false;
                if (in_array(strtoupper($token), ['WHERE', 'SET', 'VALUES', 'ON', 'ORDER', 'GROUP', 'LIMIT', 'ADD'], true) || $token === '(' || $token === ';') $tableList = false;
            }
            $previous = $token;
            if ($token === ';') $onTable = false;
        }
        $result = implode('', $tokens);
        if ($this->scoped && preg_match('/^\s*SHOW\s+TRIGGERS\s*$/i', $result)) {
            $result .= ' WHERE `Table` IN (' . implode(',', array_map(fn ($table) => "'" . $this->name($table) . "'", array_keys($this->tables))) . ')';
        }
        return $result;
    }

    public function parameters(string $sql, ?array $parameters): ?array
    {
        if ($parameters === null || stripos($sql, 'information_schema.') === false) return $parameters;
        foreach ($parameters as &$value) if (is_string($value) && isset($this->tables[$value])) $value = $this->name($value);
        return $parameters;
    }
}
