<?php
/**
 * Hepsiburada sipariş ekranı metabox'ı.
 * İçeri alınan HB siparişlerinde sipariş no / TC / VKN / vergi dairesi / kargo firması /
 * teslimat tipi / kargoya son veriliş (dueDate) gibi `_eru_hepsiburada_*` meta'sını gösterir.
 * Salt-okunur. HPOS + klasik dual hook (eru-trendyol pattern).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Hepsiburada_Order_Meta_Box {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('add_meta_boxes_shop_order', array($this, 'register_metabox_classic'), 10, 1);
        add_action('add_meta_boxes_woocommerce_page_wc-orders', array($this, 'register_metabox_hpos'), 10, 1);

        // Sipariş listesi "HB / İncele" rozeti (HPOS + klasik dual hook).
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_review_column'), 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_review_column_hpos'), 10, 2);
        add_filter('manage_edit-shop_order_columns', array($this, 'add_review_column'), 20);
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_review_column_classic'), 10, 2);
    }

    public function register_metabox_classic($post) {
        if (!$post instanceof WP_Post) {
            return;
        }
        $this->register_metabox_for_screen('shop_order', wc_get_order($post->ID));
    }

    public function register_metabox_hpos($order) {
        $screen = wc_get_page_screen_id('shop-order');
        if (!$screen) {
            $screen = 'woocommerce_page_wc-orders';
        }
        $this->register_metabox_for_screen($screen, $order instanceof WC_Order ? $order : null);
    }

    private function register_metabox_for_screen($screen, $order) {
        // Yalnız Hepsiburada kaynaklı siparişlerde göster.
        if (!$order instanceof WC_Order || $order->get_meta('_eru_hepsiburada_source') !== 'hepsiburada') {
            return;
        }
        add_meta_box(
            'eru_hepsiburada_order_metabox',
            __('Hepsiburada Sipariş Bilgisi', 'eru-hepsiburada'),
            array($this, 'render_metabox'),
            $screen,
            'side',
            'default'
        );
    }

    /** Sipariş listesine dar "HB" kolonu ekler (order_status'tan sonra). */
    public function add_review_column($columns) {
        $new = array();
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ('order_status' === $key) {
                $new['eru_hepsiburada'] = __('HB', 'eru-hepsiburada');
            }
        }
        if (!isset($new['eru_hepsiburada'])) {
            $new['eru_hepsiburada'] = __('HB', 'eru-hepsiburada');
        }
        return $new;
    }

    public function render_review_column_hpos($column, $order) {
        if ('eru_hepsiburada' !== $column) {
            return;
        }
        $this->render_review_cell($order instanceof WC_Order ? $order : wc_get_order($order));
    }

    public function render_review_column_classic($column, $post_id) {
        if ('eru_hepsiburada' !== $column) {
            return;
        }
        $this->render_review_cell(wc_get_order($post_id));
    }

    /** HB kaynaklı siparişte rozet basar: incelenecekse kırmızı "İncele", değilse gri "HB". */
    private function render_review_cell($order) {
        if (!$order instanceof WC_Order || $order->get_meta('_eru_hepsiburada_source') !== 'hepsiburada') {
            return;
        }
        if ($order->get_meta('_eru_hepsiburada_review') === 'yes') {
            echo '<mark style="background:#d63638;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;">' . esc_html__('İncele', 'eru-hepsiburada') . '</mark>';
        } else {
            echo '<span style="background:#f0f0f1;color:#50575e;padding:1px 6px;border-radius:3px;font-size:11px;">HB</span>';
        }
    }

    public function render_metabox($post_or_order) {
        $order = ($post_or_order instanceof WP_Post) ? wc_get_order($post_or_order->ID) : $post_or_order;
        if (!$order instanceof WC_Order) {
            echo '<p style="color:#666;">' . esc_html__('Sipariş okunamadı.', 'eru-hepsiburada') . '</p>';
            return;
        }

        $hb_statuses = Eru_Hepsiburada::statuses();
        $hb_slug     = $order->get_status();
        $hb_label    = isset($hb_statuses[$hb_slug]) ? $hb_statuses[$hb_slug] : wc_get_order_status_name($hb_slug);

        $tax_number = (string) $order->get_meta('_eru_hepsiburada_tax_number');
        $review     = $order->get_meta('_eru_hepsiburada_review') === 'yes';
        $delivery   = (string) $order->get_meta('_eru_hepsiburada_delivery_type');
        $delivery_labels = array(
            'StandardDelivery' => __('Standart Teslimat', 'eru-hepsiburada'),
            'BT'               => __('Bugün Teslimat', 'eru-hepsiburada'),
            'YT'               => __('Yarın Teslimat', 'eru-hepsiburada'),
        );

        $rows = array(
            __('Sipariş No', 'eru-hepsiburada')        => (string) $order->get_meta('_eru_hepsiburada_order_number'),
            __('HB Sipariş ID', 'eru-hepsiburada')     => (string) $order->get_meta('_eru_hepsiburada_order_id'),
            __('Pazaryeri Statüsü', 'eru-hepsiburada')          => $hb_label,
            __('Fatura Tipi', 'eru-hepsiburada')       => $tax_number !== '' ? __('Kurumsal (VKN\'li)', 'eru-hepsiburada') : __('Bireysel', 'eru-hepsiburada'),
            __('Firma Adı', 'eru-hepsiburada')         => $order->get_billing_company(),
            __('VKN', 'eru-hepsiburada')               => $tax_number,
            __('Vergi Dairesi', 'eru-hepsiburada')     => (string) $order->get_meta('_eru_hepsiburada_tax_office'),
            __('TC Kimlik No', 'eru-hepsiburada')      => (string) $order->get_meta('_eru_hepsiburada_identity_number'),
            __('Kargo Firması', 'eru-hepsiburada')     => (string) $order->get_meta('_eru_hepsiburada_cargo_company'),
            __('Teslimat Tipi', 'eru-hepsiburada')     => isset($delivery_labels[$delivery]) ? $delivery_labels[$delivery] : $delivery,
            __('Kargoya Son Veriliş', 'eru-hepsiburada') => (string) $order->get_meta('_eru_hepsiburada_due_date'),
        );
        ?>
        <?php if ($review) : ?>
            <p style="background:#fcf0f1;border-left:4px solid #d63638;padding:6px 8px;margin:0 0 8px;">
                <strong><?php esc_html_e('İncele:', 'eru-hepsiburada'); ?></strong>
                <?php esc_html_e('eşleşmeyen ürün / kısmi iptal / sonradan düşen kalem var. Sipariş notlarını kontrol edin.', 'eru-hepsiburada'); ?>
            </p>
        <?php endif; ?>
        <table class="widefat striped" style="border:0;">
            <tbody>
            <?php foreach ($rows as $label => $value) : ?>
                <?php if ($value === '' || $value === null) { continue; } ?>
                <tr>
                    <td style="font-weight:600;width:42%;"><?php echo esc_html($label); ?></td>
                    <td><?php echo esc_html($value); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:8px;color:#666;">
            <?php esc_html_e('Paketleme ve kargo HB Satıcı Paneli üzerinden yürütülür.', 'eru-hepsiburada'); ?>
        </p>
        <?php
    }
}
