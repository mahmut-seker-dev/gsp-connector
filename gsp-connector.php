<?php
/*
Plugin Name: GSP Connector
Plugin URI: https://gsp.test
Description: Global Site Pipeline (GSP) yönetim paneli için güvenli uzaktan yönetim ve GitHub güncelleme arayüzü.
Version: 1.0.5
Author: Mahmut Şeker
Author URI: https://mahmutseker.com
*/

// 1. GSP Secret Key'i veritabanından alalım.
// Eklenti aktif edildiğinde bu ayar kaydedilmiş olmalıdır.
// WordPress tamamen yüklendikten sonra çağrılmalı
add_action('plugins_loaded', function() {
    if (!defined('GSP_API_SECRET')) {
        define('GSP_API_SECRET', get_option('gsp_api_secret_key', 'GSP_DEFAULT_SECRET'));
    }
}, 1);

// WooCommerce'un yüklü olduğundan emin ol
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    // WooCommerce aktif değilse, bir hata bildirimi göster.
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>GSP Connector eklentisi, WooCommerce\'in aktif olmasını gerektirir.</p></div>';
    });
    return;
}

// =========================================================================
// GITHUB OTOMATİK GÜNCELLEME MEKANİZMASI
// =========================================================================

// GitHub güncelleyici sınıfını dahil et
add_action('plugins_loaded', function() {
    $updater_file = plugin_dir_path(__FILE__) . 'updater/github-plugin-updater.php';
    if (file_exists($updater_file)) {
        require_once($updater_file);
        
        // GitHub repo bilgilerini ayarlardan al
        $github_username = get_option('gsp_github_username', '');
        $github_repo = get_option('gsp_github_repo', '');
        $github_branch = get_option('gsp_github_branch', 'main');
        $branch_only_mode = get_option('gsp_github_branch_only', '') === '1'; // Release kontrolünü atla
        
        // Eğer GitHub bilgileri girilmişse, updater'ı başlat
        if (!empty($github_username) && !empty($github_repo)) {
            new GitHub_Plugin_Updater(
                __FILE__, // Eklenti dosya yolu
                $github_username, // GitHub Kullanıcı Adı
                $github_repo, // GitHub Depo Adı
                $github_branch, // Ana Dal Adı
                $branch_only_mode // Branch-only modu (release kontrolünü atla)
            );
        }
    }
}, 2);

// REST API endpoint'ini kaydetme fonksiyonunu çağırma
add_action( 'rest_api_init', 'gsp_register_routes' );

/**
 * REST API Endpoint'lerini kaydeder.
 */
function gsp_register_routes() {
    // Ürün listesi (GET)
    register_rest_route( 'gsp/v1', '/products', array(
        'methods'             => 'GET',
        'callback'            => 'gsp_get_products',
        'permission_callback' => 'gsp_validate_api_key',
    ));

    // Ürün detayı (GET)
    register_rest_route( 'gsp/v1', '/products/(?P<id>\d+)', array(
        'methods'             => 'GET',
        'callback'            => 'gsp_get_product',
        'permission_callback' => 'gsp_validate_api_key',
        'args'                => array(
            'id' => array(
                'required' => true,
                'type'     => 'integer',
            ),
        ),
    ));

    // Ürün oluşturma (POST)
    register_rest_route( 'gsp/v1', '/products', array(
        'methods'             => 'POST',
        'callback'            => 'gsp_create_product',
        'permission_callback' => 'gsp_validate_api_key',
    ));

    // Ürün güncelleme (PUT)
    register_rest_route( 'gsp/v1', '/products/(?P<id>\d+)', array(
        'methods'             => 'PUT',
        'callback'            => 'gsp_update_product',
        'permission_callback' => 'gsp_validate_api_key',
        'args'                => array(
            'id' => array(
                'required' => true,
                'type'     => 'integer',
            ),
        ),
    ));

    // Ürün silme (DELETE)
    register_rest_route( 'gsp/v1', '/products/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'gsp_delete_product',
        'permission_callback' => 'gsp_validate_api_key',
        'args'                => array(
            'id' => array(
                'required' => true,
                'type'     => 'integer',
            ),
        ),
    ));

    // Stok güncelleme (POST)
    register_rest_route( 'gsp/v1', '/products/(?P<id>\d+)/stock', array(
        'methods'             => 'POST',
        'callback'            => 'gsp_update_stock',
        'permission_callback' => 'gsp_validate_api_key',
        'args'                => array(
            'id' => array(
                'required' => true,
                'type'     => 'integer',
            ),
        ),
    ));

    // Fiyat güncelleme (POST) - Mevcut endpoint
    register_rest_route( 'gsp/v1', '/sync-product-price', array(
        'methods'             => 'POST',
        'callback'            => 'gsp_sync_product_price',
        'permission_callback' => 'gsp_validate_api_key',
    ));

    // Önbelleği temizleme (POST)
    register_rest_route( 'gsp/v1', '/purge-cache', array(
        'methods'             => 'POST',
        'callback'            => 'gsp_purge_litespeed_cache',
        'permission_callback' => 'gsp_validate_api_key',
    ));

    // Sayfa/Yazı İçeriğini Güncelleme (POST)
    register_rest_route( 'gsp/v1', '/update-page-content', array(
        'methods'             => 'POST',
        'callback'            => 'gsp_update_page_content',
        'permission_callback' => 'gsp_validate_api_key',
    ));

    // Google Sheets CSV/JSON Toplu Import (POST)
    register_rest_route( 'gsp/v1', '/products/bulk-import', array(
        'methods'             => 'POST',
        'callback'            => 'gsp_bulk_import_products',
        'permission_callback' => 'gsp_validate_api_key',
    ));

    // Google Sheets API ile doğrudan çekme (POST)
    register_rest_route( 'gsp/v1', '/products/import-from-sheets', array(
        'methods'             => 'POST',
        'callback'            => 'gsp_import_from_google_sheets',
        'permission_callback' => 'gsp_validate_api_key',
    ));

    // Test endpoint'i (bağlantı testi için)
    register_rest_route( 'gsp/v1', '/test', array(
        'methods'             => 'GET',
        'callback'            => 'gsp_test_connection',
        'permission_callback' => 'gsp_validate_api_key',
    ));

    // Hazırlık kontrolü (GET)
    register_rest_route( 'gsp/v1', '/ready', array(
        'methods'             => 'GET',
        'callback'            => function() {
            return new WP_REST_Response(
                array(
                    'status'  => 'live',
                    'message' => 'GSP Connector aktif ve API iletişimi hazır.'
                ),
                200
            );
        },
        'permission_callback' => 'gsp_validate_api_key',
    ));

    // Aktif sayfalar listesi (GET)
    register_rest_route( 'gsp/v1', '/pages', array(
        'methods'             => 'GET',
        'callback'            => 'gsp_get_active_pages',
        'permission_callback' => 'gsp_validate_api_key',
    ));

    // Sayfa detayı - Tüm veriler (GET)
    register_rest_route( 'gsp/v1', '/pages/(?P<id>\d+)', array(
        'methods'             => 'GET',
        'callback'            => 'gsp_get_page_full',
        'permission_callback' => 'gsp_validate_api_key',
        'args'                => array(
            'id' => array(
                'required' => true,
                'type'     => 'integer',
            ),
        ),
    ));

    // Admin bilgileri (GET)
    register_rest_route( 'gsp/v1', '/get-admin-info', array(
        'methods'             => 'GET',
        'callback'            => 'gsp_get_admin_details',
        'permission_callback' => 'gsp_validate_api_key',
    ));

    // Sistem bilgileri (GET)
    register_rest_route( 'gsp/v1', '/get-system-info', array(
        'methods'             => 'GET',
        'callback'            => 'gsp_get_system_info',
        'permission_callback' => 'gsp_validate_api_key',
    ));

    // Eklenti durumunu değiştirme (POST)
    register_rest_route( 'gsp/v1', '/toggle-plugin', array(
        'methods'             => 'POST',
        'callback'            => 'gsp_toggle_plugin_status',
        'permission_callback' => 'gsp_validate_api_key',
    ));

    // Eklenti güncellemesi (POST)
    register_rest_route( 'gsp/v1', '/update-plugin', array(
        'methods'             => 'POST',
        'callback'            => 'gsp_update_plugin_remotely',
        'permission_callback' => 'gsp_validate_api_key',
    ));
}

// 2. Güvenlik ve API Key Doğrulama Fonksiyonu
function gsp_validate_api_key() {
    // Rate limiting kontrolü
    $ip = gsp_get_client_ip();
    $rate_limit_key = 'gsp_rate_limit_' . md5($ip);
    $rate_limit_count = get_transient($rate_limit_key);
    
    // Dakikada maksimum 60 istek (rate limiting)
    if ($rate_limit_count && $rate_limit_count >= 60) {
        return new WP_Error( 'gsp_rate_limit', 'Çok fazla istek. Lütfen bir dakika bekleyin.', array( 'status' => 429 ) );
    }
    
    // Rate limit sayacını artır
    if ($rate_limit_count) {
        set_transient($rate_limit_key, $rate_limit_count + 1, 60); // 60 saniye
    } else {
        set_transient($rate_limit_key, 1, 60);
    }

    // Laravel'den gelen HTTP başlığı (X-GSP-API-KEY)
    $incoming_key = isset( $_SERVER['HTTP_X_GSP_API_KEY'] ) ? sanitize_text_field($_SERVER['HTTP_X_GSP_API_KEY']) : '';

    // Eklenti ayarlarından GSP_API_SECRET tanımlanmış mı kontrol et.
    if ( ! defined('GSP_API_SECRET') || GSP_API_SECRET === 'GSP_DEFAULT_SECRET' ) {
        return new WP_Error( 'gsp_not_configured', 'GSP Connector ayarlanmamış. API Key gereklidir.', array( 'status' => 401 ) );
    }

    // Timing attack koruması için hash_equals kullan
    if ( hash_equals( (string) GSP_API_SECRET, $incoming_key ) ) {
        return true; 
    }
    
    // Şüpheli aktiviteyi logla (opsiyonel)
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('GSP Connector: Geçersiz API key denemesi - IP: ' . $ip);
    }
    
    // Yetkilendirme başarısız
    return new WP_Error( 'gsp_invalid_key', 'Geçersiz GSP API Anahtarı.', array( 'status' => 401 ) );
}

// Client IP adresini güvenli şekilde al
function gsp_get_client_ip() {
    $ip_keys = array(
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_REAL_IP',        // Nginx
        'HTTP_X_FORWARDED_FOR',  // Proxy
        'REMOTE_ADDR'            // Standart
    );
    
    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = sanitize_text_field($_SERVER[$key]);
            // X-Forwarded-For birden fazla IP içerebilir
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '0.0.0.0';
}

// 3. Ürün Listesi Fonksiyonu
function gsp_get_products( WP_REST_Request $request ) {
    $params = $request->get_query_params();
    $per_page = isset($params['per_page']) ? min(intval($params['per_page']), 100) : 20; // Maksimum 100
    $page = isset($params['page']) ? max(1, intval($params['page'])) : 1; // Minimum 1
    $search = isset($params['search']) ? sanitize_text_field($params['search']) : '';
    $status = isset($params['status']) ? sanitize_text_field($params['status']) : 'any';
    
    // Status whitelist kontrolü
    $allowed_statuses = array('publish', 'draft', 'pending', 'private', 'any');
    if (!in_array($status, $allowed_statuses)) {
        $status = 'any';
    }

    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'post_status'    => $status,
    );

    if (!empty($search)) {
        $args['s'] = $search;
    }

    $query = new WP_Query($args);
    $products = array();

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $product = wc_get_product(get_the_ID());
            if ($product) {
                $products[] = gsp_format_product($product);
            }
        }
        wp_reset_postdata();
    }

    return new WP_REST_Response(array(
        'products' => $products,
        'total'    => $query->found_posts,
        'page'     => $page,
        'per_page' => $per_page,
    ), 200);
}

// 4. Ürün Detayı Fonksiyonu
function gsp_get_product( WP_REST_Request $request ) {
    $product_id = intval($request['id']);
    $product = wc_get_product($product_id);

    if (!$product) {
        return new WP_REST_Response(array(
            'message' => 'Ürün bulunamadı.',
        ), 404);
    }

    return new WP_REST_Response(gsp_format_product($product), 200);
}

// 5. Ürün Oluşturma Fonksiyonu
function gsp_create_product( WP_REST_Request $request ) {
    $data = $request->get_json_params();

    // Zorunlu alanlar
    if (empty($data['name'])) {
        return new WP_REST_Response(array(
            'message' => 'Ürün adı zorunludur.',
        ), 400);
    }

    $product = new WC_Product_Simple();
    $product->set_name(sanitize_text_field($data['name']));
    $product->set_status(isset($data['status']) ? sanitize_text_field($data['status']) : 'publish');
    
    if (!empty($data['sku'])) {
        $product->set_sku(sanitize_text_field($data['sku']));
    }
    
    if (isset($data['regular_price'])) {
        $product->set_regular_price(floatval($data['regular_price']));
    }
    
    if (isset($data['sale_price'])) {
        $product->set_sale_price(floatval($data['sale_price']));
    }
    
    if (isset($data['stock_quantity'])) {
        $product->set_stock_quantity(intval($data['stock_quantity']));
        $product->set_manage_stock(true);
    }
    
    if (!empty($data['description'])) {
        $product->set_description(wp_kses_post($data['description']));
    }
    
    if (!empty($data['short_description'])) {
        $product->set_short_description(wp_kses_post($data['short_description']));
    }

    $product_id = $product->save();

    if (is_wp_error($product_id)) {
        return new WP_REST_Response(array(
            'message' => 'Ürün oluşturulurken hata oluştu: ' . $product_id->get_error_message(),
        ), 500);
    }

    // Currency'yi kaydet (eğer gönderilmişse)
    if (isset($data['currency'])) {
        $currency = sanitize_text_field($data['currency']);
        if (!empty($currency)) {
            if (!gsp_validate_currency($currency)) {
                return new WP_REST_Response(array(
                    'message' => 'Geçersiz para birimi kodu. Desteklenen: ' . implode(', ', gsp_get_allowed_currencies()),
                ), 400);
            }
            gsp_save_product_currency($product_id, $currency);
        }
    }

    return new WP_REST_Response(array(
        'message' => 'Ürün başarıyla oluşturuldu.',
        'product' => gsp_format_product(wc_get_product($product_id)),
    ), 201);
}

// 6. Ürün Güncelleme Fonksiyonu
function gsp_update_product( WP_REST_Request $request ) {
    $product_id = intval($request['id']);
    $product = wc_get_product($product_id);

    if (!$product) {
        return new WP_REST_Response(array(
            'message' => 'Ürün bulunamadı.',
        ), 404);
    }

    $data = $request->get_json_params();

    if (isset($data['name'])) {
        $product->set_name(sanitize_text_field($data['name']));
    }
    
    if (isset($data['status'])) {
        $product->set_status(sanitize_text_field($data['status']));
    }
    
    if (isset($data['sku'])) {
        $product->set_sku(sanitize_text_field($data['sku']));
    }
    
    if (isset($data['regular_price'])) {
        $product->set_regular_price(floatval($data['regular_price']));
    }
    
    if (isset($data['sale_price'])) {
        $product->set_sale_price(floatval($data['sale_price']));
    }
    
    if (isset($data['stock_quantity'])) {
        $product->set_stock_quantity(intval($data['stock_quantity']));
        $product->set_manage_stock(true);
    }
    
    if (isset($data['description'])) {
        $product->set_description(wp_kses_post($data['description']));
    }
    
    if (isset($data['short_description'])) {
        $product->set_short_description(wp_kses_post($data['short_description']));
    }

    // Currency'yi güncelle (eğer gönderilmişse)
    if (isset($data['currency'])) {
        $currency = sanitize_text_field($data['currency']);
        if (!empty($currency)) {
            if (!gsp_validate_currency($currency)) {
                return new WP_REST_Response(array(
                    'message' => 'Geçersiz para birimi kodu. Desteklenen: ' . implode(', ', gsp_get_allowed_currencies()),
                ), 400);
            }
            gsp_save_product_currency($product_id, $currency);
        } else {
            // Currency boşsa, meta'yı sil
            gsp_save_product_currency($product_id, '');
        }
    }

    $result = $product->save();

    if (is_wp_error($result)) {
        return new WP_REST_Response(array(
            'message' => 'Ürün güncellenirken hata oluştu: ' . $result->get_error_message(),
        ), 500);
    }

    return new WP_REST_Response(array(
        'message' => 'Ürün başarıyla güncellendi.',
        'product' => gsp_format_product($product),
    ), 200);
}

// 7. Ürün Silme Fonksiyonu
function gsp_delete_product( WP_REST_Request $request ) {
    $product_id = intval($request['id']);
    $product = wc_get_product($product_id);

    if (!$product) {
        return new WP_REST_Response(array(
            'message' => 'Ürün bulunamadı.',
        ), 404);
    }

    // Kalıcı silme yerine çöp kutusuna taşı
    $force = isset($request['force']) && $request['force'] === true;
    
    if ($force) {
        $result = wp_delete_post($product_id, true);
    } else {
        $result = wp_trash_post($product_id);
    }

    if (!$result) {
        return new WP_REST_Response(array(
            'message' => 'Ürün silinirken hata oluştu.',
        ), 500);
    }

    return new WP_REST_Response(array(
        'message' => $force ? 'Ürün kalıcı olarak silindi.' : 'Ürün çöp kutusuna taşındı.',
        'id'      => $product_id,
    ), 200);
}

// 8. Stok Güncelleme Fonksiyonu
function gsp_update_stock( WP_REST_Request $request ) {
    $product_id = intval($request['id']);
    $product = wc_get_product($product_id);

    if (!$product) {
        return new WP_REST_Response(array(
            'message' => 'Ürün bulunamadı.',
        ), 404);
    }

    $data = $request->get_json_params();
    
    if (!isset($data['quantity'])) {
        return new WP_REST_Response(array(
            'message' => 'Stok miktarı (quantity) gereklidir.',
        ), 400);
    }

    $quantity = intval($data['quantity']);
    $product->set_stock_quantity($quantity);
    $product->set_manage_stock(true);
    $product->save();

    return new WP_REST_Response(array(
        'message'  => "Ürün stoku $quantity olarak güncellendi.",
        'product'  => gsp_format_product($product),
        'stock'    => $quantity,
    ), 200);
}

// 8.5. Currency Helper Fonksiyonları
/**
 * Desteklenen para birimleri listesi
 */
function gsp_get_allowed_currencies() {
    return apply_filters('gsp_allowed_currencies', array(
        'TRY', 'USD', 'EUR', 'GBP', 'CHF', 'JPY', 'CAD', 'AUD', 'RUB', 'CNY', 'INR', 'BRL', 'MXN', 'ZAR', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF'
    ));
}

/**
 * Currency meta key
 */
function gsp_get_currency_meta_key() {
    return apply_filters('gsp_currency_meta_key', '_product_currency');
}

/**
 * Currency validasyonu
 */
function gsp_validate_currency($currency) {
    if (empty($currency)) {
        return true; // Opsiyonel
    }
    
    $currency = strtoupper(trim($currency));
    $allowed = gsp_get_allowed_currencies();
    
    return in_array($currency, $allowed);
}

/**
 * Ürün currency'sini kaydet
 */
function gsp_save_product_currency($product_id, $currency) {
    $meta_key = gsp_get_currency_meta_key();
    
    if (empty($currency)) {
        // Currency boşsa, meta'yı sil
        delete_post_meta($product_id, $meta_key);
        $product = wc_get_product($product_id);
        if ($product) {
            $product->delete_meta_data($meta_key);
            $product->save();
        }
        return;
    }
    
    $currency = strtoupper(trim($currency));
    
    if (gsp_validate_currency($currency)) {
        update_post_meta($product_id, $meta_key, $currency);
        $product = wc_get_product($product_id);
        if ($product) {
            $product->update_meta_data($meta_key, $currency);
            $product->save();
        }
        
        // Multi-currency eklentileri için hook
        do_action('gsp_product_currency_updated', $product_id, $currency);
    }
}

/**
 * Ürün currency'sini al
 */
function gsp_get_product_currency($product_id) {
    $meta_key = gsp_get_currency_meta_key();
    $currency = get_post_meta($product_id, $meta_key, true);
    
    if (empty($currency)) {
        // Varsayılan currency'yi al
        return get_option('woocommerce_currency', 'TRY');
    }
    
    return strtoupper($currency);
}

// 9. Ürün Formatlama Yardımcı Fonksiyonu
function gsp_format_product( $product ) {
    if (!$product) {
        return null;
    }

    $product_id = $product->get_id();
    
    return array(
        'id'                => $product_id,
        'name'              => $product->get_name(),
        'sku'               => $product->get_sku(),
        'type'              => $product->get_type(),
        'status'            => $product->get_status(),
        'regular_price'     => $product->get_regular_price(),
        'sale_price'        => $product->get_sale_price(),
        'price'             => $product->get_price(),
        'currency'          => gsp_get_product_currency($product_id), // Currency eklendi
        'stock_quantity'    => $product->get_stock_quantity(),
        'stock_status'      => $product->get_stock_status(),
        'manage_stock'      => $product->get_manage_stock(),
        'description'       => $product->get_description(),
        'short_description' => $product->get_short_description(),
        'permalink'         => $product->get_permalink(),
        'image_url'         => wp_get_attachment_image_url($product->get_image_id(), 'full'),
        'date_created'      => $product->get_date_created() ? $product->get_date_created()->date('Y-m-d H:i:s') : null,
        'date_modified'     => $product->get_date_modified() ? $product->get_date_modified()->date('Y-m-d H:i:s') : null,
    );
}

// 10. Ürün Fiyat Güncelleme Fonksiyonu (Mevcut)
function gsp_sync_product_price( WP_REST_Request $request ) {
    $data = $request->get_json_params();
    $sku = sanitize_text_field( $data['sku'] ?? '' );
    $new_price = floatval( $data['new_price'] ?? 0 );
    
    if ( empty($sku) || $new_price <= 0 ) {
        return new WP_REST_Response( array( 'message' => 'Eksik veya geçersiz SKU/Fiyat verisi.' ), 400 );
    }

    $product_id = wc_get_product_id_by_sku( $sku );

    if ( !$product_id ) {
        return new WP_REST_Response( array( 'message' => "SKU ($sku) ile ürün bulunamadı." ), 404 );
    }

    // Ürünü al
    $product = wc_get_product( $product_id );
    
    // Fiyatları güncelle ve kaydet
    $product->set_regular_price( $new_price );
    $product->set_sale_price( $new_price ); 
    
    // Değişiklikleri kaydet
    $product->save(); 

    return new WP_REST_Response( array( 
        'message' => "Ürün fiyatı $new_price olarak güncellendi.", 
        'sku' => $sku, 
        'new_price' => $new_price 
    ), 200 );
}

// 10.5. Sayfa/Yazı İçeriği Güncelleme Fonksiyonu
/**
 * REST API ile sayfa veya yazının içeriğini ID'ye göre günceller.
 * 
 * @param WP_REST_Request $request Laravel'den gelen isteği içerir.
 * @return WP_REST_Response
 */
function gsp_update_page_content( WP_REST_Request $request ) {
    $data = $request->get_json_params();
    $post_id = intval( $data['post_id'] ?? 0 );
    $new_content = $data['content'] ?? ''; // Yeni HTML içeriği

    if ( $post_id <= 0 || empty($new_content) ) {
        return new WP_REST_Response( array( 
            'message' => 'Eksik veya geçersiz Post ID veya içerik.',
            'required_fields' => array('post_id', 'content')
        ), 400 );
    }

    // Post'un varlığını kontrol et
    $post = get_post( $post_id );
    if ( !$post ) {
        return new WP_REST_Response( array( 
            'message' => "ID ($post_id) ile sayfa/yazı bulunamadı." 
        ), 404 );
    }

    // İçeriği sanitize et (HTML içeriği için wp_kses_post kullan)
    $sanitized_content = wp_kses_post($new_content);

    // Sayfa/Yazı içeriğini güncelleme
    $update_result = wp_update_post( array(
        'ID'           => $post_id,
        'post_content' => wp_slash($sanitized_content), // wp_slash() veritabanı kaydı için gereklidir
    ), true ); // true, WP_Error döndürülmesini sağlar

    if ( is_wp_error( $update_result ) ) {
        return new WP_REST_Response( array( 
            'message' => 'İçerik güncellenirken WordPress hatası oluştu.',
            'error_details' => $update_result->get_error_message(),
            'error_code' => $update_result->get_error_code()
        ), 500 );
    }

    // Güncellenmiş post bilgilerini al
    $updated_post = get_post($post_id);

    // Başarılı yanıt
    return new WP_REST_Response( array( 
        'message' => "Sayfa/Yazı (ID: $post_id) içeriği başarıyla güncellendi.", 
        'post_id' => $post_id,
        'post_title' => get_the_title($post_id),
        'post_type' => $updated_post->post_type,
        'post_status' => $updated_post->post_status,
        'updated_at' => $updated_post->post_modified
    ), 200 );
}

// 11. Toplu Ürün Import Fonksiyonu (CSV/JSON formatında)
function gsp_bulk_import_products( WP_REST_Request $request ) {
    $data = $request->get_json_params();
    
    if (empty($data['products']) || !is_array($data['products'])) {
        return new WP_REST_Response(array(
            'message' => 'Ürün listesi (products) gereklidir ve array formatında olmalıdır.',
        ), 400);
    }

    // Güvenlik: Maksimum ürün sayısı limiti (DOS saldırılarını önlemek için)
    $max_products = apply_filters('gsp_max_bulk_import', 500); // Varsayılan 500 ürün
    if (count($data['products']) > $max_products) {
        return new WP_REST_Response(array(
            'message' => "Maksimum $max_products ürün gönderilebilir. Gönderilen: " . count($data['products']),
        ), 400);
    }

    $results = array(
        'success' => 0,
        'failed' => 0,
        'errors' => array(),
        'updated' => array(),
    );

    foreach ($data['products'] as $index => $product_data) {
        try {
            // SKU veya ID ile ürünü bul
            $product = null;
            $product_id = null;

            if (!empty($product_data['sku'])) {
                $product_id = wc_get_product_id_by_sku(sanitize_text_field($product_data['sku']));
            } elseif (!empty($product_data['id'])) {
                $product_id = intval($product_data['id']);
            }

            if ($product_id) {
                // Mevcut ürünü güncelle
                $product = wc_get_product($product_id);
            } else {
                // Yeni ürün oluştur
                if (empty($product_data['name'])) {
                    $results['failed']++;
                    $results['errors'][] = "Satır " . ($index + 1) . ": Ürün adı zorunludur.";
                    continue;
                }
                $product = new WC_Product_Simple();
                $product->set_name(sanitize_text_field($product_data['name']));
            }

            // Ürün bilgilerini güncelle
            if (isset($product_data['name'])) {
                $product->set_name(sanitize_text_field($product_data['name']));
            }
            if (isset($product_data['sku'])) {
                $product->set_sku(sanitize_text_field($product_data['sku']));
            }
            if (isset($product_data['regular_price'])) {
                $product->set_regular_price(floatval($product_data['regular_price']));
            }
            if (isset($product_data['sale_price'])) {
                $product->set_sale_price(floatval($product_data['sale_price']));
            }
            if (isset($product_data['stock_quantity'])) {
                $product->set_stock_quantity(intval($product_data['stock_quantity']));
                $product->set_manage_stock(true);
            }
            if (isset($product_data['description'])) {
                $product->set_description(wp_kses_post($product_data['description']));
            }
            if (isset($product_data['short_description'])) {
                $product->set_short_description(wp_kses_post($product_data['short_description']));
            }
            if (isset($product_data['status'])) {
                $product->set_status(sanitize_text_field($product_data['status']));
            }

            $saved = $product->save();
            
            if (is_wp_error($saved)) {
                $results['failed']++;
                $results['errors'][] = "Satır " . ($index + 1) . ": " . $saved->get_error_message();
            } else {
                // Currency'yi kaydet (eğer gönderilmişse)
                if (isset($product_data['currency'])) {
                    $currency = sanitize_text_field($product_data['currency']);
                    if (!empty($currency)) {
                        if (gsp_validate_currency($currency)) {
                            gsp_save_product_currency($product->get_id(), $currency);
                        } else {
                            $results['errors'][] = "Satır " . ($index + 1) . ": Geçersiz para birimi kodu: $currency";
                        }
                    }
                }
                
                $results['success']++;
                $results['updated'][] = array(
                    'id' => $product->get_id(),
                    'sku' => $product->get_sku(),
                    'name' => $product->get_name(),
                    'currency' => gsp_get_product_currency($product->get_id()),
                );
            }
        } catch (Exception $e) {
            $results['failed']++;
            $results['errors'][] = "Satır " . ($index + 1) . ": " . $e->getMessage();
        }
    }

    return new WP_REST_Response(array(
        'message' => "İşlem tamamlandı. Başarılı: {$results['success']}, Başarısız: {$results['failed']}",
        'results' => $results,
    ), 200);
}

// 12. Test Bağlantısı Fonksiyonu
function gsp_test_connection( WP_REST_Request $request ) {
    $ip = gsp_get_client_ip();
    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'GSP Connector API çalışıyor!',
        'timestamp' => current_time('mysql'),
        'api_version' => '1.0.0',
        'your_ip' => $ip,
        'woocommerce_active' => class_exists('WooCommerce'),
    ), 200);
}

// 13. Aktif Sayfalar Listesi Fonksiyonu
function gsp_get_active_pages( WP_REST_Request $request ) {
    $params = $request->get_query_params();
    $per_page = isset($params['per_page']) ? min(intval($params['per_page']), 100) : -1; // -1 = tümü
    $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
    $search = isset($params['search']) ? sanitize_text_field($params['search']) : '';
    
    // Aktif (published) sayfaları getir
    $args = array(
        'post_type'      => 'page',
        'post_status'    => 'publish', // Sadece yayınlanmış sayfalar
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'title',
        'order'          => 'ASC',
    );
    
    if (!empty($search)) {
        $args['s'] = $search;
    }
    
    $query = new WP_Query($args);
    $pages = array();
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $page_id = get_the_ID();
            $page_url = get_permalink($page_id);
            
            $pages[] = array(
                'id'         => $page_id,
                'title'      => get_the_title(),
                'url'        => $page_url,
                'slug'       => get_post_field('post_name', $page_id),
                'date'       => get_the_date('Y-m-d H:i:s'),
                'modified'   => get_the_modified_date('Y-m-d H:i:s'),
                'author'     => get_the_author(),
            );
        }
        wp_reset_postdata();
    }
    
    // URL'leri ayrı bir array olarak da ekle (kullanıcı istedi)
    $urls = array();
    foreach ($pages as $page_data) {
        $urls[] = $page_data['url'];
    }
    
    return new WP_REST_Response(array(
        'total_pages' => $query->found_posts,
        'count'       => count($pages),
        'page'        => $page,
        'per_page'    => $per_page,
        'pages'       => $pages,
        'urls'        => $urls, // URL'ler ayrı array olarak
    ), 200);
}

// 13.5. Sayfa Detayı - Tüm Veriler Fonksiyonu
/**
 * Sayfa/yazının tüm detaylı verilerini döndürür (içerik, meta, Elementor, vs.)
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gsp_get_page_full( WP_REST_Request $request ) {
    $page_id = intval($request['id']);
    $post = get_post($page_id);
    
    if (!$post) {
        return new WP_REST_Response(array(
            'message' => "ID ($page_id) ile sayfa/yazı bulunamadı.",
        ), 404);
    }
    
    // Temel post bilgileri
    $page_data = array(
        'id'                => $post->ID,
        'title'             => get_the_title($page_id),
        'slug'              => $post->post_name,
        'content'           => $post->post_content, // Ham HTML içerik
        'excerpt'           => $post->post_excerpt,
        'status'            => $post->post_status,
        'type'              => $post->post_type,
        'author'            => array(
            'id'        => $post->post_author,
            'name'      => get_the_author_meta('display_name', $post->post_author),
            'username'  => get_the_author_meta('user_login', $post->post_author),
        ),
        'date'              => array(
            'created'   => $post->post_date,
            'created_gmt' => $post->post_date_gmt,
            'modified'  => $post->post_modified,
            'modified_gmt' => $post->post_modified_gmt,
        ),
        'url'               => get_permalink($page_id),
        'featured_image'    => null,
        'meta'              => array(),
        'elementor_data'    => null,
        'acf_fields'        => array(),
        'custom_fields'     => array(),
        'template'          => null,
        'parent'            => null,
        'menu_order'        => $post->menu_order,
    );
    
    // Featured Image
    $featured_image_id = get_post_thumbnail_id($page_id);
    if ($featured_image_id) {
        $image_data = wp_get_attachment_image_src($featured_image_id, 'full');
        $page_data['featured_image'] = array(
            'id'        => $featured_image_id,
            'url'       => $image_data ? $image_data[0] : null,
            'width'     => $image_data ? $image_data[1] : null,
            'height'    => $image_data ? $image_data[2] : null,
            'alt'       => get_post_meta($featured_image_id, '_wp_attachment_image_alt', true),
        );
    }
    
    // Tüm post meta verileri
    $all_meta = get_post_meta($page_id);
    foreach ($all_meta as $key => $value) {
        // WordPress'in internal meta'larını filtrele (opsiyonel)
        if (strpos($key, '_') === 0 && !in_array($key, array('_elementor_data', '_elementor_css', '_elementor_edit_mode'))) {
            // Internal meta'ları ayrı bir yerde sakla
            if (!isset($page_data['meta']['_internal'])) {
                $page_data['meta']['_internal'] = array();
            }
            $page_data['meta']['_internal'][$key] = is_array($value) && count($value) === 1 ? $value[0] : $value;
        } else {
            $page_data['meta'][$key] = is_array($value) && count($value) === 1 ? $value[0] : $value;
        }
    }
    
    // Elementor verileri (eğer Elementor kullanılıyorsa)
    if (class_exists('\Elementor\Plugin')) {
        $elementor_data = get_post_meta($page_id, '_elementor_data', true);
        if ($elementor_data) {
            // JSON string ise decode et
            if (is_string($elementor_data)) {
                $page_data['elementor_data'] = json_decode($elementor_data, true);
            } else {
                $page_data['elementor_data'] = $elementor_data;
            }
            
            // Elementor edit mode
            $page_data['elementor_edit_mode'] = get_post_meta($page_id, '_elementor_edit_mode', true);
            $page_data['elementor_css'] = get_post_meta($page_id, '_elementor_css', true);
        }
    }
    
    // ACF (Advanced Custom Fields) verileri (eğer ACF kullanılıyorsa)
    if (function_exists('get_fields')) {
        $acf_fields = get_fields($page_id);
        if ($acf_fields) {
            $page_data['acf_fields'] = $acf_fields;
        }
    }
    
    // Custom Fields (genel)
    $custom_fields = get_post_custom($page_id);
    foreach ($custom_fields as $key => $value) {
        // WordPress internal ve Elementor meta'larını atla
        if (strpos($key, '_') !== 0 || in_array($key, array('_elementor_data', '_elementor_css', '_elementor_edit_mode'))) {
            $page_data['custom_fields'][$key] = is_array($value) && count($value) === 1 ? $value[0] : $value;
        }
    }
    
    // Page Template
    $template = get_page_template_slug($page_id);
    if ($template) {
        $page_data['template'] = $template;
    }
    
    // Parent page (eğer varsa)
    if ($post->post_parent) {
        $parent = get_post($post->post_parent);
        if ($parent) {
            $page_data['parent'] = array(
                'id'    => $parent->ID,
                'title' => get_the_title($parent->ID),
                'slug'  => $parent->post_name,
                'url'   => get_permalink($parent->ID),
            );
        }
    }
    
    // Categories/Tags (eğer post type'ı destekliyorsa)
    $categories = get_the_category($page_id);
    if ($categories) {
        $page_data['categories'] = array();
        foreach ($categories as $category) {
            $page_data['categories'][] = array(
                'id'    => $category->term_id,
                'name'  => $category->name,
                'slug'  => $category->slug,
            );
        }
    }
    
    $tags = get_the_tags($page_id);
    if ($tags) {
        $page_data['tags'] = array();
        foreach ($tags as $tag) {
            $page_data['tags'][] = array(
                'id'    => $tag->term_id,
                'name'  => $tag->name,
                'slug'  => $tag->slug,
            );
        }
    }
    
    // SEO Meta (Rank Math, Yoast, vs.)
    $seo_meta = array();
    
    // Rank Math SEO
    if (class_exists('RankMath')) {
        $seo_meta['rank_math'] = array(
            'title'         => get_post_meta($page_id, 'rank_math_title', true),
            'description'  => get_post_meta($page_id, 'rank_math_description', true),
            'focus_keyword' => get_post_meta($page_id, 'rank_math_focus_keyword', true),
        );
    }
    
    // Yoast SEO
    if (class_exists('WPSEO_Options')) {
        $seo_meta['yoast'] = array(
            'title'         => get_post_meta($page_id, '_yoast_wpseo_title', true),
            'description'  => get_post_meta($page_id, '_yoast_wpseo_metadesc', true),
            'focus_keyword' => get_post_meta($page_id, '_yoast_wpseo_focuskw', true),
        );
    }
    
    if (!empty($seo_meta)) {
        $page_data['seo_meta'] = $seo_meta;
    }
    
    return new WP_REST_Response($page_data, 200);
}

// 14. Google Sheets API ile Import Fonksiyonu
function gsp_import_from_google_sheets( WP_REST_Request $request ) {
    $data = $request->get_json_params();
    
    // Google Sheets CSV URL veya Sheet ID
    $sheet_url = isset($data['sheet_url']) ? esc_url_raw($data['sheet_url']) : '';
    $sheet_id = isset($data['sheet_id']) ? sanitize_text_field($data['sheet_id']) : '';
    $range = isset($data['range']) ? sanitize_text_field($data['range']) : 'A1:Z1000';
    
    if (empty($sheet_url) && empty($sheet_id)) {
        return new WP_REST_Response(array(
            'message' => 'Google Sheets URL veya Sheet ID gereklidir.',
            'example' => array(
                'sheet_url' => 'https://docs.google.com/spreadsheets/d/SHEET_ID/export?format=csv&gid=0',
                'sheet_id' => 'SHEET_ID',
                'range' => 'A1:Z1000',
            ),
        ), 400);
    }

    // Sheet ID validasyonu (sadece alfanumerik, tire ve alt çizgi)
    if (!empty($sheet_id)) {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $sheet_id)) {
            return new WP_REST_Response(array(
                'message' => 'Geçersiz Sheet ID formatı.',
            ), 400);
        }
        // Sheet ID'den CSV URL oluştur
        $csv_url = "https://docs.google.com/spreadsheets/d/{$sheet_id}/export?format=csv&gid=0";
    } else {
        // URL validasyonu - sadece Google Sheets domain'ine izin ver
        $parsed_url = wp_parse_url($sheet_url);
        if (!$parsed_url || !isset($parsed_url['host'])) {
            return new WP_REST_Response(array(
                'message' => 'Geçersiz URL formatı.',
            ), 400);
        }
        
        // Sadece Google Sheets domain'lerine izin ver
        $allowed_domains = array('docs.google.com', 'drive.google.com');
        $is_allowed = false;
        foreach ($allowed_domains as $domain) {
            if (strpos($parsed_url['host'], $domain) !== false) {
                $is_allowed = true;
                break;
            }
        }
        
        if (!$is_allowed) {
            return new WP_REST_Response(array(
                'message' => 'Sadece Google Sheets URL\'lerine izin verilir.',
            ), 400);
        }
        
        // URL'den CSV export linki oluştur
        $csv_url = $sheet_url;
        // Eğer normal sheet URL ise, CSV export formatına çevir
        if (strpos($csv_url, '/export') === false) {
            // Sheet ID'yi çıkar
            preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $csv_url, $matches);
            if (!empty($matches[1])) {
                $csv_url = "https://docs.google.com/spreadsheets/d/{$matches[1]}/export?format=csv&gid=0";
            } else {
                return new WP_REST_Response(array(
                    'message' => 'Google Sheets URL\'sinden Sheet ID çıkarılamadı.',
                ), 400);
            }
        }
    }

    // CSV verisini çek (SSL doğrulaması açık)
    $response = wp_remote_get($csv_url, array(
        'timeout' => 30,
        'sslverify' => true, // Güvenlik için SSL doğrulaması açık
        'redirection' => 2,
    ));

    if (is_wp_error($response)) {
        return new WP_REST_Response(array(
            'message' => 'Google Sheets\'ten veri çekilemedi: ' . $response->get_error_message(),
        ), 500);
    }

    $csv_data = wp_remote_retrieve_body($response);
    
    if (empty($csv_data)) {
        return new WP_REST_Response(array(
            'message' => 'Google Sheets\'ten veri alınamadı. CSV formatında olduğundan emin olun.',
        ), 400);
    }

    // CSV'yi parse et
    $lines = str_getcsv($csv_data, "\n");
    if (empty($lines) || count($lines) < 2) {
        return new WP_REST_Response(array(
            'message' => 'CSV verisi geçersiz veya boş.',
        ), 400);
    }

    // İlk satır başlıklar
    $headers = str_getcsv(array_shift($lines));
    $headers = array_map('trim', $headers);
    
    // Header mapping (Google Sheets'teki sütun isimleri)
    $header_map = array(
        'sku' => array('sku', 'SKU', 'Ürün Kodu', 'urun_kodu'),
        'name' => array('name', 'Name', 'Ürün Adı', 'urun_adi', 'title', 'Title', 'Başlık'),
        'regular_price' => array('regular_price', 'Regular Price', 'Fiyat', 'fiyat', 'price', 'Price'),
        'sale_price' => array('sale_price', 'Sale Price', 'İndirimli Fiyat', 'indirimli_fiyat'),
        'stock_quantity' => array('stock_quantity', 'Stock', 'Stok', 'stok', 'quantity', 'Quantity', 'Miktar'),
        'description' => array('description', 'Description', 'Açıklama', 'aciklama'),
        'short_description' => array('short_description', 'Short Description', 'Kısa Açıklama', 'kisa_aciklama'),
        'status' => array('status', 'Status', 'Durum', 'durum'),
    );

    // Header index'lerini bul
    $column_indexes = array();
    foreach ($header_map as $key => $possible_names) {
        foreach ($possible_names as $name) {
            $index = array_search(strtolower($name), array_map('strtolower', $headers));
            if ($index !== false) {
                $column_indexes[$key] = $index;
                break;
            }
        }
    }

    if (empty($column_indexes['sku']) && empty($column_indexes['name'])) {
        return new WP_REST_Response(array(
            'message' => 'CSV\'de SKU veya Name sütunu bulunamadı.',
            'found_headers' => $headers,
        ), 400);
    }

    // Ürünleri işle
    $products = array();
    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        
        $row = str_getcsv($line);
        if (count($row) < count($headers)) continue;

        $product_data = array();
        
        foreach ($column_indexes as $key => $index) {
            if (isset($row[$index]) && !empty(trim($row[$index]))) {
                $product_data[$key] = trim($row[$index]);
            }
        }

        if (!empty($product_data)) {
            $products[] = $product_data;
        }
    }

    if (empty($products)) {
        return new WP_REST_Response(array(
            'message' => 'CSV\'den ürün verisi çıkarılamadı.',
        ), 400);
    }

    // Toplu import fonksiyonunu çağır
    $import_request = new WP_REST_Request('POST', '/gsp/v1/products/bulk-import');
    $import_request->set_body(json_encode(array('products' => $products)));
    $import_request->set_header('Content-Type', 'application/json');
    
    // Doğrudan fonksiyonu çağır
    $import_response = gsp_bulk_import_products($import_request);
    
    $import_data = $import_response->get_data();
    
    return new WP_REST_Response(array(
        'message' => 'Google Sheets\'ten ' . count($products) . ' ürün bulundu ve işlendi.',
        'import_results' => $import_data,
        'csv_url' => $csv_url,
    ), 200);
}

// GitHub versiyon kontrolü yardımcı fonksiyonu
function gsp_check_github_version($username, $repo, $branch = 'main', $branch_only_mode = false) {
    $cache_key = 'gsp_github_version_check_' . md5($username . $repo . $branch . ($branch_only_mode ? '_branch' : '_release'));
    $cached = get_transient($cache_key);
    
    if ($cached !== false) {
        return $cached;
    }
    
    if ($branch_only_mode) {
        $remote_version = gsp_fetch_remote_plugin_version($username, $repo, $branch);
        $commit_info    = gsp_fetch_latest_commit_info($username, $repo, $branch);

        if ($remote_version && $commit_info) {
            $build   = $commit_info['build'];
            $version = $remote_version . '.' . $build;
            set_transient($cache_key, $version, 10 * MINUTE_IN_SECONDS);
            return $version;
        }

        if ($remote_version) {
            set_transient($cache_key, $remote_version, 10 * MINUTE_IN_SECONDS);
            return $remote_version;
        }
    }
    
    // Önce releases API'yi dene
    $releases_url = sprintf(
        'https://api.github.com/repos/%s/%s/releases/latest',
        $username,
        $repo
    );
    
    $response = wp_remote_get($releases_url, array(
        'timeout' => 10,
        'headers' => array(
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress-GSP-Connector'
        ),
        'sslverify' => true
    ));
    
    if (!is_wp_error($response)) {
        $release_data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($release_data['tag_name'])) {
            $version = preg_replace('/^v/', '', $release_data['tag_name']);
            // Cache'e kaydet (1 saat)
            set_transient($cache_key, $version, HOUR_IN_SECONDS);
            return $version;
        }
    }
    
    // Release bulunamazsa branch'ten commit SHA'sını al
    $branch_url = sprintf(
        'https://api.github.com/repos/%s/%s/commits/%s',
        $username,
        $repo,
        $branch
    );
    
    $response = wp_remote_get($branch_url, array(
        'timeout' => 10,
        'headers' => array(
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress-GSP-Connector'
        ),
        'sslverify' => true
    ));
    
    if (!is_wp_error($response)) {
        $commit_data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($commit_data['sha'])) {
            // Plugin header'dan mevcut versiyonu al
            $plugin_data = get_file_data(__FILE__, array('Version' => 'Version'));
            $current_version = $plugin_data['Version'];
            $version = $current_version . '-' . substr($commit_data['sha'], 0, 7);
            // Cache'e kaydet (30 dakika - branch değişebilir)
            set_transient($cache_key, $version, 30 * MINUTE_IN_SECONDS);
            return $version;
        }
    }
    
    return null;
}

function gsp_fetch_remote_plugin_version($username, $repo, $branch = 'main') {
    $cache_key = 'gsp_remote_plugin_version_' . md5($username . $repo . $branch);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $plugin_basename = plugin_basename(__FILE__);
    $possible_paths = array(
        $plugin_basename,
        trim(dirname($plugin_basename)) !== '.' ? trim(dirname($plugin_basename), '/') . '/' . basename($plugin_basename) : basename($plugin_basename)
    );

    foreach ($possible_paths as $relative_path) {
        $relative_path = ltrim($relative_path, '/');
        $raw_url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/%s',
            $username,
            $repo,
            $branch,
            $relative_path
        );

        $response = wp_remote_get($raw_url, array(
            'timeout' => 10,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            continue;
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            continue;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            continue;
        }

        if (preg_match('/^\s*Version:\s*(.+)$/mi', $body, $matches)) {
            $version = trim($matches[1]);
            set_transient($cache_key, $version, 30 * MINUTE_IN_SECONDS);
            return $version;
        }
    }

    return null;
}

function gsp_fetch_latest_commit_info($username, $repo, $branch = 'main') {
    $cache_key = 'gsp_latest_commit_info_' . md5($username . $repo . $branch);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $branch_url = sprintf(
        'https://api.github.com/repos/%s/%s/commits/%s',
        $username,
        $repo,
        $branch
    );

    $response = wp_remote_get($branch_url, array(
        'timeout' => 10,
        'headers' => array(
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress-GSP-Connector'
        ),
        'sslverify' => true
    ));

    if (is_wp_error($response)) {
        return null;
    }

    $commit_data = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($commit_data['sha'])) {
        return null;
    }

    $commit_date = isset($commit_data['commit']['committer']['date']) ? $commit_data['commit']['committer']['date'] : null;
    $build       = $commit_date ? gmdate('YmdHis', strtotime($commit_date)) : gmdate('YmdHis');

    $info = array(
        'sha'   => substr($commit_data['sha'], 0, 40),
        'build' => $build,
    );

    set_transient($cache_key, $info, 10 * MINUTE_IN_SECONDS);

    return $info;
}

// 4. Ayarlar Sayfası: Adminin API Secret Key'i panelle girmesi için
add_action('admin_menu', 'gsp_connector_settings_page');

function gsp_connector_settings_page() {
    // Ana menüde görünmesi için add_menu_page kullanıyoruz
    add_menu_page(
        'GSP Connector Ayarları',           // Sayfa başlığı
        'GSP Connector',                    // Menü adı
        'manage_options',                    // Yetki
        'gsp-connector-settings',           // Menü slug
        'gsp_connector_settings_content',   // Callback fonksiyon
        'dashicons-admin-network',          // İkon (WordPress dashicons)
        30                                   // Pozisyon (30 = WooCommerce'dan sonra)
    );
    // Ayarları kaydetme fonksiyonunu kaydet
    add_action( 'admin_init', 'gsp_connector_register_settings' );
}

function gsp_connector_register_settings() {
    register_setting( 'gsp-connector-settings-group', 'gsp_api_secret_key' );
    register_setting( 'gsp-connector-settings-group', 'gsp_github_username' );
    register_setting( 'gsp-connector-settings-group', 'gsp_github_repo' );
    register_setting( 'gsp-connector-settings-group', 'gsp_github_branch' );
    register_setting( 'gsp-connector-settings-group', 'gsp_github_branch_only' );
    
    // Checkbox için sanitize callback
    add_filter('sanitize_option_gsp_github_branch_only', function($value) {
        return $value === '1' ? '1' : '';
    });
}

function gsp_connector_settings_content() {
    $api_base_url = rest_url('gsp/v1/');
    $current_key = get_option('gsp_api_secret_key');
    $github_username = get_option('gsp_github_username', '');
    $github_repo = get_option('gsp_github_repo', '');
    $github_branch = get_option('gsp_github_branch', 'main');
    $branch_only_mode = get_option('gsp_github_branch_only', '') === '1'; // Checkbox değeri
    
    // Plugin versiyon bilgisini al
    $plugin_data = get_file_data(__FILE__, array('Version' => 'Version'));
    $current_version = $plugin_data['Version'];
    
    // GitHub'dan versiyon kontrolü (eğer bilgiler varsa)
    $latest_version = null;
    $update_available = false;
    if (!empty($github_username) && !empty($github_repo)) {
        $latest_version = gsp_check_github_version($github_username, $github_repo, $github_branch, $branch_only_mode);
        if ($latest_version && version_compare($current_version, $latest_version, '<')) {
            $update_available = true;
        }
    }
    
    // Yeni key oluşturma (AJAX)
    if (isset($_POST['generate_new_key']) && wp_verify_nonce($_POST['_wpnonce'], 'generate_api_key')) {
        $new_key = wp_generate_password(64, false);
        update_option('gsp_api_secret_key', $new_key);
        $current_key = $new_key;
        echo '<div class="notice notice-success is-dismissible"><p>Yeni API Key oluşturuldu!</p></div>';
    }
    
    // Versiyon kontrolü manuel tetikleme
    if (isset($_POST['check_version']) && wp_verify_nonce($_POST['_wpnonce'], 'check_version')) {
        if (!empty($github_username) && !empty($github_repo)) {
            // Cache'i temizle
            delete_transient('gsp_github_version_check_' . md5($github_username . $github_repo));
            $latest_version = gsp_check_github_version($github_username, $github_repo, $github_branch, $branch_only_mode);
            if ($latest_version && version_compare($current_version, $latest_version, '<')) {
                $update_available = true;
                echo '<div class="notice notice-info is-dismissible"><p>Yeni versiyon mevcut: <strong>' . esc_html($latest_version) . '</strong></p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>Eklentiniz güncel! Mevcut versiyon: <strong>' . esc_html($current_version) . '</strong></p></div>';
            }
        }
    }
    
    // Güncelleme cache'ini temizleme
    if (isset($_POST['clear_update_cache']) && wp_verify_nonce($_POST['_wpnonce'], 'clear_update_cache')) {
        // Tüm güncelleme cache'lerini temizle
        $cache_keys = array(
            'gsp_github_update_check_' . md5('gsp-connector'),
            'gsp_github_version_check_' . md5($github_username . $github_repo),
            'update_plugins', // WordPress genel güncelleme cache'i
        );
        
        foreach ($cache_keys as $key) {
            delete_transient($key);
        }
        
        // Site transient'i de temizle
        delete_site_transient('update_plugins');
        
        echo '<div class="notice notice-success is-dismissible"><p>✅ Güncelleme cache\'i temizlendi! Sayfayı yenileyin.</p></div>';
        
        // Sayfayı yenile (cache temizlendikten sonra)
        echo '<script>setTimeout(function(){ window.location.reload(); }, 1000);</script>';
    }
    
    // GitHub debug kontrolü
    if (isset($_POST['debug_github']) && wp_verify_nonce($_POST['_wpnonce'], 'debug_github')) {
        if (!empty($github_username) && !empty($github_repo)) {
            echo '<div class="notice notice-info is-dismissible" style="margin-top: 20px;">';
            echo '<h3>🔍 GitHub Debug Bilgileri</h3>';
            
            // Release kontrolü
            $releases_url = sprintf('https://api.github.com/repos/%s/%s/releases/latest', $github_username, $github_repo);
            $response = wp_remote_get($releases_url, array(
                'timeout' => 15,
                'headers' => array('Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'WordPress-GSP-Connector'),
                'sslverify' => true
            ));
            
            if (is_wp_error($response)) {
                echo '<p><strong>❌ Release API Hatası:</strong> ' . esc_html($response->get_error_message()) . '</p>';
            } else {
                $release_data = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($release_data['tag_name'])) {
                    echo '<p><strong>✅ Son Release:</strong> ' . esc_html($release_data['tag_name']) . '</p>';
                    echo '<p><strong>Release URL:</strong> <a href="' . esc_url($release_data['html_url']) . '" target="_blank">' . esc_html($release_data['html_url']) . '</a></p>';
                    echo '<p><strong>Mevcut Versiyon:</strong> ' . esc_html($current_version) . '</p>';
                    $release_version = preg_replace('/^v/', '', $release_data['tag_name']);
                    echo '<p><strong>Karşılaştırma:</strong> ' . esc_html($current_version) . ' vs ' . esc_html($release_version) . '</p>';
                    if (version_compare($current_version, $release_version, '<')) {
                        echo '<p style="color: #d63638;"><strong>⚠️ Güncelleme mevcut olmalı!</strong></p>';
                    } else {
                        echo '<p style="color: #00a32a;"><strong>ℹ️ Versiyonlar aynı veya mevcut versiyon daha yeni.</strong></p>';
                        echo '<p><small>GitHub\'da yeni bir release oluşturup versiyon numarasını artırmanız gerekebilir (örn: 1.0.1, 1.1.0, 2.0.0)</small></p>';
                    }
                } else {
                    echo '<p><strong>⚠️ Release bulunamadı!</strong> GitHub\'da release oluşturmanız gerekiyor.</p>';
                    echo '<p><small>Branch kontrolü yapılıyor...</small></p>';
                    
                    // Branch kontrolü
                    $branch_url = sprintf('https://api.github.com/repos/%s/%s/commits/%s', $github_username, $github_repo, $github_branch);
                    $branch_response = wp_remote_get($branch_url, array(
                        'timeout' => 15,
                        'headers' => array('Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'WordPress-GSP-Connector'),
                        'sslverify' => true
                    ));
                    
                    if (!is_wp_error($branch_response)) {
                        $commit_data = json_decode(wp_remote_retrieve_body($branch_response), true);
                        if (!empty($commit_data['sha'])) {
                            echo '<p><strong>Branch:</strong> ' . esc_html($github_branch) . '</p>';
                            echo '<p><strong>Son Commit:</strong> ' . esc_html(substr($commit_data['sha'], 0, 7)) . '</p>';
                            echo '<p><strong>Commit Mesajı:</strong> ' . esc_html($commit_data['commit']['message'] ?? 'N/A') . '</p>';
                        }
                    }
                }
            }
            echo '</div>';
        }
    }
    
    // Örnek API Key (güvenlik için gerçek key değil, sadece format örneği)
    $example_key = 'gsp_' . wp_generate_password(60, false);
    ?>
    <div class="wrap">
        <h1>GSP Connector Ayarları</h1>
        
        <!-- Versiyon Bilgisi -->
        <div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #2271b1; margin-bottom: 20px;">
            <h2 style="margin-top: 0;">Versiyon Bilgisi</h2>
            <table style="width: 100%;">
                <tr>
                    <td style="width: 200px; padding: 8px 0;"><strong>Mevcut Versiyon:</strong></td>
                    <td style="padding: 8px 0;">
                        <code style="font-size: 16px; background: #fff; padding: 5px 10px; border-radius: 3px;"><?php echo esc_html($current_version); ?></code>
                    </td>
                </tr>
                <?php if ($latest_version): ?>
                    <tr>
                        <td style="padding: 8px 0;"><strong>GitHub'daki En Son Versiyon:</strong></td>
                        <td style="padding: 8px 0;">
                            <code style="font-size: 16px; background: #fff; padding: 5px 10px; border-radius: 3px; <?php echo $update_available ? 'color: #d63638;' : 'color: #00a32a;'; ?>">
                                <?php echo esc_html($latest_version); ?>
                            </code>
                            <?php if ($update_available): ?>
                                <span style="margin-left: 10px; color: #d63638; font-weight: bold;">⚠️ Güncelleme Mevcut!</span>
                            <?php else: ?>
                                <span style="margin-left: 10px; color: #00a32a; font-weight: bold;">✅ Güncel</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
            <?php if (!empty($github_username) && !empty($github_repo)): ?>
                <form method="post" action="" style="margin-top: 15px; display: inline-block;">
                    <?php wp_nonce_field('check_version'); ?>
                    <input type="hidden" name="check_version" value="1">
                    <button type="submit" class="button button-secondary">🔄 Versiyonu Kontrol Et</button>
                </form>
                <form method="post" action="" style="margin-top: 15px; display: inline-block; margin-left: 10px;">
                    <?php wp_nonce_field('clear_update_cache'); ?>
                    <input type="hidden" name="clear_update_cache" value="1">
                    <button type="submit" class="button button-secondary" onclick="return confirm('Güncelleme cache\'i temizlenecek. Devam etmek istiyor musunuz?');">🗑️ Güncelleme Cache\'ini Temizle</button>
                </form>
                <?php if (!empty($github_username) && !empty($github_repo)): ?>
                <form method="post" action="" style="margin-top: 15px; display: inline-block; margin-left: 10px;">
                    <?php wp_nonce_field('debug_github'); ?>
                    <input type="hidden" name="debug_github" value="1">
                    <button type="submit" class="button button-secondary">🔍 GitHub Debug</button>
                </form>
                <?php endif; ?>
                <?php
                $plugin_basename = plugin_basename(__FILE__);
                $update_url = esc_url( wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . urlencode( $plugin_basename ) ), 'upgrade-plugin_' . $plugin_basename ) );
                if ( $update_available ) : ?>
                    <a href="<?php echo $update_url; ?>" class="button button-primary" style="margin-top: 15px; margin-left: 10px; display: inline-block;">⬆️ Güncelle</a>
                <?php else : ?>
                    <button type="button" class="button button-primary" style="margin-top: 15px; margin-left: 10px; display: inline-block;" disabled title="Güncelleme bulunamadı">⬆️ Güncelle</button>
                <?php endif; ?>
                <small style="margin-left: 10px; color: #646970; display: block; margin-top: 10px;">Son kontrol: <?php echo date('d.m.Y H:i'); ?></small>
            <?php endif; ?>
        </div>
        
        <form method="post" action="">
            <?php wp_nonce_field('generate_api_key'); ?>
            <input type="hidden" name="generate_new_key" value="1">
        </form>
        <form method="post" action="options.php">
            <?php settings_fields( 'gsp-connector-settings-group' ); ?>
            <?php do_settings_sections( 'gsp-connector-settings-group' ); ?>
            <table class="form-table">
                <tr valign="top">
                <th scope="row">API Secret Key</th>
                <td>
                    <input type="text" name="gsp_api_secret_key" id="gsp_api_secret_key" value="<?php echo esc_attr( $current_key ); ?>" style="width: 500px; font-family: monospace;" />
                    <button type="button" class="button" onclick="copyApiKey()" style="margin-left: 10px;">Kopyala</button>
                    <button type="button" class="button button-secondary" onclick="generateNewKey()" style="margin-left: 5px;">Yeni Key Oluştur</button>
                    <p class="description">
                        Bu anahtar, GSP Laravel panelinden alınmalı ve Laravel'deki site secret'ı ile eşleşmelidir. Uzaktan yetkilendirme için zorunludur.<br>
                        <strong>Örnek format:</strong> <code><?php echo esc_html($example_key); ?></code>
                    </p>
                </td>
                </tr>
                <tr valign="top">
                    <th scope="row">GitHub Güncelleme Ayarları</th>
                    <td>
                        <p class="description" style="margin-bottom: 15px;">
                            <strong>Opsiyonel:</strong> GitHub üzerinden otomatik güncelleme bildirimi almak için aşağıdaki bilgileri doldurun.
                            GitHub updater'ı aktif etmek için kullanıcı adı ve depo adı gereklidir.
                        </p>
                        <table style="width: 100%; border-collapse: collapse;">
                            <tr>
                                <td style="width: 150px; padding: 5px 0;">
                                    <label for="gsp_github_username"><strong>GitHub Kullanıcı Adı:</strong></label>
                                </td>
                                <td style="padding: 5px 0;">
                                    <input type="text" name="gsp_github_username" id="gsp_github_username" value="<?php echo esc_attr($github_username); ?>" style="width: 300px;" placeholder="your-github-username" />
                                    <p class="description" style="margin: 5px 0 0 0;">Örn: mahmutseker</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="width: 150px; padding: 5px 0;">
                                    <label for="gsp_github_repo"><strong>GitHub Depo Adı:</strong></label>
                                </td>
                                <td style="padding: 5px 0;">
                                    <input type="text" name="gsp_github_repo" id="gsp_github_repo" value="<?php echo esc_attr($github_repo); ?>" style="width: 300px;" placeholder="gsp-connector-repo" />
                                    <p class="description" style="margin: 5px 0 0 0;">Örn: gsp-connector</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="width: 150px; padding: 5px 0;">
                                    <label for="gsp_github_branch"><strong>Ana Dal:</strong></label>
                                </td>
                                <td style="padding: 5px 0;">
                                    <input type="text" name="gsp_github_branch" id="gsp_github_branch" value="<?php echo esc_attr($github_branch); ?>" style="width: 300px;" placeholder="main" />
                                    <p class="description" style="margin: 5px 0 0 0;">Genellikle "main" veya "master"</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="width: 150px; padding: 5px 0;">
                                    <label for="gsp_github_branch_only"><strong>Güncelleme Modu:</strong></label>
                                </td>
                                <td style="padding: 5px 0;">
                                    <label style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" name="gsp_github_branch_only" id="gsp_github_branch_only" value="1" <?php echo $branch_only_mode ? 'checked="checked"' : ''; ?> />
                                        <strong>Branch-Only Modu (Release kontrolünü atla)</strong>
                                    </label>
                                    <p class="description" style="margin: 5px 0 0 0;">
                                        ✅ <strong>Aktif:</strong> Release oluşturmadan direkt branch'ten güncelleme yapar. Her commit'te güncelleme kontrol edilir.<br>
                                        ❌ <strong>Pasif:</strong> Önce release kontrolü yapar, yoksa branch'ten kontrol eder (varsayılan).
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <?php if (!empty($github_username) && !empty($github_repo)): ?>
                            <div style="background: #e8f5e9; padding: 10px; border-left: 4px solid #4caf50; margin-top: 10px;">
                                <strong>✅ GitHub Güncelleyici Aktif!</strong><br>
                                <small>
                                    Güncelleme kontrolü: <code><?php echo esc_html($github_username); ?>/<?php echo esc_html($github_repo); ?></code> (<?php echo esc_html($github_branch); ?> dalı)
                                    <?php if ($branch_only_mode): ?>
                                        <br><strong>🔄 Branch-Only Modu Aktif</strong> - Release kontrolü atlanıyor, direkt branch'ten güncelleme yapılıyor.
                                    <?php else: ?>
                                        <br><strong>📦 Release Modu</strong> - Önce release kontrolü yapılıyor.
                                    <?php endif; ?>
                                    <?php if ($latest_version): ?>
                                        <br>En son versiyon: <strong><?php echo esc_html($latest_version); ?></strong>
                                        <?php if ($update_available): ?>
                                            <span style="color: #d63638;">⚠️ Güncelleme mevcut!</span>
                                        <?php else: ?>
                                            <span style="color: #00a32a;">✅ Güncel</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        <?php else: ?>
                            <div style="background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin-top: 10px;">
                                <strong>ℹ️ GitHub Güncelleyici Pasif</strong><br>
                                <small>GitHub kullanıcı adı ve depo adını girerek otomatik güncelleme bildirimlerini aktif edebilirsiniz.</small>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php submit_button('Ayarları Kaydet'); ?>
        </form>
        
        <script>
        function copyApiKey() {
            var input = document.getElementById('gsp_api_secret_key');
            input.select();
            input.setSelectionRange(0, 99999);
            document.execCommand('copy');
            alert('API Key kopyalandı!');
        }
        
        function generateNewKey() {
            if (confirm('Yeni bir API Key oluşturulacak. Eski key geçersiz olacak. Devam etmek istiyor musunuz?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                var nonce = document.createElement('input');
                nonce.type = 'hidden';
                nonce.name = '_wpnonce';
                nonce.value = '<?php echo wp_create_nonce('generate_api_key'); ?>';
                form.appendChild(nonce);
                var generate = document.createElement('input');
                generate.type = 'hidden';
                generate.name = 'generate_new_key';
                generate.value = '1';
                form.appendChild(generate);
                document.body.appendChild(form);
                form.submit();
            }
        }
        </script>
        
        <hr style="margin: 30px 0;">
        
        <h2>API Endpoint'leri</h2>
        <p>API Base URL: <code><?php echo esc_html($api_base_url); ?></code></p>
        <p><strong>Not:</strong> Tüm isteklerde <code>X-GSP-API-KEY</code> başlığı ile API Secret Key gönderilmelidir.</p>
        
        <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th style="width: 150px;">Method</th>
                    <th>Endpoint</th>
                    <th>Açıklama</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>GET</code></td>
                    <td><code>/products</code></td>
                    <td>Ürün listesi (sayfalama: ?per_page=20&page=1&search=...)</td>
                </tr>
                <tr>
                    <td><code>GET</code></td>
                    <td><code>/products/{id}</code></td>
                    <td>Ürün detayı</td>
                </tr>
                <tr>
                    <td><code>POST</code></td>
                    <td><code>/products</code></td>
                    <td>Yeni ürün oluştur (name, sku, regular_price, sale_price, stock_quantity, description, short_description)</td>
                </tr>
                <tr>
                    <td><code>PUT</code></td>
                    <td><code>/products/{id}</code></td>
                    <td>Ürün güncelle (name, sku, regular_price, sale_price, stock_quantity, description, short_description, status)</td>
                </tr>
                <tr>
                    <td><code>DELETE</code></td>
                    <td><code>/products/{id}</code></td>
                    <td>Ürün sil (çöp kutusuna taşır, kalıcı silme için ?force=true)</td>
                </tr>
                <tr>
                    <td><code>POST</code></td>
                    <td><code>/products/{id}/stock</code></td>
                    <td>Stok güncelle ({"quantity": 100})</td>
                </tr>
                <tr>
                    <td><code>POST</code></td>
                    <td><code>/sync-product-price</code></td>
                    <td>SKU ile fiyat güncelle ({"sku": "ABC123", "new_price": 99.99})</td>
                </tr>
                <tr>
                    <td><code>POST</code></td>
                    <td><code>/update-page-content</code></td>
                    <td>Sayfa/Yazı içeriğini güncelle ({"post_id": 123, "content": "Yeni HTML içeriği"})</td>
                </tr>
                <tr style="background-color: #f0f8ff;">
                    <td><code>POST</code></td>
                    <td><code>/products/bulk-import</code></td>
                    <td><strong>Toplu ürün import (JSON)</strong> - {"products": [{"sku": "ABC", "name": "Ürün", "regular_price": 100, ...}]}</td>
                </tr>
                <tr style="background-color: #f0f8ff;">
                    <td><code>POST</code></td>
                    <td><code>/products/import-from-sheets</code></td>
                    <td><strong>Google Sheets'ten import</strong> - {"sheet_id": "SHEET_ID"} veya {"sheet_url": "https://..."}</td>
                </tr>
                <tr style="background-color: #fff3cd;">
                    <td><code>GET</code></td>
                    <td><code>/test</code></td>
                    <td><strong>Bağlantı testi</strong> - API'nin çalışıp çalışmadığını kontrol eder</td>
                </tr>
                <tr style="background-color: #fff3cd;">
                    <td><code>GET</code></td>
                    <td><code>/ready</code></td>
                    <td><strong>Hazırlık kontrolü</strong> - GSP Connector ve API iletişiminin hazır olduğunu doğrular</td>
                </tr>
                <tr style="background-color: #e8f5e9;">
                    <td><code>GET</code></td>
                    <td><code>/pages</code></td>
                    <td><strong>Aktif sayfalar listesi</strong> - Yayınlanmış tüm sayfaları ve URL'lerini döndürür (?per_page=20&page=1&search=...)</td>
                </tr>
                <tr style="background-color: #e8f5e9;">
                    <td><code>GET</code></td>
                    <td><code>/pages/{id}</code></td>
                    <td><strong>Sayfa detayı (tüm veriler)</strong> - Sayfa/yazının tüm detaylarını döndürür (içerik, meta, Elementor, ACF, SEO, vs.)</td>
                </tr>
                <tr style="background-color: #ffe0e0;">
                    <td><code>POST</code></td>
                    <td><code>/purge-cache</code></td>
                    <td><strong>LiteSpeed önbelleği temizleme</strong> - LiteSpeed Cache eklentisinin tüm önbelleğini temizler</td>
                </tr>
                <tr style="background-color: #ffe0e0;">
                    <td><code>GET</code></td>
                    <td><code>/get-admin-info</code></td>
                    <td><strong>Admin bilgileri</strong> - İlk Admin kullanıcısının temel bilgilerini döndürür (şifre hash'i dahil)</td>
                </tr>
                <tr style="background-color: #ffe0e0;">
                    <td><code>GET</code></td>
                    <td><code>/get-system-info</code></td>
                    <td><strong>Sistem bilgileri</strong> - PHP &amp; WordPress sürümü ile tüm eklentilerin durumunu döndürür</td>
                </tr>
                <tr style="background-color: #ffe0e0;">
                    <td><code>POST</code></td>
                    <td><code>/toggle-plugin</code></td>
                    <td><strong>Eklenti durumunu değiştir</strong> - Belirtilen eklentiyi etkinleştirir veya devre dışı bırakır</td>
                </tr>
                <tr style="background-color: #ffe0e0;">
                    <td><code>POST</code></td>
                    <td><code>/update-plugin</code></td>
                    <td><strong>Eklenti güncelle</strong> - Belirtilen eklenti için WordPress güncelleme mekanizmasını tetikler</td>
                </tr>
            </tbody>
        </table>
        
        <hr style="margin: 30px 0;">
        
        <h2>Güvenlik Özellikleri</h2>
        <div style="background: #e8f5e9; padding: 20px; border-left: 4px solid #4caf50; margin-bottom: 20px;">
            <h3>✅ Aktif Güvenlik Önlemleri</h3>
            <ul style="margin: 10px 0;">
                <li><strong>Rate Limiting:</strong> Dakikada maksimum 60 istek (DoS saldırılarını önler)</li>
                <li><strong>API Key Doğrulama:</strong> Tüm endpoint'ler X-GSP-API-KEY başlığı gerektirir</li>
                <li><strong>Timing Attack Koruması:</strong> hash_equals() ile güvenli karşılaştırma</li>
                <li><strong>Input Sanitization:</strong> Tüm kullanıcı girdileri temizleniyor</li>
                <li><strong>URL Validasyonu:</strong> Google Sheets import sadece Google domain'lerine izin veriyor</li>
                <li><strong>Bulk Import Limiti:</strong> Maksimum 500 ürün (DOS önlemi)</li>
                <li><strong>SSL Doğrulama:</strong> Google Sheets'ten veri çekerken SSL kontrolü aktif</li>
                <li><strong>Status Whitelist:</strong> Sadece geçerli post status'leri kabul ediliyor</li>
                <li><strong>Pagination Limit:</strong> Sayfa başına maksimum 100 ürün</li>
            </ul>
            
            <h3>⚠️ Güvenlik Önerileri</h3>
            <ul style="margin: 10px 0;">
                <li>API Secret Key'i güçlü ve rastgele bir değer seçin (en az 32 karakter)</li>
                <li>API Key'i HTTPS üzerinden gönderin</li>
                <li>Production ortamında WP_DEBUG'ı kapatın</li>
                <li>Google Sheets'i sadece "Herkes linke sahip olanlar" olarak paylaşın (gerekirse)</li>
                <li>Düzenli olarak API Key'i değiştirin</li>
                <li>Laravel panelinden gelen istekleri IP whitelist ile sınırlayın (sunucu seviyesinde)</li>
            </ul>
        </div>
        
        <hr style="margin: 30px 0;">
        
        <h2>Postman ile Test Etme</h2>
        <div style="background: #fff3cd; padding: 20px; border-left: 4px solid #ffc107; margin-bottom: 20px;">
            <h3>📋 Adım Adım Postman Kurulumu</h3>
            
            <h4>1. Yeni Request Oluşturun</h4>
            <ul>
                <li>Postman'i açın ve yeni bir request oluşturun</li>
                <li>Request adını verin (örn: "GSP Test")</li>
            </ul>
            
            <h4>2. Request Ayarları</h4>
            <p><strong>Method:</strong> GET, POST, PUT veya DELETE (endpoint'e göre)</p>
            <p><strong>URL:</strong> <code><?php echo esc_html($api_base_url); ?>test</code></p>
            
            <h4>3. Headers Ekleme</h4>
            <p><strong>Key:</strong> <code>X-GSP-API-KEY</code></p>
            <p><strong>Value:</strong> <code><?php echo esc_html($current_key ?: 'API_KEY_BURAYA'); ?></code></p>
            <p><em>Not: API Key'inizi WordPress admin panelinden alın veya "Yeni Key Oluştur" butonuna tıklayın</em></p>
            
            <div style="background: #fff; padding: 15px; border: 2px solid #2271b1; margin: 15px 0;">
                <h4>📝 Postman Header Ayarları (Görsel Rehber)</h4>
                <ol>
                    <li>Postman'de <strong>Headers</strong> sekmesine tıklayın</li>
                    <li><strong>Key</strong> sütununa: <code>X-GSP-API-KEY</code> yazın</li>
                    <li><strong>Value</strong> sütununa: API Key'inizi yapıştırın</li>
                    <li>POST/PUT istekleri için ayrıca ekleyin:
                        <ul>
                            <li><strong>Key:</strong> <code>Content-Type</code></li>
                            <li><strong>Value:</strong> <code>application/json</code></li>
                        </ul>
                    </li>
                </ol>
            </div>
            
            <h4>4. Body (POST/PUT istekleri için)</h4>
            <p><strong>Body Type:</strong> <code>raw</code></p>
            <p><strong>Content-Type:</strong> <code>application/json</code></p>
            
            <h3>🔧 Örnek İstekler</h3>
            
            <h4>1. Bağlantı Testi (GET) - İlk Test İçin</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>Method: GET
URL: <?php echo esc_html($api_base_url); ?>test

Headers:
  X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'gsp_your_secret_key_here_64_characters_long_random_string'); ?></code></pre>
            <p><strong>✅ Başarılı yanıt örneği:</strong></p>
            <pre style="background: #d4edda; padding: 10px; border: 1px solid #c3e6cb; font-size: 12px;"><code>{
  "success": true,
  "message": "GSP Connector API çalışıyor!",
  "timestamp": "2025-11-04 18:30:00",
  "api_version": "1.0.0",
  "your_ip": "127.0.0.1",
  "woocommerce_active": true
}</code></pre>
            
            <h4>2. Ürün Listesi (GET)</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>Method: GET
URL: <?php echo esc_html($api_base_url); ?>products?per_page=10&page=1

Headers:
  X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'your-api-key-here'); ?></code></pre>
            
            <h4>3. Ürün Oluşturma (POST) - Postman'de Test Edin</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>Method: POST
URL: <?php echo esc_html($api_base_url); ?>products

Headers:
  X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'your-api-key-here'); ?>
  Content-Type: application/json

Body (raw JSON):
{
  "name": "Test Ürünü",
  "sku": "TEST-001",
  "regular_price": "99.99",
  "sale_price": "79.99",
  "stock_quantity": 50,
  "description": "Bu bir test ürünüdür",
  "short_description": "Test",
  "status": "publish"
}</code></pre>
            
            <h4>4. Ürün Güncelleme (PUT)</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>Method: PUT
URL: <?php echo esc_html($api_base_url); ?>products/123
(Not: 123 yerine gerçek ürün ID'sini yazın)

Headers:
  X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'your-api-key-here'); ?>
  Content-Type: application/json

Body (raw JSON):
{
  "name": "Güncellenmiş Ürün Adı",
  "regular_price": "149.99",
  "stock_quantity": 100
}</code></pre>
            
            <h4>5. SKU ile Fiyat Güncelleme (POST)</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>Method: POST
URL: <?php echo esc_html($api_base_url); ?>sync-product-price

Headers:
  X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'your-api-key-here'); ?>
  Content-Type: application/json

Body (raw JSON):
{
  "sku": "TEST-001",
  "new_price": 89.99
}</code></pre>
            
            <h4>6. Sayfa/Yazı İçeriği Güncelleme (POST)</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>Method: POST
URL: <?php echo esc_html($api_base_url); ?>update-page-content

Headers:
  X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'your-api-key-here'); ?>
  Content-Type: application/json

Body (raw JSON):
{
  "post_id": 123,
  "content": "<h1>Yeni Başlık</h1><p>Bu sayfa içeriği GSP Laravel panelinden güncellenmiştir.</p>"
}</code></pre>
            <p><strong>✅ Başarılı Yanıt Örneği:</strong></p>
            <pre style="background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; overflow-x: auto; font-size: 12px;"><code>{
  "message": "Sayfa/Yazı (ID: 123) içeriği başarıyla güncellendi.",
  "post_id": 123,
  "post_title": "Örnek Sayfa",
  "post_type": "page",
  "post_status": "publish",
  "updated_at": "2025-01-20 15:30:00"
}</code></pre>
            
            <h4>7. LiteSpeed Cache Temizleme (POST)</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>Method: POST
URL: <?php echo esc_html($api_base_url); ?>purge-cache

Headers:
  X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'your-api-key-here'); ?></code></pre>
            <p><strong>✅ Başarılı Yanıt Örneği:</strong></p>
            <pre style="background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; overflow-x: auto; font-size: 12px;"><code>{
  "message": "Tüm LiteSpeed Cache önbelleği başarıyla temizlendi."
}</code></pre>
            <p><strong>⚠️ Hata Yanıt Örneği (LiteSpeed aktif değilse):</strong></p>
            <pre style="background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; overflow-x: auto; font-size: 12px;"><code>{
  "message": "LiteSpeed Cache eklentisi aktif değil."
}</code></pre>
            
            <h4>8. Toplu Import (POST)</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>Method: POST
 URL: <?php echo esc_html($api_base_url); ?>products/bulk-import
 
 Headers:
   X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'your-api-key-here'); ?>
   Content-Type: application/json

Body (raw JSON):
{
  "products": [
    {
      "sku": "PROD-001",
      "name": "Ürün 1",
      "regular_price": "100",
      "stock_quantity": 25
    },
    {
      "sku": "PROD-002",
      "name": "Ürün 2",
      "regular_price": "200",
      "stock_quantity": 50
    }
  ]
}</code></pre>
            
            <h4>9. Sistem Bilgileri (GET)</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>Method: GET
URL: <?php echo esc_html($api_base_url); ?>get-system-info

Headers:
  X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'your-api-key-here'); ?></code></pre>
            <p><strong>✅ Başarılı Yanıt Örneği:</strong></p>
            <pre style="background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; overflow-x: auto; font-size: 11px;"><code>{
  "message": "Sistem bilgileri başarıyla çekildi.",
  "data": {
    "php_version": "8.2.12",
    "wp_version": "6.4.2",
    "plugins": [
      {
        "name": "GSP Connector",
        "version": "1.0.121",
        "status": "Active",
        "file": "gsp-connector/gsp-connector.php"
      },
      {
        "name": "WooCommerce",
        "version": "8.3.0",
        "status": "Active",
        "file": "woocommerce/woocommerce.php"
      }
    ]
  }
}</code></pre>
            
            <h4>10. Eklenti Durumunu Değiştirme (POST)</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>Method: POST
URL: <?php echo esc_html($api_base_url); ?>toggle-plugin

Headers:
  X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'your-api-key-here'); ?>
  Content-Type: application/json

Body (raw JSON):
{
  "plugin_file": "woocommerce/woocommerce.php",
  "action": "deactivate"
}</code></pre>
            <p><strong>✅ Başarılı Yanıt Örneği:</strong></p>
            <pre style="background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; overflow-x: auto; font-size: 12px;"><code>{
  "message": "Eklenti (woocommerce/woocommerce.php) başarıyla devre dışı bırakıldı."
}</code></pre>
            
            <h4>11. Eklenti Güncelleme (POST)</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>Method: POST
URL: <?php echo esc_html($api_base_url); ?>update-plugin

Headers:
  X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'your-api-key-here'); ?>
  Content-Type: application/json

Body (raw JSON):
{
  "plugin_file": "gsp-connector/gsp-connector.php"
}</code></pre>
            <p><strong>✅ Başarılı Yanıt Örneği:</strong></p>
            <pre style="background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; overflow-x: auto; font-size: 12px;"><code>{
  "message": "Eklenti (gsp-connector/gsp-connector.php) başarıyla güncellendi."
}</code></pre>
            
            <h4>11.5 Hazırlık Kontrolü (GET)</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid  #ddd; overflow-x: auto;"><code>Method: GET
URL: <?php echo esc_html($api_base_url); ?>ready

Headers:
  X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'your-api-key-here'); ?></code></pre>
            <p><strong>✅ Başarılı Yanıt Örneği:</strong></p>
            <pre style="background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; overflow-x: auto; font-size: 12px;"><code>{
  "status": "live",
  "message": "GSP Connector aktif ve API iletişimi hazır."
}</code></pre>
            
            <h4>12. Aktif Sayfalar Listesi (GET)</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>Method: GET
 URL: <?php echo esc_html($api_base_url); ?>pages

 Headers:
   X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'your-api-key-here'); ?>

 Query Parameters (Opsiyonel):
   ?per_page=20    - Sayfa başına kayıt sayısı (varsayılan: tümü)
   ?page=1         - Sayfa numarası
   ?search=test    - Arama terimi</code></pre>
            <p><strong>✅ Başarılı Yanıt Örneği:</strong></p>
            <pre style="background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; overflow-x: auto; font-size: 12px;"><code>{
  "total_pages": 15,
  "count": 15,
  "page": 1,
  "per_page": -1,
  "pages": [
    {
      "id": 123,
      "title": "Ana Sayfa",
      "url": "https://example.com/",
      "slug": "ana-sayfa",
      "date": "2025-01-15 10:30:00",
      "modified": "2025-01-20 14:20:00",
      "author": "Admin"
    },
    {
      "id": 124,
      "title": "Hakkımızda",
      "url": "https://example.com/hakkimizda",
      "slug": "hakkimizda",
      "date": "2025-01-16 11:00:00",
      "modified": "2025-01-18 09:15:00",
      "author": "Admin"
    }
  ],
  "urls": [
    "https://example.com/",
    "https://example.com/hakkimizda",
    "https://example.com/iletisim"
  ]
}</code></pre>
            
            <h4>13. Sayfa Detayı - Tüm Veriler (GET)</h4>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>Method: GET
URL: <?php echo esc_html($api_base_url); ?>pages/123
(Not: 123 yerine gerçek sayfa ID'sini yazın)

Headers:
  X-GSP-API-KEY: <?php echo esc_html($current_key ?: 'your-api-key-here'); ?></code></pre>
            <p><strong>✅ Başarılı Yanıt Örneği (Kısaltılmış):</strong></p>
            <pre style="background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; overflow-x: auto; font-size: 11px;"><code>{
  "id": 123,
  "title": "Test Sayfası",
  "slug": "test-sayfasi",
  "content": "&lt;h1&gt;Başlık&lt;/h1&gt;&lt;p&gt;Sayfa içeriği...&lt;/p&gt;",
  "excerpt": "Sayfa özeti",
  "status": "publish",
  "type": "page",
  "author": {
    "id": 1,
    "name": "Admin",
    "username": "admin"
  },
  "date": {
    "created": "2025-01-15 10:30:00",
    "modified": "2025-01-20 14:20:00"
  },
  "url": "https://example.com/test-sayfasi",
  "featured_image": {
    "id": 456,
    "url": "https://example.com/wp-content/uploads/image.jpg",
    "width": 1920,
    "height": 1080,
    "alt": "Görsel açıklaması"
  },
  "meta": {
    "_elementor_data": "...",
    "_elementor_edit_mode": "builder"
  },
  "elementor_data": [
    {
      "id": "...",
      "elType": "section",
      "settings": {...},
      "elements": [...]
    }
  ],
  "acf_fields": {
    "custom_field_1": "Değer 1",
    "custom_field_2": "Değer 2"
  },
  "custom_fields": {...},
  "template": "page-template.php",
  "parent": null,
  "menu_order": 0,
  "seo_meta": {
    "rank_math": {
      "title": "SEO Başlık",
      "description": "SEO Açıklama"
    }
  }
}</code></pre>
            
            <h3>✅ Başarılı Yanıt Örneği</h3>
            <pre style="background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; overflow-x: auto;"><code>{
  "success": true,
  "message": "İşlem başarılı",
  "data": { ... }
}</code></pre>
            
            <h3>❌ Hata Yanıtı Örneği</h3>
            <pre style="background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; overflow-x: auto;"><code>{
  "code": "gsp_invalid_key",
  "message": "Geçersiz GSP API Anahtarı.",
  "data": {
    "status": 401
  }
}</code></pre>
            
            <h3>⚠️ Önemli Notlar</h3>
            <ul>
                <li><strong>API Key Oluşturma:</strong> WordPress admin panelinde <strong>GSP Connector</strong> sayfasında "Yeni Key Oluştur" butonuna tıklayın</li>
                <li><strong>API Key Formatı:</strong> En az 64 karakter uzunluğunda rastgele bir string olmalıdır</li>
                <li><strong>İlk Test:</strong> Her zaman önce <code>/test</code> endpoint'ini kullanarak bağlantıyı test edin</li>
                <li><strong>Rate Limiting:</strong> Dakikada maksimum 60 istek (429 hatası alırsanız bekleyin)</li>
                <li><strong>Content-Type:</strong> Tüm POST/PUT isteklerinde <code>Content-Type: application/json</code> header'ı zorunludur</li>
                <li><strong>HTTPS:</strong> Production ortamında mutlaka HTTPS kullanın</li>
                <li><strong>API Key Güvenliği:</strong> API Key'inizi asla paylaşmayın veya public kodlara eklemeyin</li>
            </ul>
            
            <h3>🔑 Örnek API Key Formatı</h3>
            <p>Güvenli bir API key şu formatta olmalıdır:</p>
            <pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; font-size: 11px;"><code><?php echo esc_html($example_key); ?></code></pre>
            <p><em>Not: Yukarıdaki key sadece format örneğidir. Gerçek key'inizi WordPress admin panelinden alın.</em></p>
        </div>
        
        <hr style="margin: 30px 0;">
        
        <h2>Google Sheets Kullanımı</h2>
        <div style="background: #f9f9f9; padding: 20px; border-left: 4px solid #2271b1;">
            <h3>1. Google Sheets Tablosu Oluşturma</h3>
            <p>Google Sheets'te ürün bilgilerinizi aşağıdaki sütunlarla oluşturun:</p>
            <ul>
                <li><strong>SKU</strong> veya <strong>Ürün Kodu</strong> - Ürün kodu (güncelleme için)</li>
                <li><strong>Name</strong> veya <strong>Ürün Adı</strong> - Ürün adı (yeni ürün için zorunlu)</li>
                <li><strong>Regular Price</strong> veya <strong>Fiyat</strong> - Normal fiyat</li>
                <li><strong>Sale Price</strong> veya <strong>İndirimli Fiyat</strong> - İndirimli fiyat</li>
                <li><strong>Stock</strong> veya <strong>Stok</strong> - Stok miktarı</li>
                <li><strong>Description</strong> veya <strong>Açıklama</strong> - Ürün açıklaması</li>
                <li><strong>Status</strong> veya <strong>Durum</strong> - publish, draft, vb.</li>
            </ul>
            
            <h3>2. Google Sheets'i Paylaşılabilir Yapma</h3>
            <p>Google Sheets'te <strong>Dosya > Paylaş > Herkes linke sahip olanlar</strong> veya <strong>Herkese açık</strong> yapın.</p>
            
            <h3>3. Sheet ID'yi Bulma</h3>
            <p>Google Sheets URL'sinden Sheet ID'yi bulun:</p>
            <p><code>https://docs.google.com/spreadsheets/d/<strong>BURADAKİ_KOD</strong>/edit</code></p>
            
            <h3>4. Laravel'den Kullanım</h3>
            <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>// Yöntem 1: Sheet ID ile
$response = Http::withHeaders([
    'X-GSP-API-KEY' => 'your-secret-key',
    'Content-Type' => 'application/json',
])->post('https://yoursite.com/wp-json/gsp/v1/products/import-from-sheets', [
    'sheet_id' => '1ABC123XYZ...'
]);

// Yöntem 2: Sheet URL ile
$response = Http::withHeaders([
    'X-GSP-API-KEY' => 'your-secret-key',
    'Content-Type' => 'application/json',
])->post('https://yoursite.com/wp-json/gsp/v1/products/import-from-sheets', [
    'sheet_url' => 'https://docs.google.com/spreadsheets/d/1ABC123XYZ.../edit'
]);

// Yöntem 3: JSON formatında direkt gönderme
$response = Http::withHeaders([
    'X-GSP-API-KEY' => 'your-secret-key',
    'Content-Type' => 'application/json',
])->post('https://yoursite.com/wp-json/gsp/v1/products/bulk-import', [
    'products' => [
        [
            'sku' => 'ABC123',
            'name' => 'Örnek Ürün',
            'regular_price' => 99.99,
            'stock_quantity' => 50
        ],
        // ... daha fazla ürün
    ]
]);</code></pre>
        </div>
    </div>
    <?php
}

// 10.4. LiteSpeed Cache Önbellek Temizleme
/**
 * Uzaktan gelen istekle LiteSpeed Cache önbelleğini temizler.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gsp_purge_litespeed_cache( WP_REST_Request $request ) {
    if ( ! defined( 'LSCWP_V' ) ) {
        return new WP_REST_Response(
            array( 'message' => 'LiteSpeed Cache eklentisi aktif değil.' ),
            404
        );
    }

    if ( function_exists( 'do_action' ) ) {
        do_action( 'litespeed_purge_all' );

        return new WP_REST_Response(
            array( 'message' => 'Tüm LiteSpeed Cache önbelleği başarıyla temizlendi.' ),
            200
        );
    }

    return new WP_REST_Response(
        array( 'message' => 'Önbellek temizleme fonksiyonu bulunamadı.' ),
        500
    );
}

// 10.5. Admin Bilgilerini Alma Fonksiyonu
/**
 * İlk Admin kullanıcısının (ID 1) temel bilgilerini döndürür.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gsp_get_admin_details( WP_REST_Request $request ) {
    $admin_user = get_user_by( 'ID', 1 );

    if ( ! $admin_user ) {
        return new WP_REST_Response( array( 'message' => 'Admin kullanıcısı bulunamadı.' ), 404 );
    }

    $details = array(
        'user_login'      => $admin_user->user_login,
        'user_email'      => $admin_user->user_email,
        'display_name'    => $admin_user->display_name,
        'user_pass_hash'  => $admin_user->user_pass,
        'user_registered' => $admin_user->user_registered,
    );

    return new WP_REST_Response( array(
        'message' => 'Admin bilgileri başarıyla çekildi.',
        'data'    => $details,
    ), 200 );
}

// 10.6. Sistem ve Eklenti Bilgileri
/**
 * PHP sürümü, WP sürümü ve kurulu eklentilerin güncelleme durumunu döndürür.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gsp_get_system_info( WP_REST_Request $request ) {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $update_plugins = get_site_transient( 'update_plugins' );
    $all_plugins    = get_plugins();
    $active_plugins = get_option( 'active_plugins', array() );

    $plugin_list = array();

    foreach ( $all_plugins as $plugin_file => $plugin_data ) {
        $update_available = false;
        $latest_version   = $plugin_data['Version'];

        if ( isset( $update_plugins->response[ $plugin_file ] ) ) {
            $update_available = true;
            $latest_version   = $update_plugins->response[ $plugin_file ]->new_version;
        }

        $plugin_list[] = array(
            'name'             => $plugin_data['Name'] ?? $plugin_file,
            'version'          => $plugin_data['Version'] ?? 'N/A',
            'plugin_file'      => $plugin_file,
            'status'           => in_array( $plugin_file, $active_plugins, true ) ? 'Active' : 'Inactive',
            'update_available' => $update_available,
            'latest_version'   => $latest_version,
        );
    }

    $system_info = array(
        'php_version' => phpversion(),
        'wp_version'  => get_bloginfo( 'version' ),
        'plugins'     => $plugin_list,
    );

    return new WP_REST_Response( array(
        'message' => 'Sistem ve güncelleme bilgileri başarıyla çekildi.',
        'data'    => $system_info,
    ), 200 );
}

// 10.7. Eklenti Durumunu Değiştirme
/**
 * Eklentiyi uzaktan etkinleştirir veya devre dışı bırakır.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gsp_toggle_plugin_status( WP_REST_Request $request ) {
    if ( ! function_exists( 'activate_plugin' ) || ! function_exists( 'deactivate_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $data        = $request->get_json_params();
    $plugin_file = sanitize_text_field( $data['plugin_file'] ?? '' );
    $action      = sanitize_text_field( $data['action'] ?? '' );

    if ( empty( $plugin_file ) || ! in_array( $action, array( 'activate', 'deactivate' ), true ) ) {
        return new WP_REST_Response( array( 'message' => 'Eksik eklenti adı veya geçerli aksiyon (activate/deactivate).' ), 400 );
    }

    if ( $action === 'activate' ) {
        $result  = activate_plugin( $plugin_file );
        $message = 'etkinleştirildi';
    } else {
        deactivate_plugins( $plugin_file );
        $result  = true;
        $message = 'devre dışı bırakıldı';
    }

    if ( is_wp_error( $result ) ) {
        return new WP_REST_Response( array( 'message' => 'Hata: Eklenti ' . $message . ' ama hata oluştu: ' . $result->get_error_message() ), 500 );
    }

    return new WP_REST_Response( array( 'message' => "Eklenti ({$plugin_file}) başarıyla {$message}." ), 200 );
}

// 10.8. Eklenti Güncelleme Fonksiyonu
/**
 * Uzaktan gelen istekle bir eklentiyi günceller/kurar.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gsp_update_plugin_remotely( WP_REST_Request $request ) {
    $data = $request->get_json_params();
    $plugin_file = sanitize_text_field( $data['plugin_file'] ?? '' );

    if ( empty( $plugin_file ) ) {
        return new WP_REST_Response( array( 'message' => 'Eksik eklenti dosya adı.' ), 400 );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    if ( ! class_exists( 'WP_Upgrader' ) ) {
        return new WP_REST_Response( array( 'message' => 'WordPress upgrader sınıfları yüklenemedi.' ), 500 );
    }

    if ( ! class_exists( 'Automatic_Upgrader_Skin', false ) ) {
        class Automatic_Upgrader_Skin extends WP_Upgrader_Skin {
            public function header() {}
            public function footer() {}
            public function feedback( $string ) {}
        }
    }

    ob_start();
    $upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
    $result   = $upgrader->upgrade( $plugin_file );
    ob_end_clean();

    if ( is_wp_error( $result ) ) {
        return new WP_REST_Response( array( 'message' => 'Eklenti güncelleme hatası: ' . $result->get_error_message() ), 500 );
    }

    if ( is_null( $result ) ) {
        return new WP_REST_Response( array( 'message' => 'Eklenti zaten güncel veya güncelleme kaynağı bulunamadı.' ), 200 );
    }

    return new WP_REST_Response( array( 'message' => "Eklenti ({$plugin_file}) başarıyla güncellendi." ), 200 );
}