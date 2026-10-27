<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/LimitedHttpResponse.php';

use App\Services\Notifications\LimitedHttpResponse;

function httpResponseAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('Ограничение HTTP-ответа: ' . $message);
    }
}

$buffer = new LimitedHttpResponse(4, 12);
httpResponseAssert($buffer->accept('abc') === 3, 'первая порция не принята');
httpResponseAssert($buffer->accept('defghi') === 6, 'вторая порция не принята');
httpResponseAssert($buffer->preview() === 'abcd' && $buffer->receivedBytes() === 9,
    'нельзя сохранять больше четырёх байтов предпросмотра');
httpResponseAssert($buffer->accept('jkl') === 3 && !$buffer->exceeded(),
    'ровно допустимый предел не должен блокироваться');
httpResponseAssert($buffer->accept('m') === 0 && $buffer->exceeded(),
    'превышение лимита должно остановить передачу');
httpResponseAssert($buffer->accept('n') === 0 && $buffer->receivedBytes() === 12
    && $buffer->preview() === 'abcd',
    'после превышения нельзя продолжать накопление');

$huge = new LimitedHttpResponse();
httpResponseAssert($huge->accept(str_repeat('x', 65536)) === 65536,
    'допустимая крупная порция должна приниматься');
httpResponseAssert(strlen($huge->preview()) === 4096 && $huge->receivedBytes() === 65536,
    'предпросмотр должен занимать не более 4 КиБ');
httpResponseAssert($huge->accept(str_repeat('z', 65536)) === 0 && $huge->exceeded(),
    'вторая крупная порция должна приводить к безопасному отказу');

$push = new LimitedHttpResponse(0, 8192);
httpResponseAssert($push->accept(str_repeat('a', 8192)) === 8192
    && $push->preview() === '',
    'Push должен считать байты, не сохраняя тело');
httpResponseAssert($push->accept('b') === 0, 'Push не должен принимать ответ сверх 8 КиБ');

foreach ([[1, 0], [-1, 1], [2, 1]] as [$capture, $limit]) {
    try {
        new LimitedHttpResponse($capture, $limit);
        throw new RuntimeException('Недопустимый бюджет принят');
    } catch (InvalidArgumentException) {
        // Ожидаемый отказ: бюджет нельзя обойти некорректной конфигурацией.
    }
}

$root = dirname(__DIR__, 2);
foreach ([
    'CurlNotificationHttpTransport.php',
    'CurlWebPushHttpTransport.php',
] as $name) {
    $source = (string) file_get_contents($root . '/app/services/notifications/' . $name);
    httpResponseAssert(str_contains($source, "CURLOPT_RETURNTRANSFER => false")
        && str_contains($source, 'CURLOPT_WRITEFUNCTION')
        && str_contains($source, 'LimitedHttpResponse')
        && str_contains($source, '->exceeded()')
        && str_contains($source, "CURLOPT_PROXY => ''"),
        $name . ' должен получать поток ограниченно, без глобального прокси');
}

echo "Внешние HTTP-уведомления: ограниченная память, порог и безопасный отказ проверены\n";
