# Состояние проекта

## Текущее состояние
- Независимый плагин 1.0.1: «Приход — записки, требы и пожертвования»; английское имя Parish — Prayer Requests, Services and Donations. Slug, папка, PHP-файл и text domain: georg-kloster-orthodox-donate.
- PHP 8.4 / WordPress 7.1; main → origin/main; репозиторий https://github.com/VAtapin/kloster-prayer.git не переименован.
- Проверенный ZIP: output/georg-kloster-orthodox-donate-1.0.1.zip, 374 файла. Новые tags/releases не создавались; прежний tag 1.0.0 не изменён.
- Владелец устанавливает ZIP через WordPress самостоятельно. Production не обновлялся. Автоматизация ожидания 1.2.13 приостановлена.

## Реализовано и решения
- Стандартные формы и кнопки WordPress; сборы, расходы и регулярная поддержка используют WP_List_Table, пагинацию и штатное пустое состояние. Список сборов отделён от редактора.
- UI использует WordPress gettext; редакторы форм, треб и сборов показывают один текущий язык WPML/Polylang, без языкового плагина — язык WordPress. Собственный переключатель справки удалён. Сохранение одного языка не стирает остальные переводы новой установки.
- 360 английских msgid, полные RU/DE/UK/PL PO/MO. Имя плагина переводится штатным WordPress loader. Примеры шорткодов не принуждают язык страницы.
- По решению владельца совместимость и импорт прежних записок, платежей и переводов не нужны. Новые таблицы, настройки, actions/nonce/cron hooks имеют префикс gkod_; webhook-маршруты используют новый slug. Старые данные не импортируются и автоматически не удаляются. Старый плагин может оставаться включённым. namespace GeorgKlosterOrthodoxDonate, девять шорткодов gkod_*, CSS/DOM selectors, JavaScript globals и параметры результата оплаты также независимы. Оплату нового плагина настроить отдельно и зарегистрировать его webhook-адреса.
- Работают записки/требы и пожертвования при подаче, отдельные разовые пожертвования, целевые сборы/расходы/агрегированные публичные отчёты, приватная история, регулярная поддержка Stripe/PayPal и отмена. Сервер проверяет подписанные события, суммы/валюты/привязку, идемпотентность и права.
- Настройки содержат пошаговую помощь Stripe/PayPal; помощь — все 9 шорткодов с параметрами и копируемыми примерами. Исправленные admin.php?page=… ссылки формируются ядром WordPress.
- PDF-бланки, архив, комментарии, CSV и уведомления работают. Лицензии включены в ZIP. Frontend build не требуется.

## Проверки
- plugin-coexistence в обоих порядках загрузки: оба реальных плагина активны при старте WordPress, раздельные классы/таблицы/настройки/шорткоды/меню/webhook/AJAX — PASS. Browser: две реальные формы и оба скрипта на одной странице отправляют только собственную форму, без дублей — PASS.
- PHP 8.4 lint: 50 собственных файлов/тестов. i18n, api-domain, payment-binding, notifications, order-workflow, admin-comments/admin-behavior, paypal-help/stripe-help — PASS.
- На отдельных копиях WordPress 7.1/SQLite: fresh-install, donations/campaigns/subscriptions, native-admin, admin-navigation, shortcode-help — PASS. HTTP/почта перехвачены, реальных платежей/писем нет.
- Из ZIP: fresh-install, donations/campaigns/native-admin — PASS. Проверены отдельные таблицы/настройки, отсутствие импорта, перевод имени, один язык RU/DE, сохранение переводов новой установки, списки и nonce отмены.
- Браузер: native-admin, shortcode-help, stripe-help, donations, names, support — PASS: CSS WordPress, таблицы, копирование, мобильная ширина. Редакторы/таблицы просмотрены визуально.
- smoke, pdf-notes --render, pdf-archive --render — PASS.
- Plugin Check 2.1.0 с runtime preparation: 0 ошибок, 254 предупреждения без исключения категорий/файлов. 133 direct-query и 120 no-caching для собственных таблиц; 1 bundled gettext loader для ручного ZIP. Подробности: docs/WORDPRESS-REVIEW.md.
- ZIP: версии/папка, 374 файла, целостность и соответствие исходникам — PASS. Diff/check/stat проверены.

## Ограничения и следующие шаги
- Установить ZIP как отдельный плагин, настроить Stripe/PayPal и webhook заново, проверить WPML на тестовом сайте. В локальных проверках официальные языковые hooks симулируются; полноценные установки WPML/Polylang не тестировались.
- MySQL/Plesk, реальные Sandbox/Live операции и SMTP не проверялись. Регулярной поддержке нужны WP-Cron и дополнительные webhook-события; приватные страницы исключить из внешнего кеша.
- Slug и приём в WordPress.org утверждает Plugins Team; Plugin Check не равен одобрению каталога.

## Последний связанный commit
- Текущая задача: Isolate Parish plugin from legacy prayer plugin (этот commit).
- Предыдущий: cd8124a2633c2e147b1852bd6881cb302a029e72 — Rebuild native WordPress admin as Parish 1.0.0.
