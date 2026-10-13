# WordPress.org preparation — 1.0.5

Prepared on 2026-10-13. English directory name: **Georg Kloster – Orthodox Donations**.
Russian plugin name/menu: **Пожертвования**. Assigned slug/text domain/package
folder: `georg-kloster-orthodox-donations`. Author: Vladimir Atapin; contributor `atapin`.
Review security fixes and detailed scope: [SECURITY-REVIEW-1.0.5.md](SECURITY-REVIEW-1.0.5.md).
The public WordPress.org contributor profile was verified with HTTP 200.

## Applied publication experience

The successful `wp_cu_translator` publication was consulted: distinctive brand
prefix, isolated JavaScript globals, English source strings, separate manual and
WordPress.org ZIPs, POT in the directory build, and standard WordPress language packs.
The runtime, PHP namespace `GeorgKlosterOrthodoxDonate`, nine `gkod_*` shortcodes,
payment hooks/settings/tables and assets remain independent from the legacy plugin.

Both ZIPs contain identical runtime/assets and one correctly named root folder:

- `output/georg-kloster-orthodox-donations-1.0.5.zip`: 374 files, including all four PO/MO catalogs.
- `output/georg-kloster-orthodox-donations-wordpress-org-1.0.5.zip`: 366 files; excludes only PO/MO and retains POT.

Use the second ZIP for submission. WordPress.org builds obtain translations from
`wp-content/languages/plugins`; until published on translate.wordpress.org the
directory version uses English. Explicit saved email/PDF languages also prefer
these standard language packs and fall back to bundled catalogs in manual installs.
All 360 English msgid and RU/DE/UK/PL translations remain in the repository.

Build both deterministic packages with `python tools/build-package.py`.
For local verification, add `--extract-to tmp/publication-package-check`.
No Git tags, releases, SVN submissions or production updates were performed.

## Readme and licenses

- English readme under 10,000 bytes, short description under 150 characters;
  matching name/version/requirements, unique descending new-series changelog.
- All nine shortcodes, payment setup, one-time/recurring webhook events, consent,
  account privacy, retention, cancellation, external-service data/terms/privacy and
  local PDF requirements are documented. No legacy compatibility claims remain.
- Plugin URI points to this plugin's public source directory; Author URI is separate.
- Readable source and GPL-compatible code/library/font/artwork notices are included.
- Original prayer-slip ownership/GPL permission was confirmed by the owner previously;
  third-party notices identify bundled library and illustration licenses.
- The WordPress.org readme parser passes structural checks. Its contributor lookup
  uses the local WordPress user database, so the local fixture's missing `atapin`
  user is distinguished from the independently verified WordPress.org profile.

## Plugin Check and SQL warning review

Plugin Check **2.1.0**, all categories including runtime preparation, on WordPress
**7.1.3** / PHP **8.4.25**, for both exact extracted ZIPs:
**0 errors, 0 reported warnings**. No categories or files were excluded.

The initial 254 warnings were individually accounted for:

- 133 direct-query and 120 no-caching findings: these are custom financial,
  workflow/configuration and paginated administration tables with no equivalent
  WordPress post/meta operation. Current payment binding, conditional writes,
  idempotency, refund totals, notification claims and ownership checks must not
  read stale financial cache. 129 affected SQL statement lines have narrow PHPCS
  exceptions explaining their role; existing prepared-query explanations remain.
- The text-domain loader call and its annotation are removed in 1.0.5. WordPress
  JIT loads directory packs; manual ZIPs register only the local catalog path.

This is documented exception handling, not replacement of SQL operations with
cached financial state. Executable-token comparison against the preceding commit
confirmed financial-query semantics were unchanged by the initial annotation work.
Unrelated security, escaping, nonce, signature and query preparation checks remain active.

## Checks actually executed

- PHP syntax across all 279 source/test/tool files, including bundled libraries; JS syntax.
- i18n/POT/PO/MO completeness, placeholders and preserved translations in four locales.
- Standalone API-domain, payment binding, notifications, workflow, archive/print state,
  comments/admin behavior and Stripe/PayPal setup-help regressions.
- Separate cloned WordPress/SQLite databases: real startup, activation/schema checks,
  malformed-input rejection, nonce/capability checks, signed Stripe/PayPal events,
  payment/refund idempotency, subscriptions, cancellation, owner-only history,
  campaign totals/expenses/privacy, administration navigation and shortcode guide.
- Real WordPress gettext and explicit email/PDF locale tests; standard language-pack
  loading in four locales with no bundled MO in the WordPress.org ZIP.
- Manual ZIP: install, metadata translation, backend/payment/subscription/admin/help tests.
  Directory ZIP: readme/release metadata, real integration and language-pack tests.
- Both real legacy/new plugins active in either order; separate menus, routes,
  settings, tables, shortcode callbacks and resources. Browser submits both real
  forms on one page independently, without duplicate requests.
- Browser: native WordPress admin CSS/empty tables/mobile widths, shortcode copying
  and denied clipboard recovery, Stripe help, donation bounds, name normalization,
  support consent/cancellation and error recovery.
- PDF smoke, rendered prayer-note and archive PDFs; layout/escaping/footer checks.
- ZIP integrity, byte-for-byte source matching, excluded PO/MO, root paths and no
  tests, Git, local databases, logs or credentials in either package.
- actionlint 1.7.12 for the updated release workflow.

HTTP/payment and mail calls in automated fixtures are intercepted; they are not
presented as live provider transactions. The owner explicitly confirmed on
2026-10-13 that real payment accounts and installed WPML were already tested.
Local MySQL/MariaDB/Docker executables are unavailable; automated databases use
SQLite. This local environment limitation is separate from owner-confirmed site tests.

## Submission

The owner submitted the plugin and accepted the assigned slug
`georg-kloster-orthodox-donations`. Their upload report contained 564
WordPress.WP.I18n.TextDomainMismatch errors and one header textdomain_mismatch:
all referred to the old singular donate domain. Version 1.0.4 aligned the folder,
main PHP filename, all gettext calls, catalog filenames/headers, resource/admin
and webhook routes, tests and packaging with the assigned slug. The exact approved
display name is Georg Kloster – Orthodox Donations; gkod_ tables/options/actions
and the PHP namespace remain unchanged. No financial schema migration is introduced.

Full Plugin Check must use --slug=georg-kloster-orthodox-donations for both ZIPs;
matching only the locally chosen folder is insufficient. The readiness regression
also pins the assigned slug and the owner-approved display name.

Upload the corrected ZIP to the EXISTING submission; do not submit a new plugin.
Keep translator/calendar submissions separate. Plugins Team makes the final
approval decision; local automated checks do not approve or publish a plugin.
