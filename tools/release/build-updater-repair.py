"""Build the standalone full updater bridge without changing release assets."""
import argparse
import hashlib
import pathlib
import re
import zipfile

parser = argparse.ArgumentParser()
parser.add_argument('--output', required=True)
args = parser.parse_args()
root = pathlib.Path(__file__).resolve().parents[2]
installer = 'tools/release/repair-updater-full.php'
source = (root / installer).read_text(encoding='utf-8')
block = source.split('$files = [', 1)[1].split('];', 1)[0]
files = re.findall(r"'([a-zA-Z0-9_./-]+)'", block)
assert files and 'core/Version.php' not in files
files += [installer, 'tools/release/repair-updater-stage.php', 'core/Version.php']  # Source metadata only; never copied to live.
payload = {name: (root / name).read_bytes() for name in files}
payload['README.md'] = (root / 'docs/updates/updater-repair-2026-10-10.md').read_bytes()
payload['repair-updater.php'] = b'<?php\nrequire __DIR__ . "/tools/release/repair-updater-full.php";\n'
payload['SHA256SUMS'] = ''.join(
    f'{hashlib.sha256(data).hexdigest()}  {name}\n'
    for name, data in sorted(payload.items())
).encode()
output = pathlib.Path(args.output)
output.parent.mkdir(parents=True, exist_ok=True)
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
    for name, data in sorted(payload.items()):
        archive.writestr(name, data)
print(f'{output.name}: {len(payload)} files, SHA256 {hashlib.sha256(output.read_bytes()).hexdigest()}')
