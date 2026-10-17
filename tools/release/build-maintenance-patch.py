"""Package changed public runtime files for an installed 1.1.0, without changing its identity."""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import subprocess
import zipfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', required=True, type=Path)
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
def git(*arguments):
    return subprocess.check_output(['git', *arguments], cwd=root)

if git('status', '--porcelain', '--untracked-files=no').strip():
    raise SystemExit('Commit tracked changes before building the patch')
sha = git('rev-parse', 'HEAD').decode().strip()
version = git('show', sha + ':core/Version.php').decode()
if "VERSION = '1.1.0'" not in version or 'VERSION_CODE = 11000;' not in version:
    raise SystemExit('This maintenance patch requires source identity 1.1.0 / 11000')
names = git('diff', '--name-only', '--diff-filter=AM', 'v1.1.0', sha, '--',
            'app', 'assets', 'bin', 'core', 'modules', 'install.php', 'default.env').decode().splitlines()
names = [name for name in names if name != 'core/Version.php']
revision = 'r2'
names += ['CHANGELOG.md', 'docs/database-prefix.md', 'docs/releases/1.1.0-patch-r1.md', 'docs/releases/1.1.0-patch-r2.md']
payload = {}
for name in sorted(set(names)):
    path = PurePosixPath(name)
    if path.is_absolute() or '..' in path.parts or (name != 'default.env' and path.suffix not in {'.php', '.js', '.css', '.md', '.json'}):
        raise SystemExit('Unsafe patch entry: ' + name)
    payload[name] = git('show', sha + ':' + name)
manifest = {'patch': f'1.1.0-{revision}', 'installed_version': '1.1.0', 'installed_version_code': 11000,
            'source_commit': sha, 'changes_database': False, 'changes_version': False, 'database_prefix_support': True,
            'files': {name: hashlib.sha256(data).hexdigest() for name, data in payload.items()}}
payload[f'PATCH_1.1.0_{revision}.json'] = (json.dumps(manifest, ensure_ascii=False, indent=2) + '\n').encode()
payload[f'README_PATCH_1.1.0_{revision}.txt'] = f'''Патч {revision} для Workspace Organizer 1.1.0 / 11000

Это обычный файловый патч. Подпись, сайт публикации, админский offline-пакет и переход на новую версию не нужны.

1. Убедитесь, что установлена версия 1.1.0. Завершите активное обновление.
2. Сохраните резервную копию файлов сайта и временно закройте сайт для пользователей.
3. Распакуйте ZIP отдельно. Скопируйте его содержимое поверх корня установленного сайта с заменой файлов, объединяя каталоги. Не удаляйте существующие каталоги перед копированием.
4. Откройте сайт и нажмите Ctrl+F5 в браузере.

Версия остаётся 1.1.0 / 11000. В архиве нет core/Version.php, .env, ключей, .osp, пользовательских данных и SQL. Миграции выполнять не нужно.

Исправления: AJAX-переходы и действия модулей; плавное закрытие модальных окон; тёмно-серый календарь; отсутствие сжатия профиля при показе/скрытии материалов; сохранение .osp в последующих обновлениях; распознавание опубликованной схемы 1.1.0 без журнала миграций.

Список заменяемых файлов и SHA-256 находятся в PATCH_1.1.0_{revision}.json. Для отката верните файлы из резервной копии; дополнительные файлы, отсутствовавшие до патча, можно удалить по этому списку.
'''.encode('utf-8')
payload[f'README_PATCH_1.1.0_{revision}.txt'] += ('\nДополнительно r2: сохранение положения свёрнутого меню при AJAX-переходах; единая тёмная палитра мессенджера; списки часовых поясов событий и временных блоков; DBPREFIX для общей базы данных. Патч накопительный, включает r1. Для существующих таблиц без префикса добавьте DBPREFIX= в .env; для новой тестовой установки укажите test_. Обе установки должны работать на r2. Смена префикса не переносит данные. Подробности: docs/database-prefix.md.\n').encode('utf-8')
args.output.mkdir(parents=True, exist_ok=True)
archive = args.output / f'workspace-organizer-1.1.0-patch-{revision}.zip'
with zipfile.ZipFile(archive, 'x', compression=zipfile.ZIP_DEFLATED) as target:
    for name, data in sorted(payload.items()):
        info = zipfile.ZipInfo(name, date_time=(2026, 10, 17, 0, 0, 0))
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        target.writestr(info, data)
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
Path(str(archive) + '.sha256').write_text(digest + '  ' + archive.name + '\n', encoding='ascii')
print(json.dumps({'archive': str(archive), 'runtime_files': len(manifest['files']), 'sha256': digest,
                  'source_commit': sha, 'version': '1.1.0', 'version_code': 11000}))
