#!/usr/bin/env python3
"""Ступенчатая проверка HTTP-нагрузки Notes на одноразовом стенде Дефектов.

Тест не доказывает готовность к тысяче пользователей. Он измеряет рост очередей,
памяти, блокировок БД и задержек на небольшом ограниченном CI-исполнителе.
"""

import argparse
import collections
import concurrent.futures
import json
import os
from pathlib import Path
import subprocess
import threading
import time
from urllib.parse import urlparse
from urllib.request import urlopen
import uuid

from defects_http_load_12 import (
    conflict_and_replay, get_json, login, percentile, worker,
)


MYSQL_STATUS = (
    "Queries", "Threads_running", "Threads_connected",
    "Innodb_row_lock_waits", "Innodb_row_lock_time",
    "Innodb_row_lock_current_waits", "Innodb_deadlocks",
)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def mysql(sql):
    args = [
        "mysql", "-h", os.environ.get("DBHOST", "127.0.0.1"),
        "-P", os.environ.get("DBPORT", "3306"),
        "-u", os.environ.get("DBUSER", "root"), "-N", "-B",
        "defects_http_load", "-e", sql,
    ]
    return subprocess.run(
        args, text=True, capture_output=True, check=True, timeout=8
    ).stdout.strip()


def mysql_status():
    quoted = ",".join("'" + key + "'" for key in MYSQL_STATUS)
    result = {}
    for line in mysql(
        "SHOW GLOBAL STATUS WHERE Variable_name IN (" + quoted + ")"
    ).splitlines():
        parts = line.split("\t")
        if len(parts) == 2 and parts[1].isdigit():
            result[parts[0]] = int(parts[1])
    for required in ("Queries", "Innodb_row_lock_waits", "Innodb_row_lock_time"):
        require(required in result, "Не получена метрика MySQL: " + required)
    return result


def fpm_snapshot(origin):
    with urlopen(origin + "/__fpm_status?json", timeout=3) as response:
        result = json.load(response)
    fields = {
        "active": "active processes",
        "queue": "listen queue",
        "queue_peak": "max listen queue",
        "max_children_reached": "max children reached",
        "accepted_conn": "accepted conn",
    }
    return {name: int(result.get(key, 0)) for name, key in fields.items()}


def php_snapshot():
    data = subprocess.run(
        ["ps", "-C", "php-fpm8.3", "-o", "rss=,%cpu="],
        text=True, capture_output=True, check=True, timeout=4,
    ).stdout.splitlines()
    rss = 0
    cpu = 0.0
    processes = 0
    for line in data:
        parts = line.split()
        if len(parts) != 2:
            continue
        rss += int(parts[0])
        cpu += float(parts[1])
        processes += 1
    require(processes > 0, "Не удалось измерить процессы PHP-FPM")
    return {
        "память_миб": round(rss / 1024, 2),
        "cpu_процентов": round(cpu, 2),
        "процессов": processes,
    }


def record_resource(origin, measurements, stop):
    while not stop.is_set():
        try:
            measurements.append({
                "fpm": fpm_snapshot(origin),
                "php": php_snapshot(),
                "mysql": mysql_status(),
            })
        except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as error:
            measurements.append({"ошибка": type(error).__name__ + ": " + str(error)})
        stop.wait(0.8)


def verify_history():
    # Одноразовая тестовая БД; изменения и связи проверяются после каждой ступени.
    query = """
    SELECT COUNT(*) FROM defects d
    WHERE d.description LIKE 'HTTP нагрузка Дефекты %'
      AND (
        d.status <> 'closed' OR d.version <> 2
        OR (SELECT COUNT(*) FROM defect_history h
            WHERE h.entity_type='defect' AND h.entity_id=d.id
              AND h.event_type='created') <> 1
        OR (SELECT COUNT(*) FROM defect_history h
            WHERE h.entity_type='defect' AND h.entity_id=d.id
              AND h.event_type='closed') <> 1
        OR (SELECT COUNT(*) FROM defect_responsible_services s
            WHERE s.defect_id=d.id) < 1
      )
    """
    broken = int(mysql(query))
    total = int(mysql(
        "SELECT COUNT(*) FROM defects "
        "WHERE description LIKE 'HTTP нагрузка Дефекты %'"
    ))
    require(broken == 0, "Найдены повреждённые карточки или дубли событий: " + str(broken))
    require(total > 0, "Не найдены зарегистрированные испытательные карточки")
    return total


def stage(origin, sessions, fixture, concurrency, seconds, index, limits):
    marker = "staged-" + uuid.uuid4().hex[:16]
    before = mysql_status()
    samples = []
    stop = threading.Event()
    monitor = threading.Thread(
        target=record_resource, args=(origin, samples, stop), daemon=True
    )
    monitor.start()
    started = time.monotonic()
    try:
        finish = started + seconds
        with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as executor:
            jobs = [
                executor.submit(worker, i, origin, sessions[i], fixture, marker, finish)
                for i in range(concurrency)
            ]
            results = [job.result(timeout=seconds + 40) for job in jobs]
    finally:
        stop.set()
        monitor.join(timeout=6)
    elapsed = time.monotonic() - started
    after = mysql_status()

    metrics = [x for result in results for x in result["metrics"]]
    errors = [x for result in results for x in result["errors"]]
    counts = collections.Counter(x[0] for x in metrics)
    statuses = collections.Counter(str(x[1]) for x in metrics)
    times = [x[2] for x in metrics]
    valid = [x for x in samples if "ошибка" not in x]
    sample_errors = [x["ошибка"] for x in samples if "ошибка" in x]
    report = {
        "ступень": index,
        "клиентов": concurrency,
        "длительность_сек": round(elapsed, 2),
        "http_ответов": len(metrics),
        "ответов_в_сек": round(len(metrics) / elapsed, 2),
        "операции": dict(counts),
        "http_коды": dict(statuses),
        "p50_мс": percentile(times, .50),
        "p95_мс": percentile(times, .95),
        "p99_мс": percentile(times, .99),
        "mysql_запросов": max(0, after["Queries"] - before["Queries"]),
        "mysql_ожиданий_блокировки": max(
            0, after["Innodb_row_lock_waits"] - before["Innodb_row_lock_waits"]
        ),
        "mysql_время_ожиданий_мс": max(
            0, after["Innodb_row_lock_time"] - before["Innodb_row_lock_time"]
        ),
        "mysql_текущие_ожидания_пик": max(
            (x["mysql"].get("Innodb_row_lock_current_waits", 0) for x in valid), default=None
        ),
        "mysql_потоки_активные_пик": max(
            (x["mysql"].get("Threads_running", 0) for x in valid), default=None
        ),
        "mysql_подключения_пик": max(
            (x["mysql"].get("Threads_connected", 0) for x in valid), default=None
        ),
        "fpm_активные_пик": max((x["fpm"]["active"] for x in valid), default=None),
        "fpm_очередь_пик": max((x["fpm"]["queue"] for x in valid), default=None),
        "fpm_лимит_достигнут": max(
            (x["fpm"]["max_children_reached"] for x in valid), default=None
        ),
        "php_память_пик_миб": max(
            (x["php"]["память_миб"] for x in valid), default=None
        ),
        "php_cpu_процентов_пик": max(
            (x["php"]["cpu_процентов"] for x in valid), default=None
        ),
        "замеров": len(valid),
        "ошибок_мониторинга": sample_errors[:6],
        "ошибок_http": errors[:12],
    }
    violations = []
    if len(metrics) < concurrency * 3:
        violations.append("Слишком мало ответов, возможная остановка сервера")
    if errors:
        violations.append("Ошибка HTTP или целостности ответа")
    if not valid or sample_errors:
        violations.append("Отсутствуют достоверные замеры ресурсов")
    if counts.get("регистрация", 0) == 0 or counts.get("закрытие", 0) == 0:
        violations.append("Не было хотя бы одного изменения данных")
    if report["p99_мс"] is None or report["p99_мс"] > limits["p99"]:
        violations.append("Превышено аварийное ограничение p99")
    if report["php_память_пик_миб"] is None or report["php_память_пик_миб"] > limits["php_memory"]:
        violations.append("Превышено ограничение памяти PHP-FPM")
    if report["fpm_очередь_пик"] is None or report["fpm_очередь_пик"] > limits["fpm_queue"]:
        violations.append("Очередь PHP-FPM превысила безопасный порог")
    if report["fpm_лимит_достигнут"] is None or report["fpm_лимит_достигнут"] > 0:
        violations.append("Достигнут предел рабочих процессов PHP-FPM")
    report["причины_отказа"] = violations
    return report


def main():
    parser = argparse.ArgumentParser(description="Ступенчатая HTTP-нагрузка Notes 1.2")
    parser.add_argument("--origin", default="http://127.0.0.1:18097")
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--out", required=True)
    parser.add_argument("--stages", default="4,8,12")
    parser.add_argument("--seconds", type=int, default=8)
    parser.add_argument("--max-p99-ms", type=int, default=3000)
    parser.add_argument("--max-php-memory-mib", type=int, default=1536)
    parser.add_argument("--max-fpm-queue", type=int, default=8)
    args = parser.parse_args()

    parsed = urlparse(args.origin)
    if parsed.scheme != "http" or parsed.hostname not in ("127.0.0.1", "localhost", "::1"):
        parser.error("Только локальный одноразовый стенд; запрещены внешние хосты")
    if os.environ.get("DBNAME") != "defects_http_load":
        parser.error("Нужна тестовая база defects_http_load")
    if args.origin != "http://127.0.0.1:18097":
        parser.error("Ожидается штатный порт 18097 одноразового стенда")
    try:
        stages = [int(x) for x in args.stages.split(",")]
    except ValueError:
        parser.error("Ступени задаются целыми числами через запятую")
    if not stages or sorted(set(stages)) != stages or min(stages) < 2 or max(stages) > 12:
        parser.error("Ступени должны возрастать без повторов в диапазоне 2–12")
    if not 5 <= args.seconds <= 30:
        parser.error("Длительность ступени должна быть 5–30 секунд")
    if args.seconds * len(stages) > 90:
        parser.error("Общая измеряемая нагрузка не должна превышать 90 секунд")
    if min(args.max_p99_ms, args.max_php_memory_mib, args.max_fpm_queue) < 1:
        parser.error("Пороги должны быть положительными")

    fixture = json.loads(Path(args.fixture).read_text(encoding="utf-8"))
    password = os.environ["DEFECTS_HTTP_PASSWORD"]
    with concurrent.futures.ThreadPoolExecutor(max_workers=max(stages)) as executor:
        sessions = list(executor.map(
            lambda _: login(args.origin, fixture["username"], password),
            range(max(stages))
        ))

    get_json(sessions[0][0], args.origin, "/api/defects/?status=open&limit=10", "Предпроверка")
    limits = {
        "p99": args.max_p99_ms,
        "php_memory": args.max_php_memory_mib,
        "fpm_queue": args.max_fpm_queue,
    }
    report = {
        "профиль": "Ступенчатый CI-прогон на изолированной БД; не приёмка 1000 пользователей",
        "цель": "Проверить рост очередей, задержек, блокировок и памяти при увеличении конкуренции",
        "ступени": [],
        "пороги": limits,
        "конкурентность": None,
        "целостность_карточек": None,
        "успешно": False,
    }
    output = Path(args.out)
    try:
        for number, concurrency in enumerate(stages, 1):
            measured = stage(
                args.origin, sessions, fixture, concurrency, args.seconds, number, limits
            )
            report["ступени"].append(measured)
            report["целостность_карточек"] = verify_history()
            print(json.dumps(measured, ensure_ascii=False), flush=True)
            if measured["причины_отказа"]:
                raise RuntimeError(
                    "Ступень " + str(number) + " не прошла: "
                    + "; ".join(measured["причины_отказа"])
                )
        report["конкурентность"] = conflict_and_replay(
            args.origin, sessions[:2], fixture, "staged-" + uuid.uuid4().hex[:10]
        )
        report["целостность_карточек"] = verify_history()
        report["успешно"] = True
    except (RuntimeError, ValueError, OSError, KeyError) as error:
        report["ошибка"] = str(error)
    finally:
        output.write_text(
            json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8"
        )
    print(json.dumps({
        "успешно": report["успешно"],
        "ступеней": len(report["ступени"]),
        "целостность_карточек": report["целостность_карточек"],
        "ошибка": report.get("ошибка"),
    }, ensure_ascii=False))
    if not report["успешно"]:
        raise SystemExit("Ступенчатая HTTP-нагрузка не прошла")


if __name__ == "__main__":
    main()
