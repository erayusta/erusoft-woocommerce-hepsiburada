<?php
/**
 * Hepsiburada ayar sayfası (WooCommerce alt menüsü) + kimlik bilgileri.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Hepsiburada_Settings {

    const SLUG  = 'eru-hepsiburada';
    const GROUP = 'eru_hepsiburada_settings_group';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    /** Ayarları varsayılanlarla birleştirip döndürür. */
    public static function get() {
        $saved = get_option(ERU_HEPSIBURADA_OPTION_KEY, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        return wp_parse_args($saved, Eru_Hepsiburada_Installer::default_settings());
    }

    public function register_menu() {
        add_submenu_page(
            'woocommerce',
            __('Hepsiburada Siparişleri', 'eru-hepsiburada'),
            __('Hepsiburada Siparişleri', 'eru-hepsiburada'),
            'manage_woocommerce',
            self::SLUG,
            array($this, 'render_page')
        );
    }

    public function register_settings() {
        register_setting(
            self::GROUP,
            ERU_HEPSIBURADA_OPTION_KEY,
            array('sanitize_callback' => array($this, 'sanitize'))
        );
    }

    public function sanitize($input) {
        $out = self::get(); // mevcut değerlerle başla, kaybetme

        $out['enabled']          = !empty($input['enabled']) ? 1 : 0;
        // Ortam: 'live' → 0 (Canlı), diğer her şey → 1 (Test/SIT).
        $out['test_mode']        = (($input['environment'] ?? 'test') === 'live') ? 0 : 1;
        $out['live_merchant_id'] = sanitize_text_field($input['live_merchant_id'] ?? '');
        $out['test_merchant_id'] = sanitize_text_field($input['test_merchant_id'] ?? '');
        // Parolalar: sanitize_text_field özel karakterleri bozabilir — yalnız trim.
        $out['live_password']    = trim((string) ($input['live_password'] ?? ''));
        $out['test_password']    = trim((string) ($input['test_password'] ?? ''));
        $out['user_agent']       = sanitize_text_field($input['user_agent'] ?? '');
        $out['window_days']      = max(1, min(30, (int) ($input['window_days'] ?? 3)));

        return $out;
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $s = self::get();

        $last_sync   = get_option('eru_hepsiburada_last_sync');
        $last_result = get_option('eru_hepsiburada_last_result');
        $manual_url  = wp_nonce_url(
            admin_url('admin-post.php?action=eru_hepsiburada_manual_sync'),
            'eru_hepsiburada_manual_sync'
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Hepsiburada Siparişleri', 'eru-hepsiburada'); ?></h1>

            <?php if (isset($_GET['eru_hepsiburada_synced'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Manuel çekme tamamlandı. Sonucu aşağıda görebilirsiniz.', 'eru-hepsiburada'); ?></p>
                </div>
            <?php endif; ?>

            <table class="widefat" style="max-width:640px;margin-bottom:16px;">
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e('Aktif ortam', 'eru-hepsiburada'); ?></strong></td>
                        <td>
                            <?php if (empty($s['test_mode'])) : ?>
                                <span style="color:#d63638;font-weight:600;"><?php esc_html_e('CANLI', 'eru-hepsiburada'); ?></span>
                            <?php else : ?>
                                <span style="color:#2271b1;font-weight:600;"><?php esc_html_e('TEST (SIT)', 'eru-hepsiburada'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Son senkron', 'eru-hepsiburada'); ?></strong></td>
                        <td><?php echo $last_sync ? esc_html($last_sync) : '—'; ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Son sonuç', 'eru-hepsiburada'); ?></strong></td>
                        <td><?php echo $last_result ? esc_html($last_result) : '—'; ?></td>
                    </tr>
                </tbody>
            </table>

            <p>
                <a href="<?php echo esc_url($manual_url); ?>" class="button button-secondary">
                    <?php esc_html_e('Şimdi çek', 'eru-hepsiburada'); ?>
                </a>
                <span class="description" style="margin-left:8px;"><?php esc_html_e('Seçili ortamdan hemen çeker ve GERÇEKTEN içeri alır (test ortamının sayım-modunu da bypass eder).', 'eru-hepsiburada'); ?></span>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Otomatik içe aktarım', 'eru-hepsiburada'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(ERU_HEPSIBURADA_OPTION_KEY); ?>[enabled]" value="1" <?php checked($s['enabled'], 1); ?>>
                                <?php esc_html_e('5 dakikalık cron ile siparişleri otomatik çek', 'eru-hepsiburada'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Ortam', 'eru-hepsiburada'); ?></th>
                        <td>
                            <select name="<?php echo esc_attr(ERU_HEPSIBURADA_OPTION_KEY); ?>[environment]">
                                <option value="live" <?php selected($s['test_mode'], 0); ?>><?php esc_html_e('Canlı (oms-external)', 'eru-hepsiburada'); ?></option>
                                <option value="test" <?php selected($s['test_mode'], 1); ?>><?php esc_html_e('Test / SIT (oms-external-sit)', 'eru-hepsiburada'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Seçilen ortamın host\'u ve kimlik bilgileri kullanılır. Test ortamında cron yalnız sayım + örnek payload loglar, içeri almaz (manuel "Şimdi çek" bypass eder).', 'eru-hepsiburada'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Canlı — Mağaza ID', 'eru-hepsiburada'); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr(ERU_HEPSIBURADA_OPTION_KEY); ?>[live_merchant_id]" value="<?php echo esc_attr($s['live_merchant_id']); ?>" autocomplete="off">
                            <p class="description"><?php esc_html_e('GUID formatındaki canlı satıcı kimliği. Basic auth kullanıcı adı olarak da bu kullanılır.', 'eru-hepsiburada'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Canlı — Servis Anahtarı', 'eru-hepsiburada'); ?></th>
                        <td><input type="password" class="regular-text" name="<?php echo esc_attr(ERU_HEPSIBURADA_OPTION_KEY); ?>[live_password]" value="<?php echo esc_attr($s['live_password']); ?>" autocomplete="new-password"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Test (SIT) — Merchant ID', 'eru-hepsiburada'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(ERU_HEPSIBURADA_OPTION_KEY); ?>[test_merchant_id]" value="<?php echo esc_attr($s['test_merchant_id']); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Test (SIT) — Secret Key', 'eru-hepsiburada'); ?></th>
                        <td><input type="password" class="regular-text" name="<?php echo esc_attr(ERU_HEPSIBURADA_OPTION_KEY); ?>[test_password]" value="<?php echo esc_attr($s['test_password']); ?>" autocomplete="new-password"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Developer Username (User-Agent)', 'eru-hepsiburada'); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr(ERU_HEPSIBURADA_OPTION_KEY); ?>[user_agent]" value="<?php echo esc_attr($s['user_agent']); ?>">
                            <p class="description"><?php esc_html_e('Hepsiburada\'nın verdiği geliştirici kullanıcı adı — zorunlu User-Agent header\'ında gönderilir (örn. firmaadi_dev).', 'eru-hepsiburada'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Tarama penceresi (gün)', 'eru-hepsiburada'); ?></th>
                        <td>
                            <input type="number" min="1" max="30" name="<?php echo esc_attr(ERU_HEPSIBURADA_OPTION_KEY); ?>[window_days]" value="<?php echo esc_attr($s['window_days']); ?>">
                            <p class="description"><?php esc_html_e('Cron her turda son N günün siparişlerini tarar (dedupe olduğu için örtüşme güvenlidir).', 'eru-hepsiburada'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e('Son loglar', 'eru-hepsiburada'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:160px;"><?php esc_html_e('Tarih', 'eru-hepsiburada'); ?></th>
                        <th style="width:70px;"><?php esc_html_e('Seviye', 'eru-hepsiburada'); ?></th>
                        <th><?php esc_html_e('Mesaj', 'eru-hepsiburada'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (Eru_Hepsiburada_Logger::recent(30) as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row->created_at); ?></td>
                            <td><?php echo esc_html($row->level); ?></td>
                            <td><?php echo esc_html($row->message); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
