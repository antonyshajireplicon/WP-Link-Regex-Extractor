<?php
/**
 * Plugin Name: WP Link Regex Extractor
 * Description: Extracts links matching a regex from the view-source of pages (CSV input, concurrent checks with curl_multi, progress bar, CSV export).
 * Version: 1.0
 * Author: Antony Shaji
 */

if (!defined('ABSPATH')) exit;

class WP_Link_Regex_Extractor {
    private static $instance = null;
    private $nonce_action = 'wp_lre_nonce_action';
    private $nonce_name = 'wp_lre_nonce';
    private $transient_prefix = 'wp_lre_job_';

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_action('wp_ajax_wp_lre_start', array($this, 'ajax_start'));
        add_action('wp_ajax_wp_lre_status', array($this, 'ajax_status'));
        add_action('wp_ajax_wp_lre_download', array($this, 'ajax_download'));
    }

    public function admin_menu() {
        add_menu_page('Link Regex Extractor', 'Link Regex Extractor', 'manage_options', 'wp-link-regex-extractor', array($this, 'page'));
    }

    public function enqueue($hook) {
        if ($hook !== 'toplevel_page_wp-link-regex-extractor') return;
        wp_enqueue_script('wp-lre-admin', plugin_dir_url(__FILE__) . 'wp-lre-admin.js', array('jquery'), '1.0', true);
        wp_localize_script('wp-lre-admin', 'wp_lre', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce($this->nonce_action),
        ));
        wp_enqueue_style('wp-lre-admin-css', plugin_dir_url(__FILE__) . 'wp-lre-admin.css');
    }

    public function page() {
        ?>
        <div class="wrap">
            <h1>WP Link Regex Extractor</h1>
            <p>Upload a CSV (first row contains list of URLs) or paste URLs (one per line). Provide a regex pattern to match links inside the page view-source.</p>

            <table class="form-table">
                <tr>
                    <th>CSV Upload (first row read as list)</th>
                    <td><input type="file" id="lre_csv" accept=".csv"></td>
                </tr>
                <tr>
                    <th>Or paste URLs</th>
                    <td><textarea id="lre_urls" rows="6" style="width:100%" placeholder="https://example.com/page1\nhttps://example.com/page2"></textarea></td>
                </tr>
                <tr>
                    <th>Regex pattern</th>
                    <td><input type="text" id="lre_pattern" style="width:100%" placeholder="/your-regex-here/i"></td>
                </tr>
                <tr>
                    <th>Concurrency</th>
                    <td><input type="number" id="lre_concurrency" value="5" min="1" max="20"></td>
                </tr>
                <tr>
                    <th>Delay between requests (ms, randomized)</th>
                    <td><input type="number" id="lre_delay" value="200" min="0"></td>
                </tr>
                <tr>
                    <th>Max retries per URL</th>
                    <td><input type="number" id="lre_retries" value="2" min="0"></td>
                </tr>
            </table>

            <p>
                <button id="lre_start" class="button button-primary">Start</button>
                <button id="lre_cancel" class="button" disabled>Cancel</button>
                <button id="lre_download" class="button" disabled>Download CSV</button>
            </p>

            <div id="lre_progress" style="margin-top:10px; display:none">
                <div style="border:1px solid #ccc; width:100%; height:20px; position:relative; background:#fff;">
                    <div id="lre_progress_bar" style="height:100%; width:0%; background:linear-gradient(#6fb, #3a8);"></div>
                </div>
                <div id="lre_progress_text" style="margin-top:6px"></div>
            </div>

            <div id="lre_results" style="margin-top:20px; max-height:400px; overflow:auto; border:1px solid #eee; padding:10px"></div>
        </div>
        <?php
        // Inline JS and CSS fallback if files missing
        $this->inline_assets();
    }

    private function inline_assets() {
        // Provide a small JS if the external file is absent (for single-file plugin convenience)
        ?>
        <script>
        (function($){
            let jobId = null;
            let canceled = false;

            function readCSVFile(file, cb) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const text = e.target.result;
                    // parse only first line for URL list (comma-separated)
                    const firstLine = text.split(/\r?\n/)[0] || '';
                    const parts = firstLine.split(',').map(p => p.trim()).filter(Boolean);
                    cb(parts);
                };
                reader.readAsText(file);
            }

            $('#lre_start').on('click', function(){
                canceled = false;
                $('#lre_results').empty();
                $('#lre_download').prop('disabled', true);

                const fileInput = document.getElementById('lre_csv');
                const pasted = $('#lre_urls').val().trim();
                const pattern = $('#lre_pattern').val().trim();
                const concurrency = parseInt($('#lre_concurrency').val()||5,10);
                const delay = parseInt($('#lre_delay').val()||200,10);
                const retries = parseInt($('#lre_retries').val()||2,10);

                const startWithUrls = function(urls){
                    if (!urls || urls.length === 0) { alert('No URLs provided'); return; }
                    if (!pattern) { alert('Please provide a regex pattern'); return; }

                    // start job on server
                    $.post(wp_lre.ajax_url, {
                        action:'wp_lre_start',
                        urls: JSON.stringify(urls),
                        pattern: pattern,
                        concurrency: concurrency,
                        delay: delay,
                        retries: retries,
                        nonce: wp_lre.nonce
                    }, function(resp){
                        if (!resp || !resp.success) { alert('Server error: ' + (resp && resp.data ? resp.data : 'unknown')); return; }
                        jobId = resp.data.job_id;
                        $('#lre_progress').show();
                        pollStatus();
                        $('#lre_start').prop('disabled', true);
                        $('#lre_cancel').prop('disabled', false);
                    });
                };

                if (fileInput.files && fileInput.files.length) {
                    readCSVFile(fileInput.files[0], startWithUrls);
                } else if (pasted) {
                    const lines = pasted.split(/\r?\n/).map(l=>l.trim()).filter(Boolean);
                    startWithUrls(lines);
                } else {
                    alert('Please upload CSV or paste URLs');
                }
            });

            $('#lre_cancel').on('click', function(){ canceled = true; $('#lre_cancel').prop('disabled', true); });

            $('#lre_download').on('click', function(){
                if (!jobId) return alert('No job');
                window.location = wp_lre.ajax_url + '?action=wp_lre_download&job_id=' + encodeURIComponent(jobId) + '&nonce=' + encodeURIComponent(wp_lre.nonce);
            });

            function pollStatus(){
                if (!jobId) return;
                $.post(wp_lre.ajax_url, { action:'wp_lre_status', job_id: jobId, nonce: wp_lre.nonce }, function(resp){
                    if (!resp || !resp.success) { $('#lre_progress_text').text('Error fetching status'); return; }
                    const data = resp.data;
                    $('#lre_progress_bar').css('width', (data.progress_percent||0) + '%');
                    $('#lre_progress_text').text(data.completed + ' / ' + data.total + ' completed');

                    // append new results
                    if (data.new_results && data.new_results.length) {
                        data.new_results.forEach(function(r){
                            const container = $('<div style="border-bottom:1px solid #eee; padding:6px"></div>');
                            container.append('<strong>Source:</strong> <a href="'+r.source+'" target="_blank">'+r.source+'</a> ');
                            container.append('<div><strong>HTTP:</strong> ' + r.http_code + ' <strong>Error:</strong> ' + (r.error||'') + '</div>');
                            if (r.matched && r.matched.length) {
                                const ul = $('<ul></ul>');
                                r.matched.forEach(function(m){ ul.append('<li><a href="'+m+'" target="_blank">'+m+'</a></li>'); });
                                container.append('<div><strong>Matches:</strong></div>');
                                container.append(ul);
                            } else {
                                container.append('<div><em>No matches</em></div>');
                            }
                            $('#lre_results').append(container);
                        });
                    }

                    if (data.completed < data.total && !canceled) {
                        setTimeout(pollStatus, 800);
                    } else {
                        $('#lre_progress_bar').css('width', '100%');
                        $('#lre_progress_text').text('Finished: ' + data.completed + ' / ' + data.total);
                        $('#lre_start').prop('disabled', false);
                        $('#lre_cancel').prop('disabled', true);
                        $('#lre_download').prop('disabled', false);
                        jobId = null;
                    }
                });
            }
        })(jQuery);
        </script>
        <style>
        #lre_results a{word-break:break-all}
        </style>
        <?php
    }

    // Server: start job
    public function ajax_start() {
        check_ajax_referer($this->nonce_action, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('permission');

        $urls = isset($_POST['urls']) ? json_decode(stripslashes($_POST['urls']), true) : array();
        $pattern = isset($_POST['pattern']) ? sanitize_text_field($_POST['pattern']) : '';
        $concurrency = isset($_POST['concurrency']) ? intval($_POST['concurrency']) : 5;
        $delay = isset($_POST['delay']) ? intval($_POST['delay']) : 200;
        $retries = isset($_POST['retries']) ? intval($_POST['retries']) : 2;

        if (empty($urls) || empty($pattern)) wp_send_json_error('bad_input');

        $job_id = uniqid('wp_lre_');
        $meta = array(
            'urls' => array_values($urls),
            'pattern' => $pattern,
            'concurrency' => $concurrency,
            'delay' => $delay,
            'retries' => $retries,
            'total' => count($urls),
            'completed' => 0,
            'results' => array(),
            'queue' => array_values($urls),
            'last_index' => 0,
        );
        set_transient($this->transient_prefix.$job_id, $meta, 60*60); // 1 hour

        // Immediately process server-side in short batches to avoid long-running requests from JS
        // We'll start a background-like processing loop by calling our processor repeatedly via AJAX polling.
        // But per requirement, we must perform work now — process initial batch synchronously.

        $this->process_batch_for_job($job_id);

        wp_send_json_success(array('job_id' => $job_id));
    }

    // Server: status (polling)
    public function ajax_status() {
        check_ajax_referer($this->nonce_action, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('permission');
        $job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
        if (!$job_id) wp_send_json_error('no_job');
        $key = $this->transient_prefix.$job_id;
        $meta = get_transient($key);
        if ($meta === false) wp_send_json_success(array('total'=>0,'completed'=>0,'progress_percent'=>100,'new_results'=>array()));

        // Process another batch each poll to keep moving (best-effort)
        $this->process_batch_for_job($job_id);

        // After processing, reload.
        $meta = get_transient($key);
        $new_results = array();
        if (!empty($meta['results'])) {
            // return and then clear results buffer so client gets only new results
            $new_results = $meta['results'];
            $meta['results'] = array();
            set_transient($key, $meta, 60*60);
        }

        $percent = $meta['total'] ? round(100 * ($meta['completed'] / $meta['total']), 2) : 100;
        wp_send_json_success(array(
            'total' => $meta['total'],
            'completed' => $meta['completed'],
            'progress_percent' => $percent,
            'new_results' => $new_results,
        ));
    }

    // Server: download CSV
    public function ajax_download() {
        check_ajax_referer($this->nonce_action, 'nonce');
        if (!current_user_can('manage_options')) { wp_die('permission'); }
        $job_id = isset($_GET['job_id']) ? sanitize_text_field($_GET['job_id']) : '';
        if (!$job_id) wp_die('no job id');
        $key = $this->transient_prefix.$job_id;
        $meta = get_transient($key);
        if ($meta === false) wp_die('job not found or expired');

        $rows = array();
        // header
        $rows[] = array('source_url','http_code','error','matched_links');
        // if job still has queued processed items stored separately? We stored everything in 'saved_results'
        if (!empty($meta['saved_results'])) {
            $all = $meta['saved_results'];
        } else {
            // maybe results in results+history; we only stored per-batch results - attempt to assemble
            $all = !empty($meta['history']) ? $meta['history'] : array();
            if (empty($all) && isset($meta['completed_list'])) $all = $meta['completed_list'];
        }
        // fallback: if we don't have history, create from saved_results collected in other places
        if (empty($all) && isset($meta['finished_items'])) $all = $meta['finished_items'];
        // As a final fallback, use whatever we have in 'results' (may be partial)
        if (empty($all) && !empty($meta['results'])) $all = $meta['results'];

        foreach ($all as $r) {
            $rows[] = array($r['source'], $r['http_code'], isset($r['error'])?$r['error']:'', isset($r['matched'])?implode('|',$r['matched']):'');
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="wp-lre-'.$job_id.'.csv"');
        $out = fopen('php://output', 'w');
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    // Core: process a small batch synchronously using curl_multi
    private function process_batch_for_job($job_id) {
        $key = $this->transient_prefix.$job_id;
        $meta = get_transient($key);
        if ($meta === false) return;
        if (empty($meta['queue'])) return; // nothing to do

        $concurrency = max(1, intval($meta['concurrency']));
        $delay = max(0, intval($meta['delay']));
        $retries = max(0, intval($meta['retries']));

        // we'll process up to $concurrency URLs in this batch
        $batch = array_splice($meta['queue'], 0, $concurrency);
        $processing = $this->multi_fetch_and_match($batch, $meta['pattern'], $delay, $retries);

        // push to results and history
        if (!isset($meta['saved_results'])) $meta['saved_results'] = array();
        if (!isset($meta['history'])) $meta['history'] = array();
        foreach ($processing as $item) {
            $meta['saved_results'][] = $item;
            $meta['history'][] = $item;
            $meta['completed'] = (isset($meta['completed']) ? $meta['completed'] : 0) + 1;
        }

        // store finished items also for download
        $meta['finished_items'] = isset($meta['finished_items']) ? array_merge($meta['finished_items'], $processing) : $processing;

        // also put the most recent results into 'results' buffer (for client display), but keep history
        $meta['results'] = $processing;

        set_transient($key, $meta, 60*60);
    }

    // rotate user agents
    private function user_agents() {
        return array(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/117.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.1 Safari/605.1.15',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148',
        );
    }

    // Returns array of result items: [ ['source'=>..., 'http_code'=>..., 'error'=>..., 'matched'=>[...] ], ... ]
    private function multi_fetch_and_match($urls, $pattern, $delay_ms = 200, $max_retries = 2) {
        $results = array();
        if (empty($urls)) return $results;

        // validate regex server-side — expect user to wrap with delimiters
        $ok = @preg_match($pattern, '') !== false;
        if ($ok === false) {
            // invalid pattern — return error for each url
            foreach ($urls as $u) $results[] = array('source'=>$u, 'http_code'=>0, 'error'=>'invalid regex', 'matched'=>array());
            return $results;
        }

        $ua_list = $this->user_agents();
        $mh = curl_multi_init();
        $handles = array();

        // create initial handles
        foreach ($urls as $i => $url) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9'
            ));
            // rotate UAs
            $ua = $ua_list[array_rand($ua_list)];
            curl_setopt($ch, CURLOPT_USERAGENT, $ua);

            // optional: set proxy from constant if defined (owner may configure)
            if (defined('WP_LRE_PROXY') && WP_LRE_PROXY) {
                curl_setopt($ch, CURLOPT_PROXY, WP_LRE_PROXY);
            }

            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = array('handle'=>$ch, 'url'=>$url, 'attempt'=>0);

            // small randomized sleep between handle creation to look more human
            usleep(rand(0, intval($delay_ms))*1000);
        }

        // execute multi
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            // wait for activity
            curl_multi_select($mh, 0.5);

            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $hkey = (int)$ch;
                $url = $handles[$hkey]['url'];
                $attempt = $handles[$hkey]['attempt'];

                $content = false;
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_err = curl_error($ch);
                if ($info['result'] === CURLE_OK) {
                    $content = curl_multi_getcontent($ch);
                }

                // remove handle from multi
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($info['result'] === CURLE_OK && $http_code >= 200 && $http_code < 400 && $content !== false) {
                    // find matches
                    $matches = array();
                    // search entire source for the regex; user-supplied pattern should capture full URLs or partials
                    if (@preg_match_all($pattern, $content, $m)) {
                        // if the pattern has capturing group, collect group 0 or 1 accordingly
                        if (!empty($m[0])) {
                            foreach ($m[0] as $found) {
                                $found = trim($found);
                                // try to normalize relative URLs if possible — skip for simplicity
                                $matches[] = $found;
                            }
                        }
                    }

                    $results[] = array('source'=>$url, 'http_code'=>$http_code, 'error'=>'', 'matched'=>$matches);
                } else {
                    // retry logic
                    if ($attempt < $max_retries) {
                        // create new handle and re-add
                        $nh = curl_init();
                        curl_setopt($nh, CURLOPT_URL, $url);
                        curl_setopt($nh, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($nh, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($nh, CURLOPT_MAXREDIRS, 5);
                        curl_setopt($nh, CURLOPT_CONNECTTIMEOUT, 10);
                        curl_setopt($nh, CURLOPT_TIMEOUT, 20);
                        curl_setopt($nh, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($nh, CURLOPT_SSL_VERIFYHOST, 2);
                        curl_setopt($nh, CURLOPT_HTTPHEADER, array('Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.9'));
                        $ua = $ua_list[array_rand($ua_list)];
                        curl_setopt($nh, CURLOPT_USERAGENT, $ua);
                        if (defined('WP_LRE_PROXY') && WP_LRE_PROXY) {
                            curl_setopt($nh, CURLOPT_PROXY, WP_LRE_PROXY);
                        }
                        $handles[(int)$nh] = array('handle'=>$nh, 'url'=>$url, 'attempt'=>$attempt+1);
                        curl_multi_add_handle($mh, $nh);
                        // tiny backoff
                        usleep(250000);
                    } else {
                        $results[] = array('source'=>$url, 'http_code'=>$http_code, 'error'=>$curl_err ? $curl_err : 'http_'.$http_code, 'matched'=>array());
                    }
                }
            }
        } while ($running > 0);

        curl_multi_close($mh);
        return $results;
    }
}

WP_Link_Regex_Extractor::instance();

// Optional constants for operator: define WP_LRE_PROXY in wp-config.php to use a proxy for requests
// define('WP_LRE_PROXY', 'http://user:pass@127.0.0.1:8080');

?>
