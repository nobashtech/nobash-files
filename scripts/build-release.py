#!/usr/bin/env python3
"""Create installable, reproducible Nobash Files plugin archive from the source tree."""
from pathlib import Path
import hashlib
import re
import sys
import zipfile

ROOT = Path(__file__).resolve().parents[1]
VERSION = '1.0.0.1'
PRODUCTION = [
    'LICENSE', 'nobash-files.php', 'readme.txt', 'changelog.txt', 'uninstall.php',
]
for directory in ('includes', 'assets'):
    PRODUCTION.extend(p.relative_to(ROOT).as_posix() for p in (ROOT / directory).rglob('*') if p.is_file())
PRODUCTION = sorted(set(PRODUCTION))
if not (ROOT / 'nobash-files.php').exists():
    sys.exit('Missing plugin entry point')
plugin_header = (ROOT / 'nobash-files.php').read_text(encoding='utf-8')
readme = (ROOT / 'readme.txt').read_text(encoding='utf-8')
if not re.search(r'(?m)^\s*\* Version: ' + re.escape(VERSION) + r'\s*$', plugin_header):
    sys.exit('Main plugin version differs from expected version ' + VERSION)
if not re.search(r'(?m)^Stable tag: ' + re.escape(VERSION) + r'\s*$', readme):
    sys.exit('readme.txt stable tag differs from expected version ' + VERSION)
output = ROOT / 'dist' / ('nobash-files-release-' + VERSION + '.zip')
output.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for relative in PRODUCTION:
        path = ROOT / relative
        if not path.is_file():
            sys.exit('Missing required file: ' + relative)
        # Stable ZIP timestamps/permissions for reproducible archives.
        entry = zipfile.ZipInfo('nobash-files/' + relative, (2026, 10, 16, 0, 0, 0))
        entry.create_system = 3
        entry.external_attr = 0o100644 << 16
        entry.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(entry, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
with zipfile.ZipFile(output) as archive:
    actual = archive.namelist()
    assert len(actual) == len(PRODUCTION), 'Archive file count does not match'
    assert all(name.startswith('nobash-files/') for name in actual), 'Invalid archive layout'
    assert 'nobash-files/README.md' not in actual, 'Development README must not ship'
print(f'Created {output} ({len(PRODUCTION)} production files)')
print('SHA256: ' + hashlib.sha256(output.read_bytes()).hexdigest())
