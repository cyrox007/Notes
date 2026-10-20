#!/usr/bin/env python3
"""Ограниченная HTTP-нагрузка «Дефектов»: авторизация, чтение и изменение данных."""

import argparse
import collections
import concurrent.futures
import datetime
import http.cookiejar
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import subprocess
import threading
import time
from urllib.error import HTTPError
from urllib.parse import urlparse, urlencode
from urllib.request import HTTPCookieProcessor, Request, build_opener, urlopen
import uuid


class HiddenFields(HTMLParser):
    def __init__(self):
        super().__init__()
        self.values = {}

    def handle_starttag(self, tag, attrs):
        if tag == "input":
            fields = dict(attrs)
            if fields.get("name"):
                self.values[fields["name"]] = fields.get("value", "")


def request(opener, origin, path, payload=None, csrf=None):
    headers = {"Accept": "application/json"}
    data = None
    method = "GET"
    if payload is not None:
        headers["Content-Type"] = "application/json"
        headers["X-CSRF-Token"] = csrf or ""
        data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        method = "POST"
    target = Request(origin + path, data=data, headers=headers, method=method)
    start = time.perf_counter()
    try:
        with opener.open(target, timeout=15) as response:
            status, body = response.status, response.read()
    except HTTPError as error:
        status, body = error.code, error.read()
    elapsed = (time.perf_counter() - start) * 1000
    try:
        answer = json.loads(body)
    except (ValueError, UnicodeDecodeError):
        answer = None
    return status, answer, elapsed


def login(origin, username, password):
    opener = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
    with opener.open(origin + "/auth/login/", timeout=15) as response:
        fields = HiddenFields()
        fields.feed(response.read().decode("utf-8", errors="replace"))
    token = fields.values.get("csrf_token")
    if not token:
        raise RuntimeError("Вход не выдал защитный токен")
    data = urlencode({"login": username, "password": password, "csrf_token": token}).encode()
    with opener.open(Request(
        origin + "/auth/login/", data=data, method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded"}
    ), timeout=15) as response:
        if response.status != 200:
            raise RuntimeError("Вход вернул HTTP " + str(response.status))
    with opener.open(origin + "/defects/", timeout=15) as response:
        if response.status != 200:
            raise RuntimeError("Недоступна страница Дефектов")
        markup = response.read().decode("utf-8", errors="replace")
    if 'id="defects-workspace"' not in markup:
        raise RuntimeError("Страница Дефектов не загружена после входа")
    fields = HiddenFields()
    fields.feed(markup)
    csrf = fields.values.get("csrf_token")
    if not csrf:
        raise RuntimeError("Страница Дефектов не выдала токен защиты AJAX")
    return opener, csrf


def insist(condition, message):
    if not condition:
        raise RuntimeError(message)


def get_json(opener, origin, path, label):
    status, answer, elapsed = request(opener, origin, path)
    insist(status == 200 and isinstance(answer, dict) and answer.get("status") == "ok",
           f"{label}: HTTP {status}; ответ {str(answer)[:180]}")
    return answer, elapsed


def post_json(opener, origin, csrf, path, body, expected=200):
    status, answer, elapsed = request(opener, origin, path, body, csrf)
    insist(status == expected and isinstance(answer, dict)
           and answer.get("status") == ("ok" if expected < 400 else "error"),
           f"Команда {path}: HTTP {status}, ожидается {expected}; ответ {str(answer)[:180]}")
    return answer, elapsed


def new_payload(fixture, marker, suffix):
    return {
        "object_id": fixture["object_id"],
        "service_ids": fixture["service_ids"],
        "description": "HTTP нагрузка Дефекты " + marker + " " + suffix,
        "priority": "normal",
        "discovered_at": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "registered_by_name": "HTTP-испытатель",
        "command_id": uuid.uuid4().hex,
    }


def create_close(opener, origin, token, fixture, marker, suffix, record):
    body = new_payload(fixture, marker, suffix)
    response, ms = post_json(opener, origin, token, "/api/defects/", body, 201)
    record("регистрация", 201, ms)
    defect = response.get("defect") or {}
    ident = int(defect.get("id") or 0)
    insist(ident > fixture["seeded_records"] and not defect.get("replayed"),
           "Регистрация не создала уникальную запись")
    reply, ms = post_json(opener, origin, token, f"/api/defects/{ident}/close", {
        "version": 1, "completed_work": "Испытательное устранение " + marker,
        "command_id": uuid.uuid4().hex,
    })
    record("закрытие", 200, ms)
    insist(int(reply["defect"]["version"]) == 2, "Закрытие не увеличило версию")
    return ident


def percentile(numbers, probability):
    if not numbers:
        return None
    ordered = sorted(numbers)
    return round(ordered[int((len(ordered) - 1) * probability)], 2)


def sample_resources(origin, stop, output):
    while not stop.is_set():
        try:
            mysql = subprocess.run(
                ["mysql", "-h", "127.0.0.1", "-uroot", "-N", "-B",
                 "-e", "SHOW GLOBAL STATUS WHERE Variable_name IN "
                 "('Queries','Threads_running','Innodb_row_lock_waits')"],
                capture_output=True, text=True, check=True, timeout=4
            )
            stats = {}
            for line in mysql.stdout.splitlines():
                cells = line.split("\t")
                if len(cells) == 2:
                    stats[cells[0]] = int(cells[1])
            with urlopen(origin + "/__fpm_status?json", timeout=3) as response:
                fpm = json.load(response)
            ps = subprocess.run(
                ["ps", "-C", "php-fpm8.3", "-o", "rss="],
                capture_output=True, text=True, check=True, timeout=3
            )
            output.append({
                "mysql": stats,
                "fpm": {name: fpm.get(name) for name in
                        ("active processes", "listen queue", "max children reached")},
                "php_rss_kib": sum(int(x) for x in ps.stdout.split() if x.isdigit()),
            })
        except (OSError, ValueError, subprocess.SubprocessError) as error:
            output.append({"ошибка_замера": str(error)})
        stop.wait(1)


def worker(index, origin, session, fixture, marker, finish):
    opener, token = session
    metrics = []
    failures = []
    counter = collections.Counter()

    def record(operation, status, ms):
        metrics.append((operation, status, ms))
        counter[operation] += 1

    iteration = 0
    while time.monotonic() < finish:
        operation = iteration % 10
        iteration += 1
        try:
            if operation == 0:
                create_close(opener, origin, token, fixture, marker,
                             f"{index}-{iteration}", record)
            elif operation in (1, 2, 3, 4):
                result, ms = get_json(opener, origin,
                                      "/api/defects/?status=open&limit=40", "Реестр")
                insist(isinstance(result.get("items"), list), "Реестр вернул неверный формат")
                record("реестр", 200, ms)
            elif operation in (5, 6):
                result, ms = get_json(opener, origin,
                                      "/api/defects/report?status=all&area_limit=10", "Сводка")
                insist(int(result["report"]["total"]) >= fixture["seeded_records"],
                       "Сводка потеряла подготовленные дефекты")
                record("сводка", 200, ms)
            elif operation in (7, 8):
                result, ms = get_json(opener, origin,
                                      f"/api/defects/{fixture['known_defect_id']}", "Карточка")
                insist(int(result["defect"]["id"]) == fixture["known_defect_id"],
                       "Карточка не соответствует запросу")
                record("карточка", 200, ms)
            else:
                result, ms = get_json(opener, origin,
                    f"/api/defects/{fixture['known_defect_id']}/history?limit=10", "История")
                insist(isinstance(result["history"]["events"], list),
                       "История вернула неверный формат")
                record("история", 200, ms)
        except (OSError, ValueError, KeyError, RuntimeError) as error:
            failures.append(f"Клиент {index}, действие {iteration}: {error}")
            break
        time.sleep(0.015)
    return {"metrics": metrics, "errors": failures, "counts": counter}


def conflict_and_replay(origin, sessions, fixture, marker):
    first, token1 = sessions[0]
    second, token2 = sessions[1]
    payload = new_payload(fixture, marker, "конкурентное-создание")
    barrier = threading.Barrier(2)

    def register(session, token):
        barrier.wait(timeout=10)
        return post_json(session, origin, token, "/api/defects/", payload, 201)[0]

    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        one = pool.submit(register, first, token1)
        two = pool.submit(register, second, token2)
        firstResult, secondResult = one.result(timeout=30), two.result(timeout=30)
    firstId = int(firstResult["defect"]["id"])
    insist(firstId == int(secondResult["defect"]["id"]), "Повтор команды создал две записи")
    insist(sorted([bool(firstResult["defect"]["replayed"]),
                   bool(secondResult["defect"]["replayed"])]) == [False, True],
           "Повтор регистрации не обозначен как повтор")
    reply, _ = post_json(first, origin, token1, "/api/defects/", payload, 201)
    insist(reply["defect"]["replayed"] and int(reply["defect"]["id"]) == firstId,
           "Повтор регистрации после ответа создал новую запись")

    barrier = threading.Barrier(2)
    close1 = {
        "version": 1,
        "completed_work": "Закрыл первый испытатель " + marker,
        "command_id": uuid.uuid4().hex,
    }
    close2 = {
        "version": 1,
        "completed_work": "Закрыл второй испытатель " + marker,
        "command_id": uuid.uuid4().hex,
    }

    def close(session, token, body):
        barrier.wait(timeout=10)
        return request(session, origin, f"/api/defects/{firstId}/close", body, token)

    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        one = pool.submit(close, first, token1, close1)
        two = pool.submit(close, second, token2, close2)
        answers = [one.result(timeout=30), two.result(timeout=30)]
    insist(sorted(answer[0] for answer in answers) == [200, 409],
           "Конкурентное закрытие должно вернуть ровно 200 и 409")
    winner = 0 if answers[0][0] == 200 else 1
    winningSession, winningToken, winningBody = (
        (first, token1, close1) if winner == 0 else (second, token2, close2)
    )
    reply, _ = post_json(winningSession, origin, winningToken,
                         f"/api/defects/{firstId}/close", winningBody)
    insist(reply["defect"]["replayed"], "Повтор закрытия потерял идемпотентность")
    actual, _ = get_json(winningSession, origin, f"/api/defects/{firstId}", "Итоговая карточка")
    insist(actual["defect"]["status"] == "closed"
           and actual["defect"]["completed_work"] == winningBody["completed_work"]
           and int(actual["defect"]["version"]) == 2,
           "Конфликт затёр результат победившей команды")

    history, _ = get_json(winningSession, origin,
                          f"/api/defects/{firstId}/history?limit=10", "История конкуренции")
    events = [event["event_type"] for event in history["history"]["events"]]
    insist(events.count("created") == 1 and events.count("closed") == 1,
           "Конкурентность нарушила целостность истории")
    return {"регистрация_одна": True, "закрытие_200_409": True,
            "повтор_без_дублей": True, "история_одна": True}


def main():
    parser = argparse.ArgumentParser(description="HTTP-нагрузка модуля «Дефекты»")
    parser.add_argument("--origin", default="http://127.0.0.1:18097")
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--out", required=True)
    parser.add_argument("--concurrency", type=int, default=8)
    parser.add_argument("--seconds", type=int, default=12)
    args = parser.parse_args()
    parsed = urlparse(args.origin)
    if parsed.scheme != "http" or parsed.hostname not in ("127.0.0.1", "localhost", "::1"):
        parser.error("Нагрузочный стенд запускается только на локальном HTTP-адресе")
    if not 2 <= args.concurrency <= 16 or not 3 <= args.seconds <= 45:
        parser.error("Разрешены 2–16 клиентов и 3–45 секунд")
    fixture = json.loads(Path(args.fixture).read_text(encoding="utf-8"))
    password = os.environ["DEFECTS_HTTP_PASSWORD"]
    marker = uuid.uuid4().hex[:12]
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        sessions = list(pool.map(
            lambda _: login(args.origin, fixture["username"], password),
            range(args.concurrency)
        ))
    # Условия безопасности и права проверяются ещё до измерения нагрузки.
    get_json(sessions[0][0], args.origin, "/api/defects/?status=open&limit=10", "Предпроверка")
    stop = threading.Event()
    resource_samples = []
    monitor = threading.Thread(
        target=sample_resources, args=(args.origin, stop, resource_samples), daemon=True
    )
    monitor.start()
    started = time.monotonic()
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        futures = [
            pool.submit(worker, i, args.origin, sessions[i], fixture, marker,
                        started + args.seconds)
            for i in range(args.concurrency)
        ]
        workerResults = [future.result(timeout=args.seconds + 50) for future in futures]
    duration = time.monotonic() - started
    stop.set()
    monitor.join(timeout=6)
    allMetrics = [entry for result in workerResults for entry in result["metrics"]]
    errors = [error for result in workerResults for error in result["errors"]]
    concurrencyCheck = {}
    if not errors:
        try:
            concurrencyCheck = conflict_and_replay(args.origin, sessions, fixture, marker)
        except (OSError, ValueError, KeyError, RuntimeError) as error:
            errors.append("Проверка конкурентности: " + str(error))
    timings = [entry[2] for entry in allMetrics]
    counter = collections.Counter(entry[0] for entry in allMetrics)
    mysql = [sample["mysql"].get("Queries") for sample in resource_samples
             if sample.get("mysql", {}).get("Queries") is not None]
    rss = [sample["php_rss_kib"] for sample in resource_samples if "php_rss_kib" in sample]
    fpm = [sample["fpm"] for sample in resource_samples if "fpm" in sample]
    report = {
        "описание": "Одноразовый HTTP-стенд: Nginx, PHP-FPM 8.3, MySQL 8.4, реальные сеансы",
        "пользователей_параллельно": args.concurrency,
        "длительность_сек": round(duration, 2),
        "запросов": len(allMetrics),
        "пропускная_способность_запросов_в_сек": round(len(allMetrics) / duration, 2),
        "по_операциям": dict(counter),
        "http_коды": dict(collections.Counter(str(entry[1]) for entry in allMetrics)),
        "p50_мс": percentile(timings, .50),
        "p95_мс": percentile(timings, .95),
        "p99_мс": percentile(timings, .99),
        "mysql_запросов": max(mysql) - min(mysql) if len(mysql) > 1 else None,
        "php_fpm_пик_памяти_миб": round(max(rss) / 1024, 2) if rss else None,
        "php_fpm_пик_активных": max((int(item.get("active processes") or 0) for item in fpm), default=None),
        "php_fpm_пик_очереди": max((int(item.get("listen queue") or 0) for item in fpm), default=None),
        "замеров_ресурсов": len(resource_samples),
        "конкурентность": concurrencyCheck,
        "ошибок": len(errors),
        "примеры_ошибок": errors[:15],
    }
    Path(args.out).write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2))
    if errors or len(allMetrics) < args.concurrency * 3:
        raise SystemExit("Не пройдена нагрузочная приёмка HTTP-маршрутов")
    if any(counter.get(operation, 0) < 1 for operation in
           ("регистрация", "закрытие", "реестр", "сводка", "карточка", "история")):
        raise SystemExit("Не покрыты все операции")
    if not mysql or not rss or not fpm:
        raise SystemExit("Не удалось собрать показатели MySQL, PHP-FPM и памяти")


if __name__ == "__main__":
    main()
