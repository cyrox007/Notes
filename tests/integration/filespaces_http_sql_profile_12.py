#!/usr/bin/env python3
"""Профиль SQL для трёх реальных HTTP-маршрутов общего файлового каталога.

Проверка допускается только на изолированной тестовой базе; не использовать
её с рабочим сайтом или пользовательскими файлами.
"""

import argparse
import json
import os
import pathlib
import statistics
import subprocess
import uuid

from filespaces_http_load_12 import Fields, connect, multipart, request


def sql_snapshot():
    """Счётчики нормализованных SQL-операций только для тестовой базы."""
    database = os.environ.get("DBNAME", "")
    if not database.startswith("fsp_http_load"):
        raise RuntimeError("Профиль SQL разрешён только на базе fsp_http_load")
    statement = (
        "SELECT DIGEST, COUNT_STAR, "
        "REPLACE(REPLACE(LEFT(DIGEST_TEXT, 360), CHAR(10), ' '), CHAR(9), ' ') "
        "FROM performance_schema.events_statements_summary_by_digest "
        "WHERE SCHEMA_NAME = '" + database + "' AND DIGEST IS NOT NULL"
    )
    command = [
        "mysql", "--connect-timeout=3", "--batch", "--raw", "--skip-column-names",
        "-h", os.getenv("DBHOST", "127.0.0.1"),
        "-P", os.getenv("DBPORT", "3306"),
        "-u", os.getenv("DBUSER", "root"), "-e", statement,
    ]
    result = subprocess.run(command, capture_output=True, text=True, timeout=8, check=True)
    entries = {}
    for line in result.stdout.splitlines():
        digest, count, template = line.split("\t", 2)
        entries[digest] = (int(count), template)
    return entries


def measured(opener, url, method="GET", payload=None, headers=None):
    before = sql_snapshot()
    status, body, latency = request(opener, url, method, payload, headers)
    after = sql_snapshot()
    changes = {}
    for digest, (count, template) in after.items():
        increment = count - before.get(digest, (0, ""))[0]
        if increment > 0:
            changes[digest] = (increment, template)
    if not changes:
        raise RuntimeError("MySQL не предоставил SQL-профиль HTTP-запроса")
    return status, body, latency, changes


def record(samples, name, result):
    status, _, latency, changes = result
    samples[name].append({
        "http": status, "задержка_мс": round(latency, 2),
        "sql": {digest: count for digest, (count, _) in changes.items()},
        "шаблоны": {digest: template for digest, (_, template) in changes.items()},
    })


def summarize(rows):
    aggregate = {}
    templates = {}
    for row in rows:
        for digest, count in row["sql"].items():
            aggregate[digest] = aggregate.get(digest, 0) + count
            templates[digest] = row["шаблоны"][digest]
    total = sum(aggregate.values())
    repeats = sorted(aggregate, key=lambda digest: aggregate[digest], reverse=True)
    return {
        "http_ответы": [row["http"] for row in rows],
        "замеров": len(rows),
        "sql_всего": total,
        "sql_в_среднем_на_ответ": round(total / len(rows), 2),
        "p50_мс": round(statistics.median(row["задержка_мс"] for row in rows), 2),
        "повторяющиеся_шаблоны": [
            {"выполнений": aggregate[digest], "sql": templates[digest]}
            for digest in repeats[:15]
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
        "источник": "performance_schema.events_statements_summary_by_digest",
        "ограничение": "Счётчики SQL привязаны к тестовой схеме, а не к отдельному соединению. "
                       "На одноразовом стенде во время замера нет других клиентов.",
        "маршруты": {name: summarize(rows) for name, rows in samples.items()},
    }
    pathlib.Path(args.out).write_text(json.dumps(report, ensure_ascii=False, indent=2))
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
