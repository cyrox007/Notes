"""Build a local, unsigned RC from committed Git bytes, never working/private files."""

import argparse
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import tarfile
import zipfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', required=True, type=Path)
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
if subprocess.check_output(['git', 'status', '--porcelain'], cwd=root, text=True).strip():
    raise SystemExit('Commit source changes before building a release candidate')
version_source = subprocess.check_output(['git', 'show', f'{sha}:core/Version.php'], cwd=root, text=True)
version = re.search(r"public const VERSION = '([^']+)'", version_source).group(1)
code = int(re.search(r'public const VERSION_CODE = (\d+)', version_source).group(1))
if not re.fullmatch(r'\d+\.\d+\.\d+-rc\.\d+', version):
    raise SystemExit('Only release-candidate identities are accepted')
archive = subprocess.check_output(['git', '-c', 'core.autocrlf=false', '-c', 'core.eol=lf', 'archive', '--format=tar', sha], cwd=root)
args.output.mkdir(parents=True, exist_ok=True)
path = args.output / f'workspace-organizer-v{version}.zip'
allowed_dirs = {'app', 'assets', 'bin', 'config', 'core', 'database', 'modules', 'ws_server'}
allowed_root = {'.htaccess', 'index.php', 'install.php', 'core.php', 'README.md', 'CHANGELOG.md',
                'composer.json', 'composer.lock', 'robots.txt', '.env.example'}
count = 0
with tarfile.open(fileobj=io.BytesIO(archive)) as source, zipfile.ZipFile(path, 'x', compression=zipfile.ZIP_DEFLATED) as target:
    for member in source:
        if not member.isfile():
            continue
        name = PurePosixPath(member.name)
        if '..' in name.parts or name.is_absolute():
            raise SystemExit('Unsafe archive entry')
        include = name.parts[0] in allowed_dirs or member.name in allowed_root or member.name.startswith('docs/releases/') or member.name == 'tools/release/bootstrap-1.0.16-updater.php'
        if not include:
            continue
        if name.name == '.env' or name.suffix in {'.log', '.key', '.pem', '.license-secret', '.update-secret'}:
            raise SystemExit(f'Private file in tracked runtime: {name}')
        data = source.extractfile(member).read()
        info = zipfile.ZipInfo(f'workspace-organizer-v{version}/{member.name}', date_time=(2026, 10, 13, 0, 0, 0))
        info.external_attr = (0o100644 << 16)
        info.compress_type = zipfile.ZIP_DEFLATED
        target.writestr(info, data)
        count += 1
    for directory in ['cache', 'compile']:
        info = zipfile.ZipInfo(f'workspace-organizer-v{version}/{directory}/')
        info.external_attr = (0o40755 << 16) | 0x10
        target.writestr(info, b'')
digest = hashlib.sha256(path.read_bytes()).hexdigest()
evidence = {'version': version, 'version_code': code, 'source_commit': sha, 'files': count,
            'package': path.name, 'bytes': path.stat().st_size, 'sha256': digest,
            'signed': False, 'publication': 'local candidate; not a stable update feed'}
(args.output / 'build-evidence.json').write_text(json.dumps(evidence, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
(args.output / 'SHA256SUMS').write_text(f'{digest}  {path.name}\n', encoding='ascii')
print(json.dumps(evidence, ensure_ascii=False))
