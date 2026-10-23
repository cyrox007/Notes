#!/usr/bin/env python3
"""Проверка независимых браузерных пользователей на настоящем HTTP API и базе."""
import os
import subprocess
import time

from tasks_board_http_12 import ROOT, ROUTER, fixture, request

PORTS = (18861, 18862)


def main():
    fixture("setup")
    servers = []
    try:
        for port in PORTS:
            servers.append(subprocess.Popen(
                ["php", "-S", f"127.0.0.1:{port}", ROUTER],
                cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE
            ))

        ready = False
        for _ in range(80):
            if any(server.poll() is not None for server in servers):
                raise RuntimeError("Сервер PHP завершился до запуска испытаний")
            try:
                statuses = [request(port, "GET", "/api/v1/task-boards", 1)[0] for port in PORTS]
                if statuses == [200, 200]:
                    ready = True
                    break
            except (OSError, TimeoutError):
                time.sleep(0.15)
        if not ready:
            raise RuntimeError("Не удалось поднять два процесса PHP для испытаний")

        environment = os.environ.copy()
        environment["E2E_TASKS_ORIGIN"] = f"http://127.0.0.1:{PORTS[0]}"
        environment["E2E_TASKS_SECONDARY"] = f"http://127.0.0.1:{PORTS[1]}"
        subprocess.run(
            ["node", "tests/e2e/tasks-board-live-two-users-12.mjs"],
            cwd=ROOT, env=environment, timeout=150, check=True
        )

        # Проверяем не только браузерные ответы, но и сохранённые строки БД.
        state = fixture("inspect")
        expected = ["browser-colleague-write-001", "browser-owner-current-001",
                    "browser-race-owner-001", "browser-race-colleague-001",
                    "browser-readd-current-001"]
        commands = {entry["command_id"]: entry["status"] for entry in state["commands"]}
        if any(key not in commands for key in expected):
            raise AssertionError("В журнале отсутствуют завершённые команды пользователей")
        if state.get("member_role") != "member":
            raise AssertionError("Последнее восстановление участника не сохранилось")
        if int(state.get("version", -1)) != 5 or state.get("task_status") != "review":
            raise AssertionError("Итоговая версия или статус задачи отличаются от проверенных в браузере")
        statuses = {int(row["status_code"]) for row in state["logs"]}
        if not {200, 403, 404, 409}.issubset(statuses):
            raise AssertionError("Аудит API не зафиксировал отказ, конфликт и успешное изменение")
        print("HTTP/Chromium: две действующие учётные записи, отзыв доступа, повтор и восстановление подтверждены БД")
    finally:
        for server in servers:
            server.terminate()
        for server in servers:
            try:
                _, errors = server.communicate(timeout=6)
                if server.returncode not in (0, -15):
                    print(errors.decode("utf-8", errors="replace")[-1400:])
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
        fixture("cleanup")


if __name__ == "__main__":
    main()
