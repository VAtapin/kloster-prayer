# WordPress.org review fixes and audit — 1.0.5

13 October 2026. Name/slug/prefix remain owner-approved. No financial schema,
payment calculations, frontend styles, layouts or JavaScript changed.

## Fixes

- Removed the plugin's load_plugin_textdomain call and its PHPCS suppression.
  WordPress.org ZIP uses core JIT language packs. Only the manual ZIP registers
  its bundled catalog fallback directory with the public textdomain registry;
  this does not load translations and standard language packs retain priority.
- Calendar intention labels use esc_html__. Audited translated strings passed
  to WordPress APIs that print HTML: list-table headings and bulk actions,
  catalog status cells and menu titles now use esc_html__.
- Prayer-note checkbox aria-label uses esc_attr__. Existing variables/URLs use
  context-appropriate escaping; whole forms are not passed through wp_kses_post
  because that would strip their required input controls.
- PDF export filters use the existing scalar/unslash/sanitize Input helper.
- CSV cells and translated headings cannot become spreadsheet formulas.
  Only dangerous leading values get an apostrophe at export time. Stored names,
  comments, contacts and payment data remain unchanged.

## Review scope

Reviewed all first-party PHP renderers, request entry points and their helpers.
Administrative settings/catalog/order actions require manage_options plus their
action nonce. Campaign mutations require manage_options and gkod_campaign_action.
CSV/PDF require capability and their own nonces. Subscription cancellation
requires login, its subscription nonce and ownership/staff permission. Checkout
requires its AJAX nonce, validation and rate limit. Provider webhooks use
signature verification rather than browser nonces; return pages independently
verify provider payment binding. Table names/filter/order clauses are controlled
and query values are bound. Private history is restricted by donor_user_id.

Reviewed text/attributes/URL/textarea contexts, returned shortcode HTML, native
WordPress table callbacks, mail HTML, JSON responses and CSV/binary PDF output.
The remaining narrow security annotations describe Input wrappers checked by
their action callers, already-escaped shortcode help HTML, and generated binary
PDF bytes. No new escaping-suppression annotations were added.

## Validation and practical limits

- New empty local WordPress 7.1.3 installation, only this plugin active, WP_DEBUG
  enabled; negative nonce/capability tests for settings, campaigns, CSV, PDF and
  cancellation. Malformed input and malicious translations/saved HTML tested.
- Automatic JIT translation tests for manual catalogs and directory language
  packs in RU/DE/UK/PL. The latter are installed before startup and no test loader
  call supplies the result. Saved email/PDF locale checks remain enabled.
- Focused WPCS security/i18n/development-function checks run on all first-party
  PHP without excluding application files. The full WordPress standard was also
  run; existing spacing, indentation, short-array and documentation violations
  remain. This is not a claim of full stylistic WPCS compliance. A mass formatting
  change is deliberately outside this security patch.
- Every bundled PHP file receives syntax checking. Composer audit of the actual
  five bundled PDF packages reports no advisories or abandoned packages. Dompdf
  3.1.6 is already included; dependencies were not changed. Remote PDF resources
  are disabled, chroot is restricted, embedded PHP is disabled by library default.
- Local databases use the SQLite integration; MySQL/MariaDB are not available.
  Provider HTTP/mail calls in automatic tests are intercepted. The owner's earlier
  live-account/WPML confirmation remains recorded separately.

The updated ZIP must replace the code in the EXISTING pending submission.
No email, WordPress.org upload, SVN publication or production deployment is sent
automatically. Passing local checks does not replace the team's deeper review.

References: [WordPress JIT loading](https://make.wordpress.org/core/2024/10/21/i18n-improvements-6-7/),
[escaping](https://developer.wordpress.org/apis/security/escaping/),
[Dompdf advisories](https://github.com/dompdf/dompdf/security/advisories).
