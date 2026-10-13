"""Build a manual-install ZIP and a WordPress.org ZIP from the same runtime."""
from pathlib import Path
import argparse
import hashlib
import re
import zipfile

root = Path(__file__).resolve().parent.parent
slug = "georg-kloster-orthodox-donate"
plugin = root / slug
args = argparse.ArgumentParser()
args.add_argument("--extract-to", type=Path, help="Optional local verification directory")
options = args.parse_args()
main = (plugin / f"{slug}.php").read_text(encoding="utf-8-sig")
readme = (plugin / "readme.txt").read_text(encoding="utf-8-sig")
version = re.search(r"^ \* Version: (\d+\.\d+\.\d+)$", main, re.M).group(1)
assert f"const VERSION = '{version}';" in main
assert f"Stable tag: {version}" in readme
assert f"Text Domain: {slug}" in main
files = sorted(p for p in plugin.rglob("*") if p.is_file())
assert all(not p.is_symlink() and not p.name.startswith(".") and p.name not in
           {"AGENTS.md", "wp-config.php"} and p.suffix.lower() not in
           {".sqlite", ".log", ".zip", ".bak", ".swp"} for p in files)
if options.extract_to:
    extract = options.extract_to.resolve()
    assert extract.is_relative_to((root / "tmp").resolve()), "Extract only into workspace tmp"
for directory in (False, True):
    selected = [p for p in files if not directory or p.suffix.lower() not in {".po", ".mo"}]
    suffix = f"-wordpress-org-{version}" if directory else f"-{version}"
    output = root / "output" / f"{slug}{suffix}.zip"
    output.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for source in selected:
            name = source.relative_to(root).as_posix()
            entry = zipfile.ZipInfo(name, (2026, 1, 1, 0, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, source.read_bytes())
    with zipfile.ZipFile(output) as archive:
        assert archive.testzip() is None
        assert len(archive.namelist()) == len(set(archive.namelist())) == len(selected)
        assert all(n.startswith(slug + "/") and ".." not in n.split("/") for n in archive.namelist())
        for source in selected:
            assert archive.read(source.relative_to(root).as_posix()) == source.read_bytes()
        if options.extract_to:
            archive.extractall(extract / ("wordpress-org" if directory else "manual"))
    print(f"PASS {'WordPress.org' if directory else 'manual'} ZIP: {len(selected)} source-identical files")
    print(f"{output}\nSHA256 {hashlib.sha256(output.read_bytes()).hexdigest()}")
