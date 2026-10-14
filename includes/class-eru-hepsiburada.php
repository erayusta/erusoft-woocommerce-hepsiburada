<?php
/**
 * Hepsiburada ana sınıfı (singleton).
 * - Özel `hepsiburada-yeni` / `hepsiburada-hazir` / `hepsiburada-iptal` sipariş statüleri
 * - 5 dakikalık cron senkronizasyonu (sipariş çekme + iptal taraması)
 * - Manuel "Şimdi çek" (admin-post)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Hepsiburada {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    protected function __construct() {
        // Ayar sayfası + metabox (kademeli geliştirme sırasında henüz yoksa atla)
        if (class_exists('Eru_Hepsiburada_Settings')) {
            Eru_Hepsiburada_Settings::get_instance();
        }
        if (class_exists('Eru_Hepsiburada_Order_Meta_Box')) {
            Eru_Hepsiburada_Order_Meta_Box::get_instance();
        }

        // Özel statüler
        add_action('init', array($this, 'register_status'));
        add_filter('wc_order_statuses', array($this, 'add_status_to_list'));

        // Cron
        add_action(ERU_HEPSIBURADA_CRON_HOOK, array($this, 'cron_sync'));
        add_action('init', array($this, 'maybe_schedule_cron'));

        // Manuel çekme
        add_action('admin_post_eru_hepsiburada_manual_sync', array($this, 'handle_manual_sync'));

        // CRM köprüsü GÜVENLİK AĞI: sipariş İZOLE-DIŞI bir yolla (manuel admin dropdown, toplu işlem,
        // programatik hook) CRM-relevant statüye çekilirse action'ı yine fırlat. isolated_save bu
        // hook'u save boyunca söktüğü için cron/importer yolundaki açık çağrılarla ÇAKIŞMAZ;
        // maybe_fire_crm_sync idempotent (meta guard). Bu fazda dinleyen yok (no-op).
        add_action('woocommerce_order_status_changed', array($this, 'on_status_changed_crm_bridge'), 20, 4);

        // Sipariş KALICI silinince dedupe kaydını da temizle ki sipariş BAŞTAN içeri alınabilsin.
        // Çöpe atma DAHİL DEĞİL — geri yüklemede mükerrer olmasın. HPOS + eski posts yolları kapsanır.
        add_action('woocommerce_before_delete_order', array($this, 'cleanup_dedupe_on_delete'), 10, 1);
        add_action('before_delete_post', array($this, 'cleanup_dedupe_on_delete'), 10, 1);
    }

    protected function __clone() {}
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }

    /**
     * Eklentinin Hepsiburada sipariş statüleri (wc- öneksiz slug => etiket).
     * DİKKAT: wc- önekiyle 20 karakter SINIRI var — yeni slug eklerken kontrol et.
     */
    public static function statuses() {
        return array(
            ERU_HEPSIBURADA_STATUS => _x('Hepsiburada - Yeni', 'Order status', 'eru-hepsiburada'),
            // ERP aktarımı (harici entegrasyonunuz bu statüye çeker).
            'hepsiburada-hazir'   => _x('Hepsiburada - Hazırlanıyor', 'Order status', 'eru-hepsiburada'),
            'hepsiburada-iptal'   => _x('Hepsiburada - İptal', 'Order status', 'eru-hepsiburada'),
        );
    }

    public function register_status() {
        foreach (self::statuses() as $slug => $label) {
            register_post_status('wc-' . $slug, array(
                'label'                     => $label,
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: sipariş sayısı */
                'label_count'               => _n_noop(
                    $label . ' <span class="count">(%s)</span>',
                    $label . ' <span class="count">(%s)</span>',
                    'eru-hepsiburada'
                ),
            ));
        }
    }

    public function add_status_to_list($statuses) {
        $hb  = self::statuses();
        $new = array();
        foreach ($statuses as $key => $label) {
            $new[$key] = $label;
            if ('wc-processing' === $key) {
                foreach ($hb as $slug => $h_label) {
                    $new['wc-' . $slug] = $h_label;
                }
            }
        }
        // 'wc-processing' yoksa sona ekle.
        foreach ($hb as $slug => $h_label) {
            if (!isset($new['wc-' . $slug])) {
                $new['wc-' . $slug] = $h_label;
            }
        }
        return $new;
    }

    public function maybe_schedule_cron() {
        if (!wp_next_scheduled(ERU_HEPSIBURADA_CRON_HOOK)) {
            wp_schedule_event(time() + 60, ERU_HEPSIBURADA_CRON_INTERVAL, ERU_HEPSIBURADA_CRON_HOOK);
        }
    }

    /**
     * Güvenlik ağı: bir HB siparişi İZOLE-DIŞI yolla CRM-relevant statüye geçtiğinde köprü
     * action'ını fırlatır (idempotent — meta guard maybe_fire_crm_sync içinde).
     */
    public function on_status_changed_crm_bridge($order_id, $old_status, $new_status, $order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order || $order->get_meta('_eru_hepsiburada_source') !== 'hepsiburada') {
            return;
        }
        $importer = new Eru_Hepsiburada_Importer();
        $importer->maybe_fire_crm_sync($order, $new_status);
    }

    /**
     * Bir HB siparişi KALICI silindiğinde eru_hepsiburada_orders dedupe satırını siler.
     * Böylece aynı sipariş sonradan SIFIRDAN yeniden içeri alınabilir.
     */
    public function cleanup_dedupe_on_delete($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return; // shop_order değilse dokunma
        }
        if ($order->get_meta('_eru_hepsiburada_source') !== 'hepsiburada') {
            return;
        }

        global $wpdb;
        $table        = $wpdb->prefix . 'eru_hepsiburada_orders';
        $order_number = (string) $order->get_meta('_eru_hepsiburada_order_number');

        $deleted = 0;
        if ($order_number !== '') {
            $deleted = (int) $wpdb->delete($table, array('order_number' => $order_number), array('%s'));
        }
        if ($deleted === 0) {
            $deleted = (int) $wpdb->delete($table, array('wc_order_id' => $order_id), array('%d'));
        }

        if ($deleted > 0) {
            Eru_Hepsiburada_Logger::info(sprintf(
                'Sipariş #%d silindi → dedupe kaydı temizlendi (sipariş no %s). Yeniden çekilebilir.',
                $order_id, $order_number
            ));
        }
    }

    /** Cron callback. */
    public function cron_sync() {
        $settings = Eru_Hepsiburada_Settings::get();
        if (empty($settings['enabled'])) {
            return;
        }
        $this->sync($settings);
    }

    /** Manuel "Şimdi çek" — test modunu bypass eder. */
    public function handle_manual_sync() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Yetkisiz.');
        }
        check_admin_referer('eru_hepsiburada_manual_sync');

        $settings = Eru_Hepsiburada_Settings::get();
        $this->sync($settings, true);

        wp_safe_redirect(add_query_arg(
            array('page' => Eru_Hepsiburada_Settings::SLUG, 'eru_hepsiburada_synced' => 1),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Asıl senkronizasyon: sipariş çekme + gruplama + import + iptal taraması.
     *
     * @param array $settings
     * @param bool  $force Manuel tetiklemede true: enabled ve test_mode bypass edilir.
     */
    public function sync($settings, $force = false) {
        // Eşzamanlı çalışmayı engelle
        if (get_transient('eru_hepsiburada_sync_lock')) {
            return;
        }
        set_transient('eru_hepsiburada_sync_lock', 1, 5 * MINUTE_IN_SECONDS);

        $result_msg = '';
        try {
            $client = new Eru_Hepsiburada_Client($settings);
            if (!$client->is_configured()) {
                $result_msg = 'API kimlik bilgileri eksik.';
                Eru_Hepsiburada_Logger::error($result_msg);
                return;
            }

            $window_days = max(1, min(30, (int) ($settings['window_days'] ?? 3)));
            // Site TZ = Europe/Istanbul; HB tarih formatı "Y-m-d H:i". enddate +1 dk: HB "girilen
            // tarihten ÖNCE" filtreler; dakika hassasiyetinde "şimdi" gönderilince içinde
            // bulunulan dakikada düşen kayıt pencere dışında kalıyor (SIT'te doğrulandı).
            $enddate   = date_i18n('Y-m-d H:i', current_time('timestamp') + MINUTE_IN_SECONDS);
            $begindate = date_i18n('Y-m-d H:i', current_time('timestamp') - $window_days * DAY_IN_SECONDS);

            $items = $client->fetch_orders($begindate, $enddate);
            if (is_wp_error($items)) {
                $result_msg = 'API hatası: ' . $items->get_error_message();
                Eru_Hepsiburada_Logger::error($result_msg, $items->get_error_data());
                return;
            }

            $importer = new Eru_Hepsiburada_Importer();
            $groups   = $importer->group_items($items);
            $count    = count($groups);

            // Test modu: sadece logla, içeri alma. İlk kalemin HAM payload'ı log'a —
            // merchantSKU alan adının canlıda teyidi bu kayıttan yapılır (spec §4).
            if (!empty($settings['test_mode']) && !$force) {
                $sample = !empty($items) ? reset($items) : null;
                $result_msg = sprintf('Test modu: %d sipariş (%d kalem) bulundu (içeri alınmadı).', $count, count($items));
                Eru_Hepsiburada_Logger::info($result_msg, array('ornek_kalem' => $sample));
                return;
            }

            $imported = 0;
            $failed   = 0;
            foreach ($groups as $order_number => $group_items) {
                $res = $importer->import_order($order_number, $group_items);
                if (is_wp_error($res)) {
                    $failed++;
                } else {
                    $imported++;
                }
            }

            // İptal taraması (canlı modda; iptal ucu son 1 aylık veri verir).
            $cancel_msg = $this->scan_cancellations($client, $importer);

            $result_msg = sprintf('%d sipariş tarandı, %d işlendi, %d hata.%s', $count, $imported, $failed, $cancel_msg);
            Eru_Hepsiburada_Logger::info($result_msg);
        } finally {
            delete_transient('eru_hepsiburada_sync_lock');
            update_option('eru_hepsiburada_last_sync', current_time('mysql'));
            if ($result_msg !== '') {
                update_option('eru_hepsiburada_last_result', $result_msg);
            }
        }
    }

    /**
     * İptal taraması: iptal ucundaki kalemleri içeri alınmış siparişlerle eşleştirir.
     *  - Siparişin TÜM kalemleri iptal → hepsiburada-iptal + CRM negatif action.
     *  - KISMİ iptal → otomatik aksiyon YOK; not + review işareti (tek sefer, kalem bazlı guard).
     *
     * @return string Sonuç mesajı eki ('' = iptal yok)
     */
    private function scan_cancellations($client, $importer) {
        // İptal penceresi: son 7 gün (uç zaten en fazla 1 ay verir; dar pencere yeterli —
        // içeri alınmış sipariş 7 günden eski bir iptalle gelmişse zaten önceki turlarda görüldü).
        // enddate +1 dk: dakika sınırı istisnası (bkz. sync() içindeki not).
        $enddate   = date_i18n('Y-m-d H:i', current_time('timestamp') + MINUTE_IN_SECONDS);
        $begindate = date_i18n('Y-m-d H:i', current_time('timestamp') - 7 * DAY_IN_SECONDS);

        $cancelled = $client->fetch_cancelled($begindate, $enddate);
        if (is_wp_error($cancelled)) {
            Eru_Hepsiburada_Logger::error('İptal taraması API hatası: ' . $cancelled->get_error_message(), $cancelled->get_error_data());
            return ' İptal taraması hata verdi.';
        }
        if (empty($cancelled)) {
            return '';
        }

        // orderNumber → iptal edilen lineItemId listesi
        $by_order = array();
        foreach ($cancelled as $c) {
            $no  = isset($c['orderNumber']) ? trim((string) $c['orderNumber']) : '';
            $lid = isset($c['lineItemId']) ? (string) $c['lineItemId'] : '';
            if ($no !== '' && $lid !== '') {
                $by_order[$no][$lid] = $c;
            }
        }
        if (empty($by_order)) {
            return '';
        }

        global $wpdb;
        $table      = $wpdb->prefix . 'eru_hepsiburada_orders';
        $full_count = 0;
        $part_count = 0;

        foreach ($by_order as $order_number => $lines) {
            $wc_order_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT wc_order_id FROM $table WHERE order_number = %s", $order_number
            ));
            if (!$wc_order_id) {
                continue; // içeri alınmamış sipariş — bizi ilgilendirmez
            }
            $order = wc_get_order($wc_order_id);
            if (!$order || $order->get_meta('_eru_hepsiburada_source') !== 'hepsiburada') {
                continue;
            }
            if ($order->get_status() === 'hepsiburada-iptal') {
                continue; // zaten iptal
            }

            $known         = $importer->known_line_item_ids($order);
            $cancelled_ids = array_keys($lines);
            // Eşleşmemiş (custom) kalemli siparişte known eksik olabilir → tam-iptal kararı
            // yalnız TÜM bilinen kalemler iptal listesindeyse VE bilinen kalem sayısı > 0 ise verilir.
            $all_cancelled = !empty($known) && count(array_diff($known, $cancelled_ids)) === 0;

            if ($all_cancelled) {
                $first = reset($lines);
                $importer->update_status_isolated($order, 'hepsiburada-iptal', sprintf(
                    'Hepsiburada: siparişin tüm kalemleri iptal edildi (iptal eden: %s, neden: %s).',
                    (string) ($first['cancelledBy'] ?? '-'),
                    (string) ($first['cancelReasonCode'] ?? '-')
                ));
                $importer->maybe_fire_crm_sync($order, 'hepsiburada-iptal');
                $full_count++;
                Eru_Hepsiburada_Logger::info(sprintf('Sipariş no %s (WC #%d) tam iptal → hepsiburada-iptal.', $order_number, $wc_order_id));
                continue;
            }

            // KISMİ iptal: kalem bazlı tek-sefer not (guard: _eru_hepsiburada_cancel_noted).
            $noted_json = (string) $order->get_meta('_eru_hepsiburada_cancel_noted');
            $noted      = $noted_json !== '' ? (array) json_decode($noted_json, true) : array();
            $fresh      = array_diff(array_intersect($cancelled_ids, $known), $noted);
            if (empty($fresh)) {
                continue;
            }
            foreach ($fresh as $lid) {
                $c = $lines[$lid];
                $order->add_order_note(sprintf(
                    'Hepsiburada KISMİ İPTAL: kalem %s (SKU %s, %d adet) iptal edildi (iptal eden: %s). Sipariş otomatik iptal EDİLMEDİ — kontrol edin.',
                    (string) $lid,
                    (string) ($c['merchantSku'] ?? ($c['sku'] ?? '-')),
                    max(1, (int) ($c['quantity'] ?? 1)),
                    (string) ($c['cancelledBy'] ?? '-')
                ));
                $noted[] = $lid;
            }
            $order->update_meta_data('_eru_hepsiburada_review', 'yes');
            $order->update_meta_data('_eru_hepsiburada_cancel_noted', wp_json_encode(array_values($noted)));
            $order->save();
            $part_count++;
            Eru_Hepsiburada_Logger::info(sprintf('Sipariş no %s (WC #%d) KISMİ iptal — not düşüldü, statüye dokunulmadı.', $order_number, $wc_order_id));
        }

        if ($full_count === 0 && $part_count === 0) {
            return '';
        }
        return sprintf(' İptal: %d tam, %d kısmi.', $full_count, $part_count);
    }
}
