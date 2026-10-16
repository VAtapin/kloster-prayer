# WordPress.org translation imports

Runtime code and bundled PO/MO catalogs are unchanged. These files are separate
imports for translate.wordpress.org and are not included in the plugin ZIP.

- `interface-source.pot`: exact 357 Stable originals exported from WordPress.org.
- `readme-source.pot`: exact 80 Stable Readme originals, including FAQ, setup,
  screenshots and changelog.
- Four `readme-<locale>.json` files: complete descriptions in RU/DE/UK/PL.
- `interface-de_DE.json`: informal German address following the German community's
  default-locale convention; technical keys and brand name are preserved.
- `interface-uk.json`: uppercase label correction for `None`.
- Eight generated PO files: imports for both Stable and Development.

Generate and verify:

```text
python tools/build-wordpress-org-translations.py
python tests/wordpress-org-translations.py
```

The generator takes existing runtime translations, adds the unchanged author,
source URL and plugin name required by the directory, and applies reviewed
publication overrides. `None` is capitalized in Russian. It checks all originals,
placeholders, HTML/entities, URLs, shortcodes, permissions and provider event names.
This follows the publication approach used in `wp_cu_translator`; that repository
was read only.

## Verified submission on 16 October 2026

All four locales have, in each of Stable and Development:

| Component | Total | Waiting | Current | Untranslated | Warnings |
| --- | ---: | ---: | ---: | ---: | ---: |
| Interface | 357 | 357 | 0 | 0 | 0 |
| Readme | 80 | 80 | 0 | 0 | 0 |

All sixteen Waiting exports were downloaded through the authenticated WordPress.org
browser and their complete msgid/msgstr maps exactly matched these import files.
Waiting is a submitted suggestion, **not** an approved/public translation. The
account's import form provides only Waiting for this plugin; approval requires
the locale's existing translation editors. No permissions were changed and no
editor request was sent. Language-pack generation and translated public pages
remain unconfirmed until approval.

Monitor automation `wordpress-org` continues in the current chat, quietly checking
for approval changes. Do not reimport unchanged Waiting catalogs or replace
translations approved by editors. Stop the automation only after all four locales
are approved and their public descriptions and official language packs verified.
