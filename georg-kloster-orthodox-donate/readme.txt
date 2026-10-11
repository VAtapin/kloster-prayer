=== Parish — Prayer Requests, Services and Donations ===
Contributors: atapin
Tags: orthodox, prayer, donations, stripe, paypal
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Orthodox prayer requests and donations with Stripe, PayPal, printable prayer slips, an archive and reports.

== Description ==

Accept Orthodox prayer requests and donations on a parish, church, cathedral or monastery website.
Visitors select services, submit names and optional comments, and pay through the site's own Stripe or PayPal account.

* Configurable services, amounts, forms and six responsive layouts.
* Standalone donations, targeted campaigns, public financial totals and private account history.
* Monthly or yearly support through Stripe/PayPal with explicit consent and account cancellation.
* English interface source strings with Russian, German, Ukrainian and Polish translations.
* Names separated by commas are normalized to one person per line.
* Payment confirmation emails and an optional designated recipient for paid requests.
* Reports, payer details, CSV export, archive and restore.
* Original three-column A4 landscape PDF slips for the living and departed.
* Comments immediately below names in PDF and visible in the administration area.
* Printing archived requests without restoring them.
* Existing shortcodes and webhook paths remain compatible.
* HTTPS API-origin setting for moving the same WordPress site to a new domain.

The plugin requires PHP mbstring and GD for PDF generation. PDF processing happens locally;
there is no remote PDF service. Generating a PDF sets the print marker; it does not verify physical printer output.

= External services: Stripe =

Stripe processes donations when the visitor selects Stripe. The site contacts
https://api.stripe.com/v1/checkout/sessions to create Checkout and, at the administrator's request,
retrieve payer details. It sends the donor email, EUR amount, selected service titles, internal/public
request identifiers and return URLs. Prayer names and comments are not sent in the payment request.
Regular support uses subscription-mode Checkout, /v1/subscriptions for lifecycle/cancellation,
/v1/invoice_payments to identify confirmed invoice payments and /v1/refunds to reconcile paginated refunds.
Recurring requests also send the billing interval and an internal subscription identifier.
Stripe collects card/billing/contact details on its hosted payment page. Signed webhooks return
payment results and available payer details. The site owner supplies the API key and webhook signing secret.

* Terms: https://stripe.com/legal/ssa
* Privacy: https://stripe.com/privacy
* API documentation: https://docs.stripe.com/api/checkout/sessions

= External services: PayPal =

PayPal processes donations when selected. The site contacts https://api-m.paypal.com (Live) or
https://api-m.sandbox.paypal.com (Sandbox): /v1/oauth2/token for authentication,
/v2/checkout/orders for creation/capture, and /v1/notifications/verify-webhook-signature
to verify received events. Data includes REST application credentials for authentication,
EUR amount, service titles, request/payment identifiers and return URLs. Verification sends
the received webhook event, transmission headers and Webhook ID back to PayPal.
Prayer names and comments are not sent when creating the payment.
PayPal collects payer information on its own payment page and returns payment results/identifiers.
This integration uses REST Orders v2, not IPN or legacy email-only payment buttons.
Regular support additionally uses /v1/catalogs/products, /v1/billing/plans and
/v1/billing/subscriptions to provision plans, start support and cancel agreements.
Legacy capture references can be verified through /v2/payments/captures/{capture_id}.
Recurring requests send the EUR amount, billing interval, plan and internal subscription identifiers.

* Terms: https://www.paypal.com/legalhub/paypal/useragreement-full
* Privacy: https://www.paypal.com/legalhub/paypal/privacy-full
* Documentation: https://developer.paypal.com/api/get-started/
* Webhooks: https://developer.paypal.com/api/rest/webhooks/rest/

= Personal data and email =

The site stores prayer names, comments, donor email/name, service selections, amounts,
payment identifiers/statuses, available billing details (name, email, phone and address),
timestamps and action history in its own database. It does not store card numbers or security codes.
Campaigns, separately entered expenses, account ownership, subscription states and confirmed
refund amounts are stored locally. Public campaign reports contain aggregates only.
Emails are sent through wp_mail and the site's mail configuration; they can contain prayer names,
comments and payer details. Only specify an authorized recipient. The plugin author does not
receive requests, keys, telemetry or payments. Site owners must define retention, restrict access
and describe this processing in their site's privacy policy.
Deactivation and uninstall do not automatically erase records or saved settings.

= Source code and licenses =

Readable PHP, JavaScript and CSS sources are included in the ZIP; no frontend build is required.
Source repository: https://github.com/VAtapin/kloster-prayer
third-party-notices.txt lists bundled libraries, fonts and artwork with their licenses.
The plugin code and original prayer-slip artwork are GPL-2.0-or-later.
Bundled libraries retain their own GPL-compatible licenses and notices.

== Installation ==

1. Back up the site and database before updating.
2. Deactivate the previous Kloster plugin before installing this new series. Previous records, translations and payment settings are not imported.
3. Upload the ZIP using Plugins → Add New → Upload Plugin, or install from the directory when available.
4. Activate Parish — Prayer Requests, Services and Donations.
5. Open Parish → Settings; configure only the payment providers you intend to enable, using fresh settings for this installation.
6. Register the exact webhook URL shown for each provider in that provider's dashboard.
7. Insert [kloster_form] into a page and test the full payment flow with test credentials before going Live.

= PayPal setup =

Open https://developer.paypal.com/dashboard/applications/ and select Apps & Credentials.
Choose Sandbox for testing or Live for real payments. Live requires the recipient's PayPal Business account.
Open a REST app or create one with Create App. Copy Client ID and reveal/copy Secret.
In the same app add a webhook using the exact public HTTPS URL shown by the plugin (port 443).
Select PAYMENT.CAPTURE.COMPLETED and PAYMENT.CAPTURE.REFUNDED; copy the saved webhook's ID
to the plugin's Webhook ID field. All three values and the selected mode must belong to the same app/environment.
Enable PayPal and save. An empty Secret keeps the previous one: always replace it when changing app/mode.

Use a Sandbox Business seller and a separate Sandbox Personal buyer from Testing Tools → Sandbox Accounts.
Submit a real Sandbox checkout and inspect the request status, diagnostic event and configured emails.
Mock events from Webhooks Simulator cannot be verified by PayPal's postback signature endpoint,
which this plugin uses. A mock-event signature failure is not a valid end-to-end payment test.
Expand the PayPal setup help in settings for detailed steps and official links.

= Stripe setup =

Expand Stripe setup in payment settings for the complete translated guide and direct Dashboard links.
API keys: https://dashboard.stripe.com/apikeys
Webhooks / Workbench: https://dashboard.stripe.com/webhooks
Choose Your account, Snapshot payloads and Webhook endpoint. Thin events are not supported.
The plugin parent menu is registered before feature submenus so Campaigns and Regular support
open through admin.php?page=... rather than non-existent wp-admin paths.

Obtain the Secret Key for the intended Stripe test or live environment.
Add the exact webhook URL shown in settings, subscribe to checkout.session.completed and charge.refunded,
and copy its endpoint signing secret to Webhook Secret. Keep test and live credentials consistent.
An empty Secret Key/Webhook Secret field preserves its saved value.
Never share secrets in screenshots or support messages.

= Recipient notifications =

Specify one authorized email under recipient notifications. Empty disables these notifications;
the administrator email is not used as a fallback. Only newly confirmed paid requests are queued:
enabling the option does not send historical requests. The donor confirmation is separate.
Failures retry through hourly WP-Cron. Pending notifications use the current configured address.
Mail acceptance does not prove Inbox delivery; a crash after mail acceptance can cause a retry.

= Regular support setup =

Add [kloster_support lang="ru"] to a page; campaign_id="1" optionally allocates support to a campaign.
The donor must sign in, choose a monthly or yearly amount and explicitly consent to repeated payments.
They approve the agreement on the provider's site and cancel it through [kloster_donation_history].
Staff with manage_options can inspect/cancel agreements in Regular support.
Closing a campaign prevents new agreements; existing agreements continue until cancelled.

Keep the existing one-time webhook events and additionally register:
Stripe: invoice.payment_succeeded, invoice.payment_failed, customer.subscription.created,
customer.subscription.updated and customer.subscription.deleted; charge.refunded remains required.
PayPal: PAYMENT.SALE.COMPLETED, PAYMENT.SALE.REFUNDED, BILLING.SUBSCRIPTION.CREATED,
BILLING.SUBSCRIPTION.ACTIVATED, BILLING.SUBSCRIPTION.CANCELLED, BILLING.SUBSCRIPTION.EXPIRED,
BILLING.SUBSCRIPTION.SUSPENDED and BILLING.SUBSCRIPTION.PAYMENT.FAILED.
Agreement activation and return URLs never count as income; only verified payment events do.
Cancellation stops future billing after the provider acknowledges it; it does not refund past payments.

WP-Cron must run. PayPal prepares a plan for a new amount/interval in the background;
when the form reports preparation, retry shortly. Stripe refund pagination is reconciled in the background.
Exclude personalized history/support pages from external full-page caches/CDNs.
Test real provider Sandbox/test payments, renewal events, cancellation and refunds before enabling Live.

= Shortcodes =

Help → Shortcodes in the administration menu documents every shortcode and parameter,
with copy buttons. The guide uses the WordPress admin language; examples omit lang by default.
Content editors show only the current WPML/Polylang language (or WordPress language without them).
Other saved translations are preserved. Campaigns do not require an English title.

[kloster_campaigns lang="ru"] — public campaigns and donation forms.
[kloster_campaigns id="1"] — one published campaign.
[kloster_campaign_report id="1"] — public totals without donor details.
[kloster_donation campaign_id="1"] — donate to an open, unexpired campaign.
[kloster_donation_history] — donations made while signed in to the current account.
Guests and historical payments are never assigned by email. Staff access remains
restricted to manage_options. Campaign expenses are entered separately in Campaigns;
pending, failed and refunded payments are excluded from received totals.
Confirmed partial refunds reduce received amounts rather than removing the whole payment.
Old full-refund states remain excluded; unknown historical refund amounts are not reconstructed.
The schema upgrade adds campaign records and optional ownership/allocation columns
without deleting existing prayer notes or linking old records to users.
Version 1.2.15 adds subscriptions, verified refund records and optional payment-reference columns.

[kloster_support lang="ru"] — signed-in monthly/yearly support with explicit consent.
[kloster_support campaign_id="1"] — regular support allocated to an accepting campaign.
The account history also shows owned support agreements and cancellation controls.

[kloster_donation lang="ru"] — standalone one-time donation without prayer names or services.
Amounts: EUR 0.50–999,999.99; comma or dot decimals, up to two decimal places.
Uses the same enabled Stripe/PayPal providers, verified webhooks and email settings.
Donations appear in the protected request list (type filter), total/donor reports and archive;
they have no service items, remembrance schedule or prayer-slip PDF.

[kloster_form] — general prayer form.
[kloster_form id="2"] — existing compatible form by ID.
[kloster_prayer_form slug="gebetsanliegen"] — form by slug.
[kloster_service slug="altar-candle"] — form for one service.
[kloster_payment_result] — payment-result block; standard forms already display it.

Layouts: standard, compact, wide, two_columns, cards, minimal.
header="no" hides the internal heading. Forms fill the shortcode's available container width.

= Domain changes =

An empty API-domain setting follows WordPress. An override must be an HTTPS origin serving
the same WordPress site; it does not configure DNS, TLS or hosting. It affects webhook and
PayPal return URLs, not the payment provider's API servers. Update provider dashboard URLs
after moving, and keep the old domain reachable while existing payment sessions finish.

== Frequently Asked Questions ==

= Can I use only Stripe? =
Yes. Disabled providers do not require credentials.

= Can I print archived requests? =
Yes. Open the archive and download an individual or selected-request PDF without restoring records.

= Which webhook events are supported? =
Stripe: checkout.session.completed, charge.refunded.
PayPal: PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.REFUNDED.

= Are setup diagnostics a live credentials test? =
No. They check configuration availability, not authentication or an actual payment.

= Does uninstall delete requests? =
No. Payment records and settings are retained to prevent accidental loss.
Manage retention and backups according to your site's policy.

== Changelog ==

= 1.0.0 (new series) =
* Restart the version numbering with native WordPress administration. Migration from the previous installation is outside this version's scope.
* Native WordPress lists for campaigns, expenses and regular support; separate standard campaign editor.
* One current WPML/Polylang content language in campaign, prayer-form and service editors.
* Preserve hidden translations, remove the guide language switcher and let WordPress gettext control admin UI.

= 1.2.17 =
* Complete translated Stripe setup guide with direct Dashboard links, snapshot events and troubleshooting.
* Fix Campaigns and Regular support admin links by registering the parent menu before submenus.

= 1.2.16 =
* Multilingual administrator shortcode guide with parameters, examples and copy controls.

= 1.2.15 =
* Verified Stripe/PayPal monthly/yearly support, explicit consent and owner/staff cancellation.
* Idempotent payment cycles, background PayPal plans, confirmed partial/full refund accounting.
* Compatible schema upgrade, private responsive history and four complete translations.

= 1.2.14 =
* Targeted campaigns with goals, deadlines, statuses and public aggregate reports.
* Separately recorded expenses and private account donation history with ownership checks.

= 1.2.13 =
* Use US English gettext source strings throughout the interface, email, PDF labels and PayPal help.
* Preserve all four prayer-form and donation translations in complete PO/MO catalogs.
* Use English by default on English WordPress installations; keep the saved request language for confirmations.
* Include standalone donations from 1.2.12 without changing webhook paths or stored requests.

= 1.2.12 =
* Add standalone one-time donations with exact EUR amount validation, existing Stripe/PayPal checkout and separate confirmations.
* Include donations in protected lists, archive and overall reports; exclude them from prayer-slip PDFs.

= 1.2.11 =
* Prepare directory metadata, English readme, source and third-party license notices.
* Add compiled translation catalogs and translated plugin metadata.
* Use identifier placeholders and explicit escaping; protect direct PHP entry points.
* Correct dbDelta index formatting without deleting existing records.
* Bind completed Stripe/PayPal payments to the saved request, amount and currency.
* Remove the old monastery-specific logo URL from email markup.

= 1.2.10 =
* Пошаговая помощь по PayPal: аккаунт Business, ключи, режим, Webhook ID, события и Sandbox-проверка с официальными ссылками.

= 1.2.9 =
* Исправлена критическая ошибка при прямом открытии карточки записки.

= 1.2.8 =
* Комментарии к запискам видны под именами в списке и в карточке, включая архив.

= 1.2.7 =
* Комментарий PDF сразу под именами, с переносом длинного текста и сохранением места для даты заказа.

= 1.2.6 =
* PDF отдельной или выбранных записок доступен и после архивирования, без восстановления из архива.
* Печать списка по фильтру сохраняет выбранный режим архива.

= 1.2.5 =
* Настраиваемый email получателя новых оплаченных записок, без отправки администратору по умолчанию.
* Отдельная очередь уведомлений, повторная отправка при ошибке, защита от повторных вебхуков и журнал событий.
* Подтверждение жертвователю и адреса webhook сохранены.

= 1.2.4 =
* Видимый архив над таблицей, переключатель и поиск внутри архива.
* Имена через запятую преобразуются в строки в форме, при приёме заказа и печати старых записок. Составные имена сохраняются.


= 1.2.3 =
* Настраиваемый HTTPS-домен API; пустое значение автоматически следует за WordPress.
* Сохранены REST-пути, подкаталоги и обычные постоянные ссылки; проверка домена перед сохранением.


= 1.2.2 =
* Только на сорокоусте печатается дата заказа, без номера. Пустые бланки остаются без даты.
* Убраны раздел «Поминовение» и редактор сроков. Сохранённые данные не удаляются.
* Сохранена автоматическая отметка печати после формирования PDF без подтверждений.

= 1.2.1 =
* Отметка «Распечатано» устанавливается автоматически после успешного формирования PDF.
* Убраны промежуточное подтверждение повторного PDF и кнопка ручного подтверждения печати.
* Повторное скачивание доступно сразу; пустые бланки не меняют статусы записок.

= 1.2.0 =
* Сроки поминовения отдельно для каждой требы: начало и число календарных дней, включая первый день.
* Раздел «Поминовение»: оплаченные записки на дату, заканчивающиеся, будущие, завершённые и без срока.
* Старым запискам сроки не назначаются автоматически. Для сорокоуста при назначении предлагается 40 дней.
* Архив и восстановление вместо безвозвратного удаления; данные оплаты и треб сохраняются.
* История сроков, архива, подтверждённой печати, формирования PDF, оплаты и отправки писем.
* Перед повторным PDF для распечатанной записки требуется подтверждение. Формирование PDF не ставит отметку фактической печати.

= 1.1.4 =
* Возвращены оригинальные бланки ZDRAVIE/UPOKOI с исходным орнаментом.
* Имена размещаются по координатам исходных строк без накопления смещения.
* Сохранены три колонки, пустые бланки и раздельная печать здравия и упокоения.

= 1.1.3 =
* Векторные PDF-бланки: крупный православный крест, угловой орнамент, красные и чёрные рамки.
* На листе три колонки; разные требы и заказы объединяются, здравие и упокоение печатаются отдельно.
* Незанятые колонки заполняются пустыми бланками. Добавлена печать пустых RU/DE-бланков.
* Имена сверх 15 переносятся на следующий бланк без потери.

= 1.1.2 =
* Шесть встроенных фонов заменены на WebP 768×512: суммарно 189 КБ вместо 11,6 МБ.
* В редакторе треб отображается текущий фон, включая встроенный, с просмотром в новой вкладке.
* Сброс изображения восстанавливает превью стандартного фона. Для медиатеки используются уменьшенные версии WordPress.

= 1.1.1 =
* Полноширинные экраны администрирования и компактные редакторы.
* Стандартные WP_List_Table для форм, треб и записок: поиск, сортировка, пагинация и массовые действия.
* Редакторы показывают только языки, включённые в Polylang/WPML; без них — язык сайта. Скрытые переводы сохраняются.
* Stripe и PayPal расположены в двух панелях; отключённые способы не вызывают ошибок диагностики.
* Кликабельные отчёты и таблица плательщиков; отсутствующие данные обозначены прочерком.
* Получение сохранённых данных плательщика из Stripe для существующей записки; CSV содержит имя, адрес и контакты.
* Внешние ссылки автора и страницы плагина открываются в новой вкладке.

= 1.1.0 =
* Тематические изображения треб, выбор изображения из медиатеки, дополнительные языковые поля.

= 1.0.5 =
* Все шаблоны занимают 100% контейнера; переработан внешний вид и убраны жёстко заданные формулировки.

= 1.0.4 =
* Шесть адаптивных шаблонов формы и полностью универсальный брендинг сайта.

= 1.0.3 =
* Яркие модальные результаты оплаты, HTML-письма, подробные отчёты и двуязычный PDF.
* Stripe сохраняет данные плательщика и показывает конкретную выбранную требу в чеке.

= 1.0.2 =
* Поля отключённых способов оплаты больше не блокируют сохранение настроек.

= 1.0.1 =
* Производственная доработка админки, PDF, экспорта, диагностики и доставки писем.

= 1.0.0 =
* Полная новая реализация для WordPress 7.1 и PHP 8.4.
* Двуязычные формы, новая админка, платежи, письма, PDF и отчеты.
