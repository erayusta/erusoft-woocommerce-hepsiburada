# Erusoft WooCommerce Hepsiburada Eklentisi

Hepsiburada pazaryeri siparişlerini WooCommerce'e **izole** olarak içeri aktaran WordPress eklentisi.

Hepsiburada OMS API'sinden "Paketlenecek" kalemler 5 dakikalık cron ile çekilir, `orderNumber`
altında gruplanır ve kendi özel statülerinde durur. Mağazanızın normal sipariş akışını
(stok düşümü, ERP aktarımı, kargo, ödeme, bildirim e-postaları) **tetiklemez**.

- **Gereksinimler:** WordPress 5.0+, WooCommerce, PHP 7.2+
- **Sürüm:** 1.0.0
- **HPOS:** uyumlu (High-Performance Order Storage)

---

## Neden "izole"?

Pazaryeri siparişi WooCommerce'e normal yolla kaydedilirse `woocommerce_new_order` kancasına
bağlı ne varsa çalışır — stok düşer, ERP'ye kayıt gider, müşteriye e-posta atılır. Oysa bunları
pazaryeri kendi tarafında zaten yapmıştır. Sonuç: çift stok düşümü ve mükerrer kayıt.

Bu eklenti siparişi kaydederken `woocommerce_new_order` dinleyicilerini **o kayıt boyunca
geçici olarak devre dışı bırakır ve hemen geri yükler.** Başka hiçbir eklenti veya tema
dosyasına dokunulmaz.

---

## Kurulum

1. `eru-hepsiburada` klasörünü `wp-content/plugins/` altına kopyalayın.
2. **Eklentiler → Erusoft WooCommerce Hepsiburada Eklentisi → Etkinleştir.** Tablolar oluşturulur, cron zamanlanır.
3. **WooCommerce → Hepsiburada** ekranından ayarları girin.
4. Önce **Test (SIT)** ortamıyla doğrulayın, sonra Canlı'ya geçip **Aktif** edin.

> Eklenti varsayılan olarak **kapalı** ve **Test ortamında** gelir. Kimlik bilgileri boştur.

### Hepsiburada tarafında ihtiyacınız olanlar

Hepsiburada Satıcı Paneli → **Entegrasyon / API Bilgileri**:

| Alan | Not |
|---|---|
| Merchant ID | Ortam başına ayrı (SIT ve Canlı farklıdır) |
| Servis Anahtarı / Secret | Basic auth parolası; ortam başına ayrı |
| Developer Username | `User-Agent` header'ında zorunlu gönderilir |

**Developer Username zorunludur.** Hepsiburada bu header'ı boş veya yanlış gelen istekleri
401 ile reddeder.

---

## Ayarlar

**WooCommerce → Hepsiburada**

| Ayar | Açıklama |
|---|---|
| **Aktif** | Cron'u açar/kapatır. |
| **Ortam** | `Test (SIT)` veya `Canlı`. Kimlikler ortam başına ayrı tutulur; ortam değiştirmek diğerinin bilgilerini silmez. |
| **Merchant ID (Test/Canlı)** | Basic auth kullanıcı adı olarak da kullanılır. |
| **Servis Anahtarı (Test/Canlı)** | Basic auth parolası. |
| **Developer Username** | `User-Agent` header'ı (örn. `firmaadi_dev`). Zorunlu. |
| **Tarama penceresi (gün)** | Ana uçta kaç günlük geçmişe bakılacağı. Varsayılan 3. |

### API uçları

| Ortam | Taban adres |
|---|---|
| Canlı | `https://oms-external.hepsiburada.com` |
| Test (SIT) | `https://oms-external-sit.hepsiburada.com` |

---

## Sipariş statüleri

| Statü | Anlamı |
|---|---|
| `hepsiburada-yeni` | Sipariş içeri alındı, işlenmeyi bekliyor |
| `hepsiburada-hazir` | ERP'ye / hazırlığa aktarıldı |
| `hepsiburada-iptal` | İptal |

Statüler `wc-` önekiyle kayıtlıdır ve WooCommerce sipariş listesinde filtrelenebilir.

---

## Ürün eşleşmesi

Hepsiburada `merchantSku` alanı WooCommerce **SKU**'suyla eşleştirilir. Eşleşme yoksa sipariş
yine içeri alınır, kalem "eşleşmedi" olarak işaretlenir ve log'a düşer.

---

## Sipariş gruplama

Hepsiburada OMS kalem (`lineItem`) bazlı çalışır. Eklenti aynı `orderNumber` altındaki
kalemleri **tek bir WooCommerce siparişinde** birleştirir — müşteri tarafında olduğu gibi.
İptaller de kalem bazlı gelir ve ilgili siparişe işlenir.

---

## Cron

| Hook | Sıklık | İş |
|---|---|---|
| `eru_hepsiburada_sync_orders` | 5 dakika | Sipariş çekme + iptal taraması |

Düşük trafikli sitelerde gerçek sistem cron'u önerilir:

```
*/5 * * * * curl -s https://siteniz.com/wp-cron.php?doing_wp_cron > /dev/null
```

`wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

---

## Veritabanı tabloları

| Tablo | İçerik |
|---|---|
| `{prefix}eru_hepsiburada_orders` | Çekilen kalemler/paketler, WooCommerce eşleşmesi, hata durumu |
| `{prefix}eru_hepsiburada_log` | İşlem günlüğü |

---

## Entegrasyon noktaları (hook'lar)

Stok/ERP senkronunu eklenti kendisi yapmaz; iki action yayar. Sipariş başına yalnız bir kez
tetiklenirler (meta guard).

```php
// Sipariş içeri alındığında — stok REZERVE/DÜŞ
add_action( 'eru_hepsiburada_crm_positive', function ( $order_id ) {
    // ...
}, 10, 1 );

// ERP'ye aktarıldığında ya da iptal olduğunda — stok DENGELE
add_action( 'eru_hepsiburada_crm_negative', function ( $order_id, $new_status ) {
    // $new_status: hepsiburada-hazir | hepsiburada-iptal
}, 10, 2 );
```

Sipariş meta'sında `_eru_hepsiburada_*` anahtarları bulunur (sipariş no, kalem ID, kargo takip
no, iptal eden/iptal sebebi vb.).

---

## Sorun giderme

**401 / yetkisiz**
- **Developer Username** boş olabilir — Hepsiburada'da bu header zorunludur.
- Ortam ile kimlik eşleşmiyor olabilir: SIT kimliğiyle canlı uca istek atılıyorsa 401 gelir.

**Sipariş gelmiyor**
- *Aktif* işaretli mi? Doğru ortam seçili mi?
- **Günlük** sekmesinden ham yanıtı kontrol edin.
- Tarama penceresini geçici olarak büyütüp tek tur deneyin.

**Sipariş geldi, kalem eşleşmedi**
- `merchantSku` ile WooCommerce SKU birebir aynı olmalı.

**Cron çalışmıyor**
- `wp cron event list | grep eru_hepsiburada`
- Eklentiyi pasifleştirip yeniden aktif etmek cron'u yeniden kurar.

---

## Lisans

GPL-2.0-or-later
