<?php
declare(strict_types=1);

namespace KlosterPrayer;

final class I18n
{
    public static function init(): void
    {
        load_plugin_textdomain('kloster-prayer', false, dirname(plugin_basename(FILE)) . '/languages');
    }

    public static function language(): string
    {
        $locale = determine_locale();
        $locale = strtolower($locale);
        foreach (['ru', 'uk', 'pl'] as $language) if (str_starts_with($locale, $language)) return $language;
        return 'de';
    }

    public static function text(string $key, ?string $language = null): string
    {
        $language ??= self::language();
        $strings = [
            'de' => [
                'health' => 'Für Lebende', 'rest' => 'Für Verstorbene', 'choose' => 'Gebetsanliegen auswählen',
                'names' => 'Namen, jeweils eine Person pro Zeile', 'names_placeholder' => "Vorname\nVorname\nVorname",
                'names_help' => 'Bitte verwenden Sie die Taufnamen. Die Zettel werden automatisch in Gruppen aufgeteilt.',
                'note' => 'Anmerkung (optional)', 'donor_name' => 'Ihr Name (optional)', 'email' => 'E-Mail-Adresse',
                'email_help' => 'Die Bestätigung wird erst nach dem Zahlungseingang versendet.',
                'provider' => 'Zahlungsart', 'stripe' => 'Karte / Stripe', 'paypal' => 'PayPal',
                'total' => 'Spendensumme', 'submit' => 'Gebetsanliegen absenden und spenden',
                'secure' => 'Sichere Zahlung. Ihre Daten werden ausschließlich zur Bearbeitung des Gebetsanliegens verwendet.',
                'required' => 'Bitte füllen Sie alle Pflichtfelder aus.', 'error' => 'Die Anfrage konnte nicht verarbeitet werden. Bitte versuchen Sie es erneut.',
                'thanks' => 'Vielen Dank. Ihre Zahlung wurde bestätigt und Ihr Gebetsanliegen ist bei uns eingegangen.',
                'processing' => 'Vielen Dank. Die Zahlung wird noch bestätigt. Sie erhalten unsere Bestätigung per E-Mail, sobald der Betrag eingegangen ist.',
                'cancelled' => 'Die Zahlung wurde nicht abgeschlossen. Ihr Gebetsanliegen bleibt vorläufig gespeichert.',
                'success_title' => 'Zahlung erfolgreich', 'processing_title' => 'Zahlung wird geprüft',
                'cancelled_title' => 'Zahlung nicht abgeschlossen', 'close' => 'Verstanden',
                'per_person' => 'pro Name', 'per_sheet' => 'je 10 Namen',
            ],
            'ru' => [
                'health' => 'О здравии', 'rest' => 'Об упокоении', 'choose' => 'Выберите требы',
                'names' => 'Имена, по одному человеку в строке', 'names_placeholder' => "Имя\nИмя\nИмя",
                'names_help' => 'Укажите крестильные имена. Записки автоматически разделяются на группы.',
                'note' => 'Пояснение (необязательно)', 'donor_name' => 'Ваше имя (необязательно)', 'email' => 'Электронная почта',
                'email_help' => 'Подтверждение будет отправлено только после получения оплаты.',
                'provider' => 'Способ оплаты', 'stripe' => 'Карта / Stripe', 'paypal' => 'PayPal',
                'total' => 'Сумма пожертвования', 'submit' => 'Подать записку и пожертвовать',
                'secure' => 'Безопасная оплата. Данные используются только для обработки молитвенной записки.',
                'required' => 'Заполните обязательные поля.', 'error' => 'Не удалось обработать запрос. Попробуйте ещё раз.',
                'thanks' => 'Благодарим вас. Оплата подтверждена, и записка передана для молитвенного поминовения.',
                'processing' => 'Благодарим вас. Оплата ещё подтверждается. После поступления средств мы отправим подтверждение по электронной почте.',
                'cancelled' => 'Оплата не завершена. Записка временно сохранена.',
                'success_title' => 'Оплата прошла успешно', 'processing_title' => 'Проверяем оплату',
                'cancelled_title' => 'Оплата не завершена', 'close' => 'Понятно',
                'per_person' => 'за имя', 'per_sheet' => 'за 10 имён',
            ],
            'uk' => [
                'health'=>'За здоров’я','rest'=>'За упокій','choose'=>'Оберіть треби','names'=>'Імена, по одній людині в рядку','names_placeholder'=>"Ім’я\nІм’я\nІм’я",'names_help'=>'Вкажіть хресні імена. Записки автоматично розділяються на групи.','note'=>'Пояснення (необов’язково)','donor_name'=>'Ваше ім’я (необов’язково)','email'=>'Електронна пошта','email_help'=>'Підтвердження буде надіслано лише після отримання оплати.','provider'=>'Спосіб оплати','stripe'=>'Картка / Stripe','paypal'=>'PayPal','total'=>'Сума пожертви','submit'=>'Подати записку та пожертвувати','secure'=>'Безпечна оплата. Дані використовуються лише для обробки молитовної записки.','required'=>'Заповніть обов’язкові поля.','error'=>'Не вдалося обробити запит. Спробуйте ще раз.','thanks'=>'Дякуємо. Оплату підтверджено, записку передано для молитовного поминання.','processing'=>'Дякуємо. Оплата ще підтверджується. Після надходження коштів ми надішлемо підтвердження електронною поштою.','cancelled'=>'Оплату не завершено. Записку тимчасово збережено.','success_title'=>'Оплата пройшла успішно','processing_title'=>'Перевіряємо оплату','cancelled_title'=>'Оплату не завершено','close'=>'Зрозуміло','per_person'=>'за ім’я','per_sheet'=>'за 10 імен',
            ],
            'pl' => [
                'health'=>'Za żywych','rest'=>'Za zmarłych','choose'=>'Wybierz intencje','names'=>'Imiona, jedna osoba w każdym wierszu','names_placeholder'=>"Imię\nImię\nImię",'names_help'=>'Prosimy podać imiona chrzcielne. Kartki zostaną automatycznie podzielone na grupy.','note'=>'Uwagi (opcjonalnie)','donor_name'=>'Imię ofiarodawcy (opcjonalnie)','email'=>'Adres e-mail','email_help'=>'Potwierdzenie zostanie wysłane dopiero po otrzymaniu płatności.','provider'=>'Sposób płatności','stripe'=>'Karta / Stripe','paypal'=>'PayPal','total'=>'Kwota darowizny','submit'=>'Prześlij intencję i złóż darowiznę','secure'=>'Bezpieczna płatność. Dane są używane wyłącznie do obsługi intencji modlitewnej.','required'=>'Prosimy wypełnić wymagane pola.','error'=>'Nie udało się przetworzyć zgłoszenia. Spróbuj ponownie.','thanks'=>'Dziękujemy. Płatność została potwierdzona, a intencja przekazana do modlitwy.','processing'=>'Dziękujemy. Płatność jest jeszcze potwierdzana. Po zaksięgowaniu środków wyślemy wiadomość e-mail.','cancelled'=>'Płatność nie została zakończona. Intencja została tymczasowo zapisana.','success_title'=>'Płatność zakończona pomyślnie','processing_title'=>'Sprawdzamy płatność','cancelled_title'=>'Płatność nie została zakończona','close'=>'Rozumiem','per_person'=>'za imię','per_sheet'=>'za 10 imion',
            ],
        ];
        return $strings[$language][$key] ?? $key;
    }
}
