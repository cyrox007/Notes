#!/usr/bin/env python3
"""Профиль SQL для трёх реальных HTTP-маршрутов общего файлового каталога.

Проверка допускается только на изолированной тестовой базе; не использовать
её с рабочим сайтом или пользовательскими файлами.
"""

import argparse
import json
import os
import pathlib
import re
import statistics
import subprocess
import uuid

from filespaces_http_load_12 import Fields, connect, multipart, request


def mysql_command(statement):
    """Исполнить административную команду только на временном MySQL CI."""
    command = [
        "mysql", "--connect-timeout=3", "--batch", "--raw", "--skip-column-names",
        "-h", os.getenv("DBHOST", "127.0.0.1"),
        "-P", os.getenv("DBPORT", "3306"),
        "-u", os.getenv("DBUSER", "root"), "-e", statement,
    ]
    result = subprocess.run(command, capture_output=True, text=True, timeout=8, check=True)
    return result.stdout


def sql_template(statement):
    """Оставить тип команды и имена таблиц без значений и пользовательских данных."""
    normalized = " ".join(statement.split())
    command = normalized.split(" ", 1)[0].upper() if normalized else "UNKNOWN"
    tables = re.findall(
        r"\b(?:FROM|JOIN|INTO|UPDATE)\s+[\x60a-zA-Z0-9_.]+",
        normalized, flags=re.IGNORECASE
    )
    table_names = []
    for match in tables:
        table = match.split(None, 1)[-1].strip(chr(96)).split(".")[-1].strip(chr(96))
        if re.fullmatch(r"[a-zA-Z_][a-zA-Z0-9_]*", table):
            table_names.append(table)
    if command in ("START", "COMMIT", "ROLLBACK", "SET"):
        return command
    return command + (" " + " ".join(table_names[:8]) if table_names else "")


def measured(opener, url, method="GET", payload=None, headers=None):
    # Включать журнал только между отправкой одного HTTP-запроса и его ответом.
    # Не извлекать из MySQL параметры файлов, cookie и другую персональную информацию.
    mysql_command(
        "SET GLOBAL general_log=OFF; "
        "SET GLOBAL log_output='TABLE'; "
        "TRUNCATE TABLE mysql.general_log; "
        "SET GLOBAL general_log=ON"
    )
    try:
        status, body, latency = request(opener, url, method, payload, headers)
    finally:
        mysql_command("SET GLOBAL general_log=OFF")
    output = mysql_command(
        "SELECT command_type, REPLACE(REPLACE(argument, CHAR(10), ' '), CHAR(9), ' ') "
        "FROM mysql.general_log WHERE command_type IN ('Query','Execute')"
    )
    changes = {}
    for line in output.splitlines():
        kind, statement = line.split("\t", 1)
        if statement.upper().startswith("SET GLOBAL GENERAL_LOG"):
            continue
        template = sql_template(statement)
        if kind not in ("Query", "Execute"):
            continue
        changes[template] = changes.get(template, 0) + 1
    if len(changes) < 4:
        raise RuntimeError("MySQL не предоставил полный SQL-профиль HTTP-запроса")
    return status, body, latency, changes


def record(samples, name, result):
    status, _, latency, changes = result
    samples[name].append({
        "http": status, "задержка_мс": round(latency, 2),
        "sql": dict(changes),
    })


def summarize(rows):
    aggregate = {}
    for row in rows:
        for template, count in row["sql"].items():
            aggregate[template] = aggregate.get(template, 0) + count
    total = sum(aggregate.values())
    repeats = sorted(aggregate.items(), key=lambda item: item[1], reverse=True)
    return {
        "http_ответы": [row["http"] for row in rows],
        "замеров": len(rows),
        "sql_всего": total,
        "sql_в_среднем_на_ответ": round(total / len(rows), 2),
        "p50_мс": round(statistics.median(row["задержка_мс"] for row in rows), 2),
        "повторяющиеся_шаблоны": [
            {"выполнений": count, "группа": template}
            for template, count in repeats[:18]
        ],
    }


def main():
    parser = argparse.ArgumentParser(description="Раздельное измерение SQL для Files 1.2")
    parser.add_argument("--origin", default="http://127.0.0.1:18096")
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--out", required=True)
    args = parser.parse_args()
    if args.origin != "http://127.0.0.1:18096":
        raise SystemExit("Профиль разрешён только для локального одноразового стенда")
    if os.environ.get("GITHUB_ACTIONS") != "true":
        raise SystemExit("Профиль разрешён только на одноразовом стенде GitHub Actions")
    if os.environ.get("DBNAME") != "fsp_http_load":
        raise SystemExit("Требуется одноразовая база fsp_http_load")

    fixture = json.loads(pathlib.Path(args.fixture).read_text())
    opener = connect(args.origin, fixture["username"], os.environ["FSP_HTTP_PASSWORD"],
                     fixture["folder_id"])
    folder = args.origin + "/files/spaces/global/?folder=" + str(fixture["folder_id"])
    download = args.origin + "/files/spaces/global/download/" + str(fixture["file_id"]) + "/"
    samples = {"чтение_каталога": [], "скачивание": [], "загрузка": []}

    for _ in range(3):
        result = measured(opener, folder)
        if result[0] != 200 or "Нагрузочный.txt" not in result[1].decode("utf-8", "replace"):
            raise RuntimeError("Некорректный ответ каталога")
        record(samples, "чтение_каталога", result)

    expected_bytes = fixture["expected_text"].encode()
    for _ in range(3):
        result = measured(opener, download)
        if result[0] != 200 or result[1] != expected_bytes:
            raise RuntimeError("Скачивание вернуло неверный файл")
        record(samples, "скачивание", result)

    status, body, _ = request(opener, folder)
    if status != 200:
        raise RuntimeError("Нельзя получить форму перед загрузкой")
    fields = Fields("/files/spaces/global/upload/")
    fields.feed(body.decode("utf-8", "replace"))
    csrf = fields.values.get("csrf_token")
    version = fields.values.get("expected_version")
    if not csrf or not version:
        raise RuntimeError("Отсутствует версия каталога или защитный токен")

    for _ in range(3):
        fields = {
            "parent_id": fixture["folder_id"],
            "expected_version": version,
            "csrf_token": csrf,
            "command_id": "files:http:sql:" + uuid.uuid4().hex,
        }
        payload, mime = multipart(fields, b"SQL profiling Files 1.2")
        result = measured(opener, args.origin + "/files/spaces/global/upload/",
                          "POST", payload, {
                              "Content-Type": mime, "Accept": "application/json",
                              "X-Requested-With": "XMLHttpRequest",
                          })
        if result[0] != 200:
            raise RuntimeError("Загрузка завершилась неожиданным HTTP " + str(result[0]))
        data = json.loads(result[1])
        if data.get("success") is not True:
            raise RuntimeError("Загрузка не была зафиксирована в базе")
        version = data.get("version")
        if not isinstance(version, int) or version < 1:
            raise RuntimeError("Загрузка не вернула актуальную версию")
        record(samples, "загрузка", result)

    report = {
        "стенд": "изолированный HTTP, один авторизованный клиент, без фоновой нагрузки",
        "схема": "fsp_http_load",
        "источник": "mysql.general_log (Query и Execute; без Prepare)",
        "ограничение": "Журнал включается лишь на время одного HTTP-ответа; "
                       "в тестовом MySQL нет посторонних клиентов. Лог удаляется "
                       "вместе с контейнером. В отчёте нет значений SQL.",
        "маршруты": {name: summarize(rows) for name, rows in samples.items()},
    }
    # Защита от возврата N+1 при добавлении объектов корзины.
    # Пороги выше текущей стоимости маршрутов и учитывают накладные
    # запросы загрузки приложения, но не допускают прежних сотен SQL.
    limits = {"чтение_каталога": 150, "скачивание": 55, "загрузка": 80}
    report["предельное_sql_на_ответ"] = limits
    pathlib.Path(args.out).write_text(json.dumps(report, ensure_ascii=False, indent=2))
    print(json.dumps(report, ensure_ascii=False, indent=2))
    for name, limit in limits.items():
        average = report["маршруты"][name]["sql_в_среднем_на_ответ"]
        if average > limit:
            raise SystemExit(f"SQL-регрессия маршрута {name}: {average} > {limit}")


if __name__ == "__main__":
    main()
