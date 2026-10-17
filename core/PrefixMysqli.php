<?php
declare(strict_types=1);
namespace Core;
require_once __DIR__ . '/DatabasePrefix.php';

// Module composition/readiness must still load when mysqli is unavailable.
// The existing hosting checks report the missing extension before connecting.
if (!extension_loaded('mysqli')) return;

final class PrefixMysqli extends \mysqli
{
    public readonly DatabasePrefix $namespace;
    public function __construct(?string $hostname = null, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null)
    {
        $this->namespace = new DatabasePrefix();
        parent::__construct($hostname, $username, $password, $database, $port, $socket);
    }
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): \mysqli_result|bool { return parent::query($this->namespace->sql($query), $result_mode); }
    public function schemaQuery(string $query): \mysqli_result|bool { return parent::query($this->namespace->sql($query, true)); }
    public function prepare(string $query): \mysqli_stmt|false { return parent::prepare($this->namespace->sql($query)); }
    public function multi_query(string $query): bool { return parent::multi_query($this->namespace->sql($query, true)); }
    public function execute_query(string $query, ?array $params = null): \mysqli_result|bool
    {
        return parent::execute_query($this->namespace->sql($query), $this->namespace->parameters($query, $params));
    }
}
