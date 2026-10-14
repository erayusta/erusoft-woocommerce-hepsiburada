<?php
/**
 * Hepsiburada OMS API istemcisi.
 *
 * GET {host}/orders/merchantid/{merchantId}            → Ödemesi tamamlanmış (Paketlenecek) KALEMLER
 * GET {host}/orders/merchantid/{merchantId}/cancelled  → İptal edilen kalemler (son 1 ay)
 *
 * Auth: HTTP Basic (username:password). `User-Agent` header ZORUNLU.
 * offset+limit birlikte zorunlu (ana uç limit ≤100, iptal ucu ≤50).
 * Tarih formatı: "Y-m-d H:i" (Europe/Istanbul — site TZ ile aynı).
 * Rate limit: 1 sn / 1000 istek; 429'da X-RateLimit-Reset loglanır, sayfalama erken kesilir.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Hepsiburada_Client {

    private $merchant_id;
    private $username;
    private $password;
    private $host;
    private $user_agent;

    public function __construct($settings) {
        // Ortam ayrımı: test_mode = 1 → SIT host + test kimlikleri; 0 → canlı host + canlı kimlikleri.
        $is_test = !empty($settings['test_mode']);
        $this->host = $is_test ? ERU_HEPSIBURADA_API_SIT : ERU_HEPSIBURADA_API_LIVE;

        $this->merchant_id = trim((string) ($is_test
            ? ($settings['test_merchant_id'] ?? '')
            : ($settings['live_merchant_id'] ?? '')));
        $this->password = (string) ($is_test
            ? ($settings['test_password'] ?? '')
            : ($settings['live_password'] ?? ''));

        // Geriye uyum: ortam bazlı kimlik boşsa eski tekil alanlara düş (ilk sürüm ayar şeması).
        if ($this->merchant_id === '') {
            $this->merchant_id = trim((string) ($settings['merchant_id'] ?? ''));
            $this->password    = (string) ($settings['password'] ?? '');
        }

        // HB Basic auth kullanıcı adı = merchant_id (SIT ve canlıda doğrulandı).
        $this->username = $this->merchant_id;

        // User-Agent = HB'nin verdiği Developer Username (zorunlu header).
        // Ayar boşsa merchant_id'ye düşer; HB bu header'ı zorunlu tuttuğu ve
        // boş/yanlış değerde 401 döndürdüğü için ayarın doldurulması şart.
        $dev_username     = trim((string) ($settings['user_agent'] ?? ''));
        $this->user_agent = $dev_username !== '' ? $dev_username : $this->merchant_id;
    }

    public function is_configured() {
        return $this->merchant_id !== '' && $this->username !== '' && $this->password !== '';
    }

    /**
     * Ödemesi tamamlanmış (Paketlenecek: Open/Unpacked) sipariş KALEMLERİNİ çeker — tüm sayfalar.
     *
     * @param string $begindate "Y-m-d H:i"
     * @param string $enddate   "Y-m-d H:i"
     * @return array|WP_Error   Düz kalem dizisi
     */
    public function fetch_orders($begindate, $enddate) {
        return $this->fetch_paged('/orders/merchantid/' . rawurlencode($this->merchant_id), $begindate, $enddate, 100);
    }

    /**
     * İptal edilen sipariş kalemlerini çeker (HB yalnız son 1 aylık veriyi verir) — tüm sayfalar.
     *
     * @return array|WP_Error   Düz iptal-kalem dizisi
     */
    public function fetch_cancelled($begindate, $enddate) {
        return $this->fetch_paged('/orders/merchantid/' . rawurlencode($this->merchant_id) . '/cancelled', $begindate, $enddate, 50);
    }

    /**
     * Ortak sayfalı çekim: offset+limit ile pageCount/totalCount bitene kadar döner.
     * Cevap zarfı savunmacı okunur: items[] varsa o, yoksa kök dizi.
     *
     * @return array|WP_Error
     */
    private function fetch_paged($path, $begindate, $enddate, $limit) {
        if (!$this->is_configured()) {
            return new WP_Error('eru_hepsiburada_not_configured', 'Hepsiburada API kimlik bilgileri eksik.');
        }

        $all    = array();
        $offset = 0;
        $page   = 0;

        do {
            $query = http_build_query(array(
                'offset'    => $offset,
                'limit'     => $limit,
                'begindate' => $begindate,
                'enddate'   => $enddate,
            ));
            $url = $this->host . $path . '?' . $query;

            $response = wp_remote_get($url, array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                    'User-Agent'    => $this->user_agent,
                    'Accept'        => 'application/json',
                ),
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($code === 429) {
                // Rate limit: reset süresini logla, o ana kadar biriken kalemlerle devam et.
                $reset = wp_remote_retrieve_header($response, 'x-ratelimit-reset');
                Eru_Hepsiburada_Logger::error(
                    sprintf('Hepsiburada rate limit (429); %s sn sonra sıfırlanacak. Sayfalama erken kesildi.', $reset !== '' ? $reset : '?'),
                    array('url' => $url)
                );
                break;
            }
            if ($code < 200 || $code >= 300) {
                return new WP_Error(
                    'eru_hepsiburada_http_' . $code,
                    'Hepsiburada API HTTP ' . $code,
                    array('body' => $body, 'url' => $url)
                );
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                return new WP_Error('eru_hepsiburada_bad_json', 'Hepsiburada API geçersiz JSON döndü.', array('body' => $body));
            }

            // Zarf savunması: {items:[...], totalCount, pageCount} ya da doğrudan [...]
            if (isset($data['items']) && is_array($data['items'])) {
                $items = $data['items'];
            } elseif (array_keys($data) === range(0, count($data) - 1)) {
                $items = $data; // kök düz dizi
            } else {
                $items = array();
            }

            foreach ($items as $item) {
                $all[] = $item;
            }

            // totalCount yalnız zarfta VARSA erken-durdurma olarak kullanılır; yoksa "sayfa doluysa devam".
            $total_count = isset($data['totalCount']) ? (int) $data['totalCount'] : null;
            $offset     += $limit;
            $page++;
            // Devam koşulu: bu sayfa doluydu VE (totalCount bilinmiyorsa || henüz ulaşılmadı). Güvenlik tavanı 100 sayfa.
        } while (count($items) === $limit && ($total_count === null || $offset < $total_count) && $page < 100);

        return $all;
    }
}
