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

    /** Standalone donation strings, following the saved request language. */
    public static function donation_text(string $key, ?string $language = null): string
    {
        $messages = [
            'de' => ['title'=>'Spende für die Kirche', 'submit'=>'Jetzt spenden', 'secure'=>'Sichere Zahlung. Ihre Daten werden nur zur Bearbeitung Ihrer Spende verwendet.', 'thanks'=>'Vielen Dank. Ihre Spende wurde bestätigt.', 'cancelled'=>'Die Zahlung wurde nicht abgeschlossen. Ihre Spende ist noch nicht eingegangen.', 'subject'=>'Vielen Dank für Ihre Spende', 'amount_help'=>'Betrag in EUR: 0,50 bis 999.999,99. Maximal zwei Nachkommastellen.', 'invalid_amount'=>'Bitte geben Sie einen gültigen Betrag zwischen 0,50 und 999.999,99 EUR ein.'],
            'ru' => ['title'=>'Пожертвование на нужды храма', 'submit'=>'Пожертвовать', 'secure'=>'Безопасная оплата. Данные используются только для обработки пожертвования.', 'thanks'=>'Благодарим вас. Ваше пожертвование подтверждено.', 'cancelled'=>'Оплата не завершена. Пожертвование ещё не поступило.', 'subject'=>'Благодарим за ваше пожертвование', 'amount_help'=>'Сумма в EUR: от 0,50 до 999 999,99. Не более двух знаков после запятой.', 'invalid_amount'=>'Укажите корректную сумму от 0,50 до 999 999,99 EUR.'],
            'uk' => ['title'=>'Пожертва на потреби храму', 'submit'=>'Пожертвувати', 'secure'=>'Безпечна оплата. Дані використовуються лише для обробки пожертви.', 'thanks'=>'Дякуємо. Вашу пожертву підтверджено.', 'cancelled'=>'Оплату не завершено. Пожертва ще не надійшла.', 'subject'=>'Дякуємо за вашу пожертву', 'amount_help'=>'Сума в EUR: від 0,50 до 999 999,99. Не більше двох знаків після коми.', 'invalid_amount'=>'Вкажіть коректну суму від 0,50 до 999 999,99 EUR.'],
            'pl' => ['title'=>'Darowizna na potrzeby cerkwi', 'submit'=>'Przekaż darowiznę', 'secure'=>'Bezpieczna płatność. Dane są używane wyłącznie do obsługi darowizny.', 'thanks'=>'Dziękujemy. Twoja darowizna została potwierdzona.', 'cancelled'=>'Płatność nie została zakończona. Darowizna jeszcze nie wpłynęła.', 'subject'=>'Dziękujemy za Twoją darowiznę', 'amount_help'=>'Kwota w EUR: od 0,50 do 999 999,99. Maksymalnie dwa miejsca po przecinku.', 'invalid_amount'=>'Podaj prawidłową kwotę od 0,50 do 999 999,99 EUR.'],
            'en' => ['title'=>'Donation for the church', 'submit'=>'Donate', 'secure'=>'Secure payment. Your information is used only to process your donation.', 'thanks'=>'Thank you. Your donation is confirmed.', 'cancelled'=>'Payment was not completed. Your donation has not been received.', 'subject'=>'Thank you for your donation', 'amount_help'=>'Amount in EUR: 0.50 to 999,999.99. Up to two decimal places.', 'invalid_amount'=>'Enter a valid amount between 0.50 and 999,999.99 EUR.'],
        ];
        return $messages[$language ?? self::language()][$key] ?? $messages['de'][$key] ?? $key;
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
