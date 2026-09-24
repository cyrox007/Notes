#!/usr/bin/env python3
"""Локальный DNS + HTTPS fixture для сквозного теста обновления из Admin UI."""

from __future__ import annotations

import argparse
import functools
import http.server
import ipaddress
import signal
import socket
import socketserver
import ssl
import struct
import threading
import time
from pathlib import Path


class DnsUdpServer(socketserver.ThreadingUDPServer):
    allow_reuse_address = True


class HttpsServer(http.server.ThreadingHTTPServer):
    allow_reuse_address = True


def read_question(data: bytes) -> tuple[str, int, int, bytes]:
    offset = 12
    labels: list[str] = []
    while True:
        if offset >= len(data):
            raise ValueError("Обрезанный DNS-запрос")
        size = data[offset]
        offset += 1
        if size == 0:
            break
        if size & 0xC0:
            raise ValueError("DNS compression в вопросе fixture не поддерживается")
        if offset + size > len(data):
            raise ValueError("Обрезанное DNS-имя")
        labels.append(data[offset : offset + size].decode("ascii"))
        offset += size

    if offset + 4 > len(data):
        raise ValueError("DNS-вопрос не содержит type/class")

    query_type, query_class = struct.unpack("!HH", data[offset : offset + 4])
    question_end = offset + 4
    return ".".join(labels).lower(), query_type, query_class, data[12:question_end]


class DnsHandler(socketserver.BaseRequestHandler):
    hostname = ""
    address = ""

    def handle(self) -> None:
        data, sock = self.request
        if len(data) < 12:
            return

        request_id = data[:2]
        try:
            name, query_type, query_class, question = read_question(data)
        except (UnicodeDecodeError, ValueError):
            return

        if query_class != 1:
            flags = 0x8183
            header = request_id + struct.pack("!HHHHH", flags, 1, 0, 0, 0)
            sock.sendto(header + question, self.client_address)
            return

        if name != self.hostname:
            flags = 0x8183
            header = request_id + struct.pack("!HHHHH", flags, 1, 0, 0, 0)
            sock.sendto(header + question, self.client_address)
            return

        answers = b""
        answer_count = 0
        if query_type == 1:
            packed_ip = ipaddress.ip_address(self.address).packed
            answers = b"\xc0\x0c" + struct.pack("!HHIH", 1, 1, 30, len(packed_ip)) + packed_ip
            answer_count = 1

        flags = 0x8180
        header = request_id + struct.pack("!HHHHH", flags, 1, answer_count, 0, 0)
        sock.sendto(header + question + answers, self.client_address)


class QuietStaticHandler(http.server.SimpleHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, message: str, *args: object) -> None:
        print("[HTTPS] " + (message % args), flush=True)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="DNS + HTTPS fixture обновлятора")
    parser.add_argument("--root", required=True)
    parser.add_argument("--cert", required=True)
    parser.add_argument("--key", required=True)
    parser.add_argument("--host", required=True)
    parser.add_argument("--ip", required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(args.root).resolve()
    cert = Path(args.cert).resolve()
    key = Path(args.key).resolve()

    if not root.is_dir() or not cert.is_file() or not key.is_file():
        raise RuntimeError("Fixture не получил каталог артефактов или TLS-файлы")

    ipaddress.ip_address(args.ip)
    DnsHandler.hostname = args.host.lower().rstrip(".")
    DnsHandler.address = args.ip

    dns = DnsUdpServer(("127.0.0.1", 53), DnsHandler)

    handler = functools.partial(QuietStaticHandler, directory=str(root))
    https = HttpsServer((args.ip, 443), handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(certfile=str(cert), keyfile=str(key))
    https.socket = context.wrap_socket(https.socket, server_side=True)

    threads = [
        threading.Thread(target=dns.serve_forever, name="dns-fixture", daemon=True),
        threading.Thread(target=https.serve_forever, name="https-fixture", daemon=True),
    ]
    for thread in threads:
        thread.start()

    stopped = threading.Event()

    def stop(_signum: int, _frame: object) -> None:
        stopped.set()

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)

    print(
        f"[OK] fixture слушает DNS 127.0.0.1:53 и HTTPS {args.ip}:443 для {DnsHandler.hostname}",
        flush=True,
    )

    while not stopped.is_set():
        time.sleep(0.2)

    dns.shutdown()
    https.shutdown()
    dns.server_close()
    https.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
