# Состояние проекта

## Текущее состояние
- Подготовлен релиз плагина `1.0.7`: изменения донорского кабинета/списка, шаблонов благодарственных писем и интерфейсов; теги и публикация пока не выполнены.
- Импортные каталоги WordPress.org RU/DE/UK/PL собраны по 536 интерфейсных и 80 readme-строк. Они включают текущие исходные строки и сохранённые ранее оригиналы, чтобы импорт не терял утверждённые переводы.
- В релизный workflow добавлены регрессионные проверки донорских действий, шаблонов благодарственных писем и импортных каталогов.

## Проверки
- Прошли: PHP-синтаксис новых тестов, `tests/donor-actions.php`, `tests/thank-you-template.php`, `tests/notifications.php`, `tests/api-domain.php`, `tests/payment-binding.php`, `tests/i18n.php`, сборка PO→MO, `tests/wordpress-org-translations.py`, сборка двух ZIP и проверка WordPress.org assets.
- `tests/smoke.php` локально не завершился: PHP 8.4 в этой среде не имеет `mbstring`; релизный workflow включает это расширение. WordPress локально не устанавливался.
- GitHub недоступен из-за DNS (`Could not resolve host: github.com`); commit/push, tag, релиз и импорт переводов ожидают соединения.

## Следующий шаг
- Отправить ветку и тег `georg-kloster-orthodox-donations-1.0.7`, дождаться обязательного GitHub Actions workflow и публикации кода, затем импортировать обновлённые переводы RU/DE/UK/PL в translate.wordpress.org и проверить статусы.

## Последний связанный commit
- `5b19c0e` (`Complete donation plugin fixes and audit`); изменения релиза 1.0.7 ещё не закоммичены.
