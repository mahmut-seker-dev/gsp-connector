<?php
/**
 * GitHub Plugin Updater
 * 
 * WordPress eklentileri için GitHub üzerinden otomatik güncelleme kontrolü sağlar.
 * 
 * @package GSP_Connector
 * @version 1.0.7
 */

if (!defined('ABSPATH')) {
    exit; // Güvenlik kontrolü
}

class GitHub_Plugin_Updater {
    
    private $plugin_file;
    private $plugin_basename;
    private $plugin_slug;
    private $github_username;
    private $github_repo;
    private $github_branch;
    private $cache_key;
    private $cache_duration;
    private $current_version;
    private $branch_only_mode; // Release kontrolünü atla, direkt branch'ten güncelle
    private $base_version;
    private $installed_build;
    private $last_commit_build;
    private $last_commit_sha;
    
    /**
     * Constructor
     * 
     * @param string $plugin_file Eklenti ana dosya yolu (__FILE__)
     * @param string $github_username GitHub kullanıcı adı
     * @param string $github_repo GitHub depo adı
     * @param string $github_branch Ana dal adı (default: 'main')
     */
    public function __construct($plugin_file, $github_username, $github_repo, $github_branch = 'main', $branch_only_mode = false) {
        $this->plugin_file = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->github_username = $github_username;
        $this->github_repo = $github_repo;
        $this->github_branch = $github_branch;
        $this->branch_only_mode = $branch_only_mode; // Release kontrolünü atla
        
        // Plugin bilgilerini al
        $plugin_data = get_file_data($plugin_file, array('Version' => 'Version', 'TextDomain' => 'Text Domain'));
        $this->plugin_slug = basename(dirname($plugin_file));
        $this->base_version = $this->clean_version($plugin_data['Version']);
        $this->installed_build = '';
        $this->last_commit_build = null;
        $this->last_commit_sha = null;
        
        if ($this->branch_only_mode) {
            $stored_build = get_option($this->get_build_option_key(), '');
            if (!empty($stored_build)) {
                $this->installed_build = $stored_build;
            }
        }
        $this->current_version = $this->get_current_version_for_compare();
        
        // Cache ayarları
        $this->cache_key = 'gsp_github_update_check_' . md5($this->plugin_slug);
        $this->cache_duration = 12 * HOUR_IN_SECONDS; // 12 saat
        
        // WordPress güncelleme sistemine entegre et
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_api_call'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);
        add_filter('upgrader_source_selection', array($this, 'upgrader_source_selection'), 10, 4);
        add_action('admin_post_gsp_clear_cache', array($this, 'handle_admin_actions'));
        
        // Admin bildirimleri
        add_action('admin_notices', array($this, 'admin_notice'));
    }
    
    /**
     * GitHub'dan güncelleme kontrolü yapar
     * 
     * @param object $transient WordPress güncelleme transient objesi
     * @return object
     */
    public function check_for_update($transient) {
        // Eklenti yüklü değilse veya transient boşsa
        if (empty($transient->checked) || !isset($transient->checked[$this->plugin_basename])) {
            return $transient;
        }

        $this->current_version = $this->get_current_version_for_compare();
        
        // Installed version'ı transient'e yansıt
        $transient->checked[$this->plugin_basename] = $this->current_version;
        
        // Cache kontrolü
        $cached = get_transient($this->cache_key);
        if ($cached !== false && is_array($cached)) {
            if (!empty($cached['new_version']) && $this->is_newer_version($this->current_version, $cached['version'])) {
                $transient->response[$this->plugin_basename] = (object) $cached;
            }
            return $transient;
        }
        
        // GitHub API'den güncelleme bilgisi al
        $release_info = $this->get_latest_release();
        
        // Versiyon karşılaştırması (commit SHA'sını ignore et)
        if ($release_info && $this->is_newer_version($this->current_version, $release_info['version'])) {
            $update_data = array(
                'slug' => $this->plugin_slug,
                'plugin' => $this->plugin_basename,
                'new_version' => $release_info['version'],
                'version' => $release_info['version'],
                'url' => $release_info['url'],
                'package' => $release_info['package'],
                'tested' => get_bloginfo('version'),
                'requires' => '5.0',
                'requires_php' => '7.4',
            );
            if (isset($release_info['commit_build'])) {
                $update_data['commit_build'] = $release_info['commit_build'];
            }
            if (isset($release_info['commit_sha'])) {
                $update_data['commit_sha'] = $release_info['commit_sha'];
            }
            $this->last_commit_build = $update_data['commit_build'] ?? null;
            $this->last_commit_sha = $update_data['commit_sha'] ?? null;
            
            // Cache'e kaydet
            set_transient($this->cache_key, $update_data, $this->cache_duration);
            
            $transient->response[$this->plugin_basename] = (object) $update_data;
        } else {
            // Güncelleme yok, cache'e kaydet
            set_transient($this->cache_key, array('new_version' => false), $this->cache_duration);
        }
        
        return $transient;
    }
    
    /**
     * GitHub API'den en son release bilgisini alır
     * 
     * @return array|false
     */
    private function get_latest_release() {
        // Branch-only modunda direkt branch'ten kontrol et
        if ($this->branch_only_mode) {
            return $this->get_latest_from_branch();
        }
        
        // Önce releases API'yi dene (tag'ler)
        $releases_url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_username,
            $this->github_repo
        );
        
        $response = wp_remote_get($releases_url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-GSP-Connector'
            ),
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            // Releases API başarısız olursa, branch'ten kontrol et
            return $this->get_latest_from_branch();
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        // 404 = Release yok, branch'ten kontrol et
        if ($response_code === 404) {
            return $this->get_latest_from_branch();
        }
        
        // Diğer hata kodları
        if ($response_code !== 200) {
            return false;
        }
        
        $release_data = json_decode(wp_remote_retrieve_body($response), true);
        
        // API rate limit veya geçersiz yanıt
        if (empty($release_data) || isset($release_data['message'])) {
            return false;
        }
        
        if (!empty($release_data['tag_name'])) {
            $version = $this->clean_version($release_data['tag_name']);
            $zip_url = $release_data['zipball_url'];
            
            return array(
                'version' => $version,
                'url' => $release_data['html_url'],
                'package' => $zip_url,
                'release_notes' => $release_data['body'] ?? ''
            );
        }
        
        // Release bulunamazsa branch'ten kontrol et
        return $this->get_latest_from_branch();
    }
    
    /**
     * Branch'ten en son commit bilgisini alır (releases yoksa)
     * 
     * @return array|false
     */
    private function get_latest_from_branch() {
        $branch_url = sprintf(
            'https://api.github.com/repos/%s/%s/commits/%s',
            $this->github_username,
            $this->github_repo,
            $this->github_branch
        );
        
        $response = wp_remote_get($branch_url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-GSP-Connector'
            ),
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $commit_data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($commit_data['sha'])) {
            return false;
        }
        
        $commit_sha = substr($commit_data['sha'], 0, 40);
        $commit_short = substr($commit_sha, 0, 7);
        $commit_date = isset($commit_data['commit']['committer']['date']) ? $commit_data['commit']['committer']['date'] : null;
        $commit_build = $commit_date ? gmdate('YmdHis', strtotime($commit_date)) : gmdate('YmdHis');
        
        $remote_base_version = $this->branch_only_mode ? $this->get_remote_plugin_version() : $this->base_version;
        
        // Build numarasıyla versiyon oluştur
        $version = $remote_base_version . '.' . $commit_build;
        $zip_url = sprintf(
            'https://github.com/%s/%s/archive/%s.zip',
            $this->github_username,
            $this->github_repo,
            $this->github_branch
        );
        
        return array(
            'version' => $version,
            'url' => sprintf('https://github.com/%s/%s', $this->github_username, $this->github_repo),
            'package' => $zip_url,
            'release_notes' => '',
            'commit_build' => $commit_build,
            'commit_sha' => $commit_sha,
            'base_version' => $remote_base_version,
        );
    }
    
    /**
     * Versiyon numarasını temizler (v prefix'ini kaldırır)
     * 
     * @param string $version
     * @return string
     */
    private function clean_version($version) {
        return ltrim(trim((string) $version), 'vV');
    }
    
    /**
     * Versiyon karşılaştırması yapar (commit SHA'sını ignore eder)
     * 
     * @param string $current_version Mevcut versiyon
     * @param string $remote_version GitHub'daki versiyon
     * @return bool Yeni versiyon varsa true
     */
    private function is_newer_version($current_version, $remote_version) {
        $installed_version = $this->get_current_version_for_compare();
        $remote_version = $this->clean_version($remote_version);

        return version_compare($installed_version, $remote_version, '<');
    }
    
    /**
     * Plugin API çağrısı (WordPress güncelleme sayfası için)
     * 
     * @param mixed $result
     * @param string $action
     * @param object $args
     * @return object
     */
    public function plugin_api_call($result, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== $this->plugin_slug) {
            return $result;
        }
        
        $release_info = $this->get_latest_release();
        
        if (!$release_info) {
            return $result;
        }
        
        $plugin_data = get_plugin_data($this->plugin_file);
        
        $result = (object) array(
            'name' => $plugin_data['Name'],
            'slug' => $this->plugin_slug,
            'version' => $release_info['version'],
            'author' => $plugin_data['Author'],
            'author_profile' => $plugin_data['AuthorURI'],
            'requires' => '5.0',
            'requires_php' => '7.4',
            'tested' => get_bloginfo('version'),
            'last_updated' => current_time('mysql'),
            'homepage' => sprintf('https://github.com/%s/%s', $this->github_username, $this->github_repo),
            'download_link' => $release_info['package'],
            'sections' => array(
                'description' => $plugin_data['Description'],
                'changelog' => !empty($release_info['release_notes']) ? $release_info['release_notes'] : 'Güncelleme detayları için GitHub deposunu ziyaret edin.'
            ),
            'banners' => array(
                'low' => '',
                'high' => ''
            )
        );
        
        return $result;
    }
    
    /**
     * ZIP dosyası açıldığında kaynak klasörü düzeltir
     * GitHub ZIP'i genellikle username-repo-tag şeklinde bir klasör içerir
     * 
     * @param string $source Kaynak klasör yolu
     * @param string $remote_source Uzaktan kaynak
     * @param object $upgrader Upgrader objesi
     * @param array $hook_extra Ek bilgiler
     * @return string Düzeltilmiş kaynak yolu
     */
    public function upgrader_source_selection($source, $remote_source, $upgrader, $hook_extra) {
        // Sadece bu eklenti için çalış
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $source;
        }
        
        // Kaynak klasörü kontrol et
        if (!is_dir($source)) {
            return $source;
        }
        
        // GitHub ZIP'i genellikle username-repo-tag şeklinde bir klasör içerir
        // Bu klasörü bul ve içeriğini doğrudan kaynak olarak kullan
        $files = @scandir($source);
        
        if ($files) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                $nested_path = trailingslashit($source) . $file;
                
                // Eğer bu bir klasör ise ve içinde plugin dosyası varsa
                if (is_dir($nested_path)) {
                    $plugin_file_name = basename($this->plugin_file);
                    $nested_plugin_file = trailingslashit($nested_path) . $plugin_file_name;
                    
                    if (file_exists($nested_plugin_file)) {
                        // Bu doğru klasör, bunu kaynak olarak kullan
                        return $nested_path;
                    }
                }
            }
        }
        
        return $source;
    }
    
    /**
     * Güncelleme sonrası işlemler
     * 
     * @param bool $response
     * @param array $hook_extra
     * @param array $result
     * @return array
     */
    public function post_install($response, $hook_extra, $result) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $response;
        }
        
        // Cache'i temizle
        delete_transient($this->cache_key);
        
        $destination = trailingslashit($result['destination']);
        $expected_directory = trailingslashit(WP_PLUGIN_DIR) . $this->plugin_slug . '/';

        if (trailingslashit($destination) !== trailingslashit($expected_directory)) {
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }

            // Eğer hedefte eski klasör varsa sil
            if ($wp_filesystem->is_dir($expected_directory)) {
                $wp_filesystem->delete($expected_directory, true);
            }

            // Yeni klasörü beklenen isimle taşı
            $wp_filesystem->move($destination, $expected_directory, true);
            $result['destination'] = $expected_directory;
        }

        if ($this->branch_only_mode) {
            if ($this->last_commit_build) {
                update_option($this->get_build_option_key(), $this->last_commit_build);
                $this->installed_build = $this->last_commit_build;
            }
            if ($this->last_commit_sha) {
                update_option($this->get_sha_option_key(), $this->last_commit_sha);
            }
            // Güncel versiyonu yeniden ayarla
            $this->current_version = $this->get_current_version_for_compare();
        }
        
        // WordPress güncelleme cache'ini de temizle
        delete_site_transient('update_plugins');
        
        return $response;
    }
    
    /**
     * Admin bildirimi (güncelleme varsa)
     */
    public function admin_notice() {
        // Sadece admin panelinde ve eklenti ayarları sayfasında göster
        if (!current_user_can('update_plugins')) {
            return;
        }
        
        $update_data = get_transient($this->cache_key);
        
        if (!$update_data || empty($update_data['new_version'])) {
            // Cache yoksa kontrol et
            $release_info = $this->get_latest_release();
            if ($release_info && $this->is_newer_version($this->current_version, $release_info['version'])) {
                $this->show_update_notice($release_info);
            }
        } elseif (!empty($update_data['new_version']) && is_array($update_data) && $this->is_newer_version($this->current_version, $update_data['version'])) {
            $this->show_update_notice($update_data);
        }
    }
    
    /**
     * Güncelleme bildirimi gösterir
     * 
     * @param array $update_data
     */
    private function show_update_notice($update_data) {
        $update_url = wp_nonce_url(
            admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($this->plugin_basename)),
            'upgrade-plugin_' . $this->plugin_basename
        );
        
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong>GSP Connector</strong> için yeni bir güncelleme mevcut!
                <strong>Versiyon <?php echo esc_html($update_data['version']); ?></strong> indirilebilir.
                <a href="<?php echo esc_url($update_url); ?>" class="button button-primary" style="margin-left: 10px;">
                    Şimdi Güncelle
                </a>
                <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" class="button" style="margin-left: 5px;">
                    Eklentiler Sayfasına Git
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Remote plugin sürümünü elde eder (branch-only modu için)
     *
     * @return string
     */
    private function get_remote_plugin_version() {
        $cache_key = 'gsp_remote_version_' . md5($this->plugin_slug . $this->github_branch);
        $cached = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }
        
        $possible_paths = array(
            $this->plugin_basename,
            basename($this->plugin_file)
        );
        
        foreach ($possible_paths as $relative_path) {
            $relative_path = ltrim($relative_path, '/');
            $url = sprintf('https://raw.githubusercontent.com/%s/%s/%s/%s',
                $this->github_username,
                $this->github_repo,
                $this->github_branch,
                $relative_path
            );
            $response = wp_remote_get($url, array(
                'timeout' => 15,
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
            $version = $this->extract_version_from_contents($body);
            if ($version) {
                $version = $this->clean_version($version);
                set_transient($cache_key, $version, 5 * MINUTE_IN_SECONDS);
                return $version;
            }
        }
        
        return $this->base_version;
    }

    /**
     * Plugin dosyası içeriğinden versiyon bilgisini çıkartır
     *
     * @param string $contents
     * @return string|null
     */
    private function extract_version_from_contents($contents) {
        if (preg_match('/^\s*Version:\s*(.+)$/mi', $contents, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Mevcut kurulu versiyonu karşılaştırma için hazırlar.
     *
     * @return string
     */
    private function get_current_version_for_compare() {
        $plugin_data = get_file_data($this->plugin_file, array('Version' => 'Version'));
        $base_version = !empty($plugin_data['Version']) ? $this->clean_version($plugin_data['Version']) : $this->base_version;

        if ($this->branch_only_mode) {
            if (empty($this->installed_build)) {
                $stored_build = get_option($this->get_build_option_key(), '');
                if (!empty($stored_build)) {
                    $this->installed_build = $stored_build;
                }
            }

            if (!empty($this->installed_build)) {
                $base_version .= '.' . $this->installed_build;
            }
        }

        return $base_version;
    }

    /**
     * Admin tarafı işlemleri (ör. cache temizleme) yönetir.
     */
    public function handle_admin_actions() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Bu işlemi yapmaya yetkiniz yok.', 'gsp-connector'));
        }

        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

        if ($action !== 'gsp_clear_cache') {
            return;
        }

        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'gsp_clear_cache_action')) {
            wp_die(__('Güvenlik kontrolü başarısız.', 'gsp-connector'));
        }

        delete_transient($this->cache_key);
        delete_site_transient('update_plugins');

        $remote_version_cache = 'gsp_remote_version_' . md5($this->plugin_slug . $this->github_branch);
        delete_transient($remote_version_cache);

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'gsp-connector-settings',
                    'cache_cleared' => '1',
                ),
                admin_url('admin.php')
            )
        );
        exit;
    }

    private function get_build_option_key() {
        return 'gsp_branch_build_' . $this->plugin_slug;
    }

    private function get_sha_option_key() {
        return 'gsp_branch_sha_' . $this->plugin_slug;
    }
}

