"""Package only the reviewed WordPress plugin source."""
from pathlib import Path
import hashlib
import re
import subprocess
import zipfile

root = Path(__file__).resolve().parent
source = root / "plugin"
for file in sorted(source.rglob("*.php")):
    subprocess.run(["php", "-l", str(file)], check=True, capture_output=True)

header = (source / "nexpilot-community.php").read_text()
version = re.search(r"\* Version: ([0-9A-Za-z.-]+)", header)
if not version:
    raise SystemExit("Missing plugin version")

archive = root / "dist" / f"nexpilot-community-{version.group(1)}.zip"
archive.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED) as z:
    for file in sorted(source.rglob("*")):
        if file.is_file():
            info = zipfile.ZipInfo(
                "nexpilot-community/" + str(file.relative_to(source)),
                (2026, 10, 20, 0, 0, 0),
            )
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, file.read_bytes())

checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix(".zip.sha256").write_text(f"{checksum}  {archive.name}\n")
print(f"Built {archive.name}")
