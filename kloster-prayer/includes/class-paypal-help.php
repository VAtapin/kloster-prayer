<?php
declare(strict_types=1);
namespace KlosterPrayer;

final class PayPal_Help
{
    public static function render(): void
    {
        echo '<details class="klp-paypal-help"><summary>'.esc_html__('Как подключить PayPal — пошаговая инструкция','kloster-prayer').'</summary>';
        echo '<p>'.esc_html__('Для реальных платежей нужен PayPal Business аккаунт получателя. Email, пароль аккаунта, App ID и Stripe whsec не заменяют ключи PayPal. Плагин использует REST API Orders v2, а не старые кнопки оплаты по email или IPN.','kloster-prayer').'</p><ol>';
        self::step(__('Откройте Apps & Credentials в PayPal Developer Dashboard и войдите в аккаунт получателя. Выберите Sandbox для проверки или Live для настоящих платежей.','kloster-prayer'));
        echo '<li>';self::link('https://developer.paypal.com/dashboard/applications/',__('Открыть Apps & Credentials','kloster-prayer'));echo '</li>';
        self::step(__('Откройте своё REST API приложение или создайте его через Create App. Для Sandbox выберите тестовый Business аккаунт продавца; для Live используйте приложение нужного реального получателя.','kloster-prayer'));
        self::step(__('На странице приложения скопируйте Client ID и раскройте Secret. Вставьте их в одноимённые поля плагина. Выберите тот же режим Live или Sandbox. Все три значения — Client ID, Secret и Webhook ID — должны принадлежать одному приложению и одному режиму.','kloster-prayer'));
        self::step(__('На странице этого же приложения найдите Webhooks и нажмите Add Webhook. Вставьте точный Webhook URL из настроек ниже. Адрес должен быть публичным HTTPS на порту 443, без пароля, блокировки POST и промежуточной страницы защиты.','kloster-prayer'));
        echo '<li><code>'.esc_html(Payments::api_url('paypal/webhook')).'</code></li>';
        self::step(__('Выберите PAYMENT.CAPTURE.COMPLETED и PAYMENT.CAPTURE.REFUNDED и сохраните webhook. Первое событие подтверждает оплату, второе отмечает возврат. Выбирать все события, CHECKOUT.ORDER.APPROVED или IPN для этой интеграции не нужно.','kloster-prayer'));
        self::step(__('Скопируйте ID созданного webhook из раздела Webhooks в поле Webhook ID плагина. Это не Client ID и не ID отдельного события WH-…. Поставьте «Включить PayPal» и сохраните настройки. Secret после сохранения не показывается: пустое поле оставляет прежний секрет.','kloster-prayer'));
        self::step(__('Для проверки сначала используйте Sandbox: в Testing Tools → Sandbox Accounts найдите отдельный Personal аккаунт покупателя и Business аккаунт продавца. Подайте тестовую записку и оплатите её тестовым покупателем, не своим реальным аккаунтом. Проверьте статус «Оплачено», событие в диагностике и письма при настроенной почте. Sandbox не переводит настоящие деньги.','kloster-prayer'));
        self::step(__('Для запуска замените Client ID, Secret и Webhook ID на значения приложения Live и переключите режим на Live. Плагин хранит один комплект ключей, не два. При смене приложения или режима обязательно введите новый Secret: пустое поле сохраняет старый.','kloster-prayer'));
        echo '</ol><p>'.esc_html__('Webhooks Simulator создаёт искусственные события: PayPal не поддерживает их проверку через verify-webhook-signature, которую использует плагин. Ошибка подписи такого события не доказывает поломку интеграции. Проверяйте реальную Sandbox оплату и доставку события в том же приложении.','kloster-prayer').'</p>';
        echo '<p>'.esc_html__('Если оплата или webhook не работают: проверьте одинаковый режим и приложение у всех ключей, точный URL и доступность HTTPS/POST. При переносе домена обновите webhook в PayPal. Отключённый PayPal настраивать не обязательно. Диагностика наличия ключей не проверяет их действительность в PayPal. Не публикуйте Secret в письмах, скриншотах или переписке.','kloster-prayer').'</p><p>';
        self::link('https://developer.paypal.com/api/get-started/',__('Документация PayPal: аккаунт и ключи','kloster-prayer'));echo ' · ';
        self::link('https://developer.paypal.com/api/rest/webhooks/rest/',__('Документация PayPal: webhook и проверка подписи','kloster-prayer'));
        echo '</p></details>';
    }

    private static function step(string $text): void
    {
        echo '<li>'.esc_html($text).'</li>';
    }

    private static function link(string $url,string $label): void
    {
        echo '<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">'.esc_html($label).'</a>';
    }
}
