"""Build a self-contained, authenticated browser preparation patch from this checkout."""
from pathlib import Path
import base64
import hashlib
import json
import re
import sys
import zlib

root = Path(__file__).resolve().parents[2]
source_11 = len(sys.argv) > 2 and sys.argv[2] == '--source-1.1.0'
if len(sys.argv) > 2 and not source_11:
    raise SystemExit('Supported source option: --source-1.1.0')
bridge = (root / 'tools/release/bootstrap-1.0.16-updater.php').read_text(encoding='utf-8')
runtime = (root / 'core/UpdateExternalRuntime.php').read_text(encoding='utf-8')
paths = set(re.findall(r"'((?:core|bin|app/services|assets/js)/[A-Za-z0-9_./-]+)'", bridge))
runtime_list = re.search(r'private const FILES = \[(.*?)\];', runtime, re.S).group(1)
paths.update(p for p in re.findall(r"'([^']+)'", runtime_list) if not p.startswith('config/'))
paths.add('core/Version.php')
payload = {}
for path in sorted(paths):
    if '..' in path.split('/') or not (root / path).is_file():
        raise RuntimeError(f'Invalid preparation dependency: {path}')
    data = (root / path).read_bytes()
    payload[path] = {'sha256': hashlib.sha256(data).hexdigest(), 'data': base64.b64encode(data).decode()}

helpers = bridge[bridge.index('function repairUpdaterFail'):bridge.index('$sourceRoot =')]
helpers = re.sub(r'function repairUpdaterFail\(.*?\n\}', '''function repairUpdaterFail(string $message, int $code = 1): never
{
    throw new RuntimeException($message, $code);
}''', helpers, count=1, flags=re.S)
engine = bridge[bridge.index('[$sourceVersion, $sourceCode]'):bridge.index('// Retain original updater files outside the live tree for manual recovery.\nfwrite(')]
env_start = engine.index("if (is_file($liveRoot . '/.env'))")
env_end = engine.index('// Переносится только исполнитель', env_start)
engine = engine[:env_start] + engine[env_end:]
engine = engine.replace("sys_get_temp_dir() . DIRECTORY_SEPARATOR\n    . 'notes-updater-bootstrap-1.1.0-rc.1-'", "$privateRoot . DIRECTORY_SEPARATOR\n    . 'notes-updater-bootstrap-1.1.0-'")
engine += '\nreturn $backupRoot;\n'
template = (root / 'tools/release/admin-preparation.template.php').read_text(encoding='utf-8')
requirements = (root / 'tools/release/PreparationRequirements.php').read_text(encoding='utf-8').split('final class', 1)[1]
if source_11:
    engine = engine.replace("$liveVersion !== '1.0.16' || $liveCode !== 10016", "$liveVersion !== '1.1.0' || $liveCode !== 11000")
    engine = engine.replace('1.0.16', '1.1.0')
    requirements = requirements.replace('10016', '11000').replace('1.0.16', '1.1.0')
    template = template.replace('установите 1.1.0 через', 'установите 1.1.1 через').replace('1.0.16', '1.1.0')
result = template.replace('/* REQUIREMENTS */', 'final class' + requirements)
result = result.replace('/* HELPERS */', helpers)
result = result.replace('/* ENGINE */', engine)
result = result.replace('__PAYLOAD__', base64.b64encode(zlib.compress(json.dumps(payload).encode())).decode())
result = result.replace('__PAYLOAD_SHA__', hashlib.sha256(json.dumps(payload).encode()).hexdigest())
out = Path(sys.argv[1])
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(result, encoding='utf-8', newline='\n')
print(f'{out}: {len(payload)} embedded files')
