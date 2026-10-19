<?php

declare(strict_types=1);

namespace Core;

/** Интерфейс пользовательских полей, реализуемый модулем Profile. */
interface ProfileCustomFields
{
    /** @return list<array<string,mixed>> */
    public function listFields(): array;

    /** @param list<array{id:?int,field_name:string,field_label:string,field_type:string,is_required:int}> $fields */
    public function saveFields(array $fields): void;
}
