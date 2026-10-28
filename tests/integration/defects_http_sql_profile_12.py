#!/usr/bin/env python3
"""Подсчёт SQL-операций настоящих HTTP-маршрутов в одноразовом MySQL-стенде."""

import argparse
import collections
import datetime
import json
import os
from pathlib import Path
import re
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


def normalize(statement):
    # Текст SQL не сохраняется: литералы и числовые значения заменяются.
    sql = re.sub(r"'(?:\\.|''|[^'])*'", "?", statement)
    sql = re.sub(r'"(?:\\.|""|[^"])*"', "?", sql)
    sql = re.sub(r"\b\d+(?:\.\d+)?\b", "?", sql)
    return " ".join(sql.split())[:320]


def sql_family(statement):
    # Сначала скрываем значения: модуль «Дефекты» может упоминаться как
    # параметр системного UPDATE module_lifecycle, а не как SQL-таблица.
    lower = normalize(statement).lower()
    if "information_schema" in lower or "performance_schema" in lower:
        return "проверки_схемы"
    if re.search(r"\bdefects?\b|\bdefect_", lower):
        return "запросы_дефектов"
    if lower.startswith(("set names", "set session", "set time_zone")):
        return "настройка_соединения"
    return "остальные_запросы_ядра"


def stamp():
    return mysql("SELECT DATE_FORMAT(NOW(6), '%Y-%m-%d %H:%i:%s.%f')")[0]


def recorded_queries(started_at):
    # General Log используется ТОЛЬКО в одноразовой тестовой базе.
    # На диск в артефактах не попадают значения SQL, пароли и токены.
    query = (
        "SELECT HEX(argument) FROM mysql.general_log"
        f" WHERE event_time > '{started_at}'"
        " AND command_type IN ('Query','Execute')"
        " ORDER BY event_time"
    )
    patterns = collections.Counter()
    families = collections.Counter()
    lifecycle_writes = 0
    lifecycle_reads = 0
    settings_reads = 0
    schema_reads = 0
    for line in mysql(query):
        sql = bytes.fromhex(line).decode("utf-8", errors="replace")
        lower = sql.lower().strip()
        if "mysql.general_log" in lower or "date_format(now(" in lower:
            continue
        # Взять только реально выполненные SQL-команды, без повторного счёта PREPARE.
        family = sql_family(sql)
        template = normalize(sql)
        if re.match(r"^UPDATE `?module_lifecycle`?\b", template, re.IGNORECASE):
            lifecycle_writes += 1
        if re.match(r"^SELECT module_id,installed_version", template, re.IGNORECASE):
            lifecycle_reads += 1
        if template.lower().startswith("select setting_") and "system_settings" in template.lower():
            settings_reads += 1
        if "information_schema" in template.lower():
            schema_reads += 1
        families[family] += 1
        patterns[(family, template)] += 1
    ordered = sorted(patterns.items(), key=lambda item: -item[1])
    return {
        "SQL_операций": sum(families.values()),
        "лишних_UPDATE_жизненного_цикла": lifecycle_writes,
        "чтений_жизненного_цикла": lifecycle_reads,
        "чтений_настроек": settings_reads,
        "чтений_метаданных": schema_reads,
        "по_назначению": dict(families),
        "основные_шаблоны": [
            {"назначение": key[0], "шаблон": key[1], "вызовов": count}
            for key, count in ordered[:16]
        ],
    }


def main():
    parser = argparse.ArgumentParser(description="Измерить SQL-операции под реальными HTTP-запросами")
    parser.add_argument("--origin", default="http://127.0.0.1:18097")
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--out", required=True)
    args = parser.parse_args()
    if args.origin != "http://127.0.0.1:18097":
        parser.error("Допустим только loopback-адрес одноразового стенда")
    if os.getenv("DBNAME") != "defects_http_load":
        parser.error("Разрешено профилировать только базу defects_http_load")
    if mysql("SHOW GLOBAL VARIABLES LIKE 'general_log'") != ["general_log\tOFF"]:
        raise RuntimeError("SQL-журнал должен быть выключен перед профилированием")

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
    report = {
        "описание": "SQL-операции HTTP-маршрутов: MySQL general_log в одноразовом стенде",
        "замер": "8 последовательных HTTP-запросов для каждого чтения; один — для записи",
        "маршруты": {},
    }

    mysql("SET GLOBAL log_output='TABLE'; SET GLOBAL general_log='ON'")
    try:
        for label, path in workloads:
            get_json(session, args.origin, path, label)
            started = stamp()
            latencies = []
            for _ in range(8):
                _, duration = get_json(session, args.origin, path, label)
                latencies.append(duration)
            measurements = recorded_queries(started)
            report["маршруты"][label] = {
                "HTTP_запросов": 8,
                "p50_HTTP_мс": round(statistics.median(latencies), 2),
                **measurements,
            }

        payload = new_payload(fixture, marker, "профиль")
        started = stamp()
        created, duration = post_json(session, args.origin, csrf, "/api/defects/", payload, 201)
        report["маршруты"]["регистрация"] = {
            "HTTP_запросов": 1, "время_HTTP_мс": round(duration, 2),
            **recorded_queries(started),
        }
        identifier = int(created["defect"]["id"])
        command = {
            "version": 1, "completed_work": "Испытательное устранение в SQL-профиле",
            "completed_by_name": "Испытатель SQL",
            "closed_at": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
            "close_comment": "Контроль SQL-команд",
            "command_id": uuid.uuid4().hex,
        }
        started = stamp()
        _, duration = post_json(session, args.origin, csrf, f"/api/defects/{identifier}/close", command)
        report["маршруты"]["закрытие"] = {
            "HTTP_запросов": 1, "время_HTTP_мс": round(duration, 2),
            **recorded_queries(started),
        }
    finally:
        # Основная 12-секундная нагрузка измеряется БЕЗ накладных расходов general_log.
        mysql("SET GLOBAL general_log='OFF'; SET GLOBAL log_output='FILE'")

    Path(args.out).write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(report["маршруты"], indent=2, ensure_ascii=False))
    for name, values in report["маршруты"].items():
        if values["по_назначению"].get("запросы_дефектов", 0) < values["HTTP_запросов"]:
            raise SystemExit("Недостаточно рабочих SQL-запросов Дефектов в профиле: " + name)
        if values["лишних_UPDATE_жизненного_цикла"] != 0:
            raise SystemExit("На неизменном состоянии модулей остались лишние SQL UPDATE: " + name)
        if values["чтений_жизненного_цикла"] > values["HTTP_запросов"]:
            raise SystemExit("Остались повторные чтения жизненного цикла модулей: " + name)
        # После включения отдельных схем модулей ядро выполняет четыре\n        # ограниченных проверки information_schema на один HTTP-запрос.\n        if values["чтений_метаданных"] > 4 * values["HTTP_запросов"]:
            raise SystemExit("Количество проверок структуры БД неожиданно возросло: " + name)


if __name__ == "__main__":
    main()
