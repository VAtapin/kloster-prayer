# WordPress.org preparation — 1.2.11

Prepared on 2026-10-11. Requested directory slug: `kloster-prayer`.
Repository, ZIP folder, text domain, database names, shortcodes and webhook routes
are retained. The canonical English name is **Orthodox Prayer Notes and Donations**;
bundled metadata translations retain the owner's Russian name.
Directory acceptance and the assigned slug remain decisions of the Plugins Team;
if the submission suggests another slug, request `kloster-prayer` before approval.

## Package and disclosure

- Standard English readme headers/sections, tested version, GPL license and URI.
- Stripe/PayPal purposes, transmitted fields, API origins, terms and privacy links.
- Readable PHP/JS/CSS included; no custom updater or downloaded executable code.
- Bundled upstream libraries and font/artwork notices in `third-party-notices.txt`.
- Original prayer-slip ownership/GPL permission confirmed by the owner on 2026-10-11.
- Bundled POT/PO/MO files; four prayer-form languages and translated plugin metadata.
  Existing administration screens are still mainly Russian; do not advertise a
  fully translated four-language administration interface.
- No remote monastery-logo reference; mail uses the site's own logo/name.
- Database and settings are retained on uninstall, explicitly disclosed.

## Security and compatibility changes

- Custom table identifiers use `%i`; query values retain prepared placeholders.
- Input normalizer rejects arrays for scalar fields, unslashes once, preserves
  textarea line breaks, and keeps list input separate from scalar input.
- Admin writes require `manage_options` and the admin nonce; payment creation
  requires its AJAX nonce. Public webhook routes retain signature verification.
- Completed Stripe/PayPal payments must match saved provider/payment identifiers,
  amount and currency. PayPal browser return cannot capture a different order token.
- Output escaping, explicit PHP echo tags and guarded executable entry points.
- Correct one-index-per-line dbDelta formatting; schema version 1.2.11 performs
  a compatible recheck/addition without deleting records or dropping tables.

## Verification

Plugin Check 2.1.0 on WordPress 7.1 / PHP 8.4, with `cli.php` required for runtime
checks: **0 errors, 170 warnings**. No categories or files were excluded.

Warnings are not hidden:

- 89 `WordPress.DB.DirectDatabaseQuery.DirectQuery`: custom lifecycle/payment
  tables have no equivalent WordPress post/meta API. Transactions and idempotency
  need SQL rather than stale object-cache state.
- 80 `WordPress.DB.DirectDatabaseQuery.NoCaching`: payment, notification and
  archive state is intentionally read fresh; admin queries are paginated.
- 1 `load_plugin_textdomainFound`: loading bundled MO catalogs is retained for
  manual ZIP installations and deferred to `init`, not early translation access.

Narrow annotated exceptions explain dynamic prepared queries built from fixed
filter/sort whitelists, generated binary PDF output, closing `php://output`, the
existing WPML hook, and normalization helpers whose callers enforce authorization.
They do not disable authentication, escaping or runtime validation.

Tests include PHP lint, fixture admin/notification/API checks, payment binding,
real disposable WordPress/SQLite activation and upgrade with data preservation,
nonce/capability rejection, signed Stripe event processing, duplicate delivery,
archive/comments, shortcode joins, reports and PDF queries. External HTTP and mail
are intercepted in the integration test. PDF rendering and visual review preserve
the original prayer slips.

The real WordPress integration test is local only:

```text
php -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 \
    -d extension=gd -d memory_limit=512M tests/wporg-integration.php \
    tmp/wporg-review/wordpress
```

The temporary WordPress core, checker, SQLite drop-in, test database, reports and
fake credentials are not shipped or committed. MySQL/Plesk execution, actual
Stripe/PayPal test-mode transactions and real SMTP delivery still need separate
site-owner verification; passing Plugin Check is not a security guarantee.

No WordPress.org submission, SVN publication, Git tag or production deployment
is performed by this preparation task. Install/test the ZIP separately before
updating the working donation site. Do not replace the existing calendar-plugin
review submission with this unrelated plugin.
