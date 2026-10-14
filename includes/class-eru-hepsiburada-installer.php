<?php
/**
 * Hepsiburada kurulum/migrasyon.
 * - eru_hepsiburada_orders : içeri alınan siparişlerin dedupe + durum kaydı (orderNumber bazlı)
 * - eru_hepsiburada_log    : cron/işlem logları
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Hepsiburada_Installer {

    /** Şema sürümü; şema değişince artır + update_to_X_Y_Z() ekle. */
    private static $db_version = '1.0.0';

    public static function install() {
        $installed = get_option('eru_hepsiburada_db_version');

        if (!$installed) {
            self::initial_setup();
            return;
        }
        self::maybe_update($installed);
    }

    private static function initial_setup() {
        if (false === get_option(ERU_HEPSIBURADA_OPTION_KEY)) {
            add_option(ERU_HEPSIBURADA_OPTION_KEY, self::default_settings());
        }
        self::create_tables();
        add_option('eru_hepsiburada_db_version', self::$db_version);
    }

    private static function maybe_update($installed_version) {
        if (version_compare($installed_version, self::$db_version, '<')) {
            // Migrasyon merdiveni (ilk sürümde adım yok):
            self::create_tables(); // dbDelta idempotent
            update_option('eru_hepsiburada_db_version', self::$db_version);
        }
    }

    public static function default_settings() {
        return array(
            'enabled'          => 0,
            // Ortam: 1 = Test (SIT), 0 = Canlı. Cron yalnız 'enabled' açıkken çalışır.
            // Varsayılan TEST — yanlışlıkla canlı uca istek atılmasın.
            'test_mode'        => 1,
            // Kimlikler ortam bazlı AYRI tutulur ve BOŞ gelir; WooCommerce > Hepsiburada
            // ekranından girilir. Basic auth kullanıcı adı = merchant_id.
            'live_merchant_id' => '',
            'live_password'    => '', // Servis Anahtarı (canlı)
            'test_merchant_id' => '',
            'test_password'    => '', // Secret key (SIT)
            'user_agent'       => '', // HB Developer Username — zorunlu User-Agent header'ı
            'window_days'      => 3,  // ana uç tarama penceresi (gün)
        );
    }

    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $orders = $wpdb->prefix . 'eru_hepsiburada_orders';
        $log    = $wpdb->prefix . 'eru_hepsiburada_log';

        $sql_orders = "CREATE TABLE $orders (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_number varchar(64) NOT NULL,
            hb_order_id varchar(64) DEFAULT NULL,
            wc_order_id bigint(20) DEFAULT NULL,
            status varchar(32) DEFAULT 'imported',
            review tinyint(1) NOT NULL DEFAULT 0,
            error text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_number (order_number),
            KEY wc_order_id (wc_order_id),
            KEY status (status)
        ) $charset_collate;";

        $sql_log = "CREATE TABLE $log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(16) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_orders);
        dbDelta($sql_log);
    }
}
