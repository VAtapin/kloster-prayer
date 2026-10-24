"""Regression checks for complete, reproducible, safe directory translation imports."""
from pathlib import Path
import runpy
import re

root = Path(__file__).resolve().parent.parent
builder = runpy.run_path(str(root / 'tools/build-wordpress-org-translations.py'))
directory = root / 'translations/wordpress-org'
before = {p.name: p.read_bytes() for p in directory.glob('*.po')}
assert len(before) == 8
builder['main']()
assert before == {p.name: p.read_bytes() for p in directory.glob('*.po')}, 'Catalogs must reproduce'
for locale in ('ru_RU', 'de_DE', 'uk', 'pl_PL'):
    for kind, count in (('interface', len(builder['originals']('interface'))), ('readme', 80)):
        entries = builder['catalog'](directory / f'{kind}-{locale}.po')
        translations = {e['msgid']: e['msgstr'] for e in entries if e['msgid']}
        assert len(translations) == count
        assert set(translations) == set(builder['originals'](kind))
        assert translations[builder['NAME']] == builder['NAME']
        assert all(translations.values())

german_runtime = {
    entry['msgid']: entry['msgstr']
    for entry in builder['catalog'](root / builder['SLUG'] / 'languages' / f"{builder['SLUG']}-de_DE.po")
    if entry['msgid']
}
german_interface = {
    entry['msgid']: entry['msgstr']
    for entry in builder['catalog'](directory / 'interface-de_DE.po')
    if entry['msgid']
}
german_readme = [
    entry['msgstr'] for entry in builder['catalog'](directory / 'readme-de_DE.po') if entry['msgid']
]
assert all(
    german_runtime[source] == translation
    for source, translation in german_interface.items()
    if source in german_runtime and source != builder['NAME']
)
assert not any(re.search(r'\b(?:Sie|Ihnen|Ihr\w*)\b', text) for text in (*german_runtime.values(), *german_readme))
assert german_interface['Prayer service'] == 'Gebetsdienst'
assert german_interface['Choose prayer services'] == 'Gebetsdienste auswählen'
assert german_interface['Slug'] == 'Titelform'
assert german_interface['Donors'] == 'Spender'
assert german_interface['Thank-you letter'] == 'Dankesschreiben'
assert 'Spendenaktion' in german_interface['Campaign progress']
assert not any('Gottesdienst' in text or 'Sammlung' in text for text in german_readme)

for source, bad in (
    ('Donate', ''),
    ('https://example.org/a', 'https://example.org/b'),
    ('<strong>Donation</strong>', '<em>Пожертвование</em>'),
    ('Donation: %s', 'Пожертвование'),
    ('[gkod_campaign_report id="1"]', '[gkod_campaign_report id="2"]'),
    ('PAYMENT.SALE.COMPLETED', 'PAYMENT.CAPTURE.COMPLETED'),
    ('invoice.payment_succeeded', 'invoice.payment_failed'),
    ('Help &gt; Shortcodes', 'Помощь > Шорткоды'),
    ('Use manage_options', 'Используйте edit_posts'),
    (builder['NAME'], 'Unapproved plugin rename'),
):
    try:
        builder['validate'](source, bad)
    except AssertionError:
        pass
    else:
        raise AssertionError(f'Unsafe translation accepted: {source}')
print('PASS 4 complete interface + readme catalogs, reproducibility, brand and negative token checks')
