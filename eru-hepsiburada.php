<?php
/**
 * Plugin Name: Erusoft WooCommerce Hepsiburada Eklentisi
 * Description: Hepsiburada pazaryeri sipariş entegrasyonu — düşen siparişleri 5 dakikalık cron ile İZOLE olarak WooCommerce'e içeri alır; mağazanızın stok/ERP/kargo akışını tetiklemez.
 * Version: 1.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Requires Plugins: woocommerce
 * Author: EruSoft
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: eru-hepsiburada
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ERU_HEPSIBURADA_VERSION')) {
    define('ERU_HEPSIBURADA_VERSION', '1.0.0');
}
if (!defined('ERU_HEPSIBURADA_DB_VERSION')) {
    define('ERU_HEPSIBURADA_DB_VERSION', '1.0.0');
}
if (!defined('ERU_HEPSIBURADA_PLUGIN_FILE')) {
    define('ERU_HEPSIBURADA_PLUGIN_FILE', __FILE__);
}
if (!defined('ERU_HEPSIBURADA_PLUGIN_DIR')) {
    define('ERU_HEPSIBURADA_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

// Sabitler
define('ERU_HEPSIBURADA_OPTION_KEY', 'eru_hepsiburada_settings');
define('ERU_HEPSIBURADA_CRON_HOOK', 'eru_hepsiburada_sync_orders');
define('ERU_HEPSIBURADA_CRON_INTERVAL', 'eru_hepsiburada_five_minutes');
// wc- önekiyle ≤20 karakter: wc-hepsiburada-yeni(19) / -hazir(20) / -iptal(20)
define('ERU_HEPSIBURADA_STATUS', 'hepsiburada-yeni');
define('ERU_HEPSIBURADA_API_LIVE', 'https://oms-external.hepsiburada.com');
define('ERU_HEPSIBURADA_API_SIT', 'https://oms-external-sit.hepsiburada.com');

require_once ERU_HEPSIBURADA_PLUGIN_DIR . 'includes/class-eru-hepsiburada-installer.php';
require_once ERU_HEPSIBURADA_PLUGIN_DIR . 'includes/class-eru-hepsiburada-logger.php';

// Task 2-4'te eklenecek dosyalar — var olanlar yüklenir (kademeli geliştirme sırasında fatal önler).
foreach (array(
    'includes/class-eru-hepsiburada-client.php',
    'includes/class-eru-hepsiburada-importer.php',
    'includes/class-eru-hepsiburada-settings.php',
    'includes/class-eru-hepsiburada-order-meta-box.php',
    'includes/class-eru-hepsiburada.php',
) as $eru_hb_inc) {
    if (file_exists(ERU_HEPSIBURADA_PLUGIN_DIR . $eru_hb_inc)) {
        require_once ERU_HEPSIBURADA_PLUGIN_DIR . $eru_hb_inc;
    }
}
unset($eru_hb_inc);

// HPOS uyumu
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            ERU_HEPSIBURADA_PLUGIN_FILE,
            true
        );
    }
});

// Özel cron aralığı (5 dk)
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules[ERU_HEPSIBURADA_CRON_INTERVAL])) {
        $schedules[ERU_HEPSIBURADA_CRON_INTERVAL] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __('Her 5 Dakika (Hepsiburada)', 'eru-hepsiburada'),
        );
    }
    return $schedules;
});

// Aktivasyon / deaktivasyon
register_activation_hook(__FILE__, 'eru_hepsiburada_activate');
register_deactivation_hook(__FILE__, 'eru_hepsiburada_deactivate');

function eru_hepsiburada_activate() {
    Eru_Hepsiburada_Installer::install();
    if (!wp_next_scheduled(ERU_HEPSIBURADA_CRON_HOOK)) {
        wp_schedule_event(time() + 60, ERU_HEPSIBURADA_CRON_INTERVAL, ERU_HEPSIBURADA_CRON_HOOK);
    }
}

function eru_hepsiburada_deactivate() {
    wp_clear_scheduled_hook(ERU_HEPSIBURADA_CRON_HOOK);
}

// Üretimde dosya güncellemesi sonrası şema migrasyonu
add_action('plugins_loaded', array('Eru_Hepsiburada_Installer', 'install'));

// Ana sınıfı başlat
add_action('plugins_loaded', function () {
    if (class_exists('Eru_Hepsiburada')) {
        Eru_Hepsiburada::get_instance();
    }
}, 11);
