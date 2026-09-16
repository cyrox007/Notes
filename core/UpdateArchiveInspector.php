<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Read-only ZIP auditor for update packages.
 *
 * No archive entry is extracted here. The intentionally narrow subset keeps the
 * updater independent from ext-zip while rejecting archive constructs that make
 * future safe extraction ambiguous or dangerous.
 */
final class UpdateArchiveInspector
{
    public const MAX_ENTRIES = 20000;
    public const MAX_SINGLE_UNCOMPRESSED_BYTES = 268_435_456; // 256 MiB
    public const MAX_TOTAL_UNCOMPRESSED_BYTES = 1_073_741_824; // 1 GiB
    public const MAX_COMPRESSION_RATIO = 500.0;
    private const MAX_FILENAME_BYTES = 4096;
    private const EOCD_MIN_BYTES = 22;
    private const EOCD_MAX_SEARCH_BYTES = 65557; // 22 + max ZIP comment
    private const MAX_ENTRY_METADATA_BYTES = 1_048_576;

    /**
     * @return array{entries:int,files:int,directories:int,total_uncompressed:int,total_compressed:int}
     */
    public function inspect(string $archivePath): array
    {
        if ($archivePath === '' || !is_file($archivePath) || is_link($archivePath)) {
            throw new RuntimeException('Update archive must be a regular local file, not a symlink');
        }
        $fileSize = filesize($archivePath);
        if (!is_int($fileSize) || $fileSize < self::EOCD_MIN_BYTES) {
            throw new RuntimeException('Update ZIP is too small or unreadable');
        }

        $handle = fopen($archivePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Update ZIP cannot be opened for preflight');
        }

        try {
            $searchBytes = min($fileSize, self::EOCD_MAX_SEARCH_BYTES);
            if (fseek($handle, $fileSize - $searchBytes, SEEK_SET) !== 0) {
                throw new RuntimeException('Unable to seek update ZIP');
            }
            $tail = fread($handle, $searchBytes);
            if (!is_string($tail) || strlen($tail) !== $searchBytes) {
                throw new RuntimeException('Unable to read update ZIP end-of-directory data');
            }

            $eocdPos = strrpos($tail, "PK\x05\x06");
            if ($eocdPos === false || strlen($tail) - $eocdPos < self::EOCD_MIN_BYTES) {
                throw new RuntimeException('ZIP end-of-central-directory record is missing');
            }
            $eocd = unpack(
                'vdisk/vcentral_disk/ventries_disk/ventries/Vcentral_size/Vcentral_offset/vcomment_length',
                substr($tail, $eocdPos + 4, 18)
            );
            if (!is_array($eocd)) {
                throw new RuntimeException('ZIP end-of-central-directory record is invalid');
            }

            $commentLength = (int) $eocd['comment_length'];
            if (strlen($tail) - $eocdPos !== self::EOCD_MIN_BYTES + $commentLength) {
                throw new RuntimeException('ZIP end-of-central-directory comment length is inconsistent');
            }
            if ((int) $eocd['disk'] !== 0 || (int) $eocd['central_disk'] !== 0
                || (int) $eocd['entries_disk'] !== (int) $eocd['entries']) {
                throw new RuntimeException('Multi-disk ZIP updates are not supported');
            }

            $entries = (int) $eocd['entries'];
            $centralSize = (int) $eocd['central_size'];
            $centralOffset = (int) $eocd['central_offset'];
            if ($entries === 0xffff || $centralSize === 0xffffffff || $centralOffset === 0xffffffff) {
                throw new RuntimeException('ZIP64 update archives are not supported by this updater version');
            }
            if ($entries < 1 || $entries > self::MAX_ENTRIES) {
                throw new RuntimeException('Update ZIP entry count exceeds the supported safety limit');
            }

            $absoluteEocd = ($fileSize - $searchBytes) + $eocdPos;
            if ($centralOffset < 0 || $centralSize <= 0 || $centralOffset + $centralSize !== $absoluteEocd) {
                throw new RuntimeException('ZIP central-directory boundaries are inconsistent');
            }
            if (fseek($handle, $centralOffset, SEEK_SET) !== 0) {
                throw new RuntimeException('Unable to seek ZIP central directory');
            }

            $seenPaths = [];
            $seenOffsets = [];
            $ranges = [];
            $fileCount = 0;
            $directoryCount = 0;
            $totalUncompressed = 0;
            $totalCompressed = 0;

            for ($i = 0; $i < $entries; $i++) {
                $fixed = fread($handle, 46);
                if (!is_string($fixed) || strlen($fixed) !== 46 || substr($fixed, 0, 4) !== "PK\x01\x02") {
                    throw new RuntimeException('ZIP central-directory entry is truncated or invalid');
                }
                $entry = unpack(
                    'vversion_made/vversion_needed/vflags/vmethod/vmtime/vmdate/Vcrc/Vcompressed/Vuncompressed/'
                    . 'vname_length/vextra_length/vcomment_length/vdisk_start/vinternal/Vexternal/Vlocal_offset',
                    substr($fixed, 4)
                );
                if (!is_array($entry)) {
                    throw new RuntimeException('ZIP central-directory entry cannot be decoded');
                }

                $nameLength = (int) $entry['name_length'];
                $extraLength = (int) $entry['extra_length'];
                $entryCommentLength = (int) $entry['comment_length'];
                if ($nameLength < 1 || $nameLength > self::MAX_FILENAME_BYTES) {
                    throw new RuntimeException('ZIP entry filename length is invalid');
                }
                if ($extraLength + $entryCommentLength > self::MAX_ENTRY_METADATA_BYTES) {
                    throw new RuntimeException('ZIP entry metadata is unreasonably large');
                }

                $name = fread($handle, $nameLength);
                $extra = fread($handle, $extraLength);
                $comment = fread($handle, $entryCommentLength);
                if (!is_string($name) || strlen($name) !== $nameLength
                    || !is_string($extra) || strlen($extra) !== $extraLength
                    || !is_string($comment) || strlen($comment) !== $entryCommentLength) {
                    throw new RuntimeException('ZIP central-directory entry metadata is truncated');
                }
                $nextCentralPosition = ftell($handle);
                if (!is_int($nextCentralPosition)) {
                    throw new RuntimeException('Unable to track ZIP central-directory position');
                }
                if ($this->containsZip64Extra($extra)) {
                    throw new RuntimeException('ZIP64 entry metadata is not supported: ' . $name);
                }

                $this->assertSafePath($name);
                $canonical = mb_strtolower(rtrim($name, '/'), 'UTF-8');
                if (isset($seenPaths[$canonical])) {
                    throw new RuntimeException('ZIP contains duplicate/case-colliding path: ' . $name);
                }
                $seenPaths[$canonical] = true;

                $flags = (int) $entry['flags'];
                if (($flags & 0x0001) !== 0 || ($flags & 0x0040) !== 0) {
                    throw new RuntimeException('Encrypted ZIP entries are not supported: ' . $name);
                }
                if (($flags & 0x0008) !== 0) {
                    throw new RuntimeException('ZIP data-descriptor entries are not supported: ' . $name);
                }
                $method = (int) $entry['method'];
                if (!in_array($method, [0, 8], true)) {
                    throw new RuntimeException('Unsupported ZIP compression method for ' . $name);
                }
                if ((int) $entry['disk_start'] !== 0) {
                    throw new RuntimeException('Multi-disk ZIP entry is not supported: ' . $name);
                }

                $compressed = (int) $entry['compressed'];
                $uncompressed = (int) $entry['uncompressed'];
                $localOffset = (int) $entry['local_offset'];
                if ($compressed === 0xffffffff || $uncompressed === 0xffffffff || $localOffset === 0xffffffff) {
                    throw new RuntimeException('ZIP64 entry is not supported: ' . $name);
                }
                if ($compressed < 0 || $uncompressed < 0 || $localOffset < 0 || $localOffset + 30 > $centralOffset) {
                    throw new RuntimeException('ZIP entry boundaries are invalid: ' . $name);
                }
                if (isset($seenOffsets[$localOffset])) {
                    throw new RuntimeException('ZIP entries reuse the same local header offset: ' . $name);
                }
                $seenOffsets[$localOffset] = true;

                $isDirectory = str_ends_with($name, '/');
                $this->assertSafeUnixType((int) $entry['version_made'], (int) $entry['external'], $name, $isDirectory);

                if ($isDirectory) {
                    if ($uncompressed !== 0 || $compressed !== 0) {
                        throw new RuntimeException('ZIP directory entry has unexpected content: ' . $name);
                    }
                    $directoryCount++;
                } else {
                    if ($uncompressed > self::MAX_SINGLE_UNCOMPRESSED_BYTES) {
                        throw new RuntimeException('ZIP entry exceeds per-file safety limit: ' . $name);
                    }
                    if ($uncompressed > 0 && $compressed === 0) {
                        throw new RuntimeException('ZIP entry has impossible zero compressed size: ' . $name);
                    }
                    if ($compressed > 0 && ($uncompressed / $compressed) > self::MAX_COMPRESSION_RATIO) {
                        throw new RuntimeException('ZIP entry compression ratio exceeds safety limit: ' . $name);
                    }
                    $fileCount++;
                }

                $range = $this->verifyLocalHeader(
                    $handle,
                    $entry,
                    $name,
                    $localOffset,
                    $compressed,
                    $uncompressed,
                    $centralOffset
                );
                $ranges[] = $range;
                if (fseek($handle, $nextCentralPosition, SEEK_SET) !== 0) {
                    throw new RuntimeException('Unable to return to ZIP central directory');
                }

                $totalUncompressed += $uncompressed;
                $totalCompressed += $compressed;
                if ($totalUncompressed > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('ZIP total uncompressed size exceeds safety limit');
                }
            }

            $position = ftell($handle);
            if (!is_int($position) || $position !== $centralOffset + $centralSize) {
                throw new RuntimeException('ZIP central-directory size does not match parsed entries');
            }

            usort($ranges, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
            $previousEnd = 0;
            foreach ($ranges as $range) {
                if ($range['start'] < $previousEnd) {
                    throw new RuntimeException('ZIP local entry regions overlap');
                }
                $previousEnd = $range['end'];
            }

            return [
                'entries' => $entries,
                'files' => $fileCount,
                'directories' => $directoryCount,
                'total_uncompressed' => $totalUncompressed,
                'total_compressed' => $totalCompressed,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string,int> $central
     * @return array{start:int,end:int}
     */
    private function verifyLocalHeader(
        $handle,
        array $central,
        string $centralName,
        int $localOffset,
        int $compressed,
        int $uncompressed,
        int $centralOffset
    ): array {
        if (fseek($handle, $localOffset, SEEK_SET) !== 0) {
            throw new RuntimeException('Unable to seek ZIP local header: ' . $centralName);
        }
        $fixed = fread($handle, 30);
        if (!is_string($fixed) || strlen($fixed) !== 30 || substr($fixed, 0, 4) !== "PK\x03\x04") {
            throw new RuntimeException('ZIP local header is missing or invalid: ' . $centralName);
        }
        $local = unpack(
            'vversion_needed/vflags/vmethod/vmtime/vmdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length',
            substr($fixed, 4)
        );
        if (!is_array($local)) {
            throw new RuntimeException('ZIP local header cannot be decoded: ' . $centralName);
        }

        $localNameLength = (int) $local['name_length'];
        $localExtraLength = (int) $local['extra_length'];
        if ($localNameLength < 1 || $localNameLength > self::MAX_FILENAME_BYTES
            || $localExtraLength > self::MAX_ENTRY_METADATA_BYTES) {
            throw new RuntimeException('ZIP local header metadata is invalid: ' . $centralName);
        }
        $localName = fread($handle, $localNameLength);
        $localExtra = fread($handle, $localExtraLength);
        if (!is_string($localName) || strlen($localName) !== $localNameLength
            || !is_string($localExtra) || strlen($localExtra) !== $localExtraLength) {
            throw new RuntimeException('ZIP local header metadata is truncated: ' . $centralName);
        }
        if (!hash_equals($centralName, $localName)) {
            throw new RuntimeException('ZIP local/central entry names differ: ' . $centralName);
        }
        if ($this->containsZip64Extra($localExtra)) {
            throw new RuntimeException('ZIP64 local metadata is not supported: ' . $centralName);
        }

        foreach (['flags', 'method', 'crc', 'compressed', 'uncompressed'] as $field) {
            if ((int) $local[$field] !== (int) $central[$field]) {
                throw new RuntimeException('ZIP local/central entry metadata differs for ' . $centralName);
            }
        }
        if ((int) $local['compressed'] !== $compressed || (int) $local['uncompressed'] !== $uncompressed) {
            throw new RuntimeException('ZIP local entry sizes are inconsistent: ' . $centralName);
        }

        $dataStart = $localOffset + 30 + $localNameLength + $localExtraLength;
        $dataEnd = $dataStart + $compressed;
        if ($dataStart < 0 || $dataEnd < $dataStart || $dataEnd > $centralOffset) {
            throw new RuntimeException('ZIP local entry payload crosses central directory: ' . $centralName);
        }

        return ['start' => $localOffset, 'end' => $dataEnd];
    }

    private function containsZip64Extra(string $extra): bool
    {
        $offset = 0;
        $length = strlen($extra);
        while ($offset < $length) {
            if ($offset + 4 > $length) {
                throw new RuntimeException('ZIP extra field is truncated');
            }
            $header = unpack('vid/vsize', substr($extra, $offset, 4));
            if (!is_array($header)) {
                throw new RuntimeException('ZIP extra field cannot be decoded');
            }
            $size = (int) $header['size'];
            $offset += 4;
            if ($size < 0 || $offset + $size > $length) {
                throw new RuntimeException('ZIP extra field length is invalid');
            }
            if ((int) $header['id'] === 0x0001) {
                return true;
            }
            $offset += $size;
        }
        return false;
    }

    private function assertSafePath(string $name): void
    {
        if ($name === '' || preg_match('//u', $name) !== 1 || str_contains($name, "\0")) {
            throw new RuntimeException('ZIP entry path is empty, invalid UTF-8, or contains NUL');
        }
        if (str_contains($name, '\\') || str_contains($name, ':') || str_starts_with($name, '/')) {
            throw new RuntimeException('ZIP entry path is absolute/platform-ambiguous: ' . $name);
        }

        $trimmed = rtrim($name, '/');
        if ($trimmed === '') {
            throw new RuntimeException('ZIP root directory entry is not allowed');
        }
        $segments = explode('/', $trimmed);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('ZIP entry path traversal/empty segment detected: ' . $name);
            }
            if (strlen($segment) > 255) {
                throw new RuntimeException('ZIP entry path segment exceeds 255 bytes: ' . $name);
            }
        }
    }

    private function assertSafeUnixType(int $versionMade, int $external, string $name, bool $isDirectory): void
    {
        $hostOs = ($versionMade >> 8) & 0xff;
        if ($hostOs !== 3) {
            return;
        }

        $mode = ($external >> 16) & 0xffff;
        $type = $mode & 0170000;
        if ($type === 0) {
            return;
        }
        if ($type === 0120000) {
            throw new RuntimeException('ZIP symlink entry is forbidden: ' . $name);
        }
        if ($type === 0040000 && $isDirectory) {
            return;
        }
        if ($type === 0100000 && !$isDirectory) {
            return;
        }
        throw new RuntimeException('ZIP special/mismatched Unix entry type is forbidden: ' . $name);
    }
}
