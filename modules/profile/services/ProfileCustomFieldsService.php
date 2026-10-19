<?php

declare(strict_types=1);

namespace Modules\Profile;

use Core\DatabaseManager;
use InvalidArgumentException;
use Throwable;

/**
 * Данные user_fields принадлежат Profile, а не панели Admin.
 * Все изменения выполняются в одной транзакции ядра.
 */
final class ProfileCustomFieldsService
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listFields(): array
    {
        return $this->db->fetchAll(
            'SELECT id,field_name,field_type,field_label,is_required FROM user_fields ORDER BY id ASC'
        );
    }

    /** @param list<array{id:?int,field_name:string,field_label:string,field_type:string,is_required:int}> $fields */
    public function saveFields(array $fields): void
    {
        $this->db->beginTransaction();
        try {
            $existing = $this->db->fetchAll('SELECT id FROM user_fields FOR UPDATE');
            $existingIds = array_fill_keys(array_map('intval', array_column($existing, 'id')), true);
            $keptIds = [];
            $now = date('Y-m-d H:i:s');

            foreach ($fields as $field) {
                if (!in_array($field['field_type'], ['text', 'textarea', 'number', 'date', 'select', 'checkbox'], true)
                    || preg_match('/^[a-z][a-z0-9_]{0,49}$/D', $field['field_name']) !== 1
                    || trim($field['field_label']) === ''
                    || mb_strlen($field['field_label']) > 100
                ) {
                    throw new InvalidArgumentException('Некорректное описание пользовательского поля');
                }
                $params = [
                    ':field_name' => $field['field_name'],
                    ':field_type' => $field['field_type'],
                    ':field_label' => $field['field_label'],
                    ':is_required' => $field['is_required'] ? 1 : 0,
                    ':updated_at' => $now,
                ];

                if ($field['id'] === null) {
                    $this->db->execute(
                        'INSERT INTO user_fields (field_name,field_type,field_label,is_required,created_at,updated_at)
                         VALUES (:field_name,:field_type,:field_label,:is_required,:created_at,:updated_at)',
                        $params + [':created_at' => $now]
                    );
                    continue;
                }

                $id = (int) $field['id'];
                if (!isset($existingIds[$id])) {
                    throw new InvalidArgumentException('Одно из пользовательских полей больше не существует');
                }
                if (isset($keptIds[$id])) {
                    throw new InvalidArgumentException('Повторяющийся идентификатор пользовательского поля');
                }
                $keptIds[$id] = true;
                $this->db->execute(
                    'UPDATE user_fields SET field_name=:field_name,field_type=:field_type,
                     field_label=:field_label,is_required=:is_required,updated_at=:updated_at
                     WHERE id=:id',
                    $params + [':id' => $id]
                );
            }

            foreach (array_keys($existingIds) as $id) {
                if (!isset($keptIds[$id])) {
                    $this->db->execute('DELETE FROM user_fields WHERE id=:id', [':id' => $id]);
                }
            }
            $this->db->endTransaction();
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            throw $error;
        }
    }
}
