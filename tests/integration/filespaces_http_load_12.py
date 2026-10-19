#!/usr/bin/env python3
"""Ограниченная нагрузочная приёмка настоящих HTTP-маршрутов Files 1.2."""

import argparse
import collections
import concurrent.futures
import http.cookiejar
import html.parser
import json
import os
import pathlib
import re
import statistics
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


class Fields(html.parser.HTMLParser):
    """Читает скрытые поля выбранной формы без зависимости от оформления."""

    def __init__(self, action_suffix=None):
        super().__init__()
        self.target = action_suffix
        self.inside = False
        self.values = {}

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if tag == "form":
            action = values.get("action", "")
            self.inside = self.target is None or action.endswith(self.target)
        if tag == "input" and self.inside:
            name = values.get("name")
            if name:
                self.values[name] = values.get("value", "")

    def handle_endtag(self, tag):
        if tag == "form":
            self.inside = False


def request(opener, url, method="GET", body=None, headers=None):
    started = time.perf_counter()
    r = urllib.request.Request(url, data=body, headers=headers or {}, method=method)
    try:
        with opener.open(r, timeout=12) as response:
            return response.status, response.read(), (time.perf_counter() - started) * 1000
    except urllib.error.HTTPError as error:
        return error.code, error.read(), (time.perf_counter() - started) * 1000


def connect(origin, username, password, folder_id):
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    status, body, _ = request(opener, origin + "/auth/login/")
    if status != 200:
        raise RuntimeError(f"Страница входа вернула HTTP {status}")
    fields = Fields()
    fields.feed(body.decode("utf-8", errors="replace"))
    csrf = fields.values.get("csrf_token")
    if not csrf:
        raise RuntimeError("Не получен защитный токен входа")
    form = urllib.parse.urlencode({
        "login": username, "password": password, "csrf_token": csrf,
    }).encode()
    status, _, _ = request(opener, origin + "/auth/login/", "POST", form, {
        "Content-Type": "application/x-www-form-urlencoded"
    })
    if status != 200:
        raise RuntimeError(f"Не удалось завершить вход через HTTP: {status}")
    status, html, _ = request(
        opener, origin + f"/files/spaces/global/?folder={folder_id}"
    )
    if status != 200 or "Нагрузочный.txt" not in html.decode("utf-8", errors="replace"):
        raise RuntimeError(f"Авторизованный каталог не открылся: HTTP {status}")
    return opener


def multipart(fields, content):
    boundary = "files-http-" + uuid.uuid4().hex
    parts = []
    for name, value in fields.items():
        parts += [
            f"--{boundary}\r\n".encode(),
            f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode(),
            str(value).encode(),
            b"\r\n",
        ]
    parts += [
        f"--{boundary}\r\n".encode(),
        ('Content-Disposition: form-data; name="file"; filename="Я-нагрузка-' + uuid.uuid4().hex + '.txt"\r\n').encode("utf-8"),
        b"Content-Type: text/plain\r\n\r\n",
        content,
        b"\r\n",
        f"--{boundary}--\r\n".encode(),
    ]
    return b"".join(parts), "multipart/form-data; boundary=" + boundary


def quantile(values, rank):
    if not values:
        return None
    sorted_values = sorted(values)
    return round(sorted_values[min(len(values) - 1, int((len(values) - 1) * rank))], 2)


def mysql_metrics():
    command = ["mysql", "-h", os.getenv("DBHOST", "127.0.0.1"),
               "-P", os.getenv("DBPORT", "3306"),
               "-u", os.getenv("DBUSER", "root"), "--batch", "--skip-column-names",
               "-e", "SHOW GLOBAL STATUS WHERE Variable_name IN "
               "('Queries','Threads_connected','Threads_running','Slow_queries',"
               "'Innodb_row_lock_waits')"]
    result = subprocess.run(command, capture_output=True, text=True,
                            timeout=3, check=True)
    return {key: int(value) for line in result.stdout.splitlines()
            if len(tokens := line.split("\t")) == 2
            for key, value in [tokens]}


def fpm_metrics(origin):
    try:
        with urllib.request.urlopen(origin + "/__fpm_status?json", timeout=2) as response:
            data = json.load(response)
    except (OSError, ValueError):
        return {}
    return {key: data.get(key, 0)
            for key in ("active processes", "idle processes", "listen queue",
                        "max children reached", "accepted conn")}


def php_memory_kib():
    result = subprocess.run(
        ["ps", "-C", "php-fpm8.3", "-o", "rss="],
        capture_output=True, text=True, timeout=3, check=True
    )
    return sum(int(x) for x in result.stdout.split() if x.isdigit())


def resource_monitor(origin, disk, stop, samples):
    while not stop.is_set():
        try:
            disk_free = os.statvfs(disk)
            samples.append({
                "ts": time.time(),
                "mysql": mysql_metrics(),
                "fpm": fpm_metrics(origin),
                "php_rss_kib": php_memory_kib(),
                "disk_free_bytes": disk_free.f_bavail * disk_free.f_frsize,
            })
        except (OSError, subprocess.SubprocessError, ValueError) as error:
            samples.append({"ts": time.time(), "ошибка": str(error)})
        stop.wait(1)


def worker(index, origin, fixture, password, stop_at):
    opener = connect(origin, fixture["username"], password, fixture["folder_id"])
    folder = f"/files/spaces/global/?folder={fixture['folder_id']}"
    download = f"/files/spaces/global/download/{fixture['file_id']}/"
    latencies = []
    counts = collections.Counter()
    errors = []
    sequence = 0
    while time.monotonic() < stop_at:
        mode = sequence % 10
        sequence += 1
        try:
            if mode in (0, 1, 2, 3, 4, 5):
                status, response, latency = request(opener, origin + folder)
                if status == 200 and "Нагрузочный.txt" not in response.decode("utf-8", "replace"):
                    raise RuntimeError("Каталог вернул страницу без тестового файла")
                expected = 200
            elif mode in (6, 7, 8):
                status, response, latency = request(opener, origin + download)
                if status == 200 and response != fixture["expected_text"].encode():
                    raise RuntimeError("Скачанный файл не совпадает с оригиналом")
                expected = 200
            else:
                status, html, form_latency = request(opener, origin + folder)
                # Чтение формы — полноценный HTTP-запрос. Ранее оно
                # отсутствовало в знаменателе общей нагрузки.
                counts[str(status)] += 1
                latencies.append(form_latency)
                if status != 200:
                    raise RuntimeError(f"Нельзя прочитать каталог перед загрузкой: {status}")
                parser = Fields("/files/spaces/global/upload/")
                parser.feed(html.decode("utf-8", "replace"))
                if not parser.values.get("csrf_token") or not parser.values.get("expected_version"):
                    raise RuntimeError("Не удалось прочитать версию каталога или токен")
                fields = {
                    "parent_id": fixture["folder_id"],
                    "expected_version": parser.values["expected_version"],
                    "command_id": "files:http:load:" + uuid.uuid4().hex,
                    "csrf_token": parser.values["csrf_token"],
                }
                payload, content_type = multipart(fields, "Пробная нагрузочная запись Files".encode("utf-8"))
                status, response, latency = request(
                    opener, origin + "/files/spaces/global/upload/",
                    "POST", payload,
                    {"Content-Type": content_type, "Accept": "application/json",
                     "X-Requested-With": "XMLHttpRequest"}
                )
                if status == 200 and json.loads(response).get("success") is not True:
                    raise RuntimeError("Загрузка не была зафиксирована")
                expected = (200, 409)
            counts[str(status)] += 1
            latencies.append(latency)
            if status not in (expected if isinstance(expected, tuple) else (expected,)):
                errors.append(f"HTTP {status}: операция #{sequence}")
        except (OSError, ValueError, RuntimeError) as error:
            errors.append(str(error))
        time.sleep(0.04)
    return {"counts": counts, "latencies": latencies, "errors": errors[:20]}


def main():
    parser = argparse.ArgumentParser(description="Нагрузочная проверка Files 1.2 через веб-сервер")
    parser.add_argument("--origin", default="http://127.0.0.1:18096")
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--out", required=True)
    parser.add_argument("--concurrency", type=int, default=8)
    parser.add_argument("--seconds", type=int, default=12)
    args = parser.parse_args()
    if not 1 <= args.concurrency <= 16 or not 3 <= args.seconds <= 60:
        raise SystemExit("Допустимо 1–16 клиентов и 3–60 секунд нагрузки")
    fixture = json.loads(pathlib.Path(args.fixture).read_text())
    password = os.environ["FSP_HTTP_PASSWORD"]
    storage = pathlib.Path(os.environ["PRIVATE_STORAGE_PATH"])
    stop = threading.Event()
    samples = []
    observer = threading.Thread(
        target=resource_monitor,
        args=(args.origin, str(storage), stop, samples), daemon=True
    )
    observer.start()
    started = time.monotonic()
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as executor:
        futures = [
            executor.submit(worker, i, args.origin, fixture, password,
                            started + args.seconds)
            for i in range(args.concurrency)
        ]
        results = [f.result(timeout=args.seconds + 30) for f in futures]
    stop.set()
    observer.join(timeout=4)
    latencies = [t for row in results for t in row["latencies"]]
    counts = collections.Counter()
    for row in results:
        counts.update(row["counts"])
    errors = [item for row in results for item in row["errors"]]
    queries = [
        s["mysql"]["Queries"] for s in samples if "Queries" in s.get("mysql", {})
    ]
    free = [s["disk_free_bytes"] for s in samples if "disk_free_bytes" in s]
    php = [s["php_rss_kib"] for s in samples if "php_rss_kib" in s]
    active = [s["fpm"].get("active processes", 0) for s in samples if "fpm" in s]
    queue = [s["fpm"].get("listen queue", 0) for s in samples if "fpm" in s]
    report = {
        "описание": "Ограниченный стенд: реальные маршруты, Nginx, PHP-FPM, MySQL",
        "клиентов": args.concurrency,
        "время_сек": round(time.monotonic() - started, 2),
        "запросов": sum(counts.values()),
        "ответы_http": dict(counts),
        "ошибок": len(errors),
        "примеры_ошибок": errors[:20],
        "p50_мс": quantile(latencies, 0.50),
        "p95_мс": quantile(latencies, 0.95),
        "p99_мс": quantile(latencies, 0.99),
        "mysql_запросов": max(queries) - min(queries) if len(queries) > 1 else None,
        "php_fpm_пик_rss_миб": round(max(php) / 1024, 1) if php else None,
        "php_fpm_пик_активных": max(active) if active else None,
        "php_fpm_пик_очереди": max(queue) if queue else None,
        "диск_изменение_миб": round((max(free) - min(free)) / 1048576, 2) if free else None,
        "замеры_ресурсов": len(samples),
    }
    pathlib.Path(args.out).write_text(json.dumps(report, ensure_ascii=False, indent=2))
    print(json.dumps(report, ensure_ascii=False, indent=2))
    if errors or counts.get("500", 0) or counts.get("502", 0) or counts.get("503", 0):
        raise SystemExit("Ошибки HTTP-приложения при нагрузке")
    if report["запросов"] < args.concurrency * 2:
        raise SystemExit("Недостаточно успешных запросов для приёмки")
    if not samples or not active or not queries or not php:
        raise SystemExit("Метрики PHP-FPM/MySQL/памяти недоступны")


if __name__ == "__main__":
    main()
