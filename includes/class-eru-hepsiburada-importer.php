<?php
/**
 * Hepsiburada importer — kalem listesini orderNumber'a gruplayıp WooCommerce siparişine dönüştürür.
 *
 * İZOLASYON: sipariş kaydedilirken `woocommerce_new_order` + `woocommerce_order_status_*`
 * dinleyicileri o save boyunca geçici nötrlenir ve hemen geri yüklenir. Böylece CRM stok senkronu,
 * kara liste iptali, risk skorlama, sipariş kodu üreticisi vb. bu siparişlerde ÇALIŞMAZ —
 * ve hiçbir başka eklenti/tema dosyası değiştirilmez. Siparişler özel `hepsiburada-yeni` statüsünde
 * durur; status-transition tabanlı eklentiler (ESD, kargo, havale) zaten tetiklenmez. Stok düşürülmez.
 *
 * NOT: `woocommerce_before/after_order_object_save` hook'ları sökülmez (eru-trendyol ile aynı bilinçli tercih).
 *
 * CRM köprüsü action'ları harici ERP/CRM entegrasyonunuzun dinlemesi içindir; eklenti kendisi dinlemez.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Hepsiburada_Importer {

    /**
     * build_order() içinde biriken sipariş notları — save sonrasına taşınır (ID=0 iken add_order_note sessizce düşer).
     */
    private $pending_notes = array();

    /**
     * HB kalem listesini orderNumber'a gruplar. orderNumber'sız kalemler atlanır (loglanır).
     *
     * @param array $items Ana uçtan gelen düz kalem dizisi
     * @return array [orderNumber => item[]]
     */
    public function group_items($items) {
        $groups = array();
        foreach ((array) $items as $item) {
            $no = isset($item['orderNumber']) ? trim((string) $item['orderNumber']) : '';
            if ($no === '') {
                Eru_Hepsiburada_Logger::error('orderNumber alanı olmayan kalem atlandı.', array('item' => $item));
                continue;
            }
            $groups[$no][] = $item;
        }
        return $groups;
    }

    /**
     * Bir siparişin (orderNumber grubunun) kalemlerini içeri alır.
     * Dedupe: eru_hepsiburada_orders.order_number UNIQUE — kayıtlıysa yeni sipariş AÇILMAZ;
     * gruba sonradan yeni kalem düştüyse mevcut siparişe not + review işareti düşülür.
     *
     * @param string $order_number
     * @param array  $items  Aynı orderNumber'a ait kalemler
     * @return int|WP_Error  WooCommerce sipariş ID'si veya hata
     */
    public function import_order($order_number, $items) {
        global $wpdb;

        $order_number = trim((string) $order_number);
        if ($order_number === '' || empty($items)) {
            return new WP_Error('eru_hepsiburada_empty', 'Sipariş numarası veya kalem listesi boş.');
        }

        $table    = $wpdb->prefix . 'eru_hepsiburada_orders';
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT id, wc_order_id FROM $table WHERE order_number = %s", $order_number)
        );

        if ($existing && !empty($existing->wc_order_id)) {
            $existing_id = (int) $existing->wc_order_id;
            if ($this->order_exists_in_db($existing_id)) {
                // Mevcut sipariş duruyor: yalnız YENİ kalem düştüyse not düş (resend/transfer/parçalı).
                $this->note_new_items($existing_id, $items);
                return $existing_id;
            }
            // Sipariş GERÇEKTEN silinmiş → bayat dedupe satırını temizle, sıfırdan içeri al.
            $wpdb->delete($table, array('id' => (int) $existing->id), array('%d'));
            Eru_Hepsiburada_Logger::info(sprintf(
                'Bayat dedupe temizlendi: sipariş no %s eski WC #%d silinmiş; sıfırdan içeri alınıyor.',
                $order_number, $existing_id
            ));
        }

        try {
            $order = $this->build_order($order_number, $items);
        } catch (\Exception $e) {
            $this->record_failure($order_number, $items, $e->getMessage());
            return new WP_Error('eru_hepsiburada_build_failed', $e->getMessage());
        }

        $review   = $order->get_meta('_eru_hepsiburada_review') === 'yes' ? 1 : 0;
        $order_id = $this->isolated_save($order);

        if (!$order_id) {
            $this->record_failure($order_number, $items, 'Sipariş kaydedilemedi.');
            return new WP_Error('eru_hepsiburada_save_failed', 'Sipariş kaydedilemedi.');
        }

        // Dedupe kaydı — YALNIZ save başarısından sonra (yarım import sonraki turda temiz tekrar denesin).
        $first = reset($items);
        $wpdb->replace(
            $table,
            array(
                'order_number' => $order_number,
                'hb_order_id'  => isset($first['orderId']) ? (string) $first['orderId'] : null,
                'wc_order_id'  => $order_id,
                'status'       => 'imported',
                'review'       => $review,
                'error'        => null,
                'created_at'   => current_time('mysql'),
            ),
            array('%s', '%s', '%d', '%s', '%d', '%s', '%s')
        );

        Eru_Hepsiburada_Logger::info(
            sprintf('Sipariş no %s -> WC #%d içeri alındı (%d kalem)%s.', $order_number, $order_id, count($items), $review ? ' (inceleme gerekli)' : ''),
            array('order_number' => $order_number, 'order_id' => $order_id)
        );

        // build_order'da biriken notları şimdi yaz — sipariş ID'si artık var.
        foreach ($this->pending_notes as $note) {
            $order->add_order_note($note);
        }
        $this->pending_notes = array();

        // CRM köprüsü — sipariş DÜŞTÜĞÜ an POZİTİF. İzole save DIŞINDA, tek sefer (meta guard).
        // Harici entegrasyonlar için doğru anda fırlatılır.
        $this->maybe_fire_crm_sync($order, 'hepsiburada-yeni');

        return $order_id;
    }

    /**
     * Kayıtlı siparişe sonradan düşen YENİ kalemleri (lineItemId bazlı) nota çevirir.
     * Kalem listesi YENİDEN KURULMAZ — sipariş üzerinde manuel/operasyonel değişiklik ezilmez.
     */
    private function note_new_items($wc_order_id, $items) {
        $order = wc_get_order($wc_order_id);
        if (!$order || $order->get_meta('_eru_hepsiburada_source') !== 'hepsiburada') {
            return;
        }

        $known = $this->known_line_item_ids($order);
        // Daha önce not düşülen kalemler de "bilinir" — tekrar tekrar not düşülmesin.
        $noted_json = (string) $order->get_meta('_eru_hepsiburada_noted_lines');
        $noted      = $noted_json !== '' ? (array) json_decode($noted_json, true) : array();

        $fresh = array();
        foreach ($items as $item) {
            $lid = isset($item['id']) ? (string) $item['id'] : '';
            if ($lid === '' || in_array($lid, $known, true) || in_array($lid, $noted, true)) {
                continue;
            }
            $fresh[] = $item;
            $noted[] = $lid;
        }
        if (empty($fresh)) {
            return;
        }

        $names = array();
        foreach ($fresh as $item) {
            $sku     = $this->extract_merchant_sku($item);
            $names[] = ($sku !== '' ? $sku : (string) ($item['sku'] ?? '?'))
                . ' x' . max(1, (int) ($item['quantity'] ?? 1));
        }
        $order->add_order_note(sprintf(
            'Hepsiburada: bu sipariş numarasına sonradan %d yeni kalem düştü (%s). Kalemler otomatik EKLENMEDİ — kontrol edin. (creationReason: %s)',
            count($fresh),
            implode(', ', $names),
            (string) ($fresh[0]['creationReason'] ?? '-')
        ));
        $order->update_meta_data('_eru_hepsiburada_review', 'yes');
        $order->update_meta_data('_eru_hepsiburada_noted_lines', wp_json_encode(array_values($noted)));
        $order->save();

        Eru_Hepsiburada_Logger::info(sprintf(
            'Sipariş no %s: %d yeni kalem tespit edildi, WC #%d üzerine not düşüldü (otomatik eklenmedi).',
            (string) $order->get_meta('_eru_hepsiburada_order_number'), count($fresh), $order->get_id()
        ));
    }

    /**
     * Siparişin kalemlerindeki HB lineItemId listesi.
     *
     * @return string[] lineItemId değerleri
     */
    public function known_line_item_ids($order) {
        $ids = array();
        foreach ($order->get_items() as $item) {
            $lid = (string) $item->get_meta('_eru_hepsiburada_line_item_id');
            if ($lid !== '') {
                $ids[] = $lid;
            }
        }
        return $ids;
    }

    /**
     * Sipariş DB'de gerçekten var mı — doğrudan tablo kontrolü (wc_get_order cache/HPOS ıskasına
     * güvenmeden). HPOS açıksa wc_orders, ayrıca eski/senkron postu için wp_posts kontrol edilir.
     */
    private function order_exists_in_db($order_id) {
        global $wpdb;
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return false;
        }
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $t = $wpdb->prefix . 'wc_orders';
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE id = %d", $order_id)) === $order_id) {
                return true;
            }
        }
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type IN ('shop_order','shop_order_placehold')",
            $order_id
        ));
        return (bool) $exists;
    }

    /**
     * Kalem grubundan (henüz kaydedilmemiş) WC_Order nesnesi kurar.
     */
    private function build_order($order_number, $items) {
        $this->pending_notes = array();
        $order = new WC_Order();
        $first = reset($items);

        $order->set_currency('TRY');
        $order->set_created_via('hepsiburada');
        $order->set_payment_method('hepsiburada');
        $order->set_payment_method_title('Hepsiburada');
        $order->set_customer_note('Hepsiburada pazaryeri siparişi — tahsilat Hepsiburada tarafından yönetilir; paketleme/kargo HB Satıcı Paneli üzerinden yürür.');

        // Sipariş tarihi: HB "2018-12-21T09:34:47" benzeri yerel (Europe/Istanbul) string döner.
        if (!empty($first['orderDate'])) {
            try {
                $local = new WC_DateTime((string) $first['orderDate'], new DateTimeZone(wc_timezone_string()));
                $order->set_date_created($local);
            } catch (Exception $e) {
                // tarih çözülemezse WC "şimdi" ile devam eder
            }
        }

        // --- İşaretleme + izolasyon meta'sı (save'den ÖNCE) ---
        $order->update_meta_data('_eru_hepsiburada_source', 'hepsiburada');
        $order->update_meta_data('_eru_hepsiburada_order_number', $order_number);
        $order->update_meta_data('_eru_hepsiburada_imported_at', current_time('mysql'));
        $order->update_meta_data('code', $order_number);       // sipariş kodu olarak pazaryeri sipariş no'su
        $this->set_meta_if($order, '_eru_hepsiburada_order_id', $first['orderId'] ?? null);

        // --- Fatura / kargo / teslimat meta'sı (ilk kalemden; sipariş genelinde aynı) ---
        $invoice = isset($first['invoice']) && is_array($first['invoice']) ? $first['invoice'] : array();
        $this->set_meta_if($order, '_eru_hepsiburada_identity_number', $invoice['turkishIdentityNumber'] ?? null);
        $this->set_meta_if($order, '_eru_hepsiburada_tax_number', $invoice['taxNumber'] ?? null);
        $this->set_meta_if($order, '_eru_hepsiburada_tax_office', $invoice['taxOffice'] ?? null);
        $this->set_meta_if($order, '_eru_hepsiburada_due_date', $first['dueDate'] ?? null);
        $this->set_meta_if($order, '_eru_hepsiburada_delivery_type', $first['deliveryType'] ?? null);

        $cargo = '';
        if (isset($first['cargoCompanyModel']['name'])) {
            $cargo = (string) $first['cargoCompanyModel']['name'];
        } elseif (isset($first['cargoCompany'])) {
            $cargo = (string) $first['cargoCompany'];
        }
        $this->set_meta_if($order, '_eru_hepsiburada_cargo_company', $cargo);

        // Admin sipariş aramasında bulunabilirlik: orderNumber + HB orderId transaction_id'ye.
        // (HPOS FTS açıkken meta araması bypass edilir; transaction_id her zaman LIKE ile aranır.)
        $search_refs = array($order_number);
        if (!empty($first['orderId'])) {
            $search_refs[] = (string) $first['orderId'];
        }
        $order->set_transaction_id(implode(' / ', array_filter($search_refs)));

        // Ham kalem verisi — alan doğrulama (merchantSKU teyidi) + veri kaybını önleme.
        $order->update_meta_data('_eru_hepsiburada_raw', wp_json_encode($items));

        // --- Adresler ---
        $shipping_addr = isset($first['shippingAddress']) && is_array($first['shippingAddress']) ? $first['shippingAddress'] : array();
        $invoice_addr  = isset($invoice['address']) && is_array($invoice['address']) ? $invoice['address'] : array();

        $billing  = $this->map_address(!empty($invoice_addr) ? $invoice_addr : $shipping_addr, $first);
        $shipping = $this->map_address($shipping_addr, $first);
        // Vergi no'lu (kurumsal) siparişte firma adı fatura adresindeki isimdir.
        if (!empty($invoice['taxNumber']) && !empty($invoice_addr['name'])) {
            $billing['company'] = sanitize_text_field((string) $invoice_addr['name']);
        }

        $order->set_address($billing, 'billing');
        $order->set_address($shipping, 'shipping');

        // --- Satırlar ---
        $needs_review = false;
        $has_custom   = false;
        $tax_acc      = array();
        $order_total  = 0.0;
        foreach ($items as $item) {
            $line_total = 0.0;
            if ($this->add_line($order, $item, $tax_acc, $line_total) === false) {
                $needs_review = true;
            }
            $order_total += $line_total;
            if (!empty($item['isCustomized'])) {
                $has_custom = true;
            }
        }
        if ($needs_review) {
            $order->update_meta_data('_eru_hepsiburada_review', 'yes');
        }
        if ($has_custom) {
            // Notlar save'den sonra yazılır — ID=0 iken add_order_note sessizce düşer.
            $this->pending_notes[] = 'Hepsiburada: siparişte ÖZELLEŞTİRİLEBİLİR ürün var — kalem meta\'sındaki içerik bilgilerini kontrol edin.';
        }

        // --- Toplamlar: HB tutarını koru (mağaza fiyatından yeniden hesaplama yapma) ---
        $order->set_shipping_total(0);
        $order->set_total(wc_format_decimal($order_total));

        // Oran bazlı KDV kalemlerini siparişe ekle + sipariş geneli vergi toplamını yaz.
        $cart_tax = $this->apply_tax_items($order, $tax_acc);
        if ($cart_tax > 0) {
            $order->set_cart_tax(wc_format_decimal($cart_tax));
        }

        $order->set_status(ERU_HEPSIBURADA_STATUS);
        // Notlar save'den sonra yazılır — ID=0 iken add_order_note sessizce düşer.
        $this->pending_notes[] = sprintf('Hepsiburada içe aktarım: sipariş no %s (%d kalem, HB statü: %s)',
            $order_number,
            count($items),
            (string) ($first['status'] ?? '-')
        );

        return $order;
    }

    /**
     * HB kaleminden satıcı stok kodunu (merchantSku) çıkarır — alan adı ortama göre değişebildiği
     * için (merchantSKU / merchantSku / merchantsku) TEK NOKTADAN okunur. Rollout'ta ham payload
     * doğrulanınca gerekirse yalnız burası güncellenir.
     */
    private function extract_merchant_sku($item) {
        foreach (array('merchantSKU', 'merchantSku', 'merchantsku') as $key) {
            if (isset($item[$key]) && trim((string) $item[$key]) !== '') {
                return trim((string) $item[$key]);
            }
        }
        return '';
    }

    /**
     * Tek HB kalemini siparişe ekler. Ürün merchantSku=WC SKU ile eşleşmezse ham satır ekler.
     *
     * @param WC_Order $order
     * @param array    $item        HB kalem verisi
     * @param array    $tax_acc     Oran bazlı KDV akümülatörü (referans)
     * @param float    $line_total  Çıkış: bu satırın KDV dahil toplamı (sipariş toplamı için)
     * @return bool    true: eşleşti, false: eşleşmedi (inceleme gerekli)
     */
    private function add_line($order, $item, &$tax_acc, &$line_total) {
        $merchant_sku = $this->extract_merchant_sku($item);
        $hb_sku       = isset($item['sku']) ? trim((string) $item['sku']) : '';
        $name         = isset($item['name']) ? sanitize_text_field((string) $item['name'])
                        : ($merchant_sku !== '' ? $merchant_sku : 'Hepsiburada ürünü');
        $qty          = isset($item['quantity']) ? max(1, (int) $item['quantity']) : 1;

        // Tutarlar KDV DAHİL: unitPrice.amount birim, totalPrice.amount satır toplamı.
        $unit  = isset($item['unitPrice']['amount']) ? (float) $item['unitPrice']['amount'] : 0.0;
        $total = isset($item['totalPrice']['amount']) ? (float) $item['totalPrice']['amount'] : ($unit * $qty);
        if ($unit <= 0 && $qty > 0) {
            $unit = $total / $qty;
        }
        $subtotal   = $unit * $qty; // indirim öncesi
        $line_total = $total;

        // SKU eşleşmesi: merchantSku = WC SKU (birebir aynı kodlar — kullanıcı teyitli).
        $product_id = 0;
        if ($merchant_sku !== '') {
            $product_id = wc_get_product_id_by_sku($merchant_sku);
        }
        $product = $product_id ? wc_get_product($product_id) : null;
        $matched = ($product instanceof WC_Product);

        // KDV: eşleşen ürünün sınıfından; eşleşmeyen satır kdv20 varsayılır (HB tüketici fiyatı
        // KDV dahildir, mağaza ağırlıklı %20). Matrah (hariç) ve KDV içeriden ayrılır.
        $tax_class = $matched ? $product->get_tax_class() : 'kdv20';
        $rate_info = $this->tax_rate_for_class($tax_class);
        if ($rate_info === null && $tax_class !== 'kdv20') {
            $rate_info = $this->tax_rate_for_class('kdv20');
        }
        $percent       = $rate_info ? (float) $rate_info['percent'] : 0.0;
        $subtotal_excl = $percent > 0 ? $subtotal / (1 + $percent / 100) : $subtotal;
        $total_excl    = $percent > 0 ? $total    / (1 + $percent / 100) : $total;
        $subtotal_tax  = $subtotal - $subtotal_excl;
        $total_tax     = $total - $total_excl;

        $line_item = new WC_Order_Item_Product();
        $line_item->set_quantity($qty);
        $line_item->set_subtotal(wc_format_decimal($subtotal_excl));
        $line_item->set_total(wc_format_decimal($total_excl));

        // KDV'yi orana BAĞLI yaz: skaler set_*_tax değerleri reload'da _line_tax_data'dan ezilir;
        // rate_id'li dizi vermek ŞART (aksi halde reload sonrası kalem KDV'si 0'a düşer).
        if ($rate_info && $percent > 0) {
            $rate_id = (int) $rate_info['id'];
            $line_item->set_taxes(array(
                'total'    => array($rate_id => wc_format_decimal($total_tax)),
                'subtotal' => array($rate_id => wc_format_decimal($subtotal_tax)),
            ));
            if (!isset($tax_acc[$rate_id])) {
                $tax_acc[$rate_id] = array(
                    'percent'  => $percent,
                    'label'    => $rate_info['label'],
                    'total'    => 0.0,
                    'subtotal' => 0.0,
                );
            }
            $tax_acc[$rate_id]['total']    += $total_tax;
            $tax_acc[$rate_id]['subtotal'] += $subtotal_tax;
        } else {
            $line_item->set_taxes(false);
        }

        // HB referansları — iptal eşleştirme (lineItemId) + ileride paketleme fazı için.
        if (!empty($item['id'])) {
            $line_item->add_meta_data('_eru_hepsiburada_line_item_id', (string) $item['id'], true);
        }
        if ($hb_sku !== '') {
            $line_item->add_meta_data('hepsiburada_sku', $hb_sku, true);
        }
        if ($merchant_sku !== '') {
            $line_item->add_meta_data('hepsiburada_merchant_sku', $merchant_sku, true);
        }

        // Komisyon: HB commission.amount TUTAR olarak gelir (Trendyol'daki gibi oran DEĞİL).
        // Oran, ERP çıktısı tutarlılığı için satır toplamından hesaplanır.
        $commission_amount = isset($item['commission']['amount']) ? (float) $item['commission']['amount'] : 0.0;
        if ($commission_amount > 0) {
            $line_item->add_meta_data('_eru_hepsiburada_commission_amount', wc_format_decimal($commission_amount), true);
            if ($total > 0) {
                $line_item->add_meta_data('_eru_hepsiburada_commission_rate', wc_format_decimal($commission_amount / $total * 100), true);
            }
        }

        // Özelleştirilebilir ürün içerikleri (customizedText01..X) kalem meta'sına.
        $customized = array();
        foreach ($item as $key => $value) {
            if (stripos((string) $key, 'customizedText') === 0 && trim((string) $value) !== '') {
                $customized[$key] = (string) $value;
            }
        }
        if (!empty($customized)) {
            $line_item->add_meta_data('_eru_hepsiburada_customized', wp_json_encode($customized), true);
        }

        if ($matched) {
            $line_item->set_product($product);
        } else {
            $line_item->set_name($name);
            $line_item->set_tax_class($tax_class);
            $line_item->add_meta_data('_eru_hepsiburada_unmatched', 'yes', true);
        }

        $order->add_item($line_item);
        return $matched;
    }

    /**
     * Verilen KDV sınıfı için WooCommerce'te tanımlı vergi oranını döndürür.
     * @return array{id:int,percent:float,label:string}|null
     */
    private function tax_rate_for_class($tax_class) {
        static $cache = array();
        $key = (string) $tax_class;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $result = null;
        if (class_exists('WC_Tax')) {
            $rates = WC_Tax::get_rates_for_tax_class($key);
            if (!empty($rates)) {
                $first  = reset($rates);
                $result = array(
                    'id'      => (int) $first->tax_rate_id,
                    'percent' => (float) $first->tax_rate,
                    'label'   => ($first->tax_rate_name !== '' ? $first->tax_rate_name : 'KDV'),
                );
            }
        }
        $cache[$key] = $result;
        return $result;
    }

    /**
     * Biriken oran→KDV toplamlarından siparişe WC_Order_Item_Tax kalemleri ekler ve toplam
     * KDV'yi döndürür. Order-level get_taxes()/get_tax_totals() bu kalemler olmadan boş döner.
     */
    private function apply_tax_items($order, $tax_acc) {
        if (empty($tax_acc) || !is_array($tax_acc)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($tax_acc as $rate_id => $info) {
            $tax_item = new WC_Order_Item_Tax();
            $tax_item->set_rate_id((int) $rate_id);
            $tax_item->set_rate_percent((float) $info['percent']);
            $tax_item->set_label($info['label']);
            $tax_item->set_tax_total(wc_format_decimal($info['total']));
            $tax_item->set_shipping_tax_total(0);
            $order->add_item($tax_item);
            $sum += (float) $info['total'];
        }
        return $sum;
    }

    /**
     * HB adresini WooCommerce adres dizisine çevirir.
     * HB alanları: name (tam ad), addressDetail|address (açık adres), district (MAHALLE),
     * town (İLÇE), city (İL), email, phoneNumber, alternatePhoneNumber, countryCode, postalCode.
     * WooCommerce'de (Hezarfen): state = il plaka anahtarı ("TR34"), city = İLÇE adı.
     */
    private function map_address($addr, $item) {
        // Tam adı ad/soyad'a böl.
        $full = isset($addr['name']) ? trim((string) $addr['name']) : '';
        if ($full === '') {
            $full = isset($item['customerName']) ? trim((string) $item['customerName']) : '';
        }
        $first = $full;
        $last  = '';
        if ($full !== '') {
            $parts = preg_split('/\s+/', $full);
            if (count($parts) > 1) {
                $last  = array_pop($parts);
                $first = implode(' ', $parts);
            }
        }

        $address_1 = '';
        foreach (array('addressDetail', 'address') as $key) {
            if (!empty($addr[$key])) {
                $address_1 = sanitize_text_field((string) $addr[$key]);
                break;
            }
        }
        // district = mahalle → address_2'ye.
        $address_2 = isset($addr['district']) ? sanitize_text_field((string) $addr['district']) : '';

        // city (HB, İL) → WC state anahtarı; town (HB, İLÇE) → WC city.
        $town      = isset($addr['town']) ? sanitize_text_field((string) $addr['town']) : '';
        $state_key = self::resolve_tr_state_key(isset($addr['city']) ? (string) $addr['city'] : '');

        $email = isset($addr['email']) ? sanitize_email((string) $addr['email']) : '';

        $out = array(
            'first_name' => sanitize_text_field($first),
            'last_name'  => sanitize_text_field($last),
            'company'    => '',
            'address_1'  => $address_1,
            'address_2'  => $address_2,
            'city'       => $town,
            'state'      => $state_key,
            'postcode'   => isset($addr['postalCode']) ? sanitize_text_field((string) $addr['postalCode']) : '',
            'country'    => !empty($addr['countryCode']) ? sanitize_text_field((string) $addr['countryCode']) : 'TR',
            'phone'      => isset($addr['phoneNumber']) ? sanitize_text_field((string) $addr['phoneNumber']) : '',
        );
        if ($email !== '') {
            $out['email'] = $email;
        }
        return $out;
    }

    /**
     * İl adından WooCommerce TR il anahtarını ("TR34") çözer (Hezarfen states ters eşleme).
     * Bulunamazsa ham il adını döner.
     */
    private static function resolve_tr_state_key($city_name) {
        $city_name = trim((string) $city_name);
        if ($city_name !== '' && function_exists('WC') && WC()->countries) {
            $states = WC()->countries->get_states('TR');
            if (is_array($states) && !empty($states)) {
                $key = array_search($city_name, $states, true);
                if ($key !== false) {
                    return (string) $key;
                }
                // Büyük/küçük harf toleransı (örn. "İSTANBUL" ↔ "İstanbul").
                foreach ($states as $k => $label) {
                    if (function_exists('mb_strtolower')
                        && mb_strtolower($label, 'UTF-8') === mb_strtolower($city_name, 'UTF-8')) {
                        return (string) $k;
                    }
                }
            }
        }
        return sanitize_text_field($city_name);
    }

    /**
     * Siparişi İZOLE şekilde verilen statüye çeker (ESD/kargo/CRM tetiklenmeden).
     * İptal taraması (hepsiburada-iptal geçişi) bunu kullanır.
     *
     * @return int Sipariş ID (0 = hata)
     */
    public function update_status_isolated($order, $new_status, $note = '') {
        $order->set_status($new_status);
        if ($note !== '') {
            $order->add_order_note($note);
        }
        return $this->isolated_save($order);
    }

    /**
     * Sipariş CRM-relevant bir Hepsiburada statüsüne GİRDİĞİNDE köprü action'ını tek sefer fırlatır.
     * Dinleyici harici ERP/CRM entegrasyonunuzdur; eklenti kendisi dinlemez.
     *
     * Trendyol ile BİREBİR AYNI denge modeli:
     *  - hepsiburada-yeni  => POZİTİF (sipariş düştü; CRM'de stok tutulur)
     *  - hepsiburada-hazir => NEGATİF (ERP'ye aktarım)
     *  - hepsiburada-iptal => NEGATİF (aynı "release"; hazir'dan önce iptalde stok geri döner)
     * Pozitif/negatif birer kez tetiklenir (meta guard); hazir/iptal'den hangisi önce gelirse TEK negatif.
     */
    public function maybe_fire_crm_sync($order, $new_status) {
        if (!$order instanceof WC_Order) {
            return;
        }
        $order_id = $order->get_id();

        if ($new_status === 'hepsiburada-yeni') {
            if ($order->get_meta('_eru_hepsiburada_crm_pos') === 'yes') {
                return; // zaten tetiklendi (idempotency)
            }
            $order->update_meta_data('_eru_hepsiburada_crm_pos', 'yes');
            $order->save_meta_data();
            do_action('eru_hepsiburada_crm_positive', $order_id);
            Eru_Hepsiburada_Logger::info(sprintf('Sipariş #%d: CRM pozitif köprü action\'ı fırlatıldı (hepsiburada-yeni).', $order_id));
            return;
        }

        if (in_array($new_status, array('hepsiburada-hazir', 'hepsiburada-iptal'), true)) {
            if ($order->get_meta('_eru_hepsiburada_crm_neg') === 'yes') {
                return;
            }
            $order->update_meta_data('_eru_hepsiburada_crm_neg', 'yes');
            $order->save_meta_data();
            do_action('eru_hepsiburada_crm_negative', $order_id, $new_status);
            Eru_Hepsiburada_Logger::info(sprintf('Sipariş #%d: CRM negatif köprü action\'ı fırlatıldı (%s).', $order_id, $new_status));
        }
    }

    /**
     * Siparişi İZOLE kaydeder: `woocommerce_new_order` + tüm `woocommerce_order_status_*`
     * dinleyicileri save süresince nötrlenir, finally'de geri yüklenir.
     *
     * @return int Sipariş ID'si (0 = hata)
     */
    private function isolated_save($order) {
        global $wp_filter;

        $hooks = array(
            'woocommerce_new_order',
            'woocommerce_order_edit_status',
        );
        foreach (array_keys($wp_filter) as $hook_name) {
            // woocommerce_order_status_changed, woocommerce_order_status_{slug},
            // woocommerce_order_status_{from}_to_{to} — hepsi bu önekle başlar.
            if (strpos($hook_name, 'woocommerce_order_status_') === 0) {
                $hooks[] = $hook_name;
            }
        }
        $hooks = array_unique($hooks);

        // 7 Ağu 2026 — WooCommerce'in KENDİ sipariş sayacı muaf tutulur.
        // OrderCountCacheService şu iki kancaya bağlıdır: `woocommerce_new_order` ve
        // `woocommerce_order_status_changed`. Kancayı komple kaldırdığımızda sayaç
        // güncellenmiyordu; admin sipariş listesindeki statü rozetleri gerçek veriden
        // kopuyordu (canlıda bir Trendyol statüsü boş olmasına rağmen "(7)" gösteriyor,
        // bir diğeri -5'e düşmüştü). Bu yüzden artık kancayı komple silmek yerine,
        // yalnızca çekirdek sayaç callback'lerini bırakıp gerisini nötrlüyoruz.
        $saved = array();
        foreach ($hooks as $hook) {
            if (!isset($wp_filter[$hook])) {
                continue;
            }
            $saved[$hook] = $wp_filter[$hook];

            $korunacak = self::sayac_callbackleri($wp_filter[$hook]);
            if (empty($korunacak)) {
                unset($wp_filter[$hook]);
                continue;
            }

            $yeni_hook = new \WP_Hook();
            foreach ($korunacak as $oncelik => $cbs) {
                foreach ($cbs as $cb) {
                    $yeni_hook->add_filter($hook, $cb['function'], $oncelik, $cb['accepted_args']);
                }
            }
            $wp_filter[$hook] = $yeni_hook;
        }

        $order_id = 0;
        try {
            $order_id = (int) $order->save();
        } catch (\Exception $e) {
            Eru_Hepsiburada_Logger::error('Sipariş kaydında istisna: ' . $e->getMessage());
            $order_id = 0;
        } finally {
            foreach ($saved as $hook => $cbs) {
                $wp_filter[$hook] = $cbs; // her durumda geri yükle
            }
        }

        return $order_id;
    }

    /**
     * İzole save sırasında KORUNACAK callback'ler: yalnızca WooCommerce'in sipariş
     * sayacı önbelleğini güncelleyenler. Diğer her şey (ESD, kargo, CRM, havale, fraud)
     * eskisi gibi nötrlenir.
     *
     * Sınıf adı tam eşleşme yerine parça eşleşmesiyle aranır ki WooCommerce sınıfı
     * başka bir namespace'e taşırsa sessizce kırılmasın.
     */
    private static function sayac_callbackleri($hook_obj) {
        $bulunan = array();

        if (!is_object($hook_obj) || !isset($hook_obj->callbacks) || !is_array($hook_obj->callbacks)) {
            return $bulunan;
        }

        foreach ($hook_obj->callbacks as $oncelik => $cbs) {
            foreach ($cbs as $cb) {
                $fn = isset($cb['function']) ? $cb['function'] : null;
                if (!is_array($fn) || !isset($fn[0]) || !is_object($fn[0])) {
                    continue;
                }
                if (false !== strpos(get_class($fn[0]), 'OrderCountCacheService')) {
                    $bulunan[$oncelik][] = $cb;
                }
            }
        }

        return $bulunan;
    }

    private function set_meta_if($order, $key, $value) {
        if ($value === null || $value === '') {
            return;
        }
        $order->update_meta_data($key, is_scalar($value) ? sanitize_text_field((string) $value) : wp_json_encode($value));
    }

    private function record_failure($order_number, $items, $message) {
        global $wpdb;
        $table = $wpdb->prefix . 'eru_hepsiburada_orders';
        $first = reset($items);

        $wpdb->replace(
            $table,
            array(
                'order_number' => (string) $order_number,
                'hb_order_id'  => isset($first['orderId']) ? (string) $first['orderId'] : null,
                'wc_order_id'  => null,
                'status'       => 'error',
                'review'       => 0,
                'error'        => (string) $message,
                'created_at'   => current_time('mysql'),
            ),
            array('%s', '%s', '%d', '%s', '%d', '%s', '%s')
        );

        Eru_Hepsiburada_Logger::error(
            sprintf('Sipariş no %s içeri alınamadı: %s', $order_number, $message),
            array('order_number' => $order_number)
        );
    }
}
