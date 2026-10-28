=== Georg Kloster – Orthodox Donations ===
Contributors: atapin
Tags: orthodox, prayer, donations, stripe, paypal
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 1.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Church donations and prayer requests with Stripe/PayPal, targeted campaigns, recurring support, private history and printable prayer slips.

== Description ==

Accept donations and Orthodox prayer requests on parish, church, cathedral or monastery websites using your own Stripe or PayPal account.

* One-time donations, with or without prayer requests.
* Targeted campaigns, goals, deadlines and public financial totals.
* Separately entered expenses and confirmed partial/full refund accounting.
* Monthly or yearly support with explicit consent and cancellation.
* Private donor history through a reusable email link or optional WordPress account; public reports do not show donor identities.
* Configurable services and forms with six responsive layouts.
* WordPress donor directory, manual thank-you PDF emails, reports, CSV export and request archiving.
* Local A4 landscape prayer-slip PDFs, including comments and archived requests.
* English sources; Russian, German, Ukrainian and Polish translations.
* WordPress gettext; WPML/Polylang for edited content.

Manual ZIP: four translations. WordPress.org: standard language packs; English until translations are published.

Requirements: WordPress 7.1+, PHP 8.4+, mbstring/GD for local PDFs, HTTPS callbacks and working WP-Cron.

= External services: Stripe =

Stripe is contacted only for the configured payment features. The plugin uses https://api.stripe.com for Checkout sessions, subscriptions/cancellation, invoice payment identification and refund reconciliation. Requests contain API credentials, donor email, EUR amounts, service titles, internal/public payment identifiers, billing interval and return URLs as applicable. Prayer names and comments are not sent when creating payments.

Stripe collects card/billing details on its hosted checkout. Signed webhooks return payment status and available payer information. The site owner supplies their API key and endpoint signing secret; the plugin author receives no payments or credentials.

* Terms: https://stripe.com/legal/ssa
* Privacy: https://stripe.com/privacy
* API documentation: https://docs.stripe.com/api/checkout/sessions

= External services: PayPal =

The plugin uses https://api-m.paypal.com (Live) or https://api-m.sandbox.paypal.com (Sandbox) for REST OAuth authentication, Orders v2 creation/capture, webhook signature verification, products/plans/subscriptions and cancellation. Capture references can be verified using the payments API.

Requests contain application credentials, EUR amounts, service titles, payment/subscription identifiers, billing interval and return URLs as applicable. Signature verification sends the received webhook event and transmission headers back to PayPal. Prayer names and comments are not sent when creating payments. PayPal collects payer information on its hosted checkout and returns payment results and available payer details.

* Terms: https://www.paypal.com/legalhub/paypal/useragreement-full
* Privacy: https://www.paypal.com/legalhub/paypal/privacy-full
* API documentation: https://developer.paypal.com/api/get-started/
* Webhooks: https://developer.paypal.com/api/rest/webhooks/rest/

= Personal data and email =

The site stores prayer names/comments, donor email/name, WordPress account ownership, service selections, amounts, payment identifiers/statuses, available billing/contact details, timestamps, subscriptions, refunds and action history in its own database. Card numbers and security codes are not stored. Campaign reports publish aggregates only.

Emails use wp_mail and can include names, comments and payer details. Empty recipient settings disable staff notifications, without an admin-email fallback. Failed notifications retry via WP-Cron. Acceptance does not guarantee Inbox delivery; a crash after acceptance can cause a retry.

The plugin author receives no requests, keys, telemetry or payments. Site owners must restrict access, define retention and document processing in their privacy policy. Deactivation and uninstall do not automatically delete records or settings.

= Source code and licenses =

Readable sources and GPL-compatible licenses are included in third-party-notices.txt. Code/prayer slips are GPL-2.0-or-later. No build or runtime code download is required.

Source: https://github.com/VAtapin/kloster-prayer/tree/main/georg-kloster-orthodox-donations

== Installation ==

1. Back up the site and database before updating.
2. Upload the ZIP using Plugins > Add New > Upload Plugin and activate Georg Kloster – Orthodox Donations.
3. Open Donations > Settings. Enable and configure only the payment providers you intend to use.
4. Follow the translated Stripe/PayPal setup guides in settings. Register each exact HTTPS webhook URL shown there and save its signing secret or Webhook ID.
5. Create pages using the shortcodes below. Donations > Help > Shortcodes explains all parameters and provides copy buttons.
6. Test provider test/Sandbox payments, recurring billing, cancellation and refunds before enabling Live. Exclude personalized history/support pages from external full-page caches.

= Payment setup =

Stripe API keys: https://dashboard.stripe.com/apikeys
Stripe webhook endpoints: https://dashboard.stripe.com/webhooks
Use snapshot events for your account, with matching test/live credentials. Thin events are not supported.

PayPal REST apps: https://developer.paypal.com/dashboard/applications/
Use a matching Client ID, Secret, Webhook ID and Sandbox/Live environment. For testing, use a Sandbox Business seller and a separate Personal buyer. PayPal Webhooks Simulator events are not a substitute for a real Sandbox checkout.

One-time events:
Stripe: checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, charge.refunded.
PayPal: PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.REFUNDED.

Additional recurring events:
Stripe: invoice.payment_succeeded, invoice.payment_failed, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted.
PayPal: PAYMENT.SALE.COMPLETED, PAYMENT.SALE.REFUNDED, BILLING.SUBSCRIPTION.CREATED, BILLING.SUBSCRIPTION.ACTIVATED, BILLING.SUBSCRIPTION.CANCELLED, BILLING.SUBSCRIPTION.EXPIRED, BILLING.SUBSCRIPTION.SUSPENDED, BILLING.SUBSCRIPTION.PAYMENT.FAILED.

Only verified payment events count as income; approval and return URLs do not. Cancellation stops future billing after the provider acknowledges it and does not refund previous payments. Closing a campaign prevents new payments/agreements but does not cancel existing agreements.

WP-Cron prepares new PayPal plans in the background; retry shortly if requested. Empty secret fields retain saved values; replace them when changing account/environment.

= Shortcodes =

* [gkod_form] - active general prayer form; optional id or slug selects another form.
* [gkod_prayer_form slug="gebetsanliegen"] - alternate name for gkod_form.
* [gkod_service slug="altar-candle"] - form for one active service.
* [gkod_donation] - one-time donation without prayer names/services.
* [gkod_payment_result] - result block; standard forms already include it.
* [gkod_campaigns] - public campaign list; id="1" selects one campaign.
* [gkod_campaign_report id="1"] - public financial totals for a campaign.
* [gkod_donation_history] - private donor history, another-donation link, optional account registration and recurring-support cancellation.
* [gkod_support] - guest or signed-in monthly/yearly support with explicit consent.

Donation/support forms accept campaign_id="1" to allocate payments to an open, unexpired campaign. Amounts are EUR 0.50-999,999.99 with up to two decimal places. Confirmed partial refunds reduce received totals; fully refunded, failed and pending payments are excluded. Expenses are entered separately by staff with manage_options.

Layouts: standard, compact, wide, two_columns, cards, minimal. header="no" hides supported headings. Without lang, WordPress/the language plugin selects the language. The admin guide lists parameters/defaults. Editors preserve other translations. After payment, the confirmation email contains a reusable private link to the donor cabinet. It shows donations made with that email and allows recurring support to be canceled without registration or login. Donors can optionally register a WordPress account with the same email; linking it disables the personal link. Exclude the private cabinet page from full-page/CDN caching.

The **Donors** admin page groups donations by email and reads billing contact details from the existing payment records. Staff can preview a personalized PDF, send a test copy to the configured test address, and manually send the letter to a donor; donor letters are never sent automatically. Configure the background and drag the text fields under **Donations → Thank-you letter**. PDF, JPEG and PNG backgrounds are supported. PDF backgrounds require the server ImageMagick extension with PDF/Ghostscript support; JPEG/PNG backgrounds do not. The editor includes a sample preview and sample test email that do not mark a real donation as thanked.

== Frequently Asked Questions ==

= Can I enable only one payment provider? =
Yes. Disabled providers do not require credentials.

= Does setup diagnostics test a real payment? =
No. It checks configuration availability. Test real provider test/Sandbox transactions before enabling Live.

= Does deleting the plugin erase payment history? =
No. Records and settings are retained. Site owners must manage retention and backups.

= What happens when the site's domain changes? =
Update provider webhook/return URLs after moving. An API-domain override must serve the same WordPress site over HTTPS.

== Screenshots ==

1. Russian donation form: EUR amount and Stripe/PayPal choice.
2. Prayer names, selected services and calculated donation.
3. Campaign income, expenses, balance and donation form.
4. Monthly/yearly support with explicit consent.
5. WordPress campaign editor and expense records.
6. WordPress support table and cancellation controls.

== Changelog ==

= 1.0.8 =
* Fix donation form submission on pages without the theme header or footer.

= 1.0.7 =
* Add the donor directory and payment history in WordPress administration.
* Add reusable thank-you letter templates and personalized PDF emails.
* Include complete RU/DE/UK/PL interface imports for the new strings.

= 1.0.6 =
* Add campaign images, rich text descriptions, compact campaign cards and suggested donation amounts.
* Improve expense editing and reporting, campaign image selection, minimum donation validation and translations.

= 1.0.5 =
* Remove the translation loader call and escape translated admin output.

= 1.0.4 =
* Match text domain and package paths to the assigned WordPress.org slug.

= 1.0.3 =
* Prepare the independent plugin's directory readme, metadata and reproducible package checks.

= 1.0.2 =
* Rename the plugin and menu to Donations with WordPress translations.

= 1.0.1 =
* Isolate PHP classes, nine shortcodes, assets, settings and callbacks; verify coexistence with the previous plugin.

= 1.0.0 =
* Introduce native WordPress administration and language-aware editors for the new plugin series.
