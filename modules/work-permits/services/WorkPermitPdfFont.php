<?php

declare(strict_types=1);

namespace Modules\WorkPermits\Services;

use RuntimeException;

/**
 * Чтение системного TrueType без сторонних сетевых служб.
 * Глифы кодируются их номерами (CID), текст извлекается через ToUnicode.
 */
final class WorkPermitPdfFont
{
    private string $source;
    /** @var array<string,array{offset:int,length:int}> */
    private array $tables = [];
    /** @var array<int,int> */
    private array $unicode = [];
    /** @var array<int,int> */
    private array $glyphs = [];
    private int $units;
    private int $hMetrics;
    private int $cmap;
    private int $cmapFormat;

    public function __construct(?string $path = null)
    {
        $paths = array_filter([
            $path,
            getenv('WORK_PERMITS_PDF_FONT') ?: null,
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/tahoma.ttf',
            'C:/Windows/Fonts/verdana.ttf',
        ]);
        $selected = null;
        foreach ($paths as $candidate) {
            if (is_file($candidate) && is_readable($candidate) && filesize($candidate) <= 5_000_000) {
                $selected = $candidate;
                break;
            }
        }
        if ($selected === null) {
            throw new RuntimeException('Не найден локальный шрифт TrueType с поддержкой кириллицы');
        }
        $bytes = file_get_contents($selected);
        if (!is_string($bytes) || strlen($bytes) < 256) {
            throw new RuntimeException('Не удалось прочитать шрифт для PDF');
        }
        $this->source = $bytes;
        $this->loadTables();
        $this->units = $this->u16($this->table('head') + 18);
        $this->hMetrics = $this->u16($this->table('hhea') + 34);
        if ($this->units < 16 || $this->hMetrics < 1 || $this->glyph(0x041D) === 0) {
            throw new RuntimeException('Шрифт не содержит кириллические буквы');
        }
    }

    public function contents(): string
    {
        return $this->source;
    }

    public function ascent(): int
    {
        return $this->scaled($this->i16($this->table('hhea') + 4));
    }

    public function descent(): int
    {
        return $this->scaled($this->i16($this->table('hhea') + 6));
    }

    /** @return list<int> */
    public function bounds(): array
    {
        $start = $this->table('head') + 36;
        return array_map(fn (int $offset): int => $this->scaled($this->i16($start + $offset)), [0, 2, 4, 6]);
    }

    public function glyphWidth(int $glyph): int
    {
        $position = $this->table('hmtx') + 4 * min($glyph, $this->hMetrics - 1);
        return $this->scaled($this->u16($position));
    }

    public function width(string $text, float $size): float
    {
        $sum = 0;
        foreach ($this->chars($text) as $char) {
            $sum += $this->glyphWidth($this->glyph(mb_ord($char, 'UTF-8')));
        }
        return $sum * $size / 1000;
    }

    public function encode(string $text): string
    {
        $binary = '';
        foreach ($this->chars($text) as $char) {
            $point = mb_ord($char, 'UTF-8');
            $glyph = $this->glyph($point);
            if ($glyph < 1) {
                $point = 0x003F;
                $glyph = $this->glyph($point);
            }
            $this->unicode[$glyph] ??= $point;
            $this->glyphs[$glyph] = $this->glyphWidth($glyph);
            $binary .= pack('n', $glyph);
        }
        return strtoupper(bin2hex($binary));
    }

    public function widthDefinition(): string
    {
        ksort($this->glyphs, SORT_NUMERIC);
        $definitions = [];
        foreach ($this->glyphs as $glyph => $width) {
            $definitions[] = $glyph . ' [' . $width . ']';
        }
        return '[ ' . implode(' ', $definitions) . ' ]';
    }

    public function toUnicode(): string
    {
        ksort($this->unicode, SORT_NUMERIC);
        $pairs = [];
        foreach ($this->unicode as $glyph => $point) {
            $value = mb_convert_encoding(mb_chr($point, 'UTF-8'), 'UTF-16BE', 'UTF-8');
            $pairs[] = sprintf('<%04X> <%s>', $glyph, strtoupper(bin2hex($value)));
        }
        $parts = [
            '/CIDInit /ProcSet findresource begin',
            '12 dict begin',
            'begincmap',
            '/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def',
            '/CMapName /NotesToUnicode def',
            '/CMapType 2 def',
            '1 begincodespacerange',
            '<0000> <FFFF>',
            'endcodespacerange',
        ];
        foreach (array_chunk($pairs, 100) as $batch) {
            $parts[] = count($batch) . ' beginbfchar';
            array_push($parts, ...$batch);
            $parts[] = 'endbfchar';
        }
        array_push($parts, 'endcmap', 'CMapName currentdict /CMap defineresource pop',
            'end', 'end');
        return implode("\n", $parts) . "\n";
    }

    private function loadTables(): void
    {
        if ($this->u32(0) !== 0x00010000) {
            throw new RuntimeException('Требуется шрифт TrueType, не коллекция TTC и не OTF');
        }
        $count = $this->u16(4);
        if ($count < 4 || $count > 100) {
            throw new RuntimeException('Некорректный заголовок TrueType');
        }
        for ($index = 0; $index < $count; $index++) {
            $pos = 12 + $index * 16;
            $tag = substr($this->source, $pos, 4);
            $offset = $this->u32($pos + 8);
            $length = $this->u32($pos + 12);
            if ($offset < 0 || $length < 0 || $offset + $length > strlen($this->source)) {
                throw new RuntimeException('Некорректное смещение таблицы TrueType');
            }
            $this->tables[$tag] = ['offset' => $offset, 'length' => $length];
        }
        foreach (['head', 'hhea', 'hmtx', 'cmap', 'maxp', 'glyf', 'loca'] as $required) {
            $this->table($required);
        }
        $start = $this->table('cmap');
        $records = $this->u16($start + 2);
        $bestScore = -1;
        for ($i = 0; $i < $records; $i++) {
            $at = $start + 4 + $i * 8;
            $platform = $this->u16($at);
            $encoding = $this->u16($at + 2);
            $cmap = $start + $this->u32($at + 4);
            $format = $this->u16($cmap);
            if (!in_array($format, [4, 12], true)) continue;
            $score = ($format === 12 ? 5 : 2) + ($platform === 3 ? 2 : 0)
                + ($platform === 3 && in_array($encoding, [1, 10], true) ? 2 : 0);
            if ($score > $bestScore) {
                $this->cmap = $cmap;
                $this->cmapFormat = $format;
                $bestScore = $score;
            }
        }
        if ($bestScore < 0) {
            throw new RuntimeException('Шрифт не содержит таблицу Unicode cmap');
        }
    }

    private function glyph(int $code): int
    {
        if ($this->cmapFormat === 12) {
            $groups = $this->u32($this->cmap + 12);
            $low = 0;
            $high = $groups - 1;
            while ($low <= $high) {
                $middle = intdiv($low + $high, 2);
                $at = $this->cmap + 16 + $middle * 12;
                $start = $this->u32($at);
                $end = $this->u32($at + 4);
                if ($code < $start) $high = $middle - 1;
                elseif ($code > $end) $low = $middle + 1;
                else return $this->u32($at + 8) + $code - $start;
            }
            return 0;
        }
        if ($code > 65535) return 0;
        $segments = intdiv($this->u16($this->cmap + 6), 2);
        $ends = $this->cmap + 14;
        $starts = $ends + $segments * 2 + 2;
        $deltas = $starts + $segments * 2;
        $ranges = $deltas + $segments * 2;
        for ($i = 0; $i < $segments; $i++) {
            if ($code > $this->u16($ends + $i * 2)) continue;
            if ($code < $this->u16($starts + $i * 2)) return 0;
            $delta = $this->u16($deltas + $i * 2);
            $range = $this->u16($ranges + $i * 2);
            if ($range === 0) return ($code + $delta) & 0xffff;
            $index = $ranges + $i * 2 + $range
                + ($code - $this->u16($starts + $i * 2)) * 2;
            $glyph = $this->u16($index);
            return $glyph === 0 ? 0 : ($glyph + $delta) & 0xffff;
        }
        return 0;
    }

    /** @return list<string> */
    private function chars(string $text): array
    {
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function scaled(int $value): int
    {
        return (int) round($value * 1000 / $this->units);
    }

    private function table(string $name): int
    {
        if (!isset($this->tables[$name])) throw new RuntimeException('В шрифте нет таблицы ' . $name);
        return $this->tables[$name]['offset'];
    }

    private function u16(int $offset): int
    {
        $bytes = substr($this->source, $offset, 2);
        if (strlen($bytes) !== 2) throw new RuntimeException('Шрифт повреждён: u16');
        return unpack('n', $bytes)[1];
    }

    private function i16(int $offset): int
    {
        $value = $this->u16($offset);
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function u32(int $offset): int
    {
        $bytes = substr($this->source, $offset, 4);
        if (strlen($bytes) !== 4) throw new RuntimeException('Шрифт повреждён: u32');
        return unpack('N', $bytes)[1];
    }
}
