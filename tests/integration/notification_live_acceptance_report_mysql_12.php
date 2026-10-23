<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationLiveAcceptanceReport.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationReceiptAttestation.php';

use App\Services\Notifications\NotificationLiveAcceptanceReport;
use App\Services\Notifications\NotificationReceiptAttestation;
use Core\DatabaseManager;

function checkReport(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('Приёмка уведомлений: ' . $message);
}
$dbName = (string) (getenv('TEST_DB_NAME') ?: '');
checkReport($dbName !== '' && str_contains($dbName, 'test'), 'разрешена только тестовая БД');
$pdo = new PDO(
    'mysql:host=' . (getenv('TEST_DB_HOST') ?: '127.0.0.1')
    . ';port=' . (getenv('TEST_DB_PORT') ?: '3306')
    . ';dbname=' . $dbName . ';charset=utf8mb4',
    getenv('TEST_DB_USER') ?: 'workspace',
    getenv('TEST_DB_PASS') ?: 'workspace',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
foreach ([
    'CREATE TABLE users(id INT PRIMARY KEY,is_active INT,account_status VARCHAR(16),deletion_requested_at DATETIME NULL,anonymized_at DATETIME NULL)',
    'CREATE TABLE notification_events(id INT PRIMARY KEY,event_name VARCHAR(80),category VARCHAR(80))',
    'CREATE TABLE notifications(id INT PRIMARY KEY,event_id INT,recipient_user_id INT,title TEXT,body TEXT)',
    'CREATE TABLE notification_delivery_jobs(id INT PRIMARY KEY,notification_id INT,channel VARCHAR(32),state VARCHAR(32),attempts INT,last_result_code VARCHAR(80),created_at DATETIME,sent_at DATETIME NULL,updated_at DATETIME,recipient_ref VARCHAR(255),secret_ref TEXT)',
    'CREATE TABLE notification_delivery_attempts(job_id INT,attempt_no INT,result_state VARCHAR(32),result_code VARCHAR(80),attempted_at DATETIME,error_summary TEXT)',
    'CREATE TABLE notification_receipt_attestations(job_id INT PRIMARY KEY,operator_user_id INT,receipt_state VARCHAR(16),confirmed_at DATETIME,updated_at DATETIME)',
] as $sql) $pdo->exec($sql);

try {
    $pdo->exec("INSERT INTO users VALUES(42,1,'active',NULL,NULL)");
    $pdo->exec("INSERT INTO notification_events VALUES(1,'tasks.overdue_repeat','tasks.reminders')");
    $pdo->exec("INSERT INTO notifications VALUES(1,1,42,'Секретный заголовок','Личное сообщение')");
    $pdo->exec("INSERT INTO notification_delivery_jobs VALUES
        (10,1,'email','sent',2,'smtp_accepted','2026-10-22 10:00:00','2026-10-22 10:01:00','2026-10-22 10:01:00','hidden@example.test','secret')");
    $pdo->exec("INSERT INTO notification_delivery_attempts VALUES
        (10,1,'retry','smtp_temporary','2026-10-22 10:00:00','Частный текст'),
        (10,2,'sent','smtp_accepted','2026-10-22 10:01:00',NULL)");
    $report = (new NotificationLiveAcceptanceReport(DatabaseManager::forConnection($pdo)))->forJob(10);
    checkReport($report['transport_accepted'] && !$report['recipient_receipt_verified']
        && count($report['history']) === 2, 'потеряна история или принятие ошибочно считается получением');
    checkReport($report['history'][0]['code'] === 'smtp_temporary'
        && $report['history'][1]['code'] === 'smtp_accepted', 'потеряны коды попыток');
    $text = json_encode($report, JSON_THROW_ON_ERROR);
    foreach (['hidden@example.test','secret','Секретный заголовок','Личное сообщение','Частный текст'] as $part) {
        checkReport(!str_contains($text, $part), 'отчёт раскрывает чувствительную информацию');
    }
    checkReport((new NotificationLiveAcceptanceReport(DatabaseManager::forConnection($pdo)))->forJob(999)['status'] === 'not_found',
        'несуществующее задание не распознано');
    $attest = new NotificationReceiptAttestation(DatabaseManager::forConnection($pdo));
    $attest->record(10, 42, true);
    $pdo->exec("UPDATE notification_delivery_jobs SET state='retry' WHERE id=10");
    $rejected = false;
    try { $attest->record(10, 42, true); } catch (DomainException) { $rejected = true; }
    checkReport($rejected, 'принятие канала обязательно для положительной отметки');
    $pdo->exec("UPDATE notification_delivery_jobs SET state='sent' WHERE id=10");
    $confirmed = (new NotificationLiveAcceptanceReport(DatabaseManager::forConnection($pdo)))->forJob(10);
    checkReport($confirmed['recipient_receipt_verified'] === true
        && $confirmed['recipient_receipt_status'] === 'received', 'ручное подтверждение не отражено');
    $attest->record(10, 42, false);
    $negative = (new NotificationLiveAcceptanceReport(DatabaseManager::forConnection($pdo)))->forJob(10);
    checkReport(!$negative['recipient_receipt_verified'] && $negative['recipient_receipt_status'] === 'not_received',
        'отрицательная отметка не отражена');
    echo "Отчёт приёмки: история, обезличивание, различие принятия и получения проверены\n";
} finally {
    foreach (['notification_receipt_attestations','notification_delivery_attempts','notification_delivery_jobs','notifications','notification_events','users'] as $table) {
        $pdo->exec('DROP TABLE ' . $table);
    }
}
