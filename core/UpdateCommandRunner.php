<?php

declare(strict_types=1);

namespace Core;

/**
 * Единая граница выполнения внутренних команд обновлятора.
 *
 * Реализация может использовать отдельный PHP-процесс либо безопасный
 * внутрипроцессный режим для хостингов, где запуск дочерних процессов запрещён.
 */
interface UpdateCommandRunner
{
    /**
     * @param list<string> $command
     * @return array{code:int,stdout:string,stderr:string}
     */
    public function run(array $command, string $cwd, int $timeoutSeconds): array;

    /** @param array{code:int,stdout:string,stderr:string} $result */
    public function failureDetails(array $result): string;
}
