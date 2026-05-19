<?php 

namespace App\Sockets;

use Workerman\Connection\TcpConnection;

class PingSocket {
    public function index(array $conns, TcpConnection $conn, $ping) {
        if ($ping == "Pong") {
            $conn->pingWithoutResponseCount = 0;
        }
        return;
    }
}