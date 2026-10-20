#!/usr/bin/env python3
"""Ступенчатое HTTP-испытание Files на отдельной одноразовой базе Notes 1.2."""

import argparse
import collections
import concurrent.futures
import json
import os
from pathlib import Path
import threading
import time
from urllib.parse import urlparse

from filespaces_http_load_12 import quantile, worker
from notes_staged_http_capacity_12 import (
    mysql, mysql_status, record_resource, require,
)


def verify_files(previous):
    # Только временная база стенда. Запрещены совпадающие активные имена.
    total = int(mysql(
        "SELECT COUNT(*) FROM file_space_nodes "
        "WHERE node_type='file' AND is_deleted=0",
        "fsp_http_load",
    ))
    duplicates = int(mysql(
        "SELECT COUNT(*) FROM "
        "(SELECT space_id,parent_id,name FROM file_space_nodes "
        "WHERE is_deleted=0 GROUP BY space_id,parent_id,name "
        "HAVING COUNT(*) > 1) collisions",
        "fsp_http_load",
    ))
    require(duplicates == 0, "Есть совпадающие имена в активном каталоге")
    require(total > previous, "Ступень не добавила ни одного файла")
    return total


def stage(origin, fixture, password, concurrency, seconds, index, limits, storage):
    samples = []
    stop = threading.Event()
    observer = threading.Thread(
        target=record_resource,
        args=(origin, samples, stop, "fsp_http_load"),
        daemon=True,
    )
    before = mysql_status("fsp_http_load")
    free_before = os.statvfs(storage)
    start_free = free_before.f_bavail * free_before.f_frsize
    started = time.monotonic()
    observer.start()
    try:
        deadline = started + seconds
        with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as executor:
            jobs = [
                executor.submit(worker, i, origin, fixture, password, deadline)
                for i in range(concurrency)
            ]
            results = [job.result(timeout=seconds + 40) for job in jobs]
    finally:
        stop.set()
        observer.join(timeout=6)
    duration = time.monotonic() - started
    after = mysql_status("fsp_http_load")
    free_after = os.statvfs(storage)
    end_free = free_after.f_bavail * free_after.f_frsize

    counts = collections.Counter()
    for result in results:
        counts.update(result["counts"])
    times = [latency for result in results for latency in result["latencies"]]
    failures = [failure for result in results for failure in result["errors"]]
    good_samples = [x for x in samples if "ошибка" not in x]
    monitor_errors = [x["ошибка"] for x in samples if "ошибка" in x]
    response_count = sum(counts.values())
    report = {
        "ступень": index,
        "клиентов": concurrency,
        "секунд": round(duration, 2),
        "ответов": response_count,
        "ответов_в_сек": round(response_count / duration, 2),
        "коды": dict(counts),
        "p50_мс": quantile(times, .50),
        "p95_мс": quantile(times, .95),
        "p99_мс": quantile(times, .99),
        "mysql_запросов": max(0, after["Queries"] - before["Queries"]),
        "mysql_ожиданий_блокировки": max(
            0, after["Innodb_row_lock_waits"] - before["Innodb_row_lock_waits"]
        ),
        "mysql_время_ожиданий_мс": max(
            0, after["Innodb_row_lock_time"] - before["Innodb_row_lock_time"]
        ),
        "mysql_активных_потоков_пик": max(
            (sample["mysql"].get("Threads_running", 0) for sample in good_samples),
            default=None,
        ),
        "mysql_подключений_пик": max(
            (sample["mysql"].get("Threads_connected", 0) for sample in good_samples),
            default=None,
        ),
        "fpm_активных_пик": max(
            (sample["fpm"]["active"] for sample in good_samples), default=None
        ),
        "fpm_очередь_пик": max(
            (sample["fpm"]["queue"] for sample in good_samples), default=None
        ),
        "fpm_лимит_достигнут": max(
            (sample["fpm"]["max_children_reached"] for sample in good_samples),
            default=None,
        ),
        "php_память_пик_миб": max(
            (sample["php"]["память_миб"] for sample in good_samples), default=None
        ),
        "php_cpu_пик_процентов": max(
            (sample["php"]["cpu_процентов"] for sample in good_samples), default=None
        ),
        "изменение_свободного_места_миб": round(
            (start_free - end_free) / 1048576, 2
        ),
        "замеров_ресурсов": len(good_samples),
        "ошибки_мониторинга": monitor_errors[:6],
        "ошибки_http": failures[:12],
    }
    problems = []
    if failures:
        problems.append("Неожиданный HTTP-ответ или повреждённые данные")
    if response_count < concurrency * 3:
        problems.append("Недостаточно завершённых HTTP-запросов")
    if not good_samples or monitor_errors:
        problems.append("Не выполнен мониторинг ресурсов")
    if report["p99_мс"] is None or report["p99_мс"] > limits["p99"]:
        problems.append("Превышена аварийная задержка p99")
    if report["php_память_пик_миб"] is None or report["php_память_пик_миб"] > limits["memory"]:
        problems.append("Превышен бюджет памяти PHP-FPM")
    if report["fpm_очередь_пик"] is None or report["fpm_очередь_пик"] > limits["queue"]:
        problems.append("Превышена очередь PHP-FPM")
    if report["fpm_лимит_достигнут"] is None or report["fpm_лимит_достигнут"] > 0:
        problems.append("Закончились свободные процессы PHP-FPM")
    report["причины_отказа"] = problems
    return report


def main():
    parser = argparse.ArgumentParser(description="Ступенчатая нагрузка Files 1.2")
    parser.add_argument("--origin", default="http://127.0.0.1:18096")
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--out", required=True)
    parser.add_argument("--stages", default="4,8,12")
    parser.add_argument("--seconds", type=int, default=8)
    parser.add_argument("--max-p99-ms", type=int, default=3000)
    parser.add_argument("--max-php-memory-mib", type=int, default=1536)
    parser.add_argument("--max-fpm-queue", type=int, default=8)
    args = parser.parse_args()

    origin = urlparse(args.origin)
    if origin.scheme != "http" or args.origin != "http://127.0.0.1:18096":
        parser.error("Разрешён только локальный одноразовый стенд Files")
    if os.environ.get("DBNAME") != "fsp_http_load":
        parser.error("Не указана одноразовая тестовая база fsp_http_load")
    try:
        stages = [int(part) for part in args.stages.split(",")]
    except ValueError:
        parser.error("Некорректный список ступеней")
    if not stages or sorted(set(stages)) != stages or min(stages) < 2 or max(stages) > 12:
        parser.error("Допустимы возрастающие ступени от 2 до 12 клиентов")
    if not 5 <= args.seconds <= 30 or args.seconds * len(stages) > 90:
        parser.error("Длительность ступени 5–30 секунд, всего не более 90")
    if min(args.max_p99_ms, args.max_php_memory_mib, args.max_fpm_queue) < 1:
        parser.error("Аварийные ограничения должны быть положительными")

    fixture = json.loads(Path(args.fixture).read_text(encoding="utf-8"))
    storage = Path(os.environ["PRIVATE_STORAGE_PATH"])
    require(storage.is_dir(), "Отсутствует закрытое хранилище стенда")
    password = os.environ["FSP_HTTP_PASSWORD"]
    limits = {
        "p99": args.max_p99_ms,
        "memory": args.max_php_memory_mib,
        "queue": args.max_fpm_queue,
    }
    report = {
        "описание": "Одноразовый HTTP-стенд Files, ступени нагрузки и целостность данных",
        "ступени": [],
        "пороги": limits,
        "файлов_после_ступени": [],
        "успешно": False,
    }
    previous = int(mysql(
        "SELECT COUNT(*) FROM file_space_nodes "
        "WHERE node_type='file' AND is_deleted=0",
        "fsp_http_load",
    ))
    try:
        for index, clients in enumerate(stages, 1):
            result = stage(
                args.origin, fixture, password, clients, args.seconds, index,
                limits, str(storage)
            )
            report["ступени"].append(result)
            previous = verify_files(previous)
            report["файлов_после_ступени"].append(previous)
            print(json.dumps(result, ensure_ascii=False), flush=True)
            if result["причины_отказа"]:
                raise RuntimeError(
                    "Ступень " + str(index) + ": "
                    + "; ".join(result["причины_отказа"])
                )
        report["успешно"] = True
    except (RuntimeError, ValueError, OSError, KeyError) as error:
        report["ошибка"] = str(error)
    finally:
        Path(args.out).write_text(
            json.dumps(report, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
    print(json.dumps({
        "успешно": report["успешно"],
        "ступеней": len(report["ступени"]),
        "последнее_количество_файлов": previous,
        "ошибка": report.get("ошибка"),
    }, ensure_ascii=False))
    if not report["успешно"]:
        raise SystemExit("Ступенчатое HTTP-испытание Files завершилось ошибкой")


if __name__ == "__main__":
    main()
