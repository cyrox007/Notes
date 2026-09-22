<?php

declare(strict_types=1);

/**
 * Trust registry подписи обновлений Workspace Organizer.
 *
 * ГРАНИЦА БЕЗОПАСНОСТИ:
 * - здесь допускаются только raw 32-byte ПУБЛИЧНЫЕ ключи Ed25519 в base64url;
 * - ключи подписи обновлений относятся к отдельному криптографическому домену
 *   и не должны совпадать с ключами лицензий;
 * - приватные ключи подписи обновлений никогда не должны коммититься,
 *   устанавливаться на customer servers, храниться в .env/database settings
 *   или попадать в release bundles;
 * - key ID неизменяемы. При ротации поставляйте старый и новый public keys
 *   вместе, а старый ключ удаляйте только в одном из следующих релизов.
 *
 * Production public trust root обновлений зафиксирован ниже. Соответствующий
 * private signing key остаётся offline и никогда не должен коммититься или
 * распространяться.
 *
 * @return array<string,string> key-id => публичный Ed25519 key в base64url
 */
return [
    'update-prod-2026-01' => 'HFDLlfhevvFZQRlA-ZVZLnmpH1U5bcG8SJMw2uZOXL8',
];
