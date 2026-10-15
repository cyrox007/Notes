"""Exercise the generated bridge against exact 1.0.16 without a database."""
from pathlib import Path
import base64
import hashlib
import json
import re
import subprocess
import sys
import tempfile
import zipfile
import zlib

repo = Path(__file__).resolve().parents[2]
php = sys.argv[1] if len(sys.argv) > 1 else 'php'
source_version = sys.argv[2] if len(sys.argv) > 2 else '1.0.16'
assert source_version in ['1.0.16', '1.1.0']
with tempfile.TemporaryDirectory(prefix='notes-preparation-') as directory:
    work = Path(directory)
    subprocess.run(['git', 'archive', '--format=zip', '--output=' + str(work / 'old.zip'), 'v' + source_version], cwd=repo, check=True)
    with zipfile.ZipFile(work / 'old.zip') as archive:
        archive.extractall(work / 'live')
    subprocess.run([sys.executable, str(repo / 'tools/release/build-admin-preparation.py'), str(work / 'prepare.php')] + (['--source-1.1.0'] if source_version == '1.1.0' else []), check=True)
    generated = (work / 'prepare.php').read_text(encoding='utf8')
    (work / 'engine.php').write_text(generated.split('$root = __DIR__;')[0], encoding='utf8')
    (work / 'runner.php').write_text("<?php require __DIR__.'/engine.php'; preparationApply($argv[1], $argv[2], $argv[3]);", encoding='utf8')
    (work / 'private').mkdir()
    (work / 'live/.env').write_text('PREPARATION_PRESERVATION=sentinel\n', encoding='utf8')
    (work / 'live/.osp').mkdir(exist_ok=True)
    (work / 'live/.osp/project.ini').write_text('[notes.local]\nphp_engine = PHP-8.2\n', encoding='utf8')
    protected = ['.env', '.osp/project.ini', 'core/Version.php', 'config/update_trusted_keys.php', 'config/license_trusted_keys.php']
    before = {p: (work / 'live' / p).read_bytes() for p in protected}
    packed = re.search(r"gzuncompress\(base64_decode\('([^']+)'", generated).group(1)
    payload = json.loads(zlib.decompress(base64.b64decode(packed)))
    source = work / 'source'
    for relative, entry in payload.items():
        target = source / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(base64.b64decode(entry['data']))
    args = [php, str(work / 'runner.php'), str(source), str(work / 'live'), str(work / 'private')]
    for attempt in range(2):
        subprocess.run(args, check=True)
        assert before == {p: (work / 'live' / p).read_bytes() for p in protected}
    snapshot = {str(p.relative_to(work / 'live')): hashlib.sha256(p.read_bytes()).hexdigest() for p in (work / 'live').rglob('*') if p.is_file()}
    (source / 'core/UpdatePackageStager.php').unlink()
    rejected = subprocess.run(args, capture_output=True)
    assert rejected.returncode != 0, 'Incomplete payload must fail'
    after = {str(p.relative_to(work / 'live')): hashlib.sha256(p.read_bytes()).hexdigest() for p in (work / 'live').rglob('*') if p.is_file()}
    assert after == snapshot, 'Incomplete payload modified live files'
print('Preparation preserves identity, accepts repeat, rejects incomplete payload without mutation')
