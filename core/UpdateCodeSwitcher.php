<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/UpdateCandidateVerifier.php';
require_once __DIR__ . '/UpdateFileMutator.php';
require_once __DIR__ . '/UpdatePath.php';
require_once __DIR__ . '/UpdateRollbackCodeRestorer.php';

use JsonException;
use RuntimeException;
use Throwable;

/**
 * Граница пофайлового переключения release-owned кода.
 *
 * До destructive boundary создаётся неизменяемый план с контрольными суммами
 * исходного live-tree, candidate и списка операций. При применении план,
 * backup и candidate проверяются повторно до изменения первого файла.
 */
final class UpdateCodeSwitcher
{
    private const PLAN_SCHEMA = 2;

    private UpdateFileMutator $mutator;

    /** @param list<string> $preservedRoots */
    public function __construct(
        private readonly string $appRoot,
        private readonly string $parentRoot,
        private readonly UpdateCandidateVerifier $verifier,
        private readonly array $preservedRoots
    ) {
        $this->mutator = new UpdateFileMutator($this->appRoot, $this->preservedRoots);
    }

    /**
     * @return array{
     *   scratch_dir:string,
     *   plan_path:string,
     *   plan_sha256:string,
     *   transaction_id:string,
     *   entries:list<string>
     * }
     */
    public function prepare(string $transactionId, string $candidateDir, string $backupDir): array
    {
        $this->validateTransactionId($transactionId);

        $candidate = $this->verifier->verifyCandidateTree($candidateDir);
        $backup = $this->verifier->loadCodeManifest($backupDir);
        $sourceMap = $this->backupContentMap($backup['entries']);
        $liveMap = $this->mutator->releaseFiles();
        $this->assertContentMapsEqual(
            $sourceMap,
            $liveMap,
            'Live-tree изменился после создания проверенного rollback backup'
        );

        $targetMap = $this->candidateMap($candidate);
        $operations = $this->buildOperations($liveMap, $targetMap);
        if ($operations === []) {
            throw new RuntimeException('Пофайловый updater-план не содержит изменений');
        }

        $scratch = $this->scratchPath($transactionId);
        if (file_exists($scratch) || is_link($scratch)) {
            throw new RuntimeException('Каталог updater-плана уже существует; требуется recovery перед повторным apply');
        }

        $oldUmask = umask(0077);
        $made = @mkdir($scratch, 0700, false);
        umask($oldUmask);
        if (!$made || !is_dir($scratch)) {
            throw new RuntimeException('Не удалось создать внешний каталог updater-плана');
        }

        $planPath = $scratch . DIRECTORY_SEPARATOR . 'plan.json';

        try {
            $payload = [
                'schema' => self::PLAN_SCHEMA,
                'transaction_id' => $transactionId,
                'mode' => 'file-apply',
                'application_root' => $this->appRoot,
                'candidate_dir' => $candidate['candidate_dir'],
                'candidate_tree_sha256' => $candidate['tree_sha256'],
                'backup_dir' => $backup['backup_dir'],
                'source_files_sha256' => $this->mapSha256($sourceMap),
                'target_files_sha256' => $this->mapSha256($targetMap),
                'operations' => $operations,
            ];

            $bytes = $this->jsonBytes($payload);
            $this->writeExclusive($planPath, $bytes, 0600);
            $planSha = hash('sha256', $bytes);
        } catch (Throwable $e) {
            UpdatePath::removeTree($scratch);
            throw $e;
        }

        return [
            'scratch_dir' => $scratch,
            'plan_path' => $planPath,
            'plan_sha256' => $planSha,
            'transaction_id' => $transactionId,
            'entries' => $this->topLevelsFromOperations($operations),
        ];
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public function switchPrepared(array $plan): array
    {
        $prepared = $this->loadPreparedPlan($plan);
        $payload = $prepared['payload'];
        $transactionId = (string) $payload['transaction_id'];

        $candidate = $this->verifier->verifyCandidateTree((string) $payload['candidate_dir']);
        if (!hash_equals((string) $payload['candidate_tree_sha256'], (string) $candidate['tree_sha256'])) {
            throw new RuntimeException('Candidate изменился после фиксации пофайлового updater-плана');
        }

        $backup = $this->verifier->loadCodeManifest((string) $payload['backup_dir']);
        $sourceMap = $this->backupContentMap($backup['entries']);
        if (!hash_equals((string) $payload['source_files_sha256'], $this->mapSha256($sourceMap))) {
            throw new RuntimeException('Rollback backup изменился после фиксации пофайлового updater-плана');
        }

        $targetMap = $this->candidateMap($candidate);
        if (!hash_equals((string) $payload['target_files_sha256'], $this->mapSha256($targetMap))) {
            throw new RuntimeException('Candidate file-map изменился после фиксации пофайлового updater-плана');
        }

        $liveMap = $this->mutator->releaseFiles();
        $this->assertContentMapsEqual(
            $sourceMap,
            $liveMap,
            'Live-tree изменился после фиксации пофайлового updater-плана'
        );

        $expectedOperations = $this->buildOperations($liveMap, $targetMap);
        $recordedOperations = is_array($payload['operations'] ?? null)
            ? array_values($payload['operations'])
            : [];
        if (!hash_equals(
            hash('sha256', $this->jsonBytes($expectedOperations)),
            hash('sha256', $this->jsonBytes($recordedOperations))
        )) {
            throw new RuntimeException('Список операций пофайлового updater-плана не прошёл повторную проверку');
        }

        try {
            foreach ($expectedOperations as $operation) {
                $relative = (string) $operation['path'];

                if ($operation['action'] === 'delete') {
                    $this->mutator->delete($relative, $transactionId);
                    continue;
                }

                $after = $operation['after'];
                $source = (string) $candidate['candidate_dir']
                    . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $relative);

                $this->mutator->replaceVerified(
                    $source,
                    $relative,
                    (int) $after['size'],
                    (string) $after['sha256'],
                    (int) $after['mode'],
                    $transactionId
                );
            }

            $this->assertContentMapsEqual(
                $this->contentOnlyMap($targetMap),
                $this->mutator->releaseFiles(),
                'Live-tree не совпадает с candidate после пофайлового apply'
            );
        } catch (Throwable $e) {
            $this->cleanupScratch((string) $prepared['scratch_dir']);
            throw $e;
        }

        $entries = $this->topLevelsFromOperations($expectedOperations);
        $this->cleanupScratch((string) $prepared['scratch_dir']);

        return [
            'entries' => $entries,
            'operations' => count($expectedOperations),
            'file_level' => true,
            'switched_at' => time(),
        ];
    }

    /** @return array<string,mixed> */
    public function restore(string $transactionId, string $backupDir, string $candidateDir): array
    {
        unset($candidateDir);
        return (new UpdateRollbackCodeRestorer($this->appRoot))->restore($transactionId, $backupDir);
    }

    /**
     * @param array<string,mixed> $plan
     * @return array{scratch_dir:string,payload:array<string,mixed>}
     */
    private function loadPreparedPlan(array $plan): array
    {
        $scratchInput = (string) ($plan['scratch_dir'] ?? '');
        $scratch = realpath($scratchInput);
        if (
            !is_string($scratch)
            || !is_dir($scratch)
            || is_link($scratchInput)
            || UpdatePath::normalize(dirname($scratch)) !== $this->parentRoot
        ) {
            throw new RuntimeException('Внешний каталог updater-плана отсутствует или небезопасен');
        }
        $scratch = UpdatePath::normalize($scratch);

        $planInput = (string) ($plan['plan_path'] ?? '');
        $planPath = realpath($planInput);
        if (
            !is_string($planPath)
            || !is_file($planPath)
            || is_link($planInput)
            || UpdatePath::normalize($planPath) !== $scratch . '/plan.json'
        ) {
            throw new RuntimeException('Файл пофайлового updater-плана отсутствует или небезопасен');
        }

        $bytes = file_get_contents($planPath);
        $actualSha = hash_file('sha256', $planPath);
        $expectedSha = strtolower(trim((string) ($plan['plan_sha256'] ?? '')));
        if (
            !is_string($bytes)
            || !is_string($actualSha)
            || preg_match('/^[0-9a-f]{64}$/D', $expectedSha) !== 1
            || !hash_equals($expectedSha, $actualSha)
        ) {
            throw new RuntimeException('Пофайловый updater-план изменился после подготовки');
        }

        try {
            $payload = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Пофайловый updater-план содержит некорректный JSON', 0, $e);
        }

        if (
            !is_array($payload)
            || array_is_list($payload)
            || ($payload['schema'] ?? null) !== self::PLAN_SCHEMA
            || ($payload['mode'] ?? null) !== 'file-apply'
            || !hash_equals($this->appRoot, UpdatePath::normalize((string) ($payload['application_root'] ?? '')))
            || !is_array($payload['operations'] ?? null)
        ) {
            throw new RuntimeException('Пофайловый updater-план не прошёл проверку схемы');
        }

        $transactionId = (string) ($payload['transaction_id'] ?? '');
        $this->validateTransactionId($transactionId);
        if (!hash_equals($transactionId, (string) ($plan['transaction_id'] ?? ''))) {
            throw new RuntimeException('Пофайловый updater-план принадлежит другой транзакции');
        }

        foreach (['candidate_dir', 'candidate_tree_sha256', 'backup_dir', 'source_files_sha256', 'target_files_sha256'] as $key) {
            if (!is_string($payload[$key] ?? null) || trim((string) $payload[$key]) === '') {
                throw new RuntimeException('Пофайловый updater-план не содержит обязательное поле: ' . $key);
            }
        }

        return ['scratch_dir' => $scratch, 'payload' => $payload];
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return array<string,array{size:int,sha256:string}>
     */
    private function backupContentMap(array $entries): array
    {
        $map = [];

        foreach ($entries as $entry) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', trim((string) ($entry['path'] ?? '')));
            if (!$this->mutator->isReleaseOwned($relative)) {
                throw new RuntimeException('Rollback backup содержит защищённый или небезопасный путь: ' . $relative);
            }
            if (isset($map[$relative])) {
                throw new RuntimeException('Rollback backup содержит повторяющийся путь: ' . $relative);
            }

            $size = (int) ($entry['size'] ?? -1);
            $sha = strtolower(trim((string) ($entry['sha256'] ?? '')));
            if ($size < 0 || preg_match('/^[0-9a-f]{64}$/D', $sha) !== 1) {
                throw new RuntimeException('Rollback backup содержит некорректную метаинформацию: ' . $relative);
            }

            $map[$relative] = ['size' => $size, 'sha256' => $sha];
        }

        ksort($map, SORT_STRING);
        return $map;
    }

    /**
     * @param array<string,mixed> $candidate
     * @return array<string,array{size:int,sha256:string,mode:int}>
     */
    private function candidateMap(array $candidate): array
    {
        $fileMap = $candidate['file_map'] ?? null;
        if (!is_array($fileMap)) {
            throw new RuntimeException('Проверенный candidate не содержит file-map');
        }

        $map = [];
        foreach ($fileMap as $relative => $metadata) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', trim((string) $relative));
            if (!$this->mutator->isReleaseOwned($relative)) {
                continue;
            }
            if (!is_array($metadata)) {
                throw new RuntimeException('Candidate file-map повреждён: ' . $relative);
            }

            $source = (string) $candidate['candidate_dir']
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $permissions = fileperms($source);
            $mode = is_int($permissions) ? ($permissions & 0777) : 0644;
            if ($mode === 0) {
                $mode = 0644;
            }

            $map[$relative] = [
                'size' => (int) ($metadata['size'] ?? -1),
                'sha256' => strtolower(trim((string) ($metadata['sha256'] ?? ''))),
                'mode' => $mode,
            ];
        }

        ksort($map, SORT_STRING);
        return $map;
    }

    /**
     * @param array<string,array{size:int,sha256:string}> $source
     * @param array<string,array{size:int,sha256:string,mode:int}> $target
     * @return list<array<string,mixed>>
     */
    private function buildOperations(array $source, array $target): array
    {
        $delete = array_values(array_diff(array_keys($source), array_keys($target)));
        usort($delete, [$this, 'deepestFirst']);

        $operations = [];
        foreach ($delete as $relative) {
            $operations[] = [
                'action' => 'delete',
                'path' => $relative,
                'before' => $source[$relative],
                'after' => null,
            ];
        }

        $replace = [];
        foreach ($target as $relative => $after) {
            $before = $source[$relative] ?? null;
            if (
                is_array($before)
                && (int) $before['size'] === (int) $after['size']
                && hash_equals((string) $before['sha256'], (string) $after['sha256'])
            ) {
                continue;
            }
            $replace[] = $relative;
        }
        usort($replace, [$this, 'shallowestFirst']);

        foreach ($replace as $relative) {
            $operations[] = [
                'action' => 'replace',
                'path' => $relative,
                'before' => $source[$relative] ?? null,
                'after' => $target[$relative],
            ];
        }

        return $operations;
    }

    /**
     * @param array<string,array{size:int,sha256:string,mode:int}> $map
     * @return array<string,array{size:int,sha256:string}>
     */
    private function contentOnlyMap(array $map): array
    {
        $result = [];
        foreach ($map as $relative => $metadata) {
            $result[$relative] = [
                'size' => (int) $metadata['size'],
                'sha256' => (string) $metadata['sha256'],
            ];
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param array<string,array{size:int,sha256:string}> $expected
     * @param array<string,array{size:int,sha256:string}> $actual
     */
    private function assertContentMapsEqual(array $expected, array $actual, string $message): void
    {
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);

        if (!hash_equals($this->mapSha256($expected), $this->mapSha256($actual))) {
            throw new RuntimeException($message);
        }
    }

    /** @param array<string,mixed> $map */
    private function mapSha256(array $map): string
    {
        return hash('sha256', $this->jsonBytes($map));
    }

    /** @param array<mixed> $value */
    private function jsonBytes(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /** @param list<array<string,mixed>> $operations @return list<string> */
    private function topLevelsFromOperations(array $operations): array
    {
        $entries = [];
        foreach ($operations as $operation) {
            $relative = (string) ($operation['path'] ?? '');
            if (!$this->mutator->isReleaseOwned($relative)) {
                throw new RuntimeException('Updater-план содержит небезопасный путь: ' . $relative);
            }
            $entries[explode('/', $relative, 2)[0]] = true;
        }

        $result = array_keys($entries);
        sort($result, SORT_STRING);
        return $result;
    }

    private function writeExclusive(string $path, string $bytes, int $mode): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Не удалось записать пофайловый updater-план');
        }

        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Пофайловый updater-план записан не полностью');
            }
        } finally {
            fclose($handle);
        }

        @chmod($path, $mode & 0777);
    }

    private function cleanupScratch(string $scratch): void
    {
        try {
            if (is_dir($scratch) && !is_link($scratch)) {
                UpdatePath::removeTree($scratch);
            }
        } catch (Throwable) {
            // Диагностический plan не влияет на уже проверенный результат apply/rollback.
        }
    }

    private function scratchPath(string $transactionId): string
    {
        return $this->parentRoot
            . DIRECTORY_SEPARATOR
            . '.'
            . basename($this->appRoot)
            . '.update-plan-'
            . $transactionId;
    }

    private function deepestFirst(string $left, string $right): int
    {
        $depth = substr_count($right, '/') <=> substr_count($left, '/');
        return $depth !== 0 ? $depth : strcmp($left, $right);
    }

    private function shallowestFirst(string $left, string $right): int
    {
        $depth = substr_count($left, '/') <=> substr_count($right, '/');
        return $depth !== 0 ? $depth : strcmp($left, $right);
    }

    private function validateTransactionId(string $transactionId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/D', trim($transactionId)) !== 1) {
            throw new RuntimeException('Некорректный идентификатор updater-транзакции');
        }
    }
}
