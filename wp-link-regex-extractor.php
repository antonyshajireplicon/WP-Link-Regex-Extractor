<?php
/**
 * Plugin Name: WP Link Regex Extractor (Enhanced v2.1)
 * Description: Sequentially extracts regex-matching URLs from page source. Shows found/not found + HTTP code summary.
 * Version: 2.1
 * Author: Antony Shaji
 */

if (!defined('ABSPATH')) exit;

class WP_Link_Regex_Extractor_Simple {
    private static $instance = null;
    private $nonce_action = 'wp_lre_nonce_action';
    private $transient_prefix = 'wp_lre_job_';

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_wp_lre_start', [$this, 'ajax_start']);
        add_action('wp_ajax_wp_lre_next', [$this, 'ajax_next']);
        add_action('wp_ajax_wp_lre_download', [$this, 'ajax_download']);
    }

    public function admin_menu() {
        add_menu_page('Link Regex Extractor', 'Link Regex Extractor', 'manage_options', 'wp-link-regex-extractor', [$this, 'page'], 'dashicons-search');
    }

    public function enqueue($hook) {
        if ($hook !== 'toplevel_page_wp-link-regex-extractor') return;
        wp_enqueue_script('wp-lre-enhanced', plugin_dir_url(__FILE__) . 'wp-lre-enhanced.js', ['jquery'], '2.1', true);
        wp_localize_script('wp-lre-enhanced', 'wp_lre', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce($this->nonce_action),
        ]);
        wp_enqueue_style('wp-lre-enhanced', plugin_dir_url(__FILE__) . 'wp-lre-enhanced.css');
    }

    public function page() {
        ?>
        <div class="wrap lre-container">
            <h1><span class="dashicons dashicons-search"></span> WP Link Regex Extractor</h1>
            <p class="description">Upload a CSV (first column = URLs). Provide a regex pattern to search the HTML source of each page.</p>

            <table class="form-table">
                <tr><th>CSV Upload</th><td><input type="file" id="lre_csv" accept=".csv"></td></tr>
                <tr><th>Regex Pattern</th><td><input type="text" id="lre_pattern" style="width:100%" placeholder="your-regex-pattern (delimiters added automatically)"></td></tr>
                <tr><th>Delay (ms)</th><td><input type="number" id="lre_delay" value="1500" min="0"></td></tr>
            </table>

            <div class="lre-actions">
                <button id="lre_start" class="button button-primary">Start Extraction</button>
                <button id="lre_download" class="button" disabled>Download CSV</button>
            </div>

            <div id="lre_progress_wrap" style="display:none;">
                <div class="lre-progress"><div id="lre_progress_bar"></div></div>
                <div id="lre_progress_text">Waiting...</div>
            </div>

            <div id="lre_results" class="lre-results"></div>
        </div>

        <style>
        .lre-container { max-width:900px }
        .lre-progress { width:100%; height:22px; background:#eee; border-radius:5px; overflow:hidden; }
        #lre_progress_bar { height:100%; width:0%; background:linear-gradient(90deg,#2c9,#06c); transition:width .4s }
        .lre-result { border-bottom:1px solid #eee; padding:6px 0; }
        .found { color:green; font-weight:600; }
        .not-found { color:#999; }
        .error { color:red; }
        </style>
        <?php
    }

    public function ajax_start() {
        check_ajax_referer($this->nonce_action, 'nonce');
        $urls = isset($_POST['urls']) ? json_decode(stripslashes($_POST['urls']), true) : [];
        $pattern = isset($_POST['pattern']) ? stripslashes(sanitize_text_field($_POST['pattern'])) : '';
        $delay = isset($_POST['delay']) ? intval($_POST['delay']) : 1500;

        // Automatically wrap pattern with # delimiters and add i flag
        if (!empty($pattern)) {
            // Remove existing delimiters if user added them
            $pattern = trim($pattern);
            if (preg_match('/^[#\/\|~]/', $pattern)) {
                // Pattern already has delimiters, remove them
                $pattern = preg_replace('/^[#\/\|~](.+)[#\/\|~][a-z]*$/i', '$1', $pattern);
            }
            // Add # delimiters and i flag
            $pattern = '#' . $pattern . '#i';
        }

        $urls = array_filter($urls, fn($u) => filter_var($u, FILTER_VALIDATE_URL));
        $urls = array_values($urls);

        if (empty($urls) || empty($pattern)) wp_send_json_error('Missing input');

        $job_id = uniqid('lre_');
        $meta = [
            'urls' => $urls,
            'pattern' => $pattern,
            'delay' => $delay,
            'total' => count($urls),
            'completed' => 0,
            'results' => []
        ];
        set_transient($this->transient_prefix . $job_id, $meta, 3600);
        wp_send_json_success(['job_id' => $job_id, 'total' => count($urls)]);
    }

    public function ajax_next() {
        check_ajax_referer($this->nonce_action, 'nonce');
        $job_id = sanitize_text_field($_POST['job_id']);
        $key = $this->transient_prefix . $job_id;
        $meta = get_transient($key);
        if (!$meta || empty($meta['urls'])) wp_send_json_error('No job found');

        $next_url = array_shift($meta['urls']);
        $result = $this->process_url($next_url, $meta['pattern']);
        $meta['results'][] = $result;
        $meta['completed']++;
        set_transient($key, $meta, 3600);

        $progress = round(($meta['completed'] / $meta['total']) * 100, 1);
        wp_send_json_success([
            'progress' => $progress,
            'completed' => $meta['completed'],
            'total' => $meta['total'],
            'result' => $result,
            'done' => empty($meta['urls'])
        ]);
    }

    private function process_url($url, $pattern) {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118 Safari/537.36';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);
        $html = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Log to debug file for inspection
        if (!file_exists(WP_CONTENT_DIR . '/lre-debug.log')) {
            file_put_contents(WP_CONTENT_DIR . '/lre-debug.log', "");
        }
        file_put_contents(WP_CONTENT_DIR . '/lre-debug.log', "[" . date("H:i:s") . "] $url => HTTP:$code | Error:$err\n", FILE_APPEND);

        if ($err) return ['url' => $url, 'status' => $code ?: 0, 'found' => 'Error: ' . $err, 'matches' => ''];
        if ($code < 200 || $code >= 400) return ['url' => $url, 'status' => $code, 'found' => 'Error: HTTP ' . $code, 'matches' => ''];

        $ok = @preg_match($pattern, '') !== false;
        if (!$ok) return ['url' => $url, 'status' => $code, 'found' => 'Invalid regex', 'matches' => ''];

        if (@preg_match_all($pattern, $html, $m) && !empty($m[0])) {
            // Remove duplicates and join matches with comma
            $matches = array_unique($m[0]);
            $matches_str = implode(', ', $matches);
            return ['url' => $url, 'status' => $code, 'found' => 'Found', 'matches' => $matches_str];
        }
        return ['url' => $url, 'status' => $code, 'found' => 'Not Found', 'matches' => ''];
    }

    public function ajax_download() {
        check_ajax_referer($this->nonce_action, 'nonce');
        $job_id = sanitize_text_field($_GET['job_id']);
        $meta = get_transient($this->transient_prefix . $job_id);
        if (!$meta) wp_die('No job found');

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="regex-results.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['URL', 'Found or Not Found', 'HTTP Status Code', 'Matched URLs']);
        foreach ($meta['results'] as $r) {
            fputcsv($out, [$r['url'], $r['found'], $r['status'], $r['matches'] ?? '']);
        }
        fclose($out);
        exit;
    }
}
WP_Link_Regex_Extractor_Simple::instance();
