<?php

declare(strict_types=1);

namespace Modules\WorkPermits\Services;

use RuntimeException;

/**
 * Минимальный движок PDF/A4: текст, линии, таблицы и разбиение на страницы.
 * Использует CID TrueType и ToUnicode без JavaScript, браузера и внешнего API.
 */
final class WorkPermitPdfDocument
{
    private const WIDTH = 595.28;
    private const HEIGHT = 841.89;
    private const MARGIN = 39.0;
    private const BOTTOM = 790.0;
    private const LEADING = 12.2;
    /** @var list<string> */
    private array $pages = [];
    private int $page = -1;
    private float $y = 0;

    public function __construct(private readonly WorkPermitPdfFont $font)
    {
        $this->newPage();
    }

    public function section(string $heading): void
    {
        $lines = $this->wrap($heading, $this->contentWidth() - 14, 10.5);
        $height = max(22.0, 8.0 + count($lines) * 13.0);
        $this->ensure($height + 7);
        $this->rect(self::MARGIN, $this->y, $this->contentWidth(), $height, true);
        foreach ($lines as $index => $line) {
            $this->write(self::MARGIN + 7, $this->y + 14.5 + $index * 13, $line, 10.5);
        }
        $this->y += $height + 7;
    }

    public function paragraph(string $label, string $value): void
    {
        if ($label !== '') {
            $this->ensure(22);
            $this->write(self::MARGIN + 1, $this->y + 11, $label, 9.5);
            $this->y += 16;
        }
        $lines = $this->wrap($value !== '' ? $value : 'Не заполнено', $this->contentWidth() - 15, 9.2);
        foreach ($lines as $line) {
            $this->ensure(16);
            $this->write(self::MARGIN + 8, $this->y + 10, $line, 9.2);
            $this->y += self::LEADING;
        }
        $this->y += 7;
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param list<float> $weights
     */
    public function table(array $headers, array $rows, array $weights = []): void
    {
        if ($headers === []) return;
        $count = count($headers);
        $weights = count($weights) === $count ? $weights : array_fill(0, $count, 1.0);
        $total = array_sum($weights);
        if ($total <= 0) throw new RuntimeException('Неправильная ширина столбцов');
        $widths = array_map(fn (float $x): float => $this->contentWidth() * $x / $total, $weights);
        $this->ensure(52);
        $this->tableHeader($headers, $widths);

        foreach ($rows === [] ? [array_fill(0, $count, 'Нет записей')] : $rows as $cells) {
            $columns = [];
            $maxLines = 1;
            foreach ($widths as $column => $width) {
                $value = (string) ($cells[$column] ?? '');
                $columns[$column] = $this->wrap($value, $width - 11, 8.5);
                $maxLines = max($maxLines, count($columns[$column]));
            }
            $lineOffset = 0;
            while ($lineOffset < $maxLines) {
                if ($this->y + 30 > self::BOTTOM) {
                    $this->newPage();
                    $this->tableHeader($headers, $widths);
                }
                $maxChunk = (int) floor((self::BOTTOM - $this->y - 8) / self::LEADING);
                if ($maxChunk < 1) {
                    $this->newPage();
                    $this->tableHeader($headers, $widths);
                    continue;
                }
                $take = min($maxLines - $lineOffset, $maxChunk);
                $height = $take * self::LEADING + 7;
                if ($this->y + $height > self::BOTTOM) {
                    $this->newPage();
                    $this->tableHeader($headers, $widths);
                    continue;
                }
                $x = self::MARGIN;
                foreach ($widths as $column => $width) {
                    $this->rect($x, $this->y, $width, $height, false);
                    for ($line = 0; $line < $take; $line++) {
                        $text = $columns[$column][$lineOffset + $line] ?? '';
                        $this->write($x + 5, $this->y + 11 + $line * self::LEADING, $text, 8.5);
                    }
                    $x += $width;
                }
                $this->y += $height;
                $lineOffset += $take;
                if ($lineOffset < $maxLines) {
                    $this->newPage();
                    $this->tableHeader($headers, $widths);
                }
            }
        }
        $this->y += 10;
    }

    /** Разделение лицевой и оборотной сторон: оборот всегда на новой странице. */
    public function pageBreak(): void
    {
        $this->newPage();
    }

    public function build(): string
    {
        $pageCount = count($this->pages);
        if ($pageCount > 180) throw new RuntimeException('Слишком большой наряд для печати');
        for ($i = 0; $i < $pageCount; $i++) {
            $label = 'Страница ' . ($i + 1) . ' из ' . $pageCount
                . ' · УЧЁТНАЯ КОПИЯ — не заменяет подписи работников';
            $this->writeToPage($i, self::MARGIN, 816, $label, 8.0);
        }

        /** @var array<int,string> $objects */
        $objects = [];
        $new = static function (string $value = '') use (&$objects): int {
            $id = count($objects) + 1;
            $objects[$id] = $value;
            return $id;
        };
        $stream = static function (string $content): string {
            $packed = gzcompress($content, 6);
            if ($packed === false) throw new RuntimeException('Ошибка сжатия PDF');
            return '<< /Length ' . strlen($packed) . ' /Filter /FlateDecode >>'
                . "\nstream\n" . $packed . "\nendstream";
        };
        $fontBytes = $this->font->contents();
        $fontFile = $new('<< /Length1 ' . strlen($fontBytes) . ' '
            . substr($stream($fontBytes), 3));
        $bounds = implode(' ', $this->font->bounds());
        $descriptor = $new('<< /Type /FontDescriptor /FontName /NotesCyrillic'
            . ' /Flags 32 /FontBBox [' . $bounds . '] /ItalicAngle 0'
            . ' /Ascent ' . $this->font->ascent() . ' /Descent ' . $this->font->descent()
            . ' /CapHeight ' . $this->font->ascent() . ' /StemV 80'
            . ' /FontFile2 ' . $fontFile . ' 0 R >>');
        $cid = $new('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /NotesCyrillic'
            . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
            . ' /FontDescriptor ' . $descriptor . ' 0 R /CIDToGIDMap /Identity'
            . ' /DW 1000 /W ' . $this->font->widthDefinition() . ' >>');
        $cmap = $this->font->toUnicode();
        $cmapId = $new('<< /Length ' . strlen($cmap) . ' >>'
            . "\nstream\n" . $cmap . 'endstream');
        $font = $new('<< /Type /Font /Subtype /Type0 /BaseFont /NotesCyrillic'
            . ' /Encoding /Identity-H /DescendantFonts [' . $cid . ' 0 R]'
            . ' /ToUnicode ' . $cmapId . ' 0 R >>');
        $pages = $new();
        $kids = [];
        foreach ($this->pages as $operations) {
            $contentId = $new($stream($operations));
            $pageId = $new('<< /Type /Page /Parent ' . $pages . ' 0 R'
                . ' /MediaBox [0 0 ' . self::WIDTH . ' ' . self::HEIGHT . ']'
                . ' /Resources << /Font << /F1 ' . $font . ' 0 R >> >>'
                . ' /Contents ' . $contentId . ' 0 R >>');
            $kids[] = $pageId . ' 0 R';
        }
        $objects[$pages] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . ']'
            . ' /Count ' . $pageCount . ' >>';
        $catalog = $new('<< /Type /Catalog /Pages ' . $pages . ' 0 R >>');

        $result = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($result);
            $result .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($result);
        $result .= 'xref' . "\n0 " . (count($objects) + 1) . "\n";
        $result .= "0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $result .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $result .= 'trailer' . "\n<< /Size " . (count($objects) + 1)
            . ' /Root ' . $catalog . " 0 R >>\nstartxref\n"
            . $xref . "\n%%EOF\n";
        return $result;
    }

    private function newPage(): void
    {
        if (count($this->pages) >= 180) {
            throw new RuntimeException('Превышено допустимое количество страниц PDF');
        }
        $this->pages[] = '';
        $this->page = count($this->pages) - 1;
        $this->y = 39;
        $this->write(self::MARGIN, 44, 'НАРЯД-ДОПУСК · NOTES', 13);
        $this->line(self::MARGIN, 53, self::WIDTH - self::MARGIN, 53);
        $this->y = 66;
    }

    private function tableHeader(array $headers, array $widths): void
    {
        $columns = [];
        $maxLines = 1;
        foreach ($widths as $index => $width) {
            $columns[$index] = $this->wrap((string) $headers[$index], $width - 8, 8.0);
            $maxLines = max($maxLines, count($columns[$index]));
        }
        $height = max(24.0, 7.0 + $maxLines * 10.0);
        if ($this->y + $height > self::BOTTOM) $this->newPage();
        $x = self::MARGIN;
        foreach ($widths as $index => $width) {
            $this->rect($x, $this->y, $width, $height, true);
            foreach ($columns[$index] as $line => $text) {
                $this->write($x + 4, $this->y + 11 + $line * 10, $text, 8.0);
            }
            $x += $width;
        }
        $this->y += $height;
    }

    private function ensure(float $required): void
    {
        if ($this->y + $required > self::BOTTOM) $this->newPage();
    }

    private function contentWidth(): float
    {
        return self::WIDTH - 2 * self::MARGIN;
    }

    /** @return list<string> */
    private function wrap(string $value, float $maxWidth, float $fontSize): array
    {
        $value = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $value);
        $parts = explode("\n", $value);
        $lines = [];
        foreach ($parts as $part) {
            $words = preg_split('/\s+/u', trim($part), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $current = '';
            foreach ($words as $word) {
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if ($this->font->width($candidate, $fontSize) <= $maxWidth) {
                    $current = $candidate;
                    continue;
                }
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                    if ($current !== '' && $this->font->width($current . $char, $fontSize) > $maxWidth) {
                        $lines[] = $current;
                        $current = '';
                    }
                    $current .= $char;
                }
            }
            $lines[] = $current;
        }
        return $lines === [] ? [''] : $lines;
    }

    private function write(float $x, float $y, string $text, float $size): void
    {
        $this->writeToPage($this->page, $x, $y, $text, $size);
    }

    private function writeToPage(int $page, float $x, float $y, string $text, float $size): void
    {
        $hex = $this->font->encode($text);
        $this->pages[$page] .= sprintf(
            "BT /F1 %.2F Tf 1 0 0 1 %.2F %.2F Tm <%s> Tj ET\n",
            $size, $x, self::HEIGHT - $y, $hex
        );
    }

    private function rect(float $x, float $y, float $w, float $h, bool $filled): void
    {
        $yBottom = self::HEIGHT - $y - $h;
        $this->pages[$this->page] .= sprintf(
            "%.2F %.2F %.2F %.2F re %s\n",
            $x, $yBottom, $w, $h, $filled ? '0.95 g B 0 g' : 'S'
        );
    }

    private function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->pages[$this->page] .= sprintf(
            "%.2F %.2F m %.2F %.2F l S\n",
            $x1, self::HEIGHT - $y1, $x2, self::HEIGHT - $y2
        );
    }
}
