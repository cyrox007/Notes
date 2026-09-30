<?php

declare(strict_types=1);

namespace Core;

/**
 * Центральная граница проверки права лицензии на запуск прикладного модуля.
 *
 * Реестр модулей не должен знать формат лицензионного токена. Он получает
 * только нормализованное решение для уже проверенного manifest.
 */
interface ModuleEntitlementResolver
{
    /**
     * @return array{entitled:bool,feature:?string,reason:?string}
     */
    public function decision(ModuleManifest $manifest): array;
}
