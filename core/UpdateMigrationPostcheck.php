<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Завершение миграций нельзя доказывать только нулевым кодом --status.
 *
 * Команда --status сама по себе успешно завершается и при наличии ожидающих
 * миграций; это не разрешение фиксировать транзакцию обновления.
 */
final class UpdateMigrationPostcheck
{
    public static function assertComplete(string $statusOutput): void
    {
        $rus = preg_match(
            '/^Итого:\s*\d+\s+применено,\s*(\d+)\s+ожидает\s*$/mu',
            $statusOutput,
            $match
        );
        if ($rus !== 1) {
            $eng = preg_match(
                '/^Summary:\s*\d+\s+applied,\s*(\d+)\s+pending\s*$/mi',
                $statusOutput,
                $match
            );
            if ($eng !== 1) {
                throw new RuntimeException(
                    'Невозможно подтвердить отсутствие ожидающих миграций после обновления'
                );
            }
        }

        if ((int) $match[1] !== 0) {
            throw new RuntimeException(
                'Обновление нельзя завершить: остались ожидающие миграции: '
                . (int) $match[1]
            );
        }

        if (!str_contains($statusOutput, 'Контракт схемы: OK')
            && !str_contains($statusOutput, 'Schema contract: OK')) {
            throw new RuntimeException(
                'Обновление нельзя завершить: целевой контракт схемы не подтверждён'
            );
        }
    }
}
