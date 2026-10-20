#!/usr/bin/env python3
"""Профиль SQL настоящих HTTP-запросов «Дефектов» через Performance Schema."""

import argparse
import json
import os
from pathlib import Path
import statistics
import subprocess
import uuid

from defects_http_load_12 import get_json, login, new_payload, post_json


def mysql(query):
    command = [
        "mysql", "-h", os.getenv("DBHOST", "127.0.0.1"),
        "-P", os.getenv("DBPORT", "3306"), "-u", os.getenv("DBUSER", "root"),
        "--default-character-set=utf8mb4", "-N", "-B", "-e", query,
    ]
    result = subprocess.run(
        command, check=True, capture_output=True, text=True,
        timeout=15, encoding="utf-8",
    )
    return result.stdout.strip().splitlines()


def snapshot():
    # Только одноразовая база: не собираем SQL других проектов или пользователей.
    database = os.getenv("DBNAME", "")
    if database != "defects_http_load":
        raise RuntimeError("Профилирование разрешено только в базе defects_http_load")
    sql = (
        "SELECT HEX(DIGEST_TEXT), COUNT_STAR, SUM_TIMER_WAIT, SUM_ROWS_EXAMINED,"
        " SUM_ROWS_SENT, SUM_NO_INDEX_USED, SUM_CREATED_TMP_DISK_TABLES"
        " FROM performance_schema.events_statements_summary_by_digest"
        " WHERE DIGEST_TEXT IS NOT NULL"
    )
    rows = {}
    for line in mysql(sql):
        fields = line.split("\t")
        if len(fields) != 7:
            raise RuntimeError("Неожиданный ответ сводки SQL Performance Schema")
        encoded, *numbers = fields
        statement = bytes.fromhex(encoded).decode("utf-8", errors="replace")
        # У подготовленных запросов SCHEMA_NAME бывает NULL: фильтр по базе
        # исключал именно рабочие запросы приложения.
        if "performance_schema" in statement.lower():
            continue
        rows[statement] = [int(number) for number in numbers]
    return rows


def changes(before, after):
    deltas = []
    for statement, values in after.items():
        previous = before.get(statement, [0] * len(values))
        diff = [max(0, current - old) for current, old in zip(values, previous)]
        if diff[0] == 0:
            continue
        deltas.append({
            "шаблон": statement[:320],
            "вызовов": diff[0],
            "время_мс": round(diff[1] / 1_000_000_000, 3),
            "строк_проверено": diff[2],
            "строк_выдано": diff[3],
            "без_индекса": diff[4],
            "временных_таблиц_на_диске": diff[5],
        })
    return sorted(deltas, key=lambda row: (-row["время_мс"], -row["вызовов"]))


def main():
    parser = argparse.ArgumentParser(description="Профиль SQL под настоящими HTTP-запросами Дефектов")
    parser.add_argument("--origin", default="http://127.0.0.1:18097")
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--out", required=True)
    args = parser.parse_args()
    if args.origin != "http://127.0.0.1:18097":
        parser.error("Допустим только локальный адрес одноразового стенда")
    if mysql("SELECT @@performance_schema") != ["1"]:
        raise RuntimeError("MySQL Performance Schema отключена: профиль SQL недоступен")

    fixture = json.loads(Path(args.fixture).read_text(encoding="utf-8"))
    session, csrf = login(args.origin, fixture["username"], os.environ["DEFECTS_HTTP_PASSWORD"])
    marker = uuid.uuid4().hex[:12]
    workloads = [
        ("реестр", "/api/defects/?status=open&limit=40"),
        ("реестр_с_фильтрами", "/api/defects/?status=open&priority=normal&area_id=1&service_id=1&limit=40"),
        ("сводка", "/api/defects/report?status=all&area_limit=10"),
        ("сводка_с_фильтрами", "/api/defects/report?status=open&priority=normal&service_id=1&area_limit=10"),
        ("карточка", f"/api/defects/{fixture['known_defect_id']}"),
        ("история", f"/api/defects/{fixture['known_defect_id']}/history?limit=10"),
    ]

    report = {"описание": "Разница показателей MySQL Performance Schema до и после HTTP-запросов", "маршруты": {}}
    for label, path in workloads:
        # Первый запрос разогревает маршрут, но не входит в измеряемый интервал.
        get_json(session, args.origin, path, label)
        before = snapshot()
        latencies = []
        for _ in range(8):
            _, elapsed = get_json(session, args.origin, path, label)
            latencies.append(elapsed)
        after = snapshot()
        grouped = changes(before, after)
        report["маршруты"][label] = {
            "HTTP_запросов": len(latencies),
            "p50_мс": round(statistics.median(latencies), 2),
            "SQL_вызовов": sum(row["вызовов"] for row in grouped),
            "SQL_время_мс": round(sum(row["время_мс"] for row in grouped), 2),
            "строк_проверено": sum(row["строк_проверено"] for row in grouped),
            "без_индекса": sum(row["без_индекса"] for row in grouped),
            "дорогие_запросы": grouped[:16],
        }

    command = new_payload(fixture, marker, "профиль")
    before = snapshot()
    created, latency = post_json(session, args.origin, csrf, "/api/defects/", command, 201)
    after = snapshot()
    report["маршруты"]["регистрация"] = {
        "HTTP_запросов": 1, "время_HTTP_мс": round(latency, 2),
        "SQL_вызовов": sum(row["вызовов"] for row in changes(before, after)),
        "дорогие_запросы": changes(before, after)[:16],
    }
    identifier = int(created["defect"]["id"])
    close = {
        "version": 1, "completed_work": "Проверено в SQL-профиле",
        "command_id": uuid.uuid4().hex,
    }
    before = snapshot()
    _, latency = post_json(session, args.origin, csrf, f"/api/defects/{identifier}/close", close)
    after = snapshot()
    report["маршруты"]["закрытие"] = {
        "HTTP_запросов": 1, "время_HTTP_мс": round(latency, 2),
        "SQL_вызовов": sum(row["вызовов"] for row in changes(before, after)),
        "дорогие_запросы": changes(before, after)[:16],
    }

    Path(args.out).write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps({
        name: {
            "запросов_HTTP": values["HTTP_запросов"],
            "вызовов_SQL": values["SQL_вызовов"],
            "строк_проверено": values.get("строк_проверено"),
            "дорогие": values["дорогие_запросы"][:4],
        }
        for name, values in report["маршруты"].items()
    }, indent=2, ensure_ascii=False))
    for name, data in report["маршруты"].items():
        if not any("defect" in item["шаблон"].lower()
                   for item in data["дорогие_запросы"]):
            raise SystemExit("SQL-профиль не содержит рабочих запросов Дефектов: " + name)
        if data["SQL_вызовов"] <= 0:
            raise SystemExit("SQL-профиль не зафиксировал запросы маршрута: " + name)


if __name__ == "__main__":
    main()
