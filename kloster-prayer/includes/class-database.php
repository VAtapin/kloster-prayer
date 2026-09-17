<?php
declare(strict_types=1);

namespace KlosterPrayer;

final class Database
{
    private const DB_VERSION = '1.2.0';

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'klp_' . $name;
    }

    public static function activate(): void
    {
        self::install();
        self::seed();
        flush_rewrite_rules();
    }

    public static function maybe_upgrade(): void
    {
        if (get_option('klp_db_version') !== self::DB_VERSION) {
            self::install();
            self::seed();
            self::update_generic_copy();
        }
    }

    private static function update_generic_copy(): void
    {
        global $wpdb;
        $forms = self::table('forms');
        $wpdb->query($wpdb->prepare("UPDATE {$forms} SET intro_ru=%s WHERE intro_ru=%s", 'Подайте имена для церковной молитвы.', 'Подайте имена для молитвы в нашем монастыре.'));
        $wpdb->query($wpdb->prepare("UPDATE {$forms} SET intro_de=%s WHERE intro_de=%s", 'Reichen Sie Namen für das kirchliche Gebet ein.', 'Reichen Sie Namen für das Gebet in unserem Kloster ein.'));
        $wpdb->query("UPDATE {$forms} SET intro_uk='Подайте імена для церковної молитви.' WHERE intro_uk='' OR intro_uk IS NULL");
        $wpdb->query("UPDATE {$forms} SET intro_pl='Prosimy podać imiona do modlitwy cerkiewnej.' WHERE intro_pl='' OR intro_pl IS NULL");
        $services=self::table('services');
        $translations=['sorokoust'=>['Сорокоуст','Sorokoust'],'moleben'=>['Молебень','Molebień'],'akathist'=>['Акафіст','Akatyst'],'altar-candle'=>['Свічка у вівтар','Świeca do ołtarza'],'panikhida'=>['Панахида','Panichida'],'psalter'=>['Неусипна Псалтир','Nieustające czytanie Psałterza']];
        foreach($translations as $slug=>$titles){$wpdb->query($wpdb->prepare("UPDATE {$services} SET title_uk=%s,title_pl=%s WHERE slug=%s AND (title_uk='' OR title_uk IS NULL)",$titles[0],$titles[1],$slug));}
        $wpdb->query("UPDATE {$forms} SET title_uk=title_ru WHERE title_uk='' OR title_uk IS NULL");
        $wpdb->query("UPDATE {$forms} SET title_pl=title_de WHERE title_pl='' OR title_pl IS NULL");
    }

    private static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $services = self::table('services');
        $forms = self::table('forms');
        $form_services = self::table('form_services');
        $orders = self::table('orders');
        $items = self::table('order_items');
        $events = self::table('payment_events');

        dbDelta("CREATE TABLE {$services} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(100) NOT NULL,
            title_ru varchar(255) NOT NULL,
            title_de varchar(255) NOT NULL,
            title_uk varchar(255) NOT NULL DEFAULT '',
            title_pl varchar(255) NOT NULL DEFAULT '',
            description_ru text NULL,
            description_de text NULL,
            description_uk text NULL,
            description_pl text NULL,
            image_id bigint unsigned NOT NULL DEFAULT 0,
            intention_mode enum('both','health','rest','none') NOT NULL DEFAULT 'both',
            pricing_mode enum('flat','per_person','per_sheet') NOT NULL DEFAULT 'flat',
            price_cents int unsigned NOT NULL DEFAULT 0,
            names_per_sheet smallint unsigned NOT NULL DEFAULT 10,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY slug (slug), KEY active_sort (is_active,sort_order)
        ) {$charset};");

        dbDelta("CREATE TABLE {$forms} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(100) NOT NULL,
            title_ru varchar(255) NOT NULL,
            title_de varchar(255) NOT NULL,
            title_uk varchar(255) NOT NULL DEFAULT '',
            title_pl varchar(255) NOT NULL DEFAULT '',
            intro_ru text NULL,
            intro_de text NULL,
            intro_uk text NULL,
            intro_pl text NULL,
            layout varchar(30) NOT NULL DEFAULT 'standard',
            default_intention enum('health','rest') NOT NULL DEFAULT 'health',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY slug (slug)
        ) {$charset};");

        dbDelta("CREATE TABLE {$form_services} (
            form_id bigint unsigned NOT NULL,
            service_id bigint unsigned NOT NULL,
            sort_order int NOT NULL DEFAULT 0,
            PRIMARY KEY (form_id,service_id), KEY service_id (service_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$orders} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            form_id bigint unsigned NULL,
            language char(2) NOT NULL DEFAULT 'de',
            intention enum('health','rest','none') NOT NULL DEFAULT 'health',
            names longtext NOT NULL,
            note text NULL,
            donor_name varchar(190) NULL,
            donor_email varchar(190) NOT NULL,
            billing_name varchar(190) NULL,
            billing_email varchar(190) NULL,
            billing_phone varchar(80) NULL,
            billing_address text NULL,
            amount_cents int unsigned NOT NULL DEFAULT 0,
            currency char(3) NOT NULL DEFAULT 'EUR',
            payment_status enum('pending','paid','failed','refunded','cancelled') NOT NULL DEFAULT 'pending',
            payment_provider enum('stripe','paypal','none') NOT NULL DEFAULT 'none',
            payment_id varchar(191) NULL,
            return_url text NULL,
            printed_at datetime NULL,
            archived_at datetime NULL,
            confirmation_sent_at datetime NULL,
            created_at datetime NOT NULL,
            paid_at datetime NULL,
            PRIMARY KEY (id), UNIQUE KEY public_id (public_id), UNIQUE KEY provider_payment (payment_provider,payment_id),
            KEY status_created (payment_status,created_at), KEY printed_at (printed_at), KEY form_id (form_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$items} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint unsigned NOT NULL,
            service_id bigint unsigned NOT NULL,
            service_title varchar(255) NOT NULL,
            pricing_mode varchar(20) NOT NULL,
            unit_price_cents int unsigned NOT NULL,
            quantity int unsigned NOT NULL DEFAULT 1,
            sheet_count int unsigned NOT NULL DEFAULT 1,
            subtotal_cents int unsigned NOT NULL,
            remembrance_start date NULL,
            remembrance_end date NULL,
            PRIMARY KEY (id), KEY order_id (order_id), KEY service_id (service_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            provider enum('stripe','paypal') NOT NULL,
            event_id varchar(191) NOT NULL,
            event_type varchar(100) NOT NULL,
            order_id bigint unsigned NULL,
            payload_hash char(64) NOT NULL,
            processed tinyint(1) NOT NULL DEFAULT 0,
            error_message text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY provider_event (provider,event_id), KEY order_id (order_id)
        ) {$charset};");

        $history = self::table('order_history');
        dbDelta("CREATE TABLE {$history} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint unsigned NOT NULL,
            event_type varchar(50) NOT NULL,
            message text NOT NULL,
            user_id bigint unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY order_history (order_id,id)
        ) {$charset};");
        update_option('klp_db_version', self::DB_VERSION, true);
    }

    private static function seed(): void
    {
        global $wpdb;
        $services = self::table('services');
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$services}") > 0) {
            return;
        }

        $now = current_time('mysql');
        $defaults = [
            ['sorokoust', 'Сорокоуст', 'Sorokoust', 'Сорокоуст', 'Sorokoust', 'both', 'per_sheet', 4000, 10],
            ['moleben', 'Молебен', 'Moleben', 'Молебень', 'Molebień', 'health', 'per_sheet', 2000, 10],
            ['akathist', 'Акафист', 'Akathistos', 'Акафіст', 'Akatyst', 'health', 'per_sheet', 1500, 10],
            ['altar-candle', 'Свеча в алтарь', 'Kerze im Altar', 'Свічка у вівтар', 'Świeca do ołtarza', 'both', 'per_person', 3000, 10],
            ['panikhida', 'Панихида', 'Panichida', 'Панахида', 'Panichida', 'rest', 'per_sheet', 2000, 10],
            ['psalter', 'Неусыпаемая Псалтирь', 'Immerwährendes Psaltergedenken', 'Неусипна Псалтир', 'Nieustające czytanie Psałterza', 'both', 'per_sheet', 4000, 10],
        ];
        foreach ($defaults as $index => $row) {
            $wpdb->insert($services, [
                'slug' => $row[0], 'title_ru' => $row[1], 'title_de' => $row[2], 'title_uk' => $row[3], 'title_pl' => $row[4],
                'intention_mode' => $row[5], 'pricing_mode' => $row[6], 'price_cents' => $row[7],
                'names_per_sheet' => $row[8], 'is_active' => 1, 'sort_order' => ($index + 1) * 10,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $forms = self::table('forms');
        $service_rows = $wpdb->get_results("SELECT id,slug FROM {$services}");
        $by_slug = [];
        foreach ($service_rows as $service) $by_slug[$service->slug] = (int) $service->id;
        // IDs 1-5 intentionally match the existing production shortcodes.
        $form_defaults = [
            [1, 'altar-candle', 'Свеча в алтарь', 'Kerze im Altar', 'compact', ['altar-candle']],
            [2, 'gebetsanliegen', 'Молитвенные записки', 'Gebetsanliegen', 'standard', ['sorokoust','moleben','akathist','altar-candle','panikhida','psalter']],
            [3, 'sorokoust', 'Сорокоуст', 'Sorokoust', 'standard', ['sorokoust']],
            [4, 'panikhida', 'Панихида', 'Panichida', 'standard', ['panikhida']],
            [5, 'moleben', 'Молебен', 'Moleben', 'standard', ['moleben']],
        ];
        foreach ($form_defaults as $definition) {
            $wpdb->insert($forms, [
                'id' => $definition[0], 'slug' => $definition[1], 'title_ru' => $definition[2], 'title_de' => $definition[3],
                'intro_ru' => 'Подайте имена для церковной молитвы.',
                'intro_de' => 'Reichen Sie Namen für das kirchliche Gebet ein.',
                'title_uk' => $definition[2], 'title_pl' => $definition[2],
                'intro_uk' => 'Подайте імена для церковної молитви.',
                'intro_pl' => 'Prosimy podać imiona do modlitwy cerkiewnej.',
                'layout' => $definition[4], 'default_intention' => $definition[1] === 'panikhida' ? 'rest' : 'health',
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($definition[5] as $sort => $slug) {
                if (isset($by_slug[$slug])) $wpdb->insert(self::table('form_services'), ['form_id' => $definition[0], 'service_id' => $by_slug[$slug], 'sort_order' => $sort]);
            }
        }
    }
}
