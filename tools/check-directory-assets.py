"""Validate and package separate WordPress.org listing artwork (not plugin runtime)."""
from pathlib import Path
import re
import struct
import zipfile

root = Path(__file__).resolve().parent.parent
assets = root / 'wordpressorg-assets'
expected = {'icon-128x128.png': (128, 128), 'icon-256x256.png': (256, 256),
            'banner-772x250.png': (772, 250), 'banner-1544x500.png': (1544, 500)}
for locale in ('ru_RU', 'de_DE', 'uk', 'pl_PL'):
    for width, height in ((772, 250), (1544, 500)):
        expected[f'banner-{width}x{height}-{locale}.png'] = (width, height)
readme = (root / 'georg-kloster-orthodox-donations/readme.txt').read_text(encoding='utf-8-sig')
section = readme.split('== Screenshots ==')[1].split('== Changelog ==')[0]
captions = re.findall(r'^(\d+)\. (.+)$', section, re.M)
assert [int(n) for n, _ in captions] == list(range(1, 7))
for number, _ in captions:
    expected[f'screenshot-{number}.png'] = None
assert {p.name for p in assets.iterdir()} == set(expected)
for name, dimensions in expected.items():
    data = (assets / name).read_bytes()
    assert data[:8] == b'\x89PNG\r\n\x1a\n'
    actual = struct.unpack('>II', data[16:24])
    assert (actual == dimensions if dimensions else min(actual) >= 200), (name, actual)
    print(f'PASS {name}: {actual[0]}x{actual[1]}')
output = root / 'output/georg-kloster-orthodox-donations-wordpressorg-assets.zip'
output.parent.mkdir(parents=True, exist_ok=True)
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
    for name in sorted(expected):
        archive.write(assets / name, 'assets/' + name)
with zipfile.ZipFile(output) as archive:
    assert archive.testzip() is None
    for name in expected:
        assert archive.read('assets/' + name) == (assets / name).read_bytes()
print(f'PASS six readme captions and separate assets ZIP: {output}')
