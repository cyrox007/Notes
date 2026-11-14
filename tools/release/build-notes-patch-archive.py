#!/usr/bin/env python3
"""Собирает архив пофайлового патча Notes без изменения номера версии."""

import argparse
import hashlib
import json
import subprocess
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FILES = [
    "app/middlewares/EnforceMaintenanceMode.php",
    "app/views/core/base.php",
    "core/Controller.php",
    "modules/admin/AdminRuntimeProvider.php",
    "modules/admin/assets/admin-settings-nav.js",
    "modules/admin/assets/style.css",
    "modules/admin/controllers/AdminBackupController.php",
    "modules/admin/services/AdminBackupFiles.php",
    "modules/admin/services/AdminBackupService.php",
    "modules/admin/views/backups.php",
    "modules/defects/assets/defects.css",
    "modules/work-permits/WorkPermitsRuntimeProvider.php",
    "modules/work-permits/assets/work-permits.css",
    "modules/work-permits/controllers/WorkPermitController.php",
    "modules/work-permits/module.json",
    "modules/work-permits/views/index.php",
]
VERSION = "1.2.0-rc.1"


def git(*args: str) -> bytes:
    return subprocess.check_output(["git", *args], cwd=ROOT)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def main() -> None:
    # Сборщик всегда пишет сообщения на UTF-8, в том числе в Windows PowerShell.
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", required=True, help="Точная базовая фиксация")
    parser.add_argument("--output", required=True, help="Путь итогового ZIP")
    args = parser.parse_args()
    base = git("rev-parse", args.base).decode().strip()
    target = git("rev-parse", "HEAD").decode().strip()

    modified = set(
        git("diff", "--name-only", base, target, "--").decode().splitlines()
    )
    expected = set(FILES)
    runtime = {
        path for path in modified
        if path.startswith(("app/", "core/", "modules/"))
    }
    if runtime != expected:
        raise SystemExit(
            "Состав исполняемых файлов не совпал с утверждённым патчем. "
            f"Отсутствуют: {sorted(expected - runtime)}; лишние: {sorted(runtime - expected)}"
        )

    base_version = git("show", f"{base}:core/Version.php").decode()
    target_version = (ROOT / "core/Version.php").read_text(encoding="utf-8")
    marker = f"public const VERSION = '{VERSION}'"
    if marker not in base_version or marker not in target_version:
        raise SystemExit("Номер версии изменён: архив без смены версии запрещён")
    base_module = json.loads(git("show", f"{base}:modules/work-permits/module.json"))
    target_module = json.loads((ROOT / "modules/work-permits/module.json").read_text(encoding="utf-8"))
    if base_module["version"] != target_module["version"]:
        raise SystemExit("Изменился номер версии ЖНДР")

    entries = []
    data = {}
    for path in FILES:
        live = (ROOT / path).read_bytes()
        try:
            previous = git("show", f"{base}:{path}")
        except subprocess.CalledProcessError:
            previous = None
        data[path] = live
        entries.append({
            "path": path,
            "base_sha256": sha(previous) if previous is not None else None,
            "target_sha256": sha(live),
            "size": len(live),
        })

    manifest = {
        "format": "notes-overlay-patch-v1",
        "application_version": VERSION,
        "base_commit": base,
        "target_commit": target,
        "changed_files": entries,
        "database_migrations": False,
        "changes_application_version": False,
        "automated_restore_accepted": False,
    }
    destination = Path(args.output).resolve()
    destination.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(destination, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        archive.writestr("manifest.json", json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")
        archive.write(ROOT / "tools/release/install-notes-patch.php", "repair.php")
        archive.write(ROOT / "tools/release/NOTES_PATCH_README_RU.md", "ИНСТРУКЦИЯ.md")
        for path in FILES:
            # Файлы лежат в корне ZIP с той же структурой, что и у сайта.
            archive.writestr(path, data[path])
    print(f"Патч собран: {destination}")
    print(f"Рабочих файлов: {len(FILES)}")
    print(f"Размер архива: {destination.stat().st_size} байт")


if __name__ == "__main__":
    main()
