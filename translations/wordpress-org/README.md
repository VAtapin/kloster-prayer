# WordPress.org translation imports

These files are separate imports for translate.wordpress.org and are not included
in the plugin ZIP. The German bundled PO/MO catalogs are kept in sync for manual
installations; runtime PHP code is unchanged.

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

## Verified publication on 17 October 2026

German `de/default` now has, in each of Stable and Development:

| Component | Total | Waiting | Current | Untranslated | Warnings |
| --- | ---: | ---: | ---: | ---: | ---: |
| Interface | 357 | 0 | 357 | 0 | 1 |
| Readme | 80 | 0 | 80 | 0 | 0 |

The German locale's PTE rights allowed importing the corrected catalogs as
Current. WordPress.org synced Stable to Development. The sole warning is an
automatic false positive: the source `period removed` starts lowercase, but the
German noun `Zeitraum` must be capitalized. It was approved manually. The
[public German plugin page](https://de.wordpress.org/plugins/georg-kloster-orthodox-donations/)
shows the translated description and screenshots. Its stale “not translated”
notice and language list had not refreshed at the time of verification; official
language-pack availability remains unconfirmed.

Russian `ru/default` is also Current in both Stable and Development: 357/357
interface strings and 80/80 Readme strings, with no Waiting, Fuzzy or Warnings.
The [public Russian plugin page](https://ru.wordpress.org/plugins/georg-kloster-orthodox-donations/)
shows the translated description, FAQ and screenshot captions. Existing approved
strings were not reimported.

Ukrainian `uk/default` is also 100% Current in Stable, Development and both
Readme projects, with no Waiting or Fuzzy strings. Stable has 357/357 Current
interface strings. The [public Ukrainian plugin page](https://uk.wordpress.org/plugins/georg-kloster-orthodox-donations/)
shows the translated description, FAQ and screenshot captions. No reimport was
needed. Polish submissions remain in Waiting.

Monitor automation `wordpress-org` continues to check Polish and language packs quietly.
Do not reimport unchanged Waiting catalogs or replace translations approved by
editors. Stop the automation only after all four locales and language packs are
verified.
