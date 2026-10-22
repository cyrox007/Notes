#!/usr/bin/env python3
"""Настоящие HTTP-запросы к двум процессам PHP с одной тестовой MySQL/MariaDB."""
import concurrent.futures
import json
import os
import subprocess
import time
import urllib.error
import urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))
FIXTURE = os.path.join(ROOT, "tests/integration/tasks_board_http_fixture_12.php")
ROUTER = os.path.join(ROOT, "tests/integration/tasks_board_http_router_12.php")
BOARD = "a" * 32
TASK = "b" * 32


def fixture(action):
    result = subprocess.run(["php", FIXTURE, action], cwd=ROOT, text=True,
                            capture_output=True, check=True, timeout=25)
    return json.loads(result.stdout) if action == "inspect" else result.stdout


def request(port, method, path, owner=None, body=None):
    headers = {"Accept": "application/json"}
    if owner is not None:
        headers["Authorization"] = "Bearer ntk1_" + str(owner) * 64
    data = None if body is None else json.dumps(body, ensure_ascii=False).encode("utf-8")
    if data is not None:
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(
        f"http://127.0.0.1:{port}{path}", data=data, headers=headers, method=method
    )
    try:
        with urllib.request.urlopen(req, timeout=12) as result:
            status, raw = result.status, result.read().decode("utf-8")
    except urllib.error.HTTPError as error:
        status, raw = error.code, error.read().decode("utf-8")
    try:
        parsed = json.loads(raw)
    except ValueError as error:
        raise AssertionError(f"Неверный JSON: HTTP {status} {raw[:700]}") from error
    return status, parsed


def verify(condition, message):
    if not condition:
        raise AssertionError(message)


def run():
    fixture("setup")
    ports = [18761, 18762]
    processes = []
    try:
        for port in ports:
            processes.append(subprocess.Popen(
                ["php", "-S", f"127.0.0.1:{port}", ROUTER], cwd=ROOT,
                stdout=subprocess.DEVNULL, stderr=subprocess.PIPE
            ))
        ready = False
        for _ in range(80):
            if any(process.poll() is not None for process in processes):
                raise RuntimeError("HTTP-испытание: PHP-сервер завершился")
            try:
                status, body = request(ports[0], "GET", "/api/v1/task-boards", 1)
                ready = status == 200
                if ready:
                    break
                if status >= 500:
                    raise AssertionError(f"Сервер: {status}, {body}")
            except (OSError, TimeoutError):
                time.sleep(0.15)
        verify(ready, "HTTP-маршрут не запустился")

        status, body = request(ports[0], "GET", "/api/v1/task-boards", None)
        verify(status == 401 and body.get("error") == "invalid_token",
               "Запрос без токена прошёл авторизацию")
        status, body = request(ports[0], "GET", "/api/v1/task-boards", 4)
        verify(status == 200 and body.get("items") == [],
               "Посторонний пользователь видит чужую закрытую доску")
        status, body = request(ports[0], "GET", f"/api/v1/task-boards/{BOARD}/tasks", 3)
        verify(status == 200 and len(body.get("items", [])) == 1,
               "Наблюдателю недоступен разрешённый просмотр")
        status, body = request(ports[0], "GET", f"/api/v1/task-boards/{BOARD}/tasks", 4)
        verify(status == 404, "Посторонний прочитал содержимое закрытой доски")

        path = f"/api/v1/task-boards/{BOARD}/members/13"
        change = {"command_id": "http-member-change-001",
                  "expected_role": "viewer", "role": "member"}
        status, body = request(ports[0], "PUT", path, 3, change)
        verify(status == 403, "Наблюдатель смог изменить роль")
        status, body = request(ports[0], "PUT", path, 4, change)
        verify(status == 404, "Посторонний смог изменить чужую доску")

        # Два разных HTTP-процесса одновременно повторяют одно изменение.
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
            jobs = [executor.submit(request, port, "PUT", path, 2, change) for port in ports]
            results = [job.result(timeout=20) for job in jobs]
        verify(sorted(status for status, _ in results) == [200, 200],
               f"Конкурентный повтор не завершился: {results}")
        verify(sorted(bool(body.get("replayed")) for _, body in results) == [False, True],
               f"Повтор выполнил вторую команду: {results}")
        status, body = request(ports[0], "PUT", path, 2,
                               {"command_id": "http-member-change-002",
                                "expected_role": "viewer", "role": "viewer"})
        verify(status == 409 and body.get("error") == "role_conflict",
               "Конфликт роли не возвращает 409")
        state = fixture("inspect")
        verify(state["role"] == "member", "Конфликт или повтор поменял роль")
        verify(sum(c["command_id"] == "http-member-change-001"
                   for c in state["commands"]) == 1,
               "Одна команда породила несколько квитанций")
        verify(any(int(log["status_code"]) == 409 for log in state["logs"]),
               "Результат конфликта отсутствует в журнале")

        fixture("revoke")
        status, body = request(ports[0], "PUT", path, 2,
                               {"command_id": "http-member-change-003",
                                "expected_role": "member", "role": "viewer"})
        verify(status == 401, "Отозванный Bearer продолжает работать")
        fixture("restrict")
        status, body = request(ports[0], "PUT", path, 2,
                               {"command_id": "http-member-change-004",
                                "expected_role": "member", "role": "viewer"})
        verify(status == 401, "Отозванный ключ получил обход через сессию")
        status, body = request(ports[0], "PUT", path, 3,
                               {"command_id": "http-member-change-005",
                                "expected_role": "member", "role": "viewer"})
        verify(status == 403, "Наблюдатель обошёл запрет записи")

        print("HTTP Tasks API: Bearer, права, две параллельные команды, 409, отзыв, журнал — успешно")
    finally:
        for process in processes:
            process.terminate()
        for process in processes:
            try:
                _, errors = process.communicate(timeout=5)
                if process.returncode not in (0, -15):
                    print(errors.decode("utf-8", errors="replace")[-1400:])
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
        fixture("cleanup")


if __name__ == "__main__":
    run()
