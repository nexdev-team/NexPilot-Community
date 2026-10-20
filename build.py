"""Build reproducible plugin-only ZIP; never install or contact a site."""
from pathlib import Path
import hashlib, subprocess, zipfile, re
root = Path(__file__).resolve().parent
source = root / 'plugin'
for file in sorted(source.rglob('*.php')):
    subprocess.run(['php', '-l', str(file)], check=True, capture_output=True)
version = re.search(r'\* Version: ([0-9A-Za-z.-]+)', (source / 'nexdev-site-connector.php').read_text()).group(1)
archive = root / f'dist/nexdev-site-connector-{version}.zip'
archive.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED) as z:
    for file in sorted(source.rglob('*')):
        if not file.is_file():
            continue
        info = zipfile.ZipInfo('nexdev-site-connector/' + str(file.relative_to(source)), (2026, 10, 17, 0, 0, 0))
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        z.writestr(info, file.read_bytes())
checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(checksum + '  ' + archive.name + '\n')
print(f'Built {archive.name} ({archive.stat().st_size} bytes). No deployment performed.')
