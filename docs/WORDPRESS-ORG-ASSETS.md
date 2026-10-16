# Материалы страницы WordPress.org

Комплект находится в `wordpressorg-assets/`, отдельно от исходников плагина.
Для передачи подготовлен `output/georg-kloster-orthodox-donations-wordpressorg-assets.zip`.
Он содержит 18 PNG в папке `assets/`.

Первая публикация 1.0.5 выполнена через Git tag (Actions 38924938123, success).
Изображения автоматически публикуются из Git в каталог WordPress.org: workflow
`directory-assets.yml` запускается после push main с изменениями материалов.
Он меняет только assets, без кода, readme, версии или нового release tag.
Production-сайты не обновляются.
Официальные требования: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/

## Состав и язык

- `icon-128x128.png` и `icon-256x256.png`: святой Георгий, записка с православным крестом и свеча.
- `banner-772x250.png` и `banner-1544x500.png`: английский fallback.
- Пары с суффиксами `-ru_RU`, `-de_DE`, `-uk`, `-pl_PL`: локализованные баннеры; WordPress.org выбирает язык автоматически.
- `screenshot-1.png`: разовое пожертвование.
- `screenshot-2.png`: имена, требы и рассчитанное пожертвование.
- `screenshot-3.png`: поступления, расходы и остаток целевого сбора, форма пожертвования.
- `screenshot-4.png`: регулярная поддержка с явным согласием.
- `screenshot-5.png`: редактор сбора и отдельные расходы.
- `screenshot-6.png`: штатная административная таблица регулярной поддержки и отмена.

Русский баннер сохранён побайтно; интерфейс скриншотов русский по решению владельца. Шесть подписей
в разделе Screenshots readme английские. Название монастыря на баннере отсутствует:
плагин работает с платёжными аккаунтами того сайта, который его установил.

Скриншоты получены из текущих PHP-renderers и JavaScript плагина на отдельном
локальном WordPress 7.1.3. WordPress CSS используется для админки. Это демонстрационные
данные в клонированной SQLite-базе; реальных платежей, писем, контактов и ключей нет.
Интерфейс не нарисован imagegen и не изменён графическим редактором.

## Создание и проверка

Использован встроенный imagegen для художественных материалов. Основа — предоставленный
владельцем логотип из Kalendar. Итоговое задание для иконки: сохранить святого Георгия,
зелёный фон и золотую рамку; добавить записку с православным крестом и зажжённую
церковную свечу; исключить монеты, денежные знаки и текст; прозрачность снаружи рамки.
Задание для баннера: сохранить зелёно-золотой стиль первого принятого варианта;
убрать Georg-Kloster; оставить только точные русские строки выше; поместить новую
иконку с запиской и свечой слева. Финальные материалы предоставляются под GPL-2.0-or-later.

Экспорт размеров без изменения композиции:

```powershell
./tools/resize-directory-art.ps1 -IconSource <исходник-иконки> -BannerSource <исходник-баннера>
```

Повторное получение скриншотов требует локального стенда и его русского core language pack:

```text
C:\php\php.exe -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 -d extension=gd tools/render-directory-screenshots.php tmp/wporg-review/wordpress
node tools/capture-directory-screenshots.cjs
python tools/check-directory-assets.py
python tools/build-package.py
```

Браузерный инструмент использует существующий Playwright из соседнего Kalendar
и установленный Edge. Общий bootstrap допускает только disposable review site;
изменяет копию БД, не оригинальный стенд и не production.

## Локализованные надписи

| Locale | Заголовок | Подзаголовок |
| --- | --- | --- |
| en (fallback) | Prayer requests, services and donations | One-time and recurring support |
| ru_RU | Записки, требы и пожертвования | Разовая и регулярная поддержка |
| de_DE | Gebetsanliegen, Gottesdienste und Spenden | Einmalige und regelmäßige Unterstützung |
| uk | Записки, треби та пожертви | Разова та регулярна підтримка |
| pl_PL | Intencje modlitewne, nabożeństwa i darowizny | Jednorazowe i regularne wsparcie |

Built-in imagegen: text-localization существующего баннера; сохранить зелёный фон,
золотую рамку, святого Георгия, записку и свечу; заменить только заголовок и подзаголовок
точными строками таблицы; не добавлять Georg Kloster, монеты и watermark.
Экспорт двух размеров: tools/export-localized-banners.ps1 -Source <PNG> -Locale <locale>.
Проверка: python tools/check-directory-assets.py; actionlint.
