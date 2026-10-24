"""Build import catalogs against the exact WordPress.org originals; no runtime edits."""
from pathlib import Path
import ast
import json
import re

ROOT = Path(__file__).resolve().parent.parent
DIRECTORY = ROOT / 'translations/wordpress-org'
SLUG = 'georg-kloster-orthodox-donations'
NAME = 'Georg Kloster – Orthodox Donations'
AUTHOR_URL = 'https://github.com/VAtapin/kloster-prayer/tree/main/' + SLUG


def catalog(path):
    entries = []
    entry = {}
    field = None
    for line in path.read_text(encoding='utf-8-sig').splitlines() + ['']:
        if not line:
            if 'msgid' in entry:
                entries.append(entry)
            entry, field = {}, None
        elif line.startswith('msgid ') and 'msgid' in entry:
            entries.append(entry)
            entry, field = {}, None
            field, value = line.split(' ', 1)
            entry[field] = ast.literal_eval(value)
        elif line.startswith(('msgid ', 'msgstr ', 'msgctxt ')):
            field, value = line.split(' ', 1)
            entry[field] = ast.literal_eval(value)
        elif line.startswith('"') and field:
            entry[field] += ast.literal_eval(line)
        else:
            assert line.startswith('#'), f'Unsupported PO syntax: {line}'
    return entries


def originals(kind):
    return [e['msgid'] for e in catalog(DIRECTORY / f'{kind}-source.pot') if e['msgid']]


def validate(source, translation):
    assert isinstance(translation, str) and translation.strip(), f'Missing: {source}'
    for expression in (r'https://[^\s<>]+', r'<[^>]+>', r'%(?:\d+\$)?[sd]',
                       r'\[gkod_[^\]]+\]', r'\b(?:id|slug|campaign_id|header)="[^"]+"',
                       r'&(?:[a-z]+|#\d+);', r'\b(?:gkod_\w+|manage_options|wp_mail|WP-Cron)\b',
                       r'\b(?:PAYMENT|BILLING)\.[A-Z_.]+',
                       r'\b(?:checkout|charge|invoice|customer)\.[a-z_.]+'):
        assert sorted(re.findall(expression, source)) == sorted(re.findall(expression, translation)), f'Changed token: {source}'
    if NAME in source:
        assert NAME in translation, 'Keep the plugin name unchanged'


def write(kind, locale, entries):
    language_header = next(e['msgstr'] for e in catalog(ROOT / SLUG / 'languages' / f'{SLUG}-{locale}.po') if not e['msgid'])
    header = re.sub(r'Project-Id-Version:.*', f'Project-Id-Version: WordPress.org {kind}', language_header)
    lines = ['# GPL-2.0-or-later. WordPress.org import catalog.', 'msgid ""',
             'msgstr ' + json.dumps(header, ensure_ascii=False), '']
    for source, translation in entries:
        validate(source, translation)
        lines.extend(['msgid ' + json.dumps(source, ensure_ascii=False),
                      'msgstr ' + json.dumps(translation, ensure_ascii=False), ''])
    (DIRECTORY / f'{kind}-{locale}.po').write_text('\n'.join(lines), encoding='utf-8')


def main(interface_only=False):
    source = originals('interface')
    runtime_source = [e['msgid'] for e in catalog(ROOT / SLUG / 'languages' / f'{SLUG}.pot') if e['msgid']]
    assert set(runtime_source).issubset(source) and len(source) == len(set(source)), 'Interface import must include every current runtime string while preserving prior originals'
    for locale in ('ru_RU', 'de_DE', 'uk', 'pl_PL'):
        previous = DIRECTORY / f'interface-{locale}.po'
        translations = {e['msgid']: e['msgstr'] for e in catalog(previous)} if previous.exists() else {}
        translations.update({e['msgid']: e['msgstr'] for e in catalog(ROOT / SLUG / 'languages' / f'{SLUG}-{locale}.po')})
        translations.update({NAME: NAME, 'Vladimir Atapin': 'Vladimir Atapin', AUTHOR_URL: AUTHOR_URL})
        if locale == 'ru_RU':
            translations['None'] = 'Нет'
        overrides = DIRECTORY / f'interface-{locale}.json'
        if overrides.exists():
            reviewed = json.loads(overrides.read_text(encoding='utf-8'))
            assert set(reviewed).issubset(source), 'Override must match an active original'
            translations.update(reviewed)
        write('interface', locale, [(s, translations.get(s)) for s in source])
        if not interface_only:
            readme_source = originals('readme')
            translated = json.loads((DIRECTORY / f'readme-{locale}.json').read_text(encoding='utf-8'))
            assert len(readme_source) == len(translated) == 80
            write('readme', locale, list(zip(readme_source, translated)))
    print('PASS exact originals, complete catalogs, HTML/URL/shortcode/event/placeholder integrity')


if __name__ == '__main__':
    import sys
    main('--interface-only' in sys.argv)
