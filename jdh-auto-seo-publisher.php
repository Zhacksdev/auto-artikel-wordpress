<?php
/*
Plugin Name: Auto SEO Saas
Plugin URI: https://github.com/Zhacksdev/Auto-SEO-v1
Description: AI SEO publisher with SaaS site profiles, Nine Router writing/image pipeline, keyword angle tracking, duplicate rewrite, watermarking, SEO metadata, crawl and daily reporting telegram.
Version: 4.2.6
Author: Zhacksdev
Update URI: https://github.com/Zhacksdev/Auto-SEO-v1
Requires at least: 5.0
Requires PHP: 7.4
License: GPL v2 or later
*/

if (!defined('ABSPATH')) {
    exit;
}

class JDH_Auto_SEO_Publisher
{
    const VERSION = '4.2.6';
    const GITHUB_USER = 'Zhacksdev';
    const GITHUB_REPO = 'Auto-SEO-v1';
    const GITHUB_BRANCH = 'main';
    const OPTION_KEY = 'jdh_seo_options';
    const KEYWORD_STATE_KEY = 'jdh_keyword_state';
    const JOBS_KEY = 'jdh_jobs';
    const LOCK_KEY = 'jdh_processing_lock';
    const MAX_JOBS = 100;
    const MAX_RETRY_PER_REQUEST = 3;
    const MAX_IMAGE_RETRY = 3;
    const MAX_ANGLE_ATTEMPTS = 3;
    const QUEUED_TIMEOUT_SECONDS = 3600;
    const ARTICLE_MIN_WORDS = 1200;
    const ARTICLE_MAX_WORDS = 2400;
    const ARTICLE_MIN_H2 = 8;
    const DEFAULT_WRITING_COMBO = 'Artikel';
    const LEGACY_WRITING_MODEL = 'gemini/gemini-3.1-flash-lite-preview';

    private $pexels_per_page = 8;
    private $pexels_orientation = 'landscape';

    public function __construct()
    {
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_page']);
            add_action('admin_init', [$this, 'register_plugin_settings']);
            add_action('admin_notices', [$this, 'show_notices']);
            add_filter('pre_set_site_transient_update_plugins', [$this, 'check_github_update']);
            add_filter('plugins_api', [$this, 'github_plugin_info'], 20, 3);
            add_filter('upgrader_source_selection', [$this, 'fix_github_folder_name'], 10, 4);
            add_action('upgrader_process_complete', [$this, 'after_plugin_update'], 10, 2);
        }

        add_action('wp_ajax_jdh_run_article', [$this, 'ajax_run_article']);
        add_action('wp_ajax_jdh_reset_tracker', [$this, 'ajax_reset_tracker']);
        add_action('wp_ajax_jdh_test_router', [$this, 'ajax_test_router']);
        add_action('wp_ajax_jdh_js_cron_check', [$this, 'ajax_js_cron_check']);
        add_action('jdh_daily_article_generation', [$this, 'run_daily_task']);
        add_action('init', [$this, 'maybe_schedule_daily_event']);

        add_action('wp_head', [$this, 'add_seo_meta_tags'], 1);
        add_filter('robots_txt', [$this, 'inject_sitemap_to_robots'], 10, 2);
    }

    public function activate()
    {
        $this->reschedule_daily_event($this->get_options());
    }

    public function deactivate()
    {
        wp_clear_scheduled_hook('jdh_daily_article_generation');
    }

    private function default_options()
    {
        return [
            'router_enabled' => '',
            'router_api_mode' => 'openai_compatible',
            'writing_router_endpoint' => '',
            'image_router_endpoint' => '',
            'router_api_key' => '',
            'router_model' => self::DEFAULT_WRITING_COMBO,
            'router_image_model' => 'gemini/gemini-3.1-flash-image-preview',
            'pexels_api_key' => '',
            'site_niche' => '',
            'target_audience' => '',
            'content_language' => 'id',
            'brand_tone' => 'edukatif, jelas, praktis, profesional',
            'social_or_external_links' => '',
            'forbidden_topics' => "SARA\npolitik",
            'target_keywords' => '',
            'articles_per_day' => 1,
            'max_angles_per_keyword' => 5,
            'daily_run_time' => '09:00',
            'external_url' => '',
            'youtube_link' => '',
            'publish_mode' => 'publish',
            'content_quality_min_score' => 80,
            'use_media_image' => '1',
            'pexels_enabled' => '',
            'auto_index_google' => '1',
            'schedule_end_date' => '',
            'watermark_enabled' => '1',
            'watermark_text_or_logo' => '',
            'js_cron_enabled' => '',
            'telegram_enabled' => '',
            'telegram_bot_token' => '',
            'telegram_chat_id' => '',
            'telegram_include_logs' => '1',
            'user_api_key' => '',
        ];
    }

    private function get_options()
    {
        $saved = get_option(self::OPTION_KEY, []);
        $saved = is_array($saved) ? $saved : [];
        $options = wp_parse_args($saved, $this->default_options());
        $configured_model = trim($this->scalar_string($options['router_model'] ?? ''));
        if ($configured_model === '' || $configured_model === self::LEGACY_WRITING_MODEL) {
            $options['router_model'] = self::DEFAULT_WRITING_COMBO;
        }
        return $options;
    }

    public function maybe_schedule_daily_event()
    {
        if (!wp_next_scheduled('jdh_daily_article_generation')) {
            $this->reschedule_daily_event($this->get_options());
        }
    }

    private function reschedule_daily_event($options)
    {
        wp_clear_scheduled_hook('jdh_daily_article_generation');
        $timestamp = $this->next_daily_timestamp($options['daily_run_time'] ?? '09:00');
        wp_schedule_event($timestamp, 'daily', 'jdh_daily_article_generation');
    }

    private function next_daily_timestamp($time)
    {
        $time = $this->normalize_daily_time($time);
        $timezone = wp_timezone();
        $now = new DateTimeImmutable('now', $timezone);
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $target = $now->setTime($hour, $minute, 0);
        if ($target <= $now) {
            $target = $target->modify('+1 day');
        }
        return $target->getTimestamp();
    }

    private function split_lines($value)
    {
        $parts = preg_split('/\R+/', $this->scalar_string($value));
        return is_array($parts) ? array_values(array_filter(array_map('trim', $parts))) : [];
    }

    private function normalize_keyword($keyword)
    {
        $normalized = preg_replace('/\s+/', ' ', sanitize_text_field($this->scalar_string($keyword)));
        return strtolower(trim(is_string($normalized) ? $normalized : ''));
    }

    private function scalar_string($value, $default = '')
    {
        return is_scalar($value) ? (string) $value : (string) $default;
    }

    private function scalar_int($value, $default = 0)
    {
        return is_scalar($value) && is_numeric($value) ? (int) $value : (int) $default;
    }

    private function normalize_daily_time($value)
    {
        $value = $this->scalar_string($value, '09:00');
        if (!preg_match('/^(\d{2}):(\d{2})$/', $value, $matches)) {
            return '09:00';
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        if ($hour > 23 || $minute > 59) {
            return '09:00';
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function extend_execution_time($seconds)
    {
        if (!function_exists('set_time_limit')) {
            return;
        }

        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        if (!in_array('set_time_limit', $disabled, true)) {
            set_time_limit(max(0, (int) $seconds));
        }
    }

    private function read_base_guide()
    {
        $guide_paths = [
            plugin_dir_path(__FILE__) . 'guide.md',
            dirname(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR . 'guide.md',
        ];

        foreach ($guide_paths as $guide_path) {
            if (is_readable($guide_path)) {
                $guide = file_get_contents($guide_path);
                if (is_string($guide) && $guide !== '') {
                    return $guide;
                }
            }
        }

        return 'Create focused, SEO-friendly Indonesian articles. Stay inside the site niche, avoid SARA and politics, avoid prices unless explicitly allowed, and return valid JSON only.';
    }

    private function build_dynamic_guide($options)
    {
        $niche = trim($options['site_niche'] ?? '');
        $audience = trim($options['target_audience'] ?? '');
        $language = trim($options['content_language'] ?? 'id');
        $tone = trim($options['brand_tone'] ?? '');
        $forbidden = implode(', ', $this->split_lines($options['forbidden_topics'] ?? ''));

        $allowed_scope = $niche ? "Fokus utama website adalah {$niche}. Semua artikel harus tetap berada dalam scope ini." : 'Scope niche belum diisi. Tetap buat artikel yang sangat relevan dengan keyword dan hindari pelebaran topik.';
        $audience_rule = $audience ? "Target pembaca: {$audience}." : 'Target pembaca belum diisi. Gunakan gaya umum yang mudah dipahami pemula.';
        $tone_rule = $tone ? "Gaya bahasa: {$tone}." : 'Gaya bahasa: edukatif, jelas, praktis, dan tidak berlebihan.';

        return trim(implode("\n", [
            '# Dynamic Site Guide',
            $allowed_scope,
            $audience_rule,
            "Bahasa artikel: {$language}.",
            $tone_rule,
            "Topik terlarang: {$forbidden}.",
            'Jangan membahas harga, biaya, politik, SARA, atau topik di luar niche kecuali user secara eksplisit mengizinkan.',
            'Gunakan keyword utama, sinonim, dan related entities secara natural. Jangan keyword stuffing.',
            'Jika ada link sosial, backlink, atau external link dari user, sisipkan secara natural hanya bila relevan.',
            'Jika keyword terlalu luas, persempit angle agar tetap sesuai niche website.',
        ]));
    }

    public function add_admin_page()
    {
        add_menu_page(
            'JDH Auto SEO',
            'Auto SEO',
            'manage_options',
            'jdh-auto-seo',
            [$this, 'render_admin_interface'],
            'dashicons-edit-page',
            25
        );
    }

    public function render_admin_interface()
    {
        $options = $this->get_options();
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('jdh_ajax_nonce');
        $state = $this->get_keyword_state();
        $jobs = $this->get_jobs();
        $keywords = $this->split_lines($options['target_keywords']);
        $next = $this->get_next_work_item($options, false);
        $next_cron = wp_next_scheduled('jdh_daily_article_generation');
        ?>
        <div class="wrap jdh-wrap">
            <style>
                .jdh-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(280px,.65fr);gap:18px;align-items:start}
                .jdh-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:14px 0}
                .jdh-panel h2{margin-top:0}
                .jdh-status{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}
                .jdh-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #dcdcde;border-radius:999px;padding:5px 10px;background:#f6f7f7;font-size:12px}
                .jdh-chip strong{font-size:13px}
                .jdh-primary{font-size:15px!important;padding:6px 22px!important}
                .jdh-log{background:#111827;color:#d1d5db;padding:14px;border-radius:8px;max-height:360px;overflow:auto;white-space:pre-wrap;line-height:1.6}
                .jdh-help{color:#646970;margin-top:4px}
                .jdh-advanced summary{cursor:pointer;font-weight:600;margin:8px 0}
                .jdh-table{width:100%;border-collapse:collapse}
                .jdh-table th,.jdh-table td{border-bottom:1px solid #e5e5e5;padding:8px;text-align:left;vertical-align:top}
                .jdh-table th{font-weight:600}
                @media (max-width: 960px){.jdh-grid{grid-template-columns:1fr}}
            </style>

            <h1>JDH Auto SEO Publisher <span style="font-size:12px;background:#2271b1;color:#fff;border-radius:999px;padding:3px 9px;">SaaS Router v<?php echo esc_html(self::VERSION); ?></span></h1>
            <p>Panel sederhana untuk membuat artikel SEO otomatis berdasarkan profil website, keyword, dan guide konten.</p>

            <div class="jdh-grid">
                <div>
                    <div class="jdh-panel">
                        <h2>1. Profil Website</h2>
                        <p class="jdh-help">Isi bagian ini supaya AI tahu batasan niche, target pembaca, bahasa, dan gaya artikel.</p>
                        <form method="post" action="options.php">
                            <?php
                            settings_fields('jdh-seo-settings-group');
                            do_settings_sections('jdh-auto-seo-publisher');
                            submit_button('Simpan Pengaturan');
                            ?>
                        </form>
                    </div>

                    <div class="jdh-panel">
                        <h2>2. Buat Artikel</h2>
                        <p>Plugin akan mengambil keyword dan angle berikutnya dari server. Satu keyword bisa dibuat sampai <?php echo esc_html((string) (int) $options['max_angles_per_keyword']); ?> angle berbeda.</p>
                        <div class="jdh-status">
                            <span class="jdh-chip">Keyword: <strong><?php echo esc_html((string) count($keywords)); ?></strong></span>
                            <span class="jdh-chip">Sudah publish: <strong><?php echo esc_html((string) $this->count_published_angles($state)); ?></strong></span>
                            <span class="jdh-chip">Berikutnya: <strong><?php echo esc_html($next ? $next['keyword'] . ' / angle ' . $next['angle_number'] : 'Tidak ada'); ?></strong></span>
                            <span class="jdh-chip">Mesin AI: <strong><?php echo !empty($options['router_enabled']) ? 'Router aktif' : 'Mode fallback'; ?></strong></span>
                            <span class="jdh-chip">Auto harian: <strong><?php echo esc_html($next_cron ? wp_date('Y-m-d H:i', $next_cron) : 'Belum terjadwal'); ?></strong></span>
                        </div>
                        <button id="jdh-run-btn" class="button button-primary button-large jdh-primary">Buat Artikel Sekarang</button>
                        <button id="jdh-batch-btn" class="button button-secondary button-large">Buat Batch</button>
                        <button id="jdh-test-router-btn" class="button">Tes Router</button>
                        <button id="jdh-reset-btn" class="button">Reset Tracker</button>
                        <div id="jdh-progress" style="display:none;margin-top:14px;">
                            <div style="height:18px;background:#f0f0f1;border-radius:999px;overflow:hidden;">
                                <div id="jdh-progress-bar" style="height:100%;width:0%;background:#2271b1;transition:width .25s;"></div>
                            </div>
                            <p id="jdh-progress-text" style="font-weight:600;"></p>
                        </div>
                        <div id="jdh-results"></div>
                        <pre id="jdh-log" class="jdh-log" style="display:none;"></pre>
                    </div>
                </div>

                <div>
                    <div class="jdh-panel">
                        <h2>Guide Otomatis</h2>
                        <p class="jdh-help">Ringkasan ini dikirim ke router setiap generate agar artikel tidak melebar.</p>
                        <pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px;max-height:280px;overflow:auto;"><?php echo esc_html($this->build_dynamic_guide($options)); ?></pre>
                    </div>

                    <div class="jdh-panel">
                        <h2>Riwayat Terakhir</h2>
                        <?php if (empty($jobs)) : ?>
                            <p>Belum ada riwayat generate.</p>
                        <?php else : ?>
                            <table class="jdh-table">
                                <thead><tr><th>Status</th><th>Keyword</th><th>Artikel</th><th>Error</th></tr></thead>
                                <tbody>
                                <?php foreach (array_slice(array_reverse($jobs), 0, 10) as $job) : 
                                    $status = $job['status'] ?? '-';
                                    $status_color = $status === 'done' ? '#00a32a' : ($status === 'failed' ? '#d63638' : ($status === 'skipped' ? '#dba617' : '#2271b1'));
                                ?>
                                    <tr>
                                        <td><span style="color:<?php echo esc_attr($status_color); ?>;font-weight:600;"><?php echo esc_html($status); ?></span></td>
                                        <td><?php echo esc_html($job['keyword'] ?? '-'); ?><br><small>Angle <?php echo esc_html((string) ($job['angle_number'] ?? '-')); ?></small></td>
                                        <td>
                                            <?php if (!empty($job['post_id'])) : ?>
                                                <a href="<?php echo esc_url(get_edit_post_link((int) $job['post_id'])); ?>">#<?php echo esc_html((string) (int) $job['post_id']); ?></a>
                                            <?php else : ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td><small><?php echo esc_html(wp_trim_words($job['error_message'] ?? '-', 12, '...')); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var maxBatch = <?php echo (int) max(1, min(5, absint($options['articles_per_day']))); ?>;
            var runBtn = document.getElementById('jdh-run-btn');
            var batchBtn = document.getElementById('jdh-batch-btn');
            var testRouterBtn = document.getElementById('jdh-test-router-btn');
            var resetBtn = document.getElementById('jdh-reset-btn');
            var progress = document.getElementById('jdh-progress');
            var progressBar = document.getElementById('jdh-progress-bar');
            var progressText = document.getElementById('jdh-progress-text');
            var results = document.getElementById('jdh-results');
            var logBox = document.getElementById('jdh-log');

            function appendResult(ok, text) {
                var div = document.createElement('div');
                div.style.cssText = 'padding:9px 12px;margin:8px 0;border-radius:6px;border-left:4px solid ' + (ok ? '#00a32a' : '#d63638') + ';background:' + (ok ? '#edfaef' : '#fcf0f1') + ';';
                div.innerHTML = text;
                results.appendChild(div);
            }
            function appendLog(lines) {
                if (!lines || !lines.length) return;
                logBox.style.display = 'block';
                logBox.textContent += lines.join("\n") + "\n---\n";
                logBox.scrollTop = logBox.scrollHeight;
            }
            function setBusy(isBusy) {
                runBtn.disabled = isBusy;
                batchBtn.disabled = isBusy;
                resetBtn.disabled = isBusy;
            }
            function runOne(index, total) {
                progress.style.display = 'block';
                progressBar.style.width = Math.round((index / total) * 100) + '%';
                progressText.textContent = 'Membuat artikel ' + (index + 1) + ' dari ' + total + '...';
                var fd = new FormData();
                fd.append('action', 'jdh_run_article');
                fd.append('nonce', nonce);
                fd.append('index', index);
                fd.append('total', total);
                return fetch(ajaxUrl, {method:'POST', body:fd})
                    .then(function(r){return r.json();})
                    .then(function(data){
                        if (data.success) {
                            appendResult(true, data.data.msg);
                            appendLog(data.data.logs);
                        } else {
                            var msg = data.data && data.data.msg ? data.data.msg : 'Gagal membuat artikel.';
                            appendResult(false, msg);
                            if (data.data && data.data.logs) appendLog(data.data.logs);
                        }
                    });
            }
            function runBatch(total) {
                setBusy(true);
                results.innerHTML = '';
                logBox.textContent = '';
                var chain = Promise.resolve();
                for (var i = 0; i < total; i++) {
                    (function(idx){
                        chain = chain.then(function(){ return runOne(idx, total); });
                    })(i);
                }
                chain.finally(function(){
                    progressBar.style.width = '100%';
                    progressText.textContent = 'Selesai.';
                    setBusy(false);
                });
            }
            runBtn.addEventListener('click', function(){ runBatch(1); });
            batchBtn.addEventListener('click', function(){ runBatch(maxBatch); });
            testRouterBtn.addEventListener('click', function(){
                setBusy(true);
                results.innerHTML = '';
                logBox.textContent = '';
                progress.style.display = 'block';
                progressBar.style.width = '30%';
                progressText.textContent = 'Mengetes koneksi router...';
                var fd = new FormData();
                fd.append('action', 'jdh_test_router');
                fd.append('nonce', nonce);
                fetch(ajaxUrl, {method:'POST', body:fd}).then(function(r){return r.json();}).then(function(data){
                    progressBar.style.width = '100%';
                    progressText.textContent = data.success ? 'Tes router selesai.' : 'Tes router gagal.';
                    if (data.success) {
                        appendResult(true, data.data.msg);
                    } else {
                        appendResult(false, data.data && data.data.msg ? data.data.msg : 'Router belum bisa dihubungi.');
                    }
                    if (data.data && data.data.logs) appendLog(data.data.logs);
                }).catch(function(err){
                    progressText.textContent = 'Tes router gagal.';
                    appendResult(false, 'Error koneksi: ' + err.message);
                }).finally(function(){ setBusy(false); });
            });
            resetBtn.addEventListener('click', function(){
                if (!confirm('Reset tracker keyword dan angle?')) return;
                setBusy(true);
                var fd = new FormData();
                fd.append('action', 'jdh_reset_tracker');
                fd.append('nonce', nonce);
                fetch(ajaxUrl, {method:'POST', body:fd}).then(function(r){return r.json();}).then(function(data){
                    alert(data.success ? data.data.msg : 'Reset gagal.');
                    location.reload();
                }).finally(function(){ setBusy(false); });
            });
        })();
        </script>
        <?php
    }

    public function register_plugin_settings()
    {
        register_setting('jdh-seo-settings-group', self::OPTION_KEY, [$this, 'sanitize_options']);

        add_settings_section('jdh_profile_section', 'Profil Website', '__return_null', 'jdh-auto-seo-publisher');
        add_settings_field('site_niche', 'Niche Website', [$this, 'cb_site_niche'], 'jdh-auto-seo-publisher', 'jdh_profile_section');
        add_settings_field('target_audience', 'Target Pembaca', [$this, 'cb_target_audience'], 'jdh-auto-seo-publisher', 'jdh_profile_section');
        add_settings_field('content_language', 'Bahasa Artikel', [$this, 'cb_content_language'], 'jdh-auto-seo-publisher', 'jdh_profile_section');
        add_settings_field('brand_tone', 'Gaya Tulisan', [$this, 'cb_brand_tone'], 'jdh-auto-seo-publisher', 'jdh_profile_section');
        add_settings_field('forbidden_topics', 'Topik Dilarang', [$this, 'cb_forbidden_topics'], 'jdh-auto-seo-publisher', 'jdh_profile_section');

        add_settings_section('jdh_content_section', 'Keyword dan Link', '__return_null', 'jdh-auto-seo-publisher');
        add_settings_field('target_keywords', 'Target Keywords', [$this, 'cb_keywords'], 'jdh-auto-seo-publisher', 'jdh_content_section');
        add_settings_field('articles_per_day', 'Jumlah Artikel per Batch', [$this, 'cb_articles'], 'jdh-auto-seo-publisher', 'jdh_content_section');
        add_settings_field('max_angles_per_keyword', 'Maksimal Angle per Keyword', [$this, 'cb_max_angles'], 'jdh-auto-seo-publisher', 'jdh_content_section');
        add_settings_field('daily_run_time', 'Jam Generate Harian', [$this, 'cb_daily_run_time'], 'jdh-auto-seo-publisher', 'jdh_content_section');
        add_settings_field('social_or_external_links', 'Link Sosial / Backlink', [$this, 'cb_links'], 'jdh-auto-seo-publisher', 'jdh_content_section');
        add_settings_field('external_url', 'Link Utama Opsional', [$this, 'cb_external_url'], 'jdh-auto-seo-publisher', 'jdh_content_section');
        add_settings_field('youtube_link', 'Video Opsional', [$this, 'cb_youtube'], 'jdh-auto-seo-publisher', 'jdh_content_section');

        add_settings_section('jdh_image_section', 'Gambar dan Watermark', '__return_null', 'jdh-auto-seo-publisher');
        add_settings_field('use_media_image', 'Media Library', [$this, 'cb_use_media'], 'jdh-auto-seo-publisher', 'jdh_image_section');
        add_settings_field('pexels_enabled', 'Pexels', [$this, 'cb_pexels_enabled'], 'jdh-auto-seo-publisher', 'jdh_image_section');
        add_settings_field('watermark_enabled', 'Watermark', [$this, 'cb_watermark_enabled'], 'jdh-auto-seo-publisher', 'jdh_image_section');
        add_settings_field('watermark_text_or_logo', 'Teks Watermark', [$this, 'cb_watermark_text'], 'jdh-auto-seo-publisher', 'jdh_image_section');

        add_settings_section('jdh_advanced_section', 'Pengaturan Lanjutan', [$this, 'render_advanced_hint'], 'jdh-auto-seo-publisher');
        add_settings_field('router_enabled', 'Nine Router', [$this, 'cb_router_enabled'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('router_api_mode', 'Mode Router', [$this, 'cb_router_api_mode'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('writing_router_endpoint', 'Writing Router Endpoint', [$this, 'cb_writing_endpoint'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('image_router_endpoint', 'Image Router Endpoint', [$this, 'cb_image_endpoint'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('router_api_key', 'Router API Key', [$this, 'cb_router_api_key'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('router_model', 'Combo Artikel 9Router', [$this, 'cb_router_model'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('router_image_model', 'Model Image Router', [$this, 'cb_router_image_model'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('pexels_api_key', 'Pexels API Key', [$this, 'cb_pexels_api_key'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('content_quality_min_score', 'Minimum Quality Score', [$this, 'cb_quality_score'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('auto_index_google', 'Crawl Readiness', [$this, 'cb_auto_index'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('schedule_end_date', 'Jadwal Sampai Tanggal', [$this, 'cb_schedule_end'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('js_cron_enabled', 'JS Cron Fallback', [$this, 'cb_js_cron'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('telegram_enabled', 'Telegram Bot', [$this, 'cb_telegram_enabled'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('telegram_bot_token', 'Telegram Bot Token', [$this, 'cb_telegram_bot_token'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('telegram_chat_id', 'Telegram Chat ID', [$this, 'cb_telegram_chat_id'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('telegram_include_logs', 'Log Controller Telegram', [$this, 'cb_telegram_include_logs'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
        add_settings_field('user_api_key', 'Fallback Groq API Key', [$this, 'cb_user_api_key'], 'jdh-auto-seo-publisher', 'jdh_advanced_section');
    }

    public function render_advanced_hint()
    {
        echo '<p class="description">Bagian ini untuk admin teknis. User awam cukup mengisi profil, keyword, link, dan gambar.</p>';
    }

    private function option_value($key)
    {
        $options = $this->get_options();
        return $options[$key] ?? '';
    }

    public function cb_site_niche()
    {
        echo '<input type="text" name="' . esc_attr(self::OPTION_KEY) . '[site_niche]" value="' . esc_attr($this->option_value('site_niche')) . '" class="regular-text" placeholder="Contoh: jasa pasang bangunan">';
        echo '<p class="description">Ini menjadi batas utama topik artikel.</p>';
    }

    public function cb_target_audience()
    {
        echo '<input type="text" name="' . esc_attr(self::OPTION_KEY) . '[target_audience]" value="' . esc_attr($this->option_value('target_audience')) . '" class="regular-text" placeholder="Contoh: pemilik rumah yang ingin renovasi">';
    }

    public function cb_content_language()
    {
        echo '<input type="text" name="' . esc_attr(self::OPTION_KEY) . '[content_language]" value="' . esc_attr($this->option_value('content_language')) . '" class="small-text" placeholder="id">';
    }

    public function cb_brand_tone()
    {
        echo '<input type="text" name="' . esc_attr(self::OPTION_KEY) . '[brand_tone]" value="' . esc_attr($this->option_value('brand_tone')) . '" class="regular-text" placeholder="edukatif, meyakinkan, mudah dipahami">';
    }

    public function cb_forbidden_topics()
    {
        echo '<textarea name="' . esc_attr(self::OPTION_KEY) . '[forbidden_topics]" rows="3" class="large-text">' . esc_textarea($this->option_value('forbidden_topics')) . '</textarea>';
        echo '<p class="description">Satu topik per baris. Default: SARA dan politik.</p>';
    }

    public function cb_keywords()
    {
        echo '<textarea name="' . esc_attr(self::OPTION_KEY) . '[target_keywords]" rows="7" class="large-text" placeholder="Satu keyword per baris...">' . esc_textarea($this->option_value('target_keywords')) . '</textarea>';
    }

    public function cb_articles()
    {
        echo '<input type="number" name="' . esc_attr(self::OPTION_KEY) . '[articles_per_day]" value="' . esc_attr($this->option_value('articles_per_day')) . '" min="1" max="5" class="small-text">';
    }

    public function cb_max_angles()
    {
        echo '<input type="number" name="' . esc_attr(self::OPTION_KEY) . '[max_angles_per_keyword]" value="' . esc_attr($this->option_value('max_angles_per_keyword')) . '" min="1" max="5" class="small-text">';
    }

    public function cb_daily_run_time()
    {
        echo '<input type="time" name="' . esc_attr(self::OPTION_KEY) . '[daily_run_time]" value="' . esc_attr($this->option_value('daily_run_time')) . '" class="small-text">';
        echo '<p class="description">Plugin menjadwalkan generate otomatis harian pada jam ini mengikuti timezone WordPress.</p>';
    }

    public function cb_links()
    {
        echo '<textarea name="' . esc_attr(self::OPTION_KEY) . '[social_or_external_links]" rows="4" class="large-text" placeholder="Instagram | https://instagram.com/brand">' . esc_textarea($this->option_value('social_or_external_links')) . '</textarea>';
        echo '<p class="description">Format bebas, satu link per baris. Akan dipakai natural jika relevan.</p>';
    }

    public function cb_external_url()
    {
        echo '<input type="url" name="' . esc_attr(self::OPTION_KEY) . '[external_url]" value="' . esc_attr($this->option_value('external_url')) . '" class="regular-text" placeholder="https://...">';
    }

    public function cb_youtube()
    {
        echo '<input type="text" name="' . esc_attr(self::OPTION_KEY) . '[youtube_link]" value="' . esc_attr($this->option_value('youtube_link')) . '" class="regular-text" placeholder="YouTube, Vimeo, atau URL video">';
    }

    public function cb_use_media()
    {
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[use_media_image]" value="1" ' . checked('1', $this->option_value('use_media_image'), false) . '> Pakai gambar dari Media Library jika relevan dengan artikel</label>';
        echo '<p class="description">Jika tidak dicentang, Media Library dilewati.</p>';
    }

    public function cb_pexels_enabled()
    {
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[pexels_enabled]" value="1" ' . checked('1', $this->option_value('pexels_enabled'), false) . '> Pakai Pexels jika API key tersedia</label>';
        echo '<p class="description">Jika Media Library dan Pexels tidak dicentang, sistem langsung memakai Image Router.</p>';
    }

    public function cb_watermark_enabled()
    {
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[watermark_enabled]" value="1" ' . checked('1', $this->option_value('watermark_enabled'), false) . '> Tambahkan watermark pada gambar baru</label>';
    }

    public function cb_watermark_text()
    {
        echo '<input type="text" name="' . esc_attr(self::OPTION_KEY) . '[watermark_text_or_logo]" value="' . esc_attr($this->option_value('watermark_text_or_logo')) . '" class="regular-text" placeholder="Kosongkan untuk pakai nama website">';
    }

    public function cb_router_enabled()
    {
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[router_enabled]" value="1" ' . checked('1', $this->option_value('router_enabled'), false) . '> Gunakan Nine Router</label>';
    }

    public function cb_router_api_mode()
    {
        $value = $this->option_value('router_api_mode') ?: 'openai_compatible';
        echo '<select name="' . esc_attr(self::OPTION_KEY) . '[router_api_mode]">';
        echo '<option value="openai_compatible" ' . selected('openai_compatible', $value, false) . '>OpenAI Compatible /v1</option>';
        echo '<option value="webhook" ' . selected('webhook', $value, false) . '>Webhook JSON</option>';
        echo '</select>';
        echo '<p class="description">Pakai OpenAI Compatible untuk endpoint seperti http://host:port/v1.</p>';
    }

    public function cb_writing_endpoint()
    {
        echo '<input type="url" name="' . esc_attr(self::OPTION_KEY) . '[writing_router_endpoint]" value="' . esc_attr($this->option_value('writing_router_endpoint')) . '" class="regular-text" placeholder="http://100.104.182.65:20128/v1">';
        echo '<p class="description">Untuk mode OpenAI Compatible, isi base URL /v1. Untuk mode Webhook, isi URL webhook writing.</p>';
    }

    public function cb_image_endpoint()
    {
        echo '<input type="url" name="' . esc_attr(self::OPTION_KEY) . '[image_router_endpoint]" value="' . esc_attr($this->option_value('image_router_endpoint')) . '" class="regular-text" placeholder="http://localhost:5678/webhook/image">';
    }

    public function cb_router_api_key()
    {
        echo '<input type="password" name="' . esc_attr(self::OPTION_KEY) . '[router_api_key]" value="' . esc_attr($this->option_value('router_api_key')) . '" class="regular-text">';
    }

    public function cb_router_model()
    {
        echo '<input type="text" name="' . esc_attr(self::OPTION_KEY) . '[router_model]" value="' . esc_attr($this->option_value('router_model')) . '" class="regular-text" placeholder="Artikel">';
        echo '<p class="description">Isi model ID combo 9Router, misalnya Artikel. Urutan model dan fallback dikelola sepenuhnya di dalam combo 9Router.</p>';
    }

    public function cb_router_image_model()
    {
        echo '<input type="text" name="' . esc_attr(self::OPTION_KEY) . '[router_image_model]" value="' . esc_attr($this->option_value('router_image_model')) . '" class="regular-text" placeholder="gemini/gemini-3.1-flash-image-preview">';
        echo '<p class="description">Model dari /v1/models/image untuk generate gambar native 9Router.</p>';
    }

    public function cb_pexels_api_key()
    {
        echo '<input type="password" name="' . esc_attr(self::OPTION_KEY) . '[pexels_api_key]" value="' . esc_attr($this->option_value('pexels_api_key')) . '" class="regular-text">';
        echo '<p class="description">Dipakai hanya jika opsi Pexels di bagian Gambar dan Watermark dicentang.</p>';
    }

    public function cb_quality_score()
    {
        echo '<input type="number" name="' . esc_attr(self::OPTION_KEY) . '[content_quality_min_score]" value="' . esc_attr($this->option_value('content_quality_min_score')) . '" min="50" max="100" class="small-text">';
    }

    public function cb_auto_index()
    {
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[auto_index_google]" value="1" ' . checked('1', $this->option_value('auto_index_google'), false) . '> Aktifkan sitemap/crawl readiness setelah publish</label>';
    }

    public function cb_schedule_end()
    {
        echo '<input type="date" name="' . esc_attr(self::OPTION_KEY) . '[schedule_end_date]" value="' . esc_attr($this->option_value('schedule_end_date')) . '">';
    }

    public function cb_js_cron()
    {
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[js_cron_enabled]" value="1" ' . checked('1', $this->option_value('js_cron_enabled'), false) . '> Fallback darurat saat WP-Cron tidak jalan</label>';
    }

    public function cb_telegram_enabled()
    {
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[telegram_enabled]" value="1" ' . checked('1', $this->option_value('telegram_enabled'), false) . '> Kirim riwayat generate ke Telegram</label>';
        echo '<p class="description">Notifikasi dikirim saat job selesai, baik berhasil publish maupun gagal.</p>';
    }

    public function cb_telegram_bot_token()
    {
        echo '<input type="password" name="' . esc_attr(self::OPTION_KEY) . '[telegram_bot_token]" value="' . esc_attr($this->option_value('telegram_bot_token')) . '" class="regular-text" placeholder="123456789:ABC...">';
    }

    public function cb_telegram_chat_id()
    {
        echo '<input type="text" name="' . esc_attr(self::OPTION_KEY) . '[telegram_chat_id]" value="' . esc_attr($this->option_value('telegram_chat_id')) . '" class="regular-text" placeholder="Contoh: 123456789 atau -1001234567890">';
    }

    public function cb_telegram_include_logs()
    {
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[telegram_include_logs]" value="1" ' . checked('1', $this->option_value('telegram_include_logs'), false) . '> Sertakan log controller/pipeline di pesan Telegram</label>';
        echo '<p class="description">Log dipotong otomatis agar tetap muat di batas pesan Telegram.</p>';
    }

    public function cb_user_api_key()
    {
        echo '<input type="password" name="' . esc_attr(self::OPTION_KEY) . '[user_api_key]" value="' . esc_attr($this->option_value('user_api_key')) . '" class="regular-text" placeholder="gsk_...">';
    }

    public function sanitize_options($input)
    {
        $defaults = $this->default_options();
        $input = is_array($input) ? $input : [];
        $router_mode = $this->scalar_string($input['router_api_mode'] ?? 'openai_compatible');
        $schedule_end = $this->scalar_string($input['schedule_end_date'] ?? '');
        if ($schedule_end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedule_end)) {
            $schedule_end = '';
        }
        $sanitized = [
            'router_enabled' => !empty($input['router_enabled']) ? '1' : '',
            'router_api_mode' => in_array($router_mode, ['openai_compatible', 'webhook'], true) ? $router_mode : 'openai_compatible',
            'writing_router_endpoint' => esc_url_raw($this->scalar_string($input['writing_router_endpoint'] ?? '')),
            'image_router_endpoint' => esc_url_raw($this->scalar_string($input['image_router_endpoint'] ?? '')),
            'router_api_key' => sanitize_text_field($this->scalar_string($input['router_api_key'] ?? '')),
            'router_model' => sanitize_text_field($this->scalar_string($input['router_model'] ?? $defaults['router_model'], $defaults['router_model'])),
            'router_image_model' => sanitize_text_field($this->scalar_string($input['router_image_model'] ?? $defaults['router_image_model'], $defaults['router_image_model'])),
            'pexels_api_key' => sanitize_text_field($this->scalar_string($input['pexels_api_key'] ?? '')),
            'site_niche' => sanitize_text_field($this->scalar_string($input['site_niche'] ?? '')),
            'target_audience' => sanitize_text_field($this->scalar_string($input['target_audience'] ?? '')),
            'content_language' => sanitize_text_field($this->scalar_string($input['content_language'] ?? $defaults['content_language'], $defaults['content_language'])),
            'brand_tone' => sanitize_text_field($this->scalar_string($input['brand_tone'] ?? '')),
            'social_or_external_links' => sanitize_textarea_field($this->scalar_string($input['social_or_external_links'] ?? '')),
            'forbidden_topics' => sanitize_textarea_field($this->scalar_string($input['forbidden_topics'] ?? $defaults['forbidden_topics'], $defaults['forbidden_topics'])),
            'target_keywords' => sanitize_textarea_field($this->scalar_string($input['target_keywords'] ?? '')),
            'articles_per_day' => max(1, min(5, $this->scalar_int($input['articles_per_day'] ?? 1, 1))),
            'max_angles_per_keyword' => max(1, min(5, $this->scalar_int($input['max_angles_per_keyword'] ?? 5, 5))),
            'daily_run_time' => $this->normalize_daily_time($input['daily_run_time'] ?? '09:00'),
            'external_url' => esc_url_raw($this->scalar_string($input['external_url'] ?? '')),
            'youtube_link' => sanitize_text_field($this->scalar_string($input['youtube_link'] ?? '')),
            'publish_mode' => 'publish',
            'content_quality_min_score' => max(50, min(100, $this->scalar_int($input['content_quality_min_score'] ?? 80, 80))),
            'use_media_image' => !empty($input['use_media_image']) ? '1' : '',
            'pexels_enabled' => !empty($input['pexels_enabled']) ? '1' : '',
            'auto_index_google' => !empty($input['auto_index_google']) ? '1' : '0',
            'schedule_end_date' => sanitize_text_field($schedule_end),
            'watermark_enabled' => !empty($input['watermark_enabled']) ? '1' : '',
            'watermark_text_or_logo' => sanitize_text_field($this->scalar_string($input['watermark_text_or_logo'] ?? '')),
            'js_cron_enabled' => !empty($input['js_cron_enabled']) ? '1' : '',
            'telegram_enabled' => !empty($input['telegram_enabled']) ? '1' : '',
            'telegram_bot_token' => sanitize_text_field($this->scalar_string($input['telegram_bot_token'] ?? '')),
            'telegram_chat_id' => sanitize_text_field($this->scalar_string($input['telegram_chat_id'] ?? '')),
            'telegram_include_logs' => !empty($input['telegram_include_logs']) ? '1' : '',
            'user_api_key' => sanitize_text_field($this->scalar_string($input['user_api_key'] ?? '')),
        ];

        $this->reschedule_daily_event($sanitized);

        return $sanitized;
    }

    public function ajax_run_article()
    {
        check_ajax_referer('jdh_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['msg' => 'Tidak punya izin.']);
        }

        $this->extend_execution_time(900);

        $options = $this->get_options();
        $logs = [];
        $work = $this->get_next_work_item($options, true);

        if (!$work) {
            $lock = get_option(self::LOCK_KEY, 0);
            if ($lock && (time() - (int) $lock) < self::QUEUED_TIMEOUT_SECONDS) {
                wp_send_json_error(['msg' => 'Proses lain sedang berjalan. Coba lagi dalam beberapa menit.', 'logs' => ['Lock aktif, proses paralel dicegah.']]);
            }
            wp_send_json_error(['msg' => 'Tidak ada keyword atau angle yang bisa diproses.', 'logs' => ['Keyword kosong atau semua angle sudah selesai.']]);
        }

        $job = $this->create_job($work['keyword'], $work['angle_number']);
        $logs[] = "Job {$job['job_id']}: {$work['keyword']} / angle {$work['angle_number']}";
        $logs[] = "Router: " . (!empty($options['router_enabled']) ? 'Nine Router aktif' : 'Mode fallback Groq');

        $result = $this->process_article_job_safely($job, $work, $options, $logs);

        if (!empty($result['success'])) {
            wp_send_json_success([
                'msg' => 'Berhasil publish: <a href="' . esc_url($result['url']) . '" target="_blank">' . esc_html($result['title']) . '</a>',
                'post_id' => $result['post_id'],
                'logs' => $logs,
            ]);
        }

        $msg = $result['message'] ?? 'Gagal membuat artikel.';
        if (!empty($result['skipped'])) {
            $msg = 'Dilewati: ' . $msg;
        }
        wp_send_json_error([
            'msg' => $msg,
            'logs' => $logs,
        ]);
    }

    private function process_article_job($job, $work, $options, &$logs)
    {
        $this->update_job($job['job_id'], ['status' => 'sending_to_router']);

        $article = false;
        $last_error = '';
        for ($attempt = 1; $attempt <= self::MAX_RETRY_PER_REQUEST; $attempt++) {
            $logs[] = "Attempt $attempt: generate artikel.";
            $article = $this->generate_article_payload($work, $options, $logs, $attempt, $last_error);
            if (!$article) {
                $last_error = 'Router/fallback gagal mengembalikan artikel.';
                continue;
            }

            $validation = $this->validate_article_payload($article, $options);
            if (!$validation['valid']) {
                $last_error = $validation['message'];
                $logs[] = "Validasi gagal: $last_error";
                $article = false;
                continue;
            }

            if ($this->is_forbidden_topic($article, $options)) {
                $last_error = 'Topik mengandung SARA/politik atau topik terlarang.';
                $logs[] = $last_error;
                $article = false;
                $this->mark_angle_skipped($work['keyword'], $work['angle_number'], $last_error);
                $this->update_job($job['job_id'], ['status' => 'skipped', 'error_message' => $last_error]);
                return ['success' => false, 'message' => $last_error, 'skipped' => true];
            }

            $duplicate = $this->detect_duplicate_article($article, $work);
            if ($duplicate['duplicate']) {
                $last_error = $duplicate['message'];
                $logs[] = "Duplicate terdeteksi: {$duplicate['message']}. Minta rewrite.";
                $article = false;
                continue;
            }

            break;
        }

        if (!$article) {
            $this->update_job($job['job_id'], ['status' => 'failed', 'error_message' => $last_error]);
            $this->mark_angle_failed($work['keyword'], $work['angle_number'], $last_error);
            return ['success' => false, 'message' => $last_error ?: 'Gagal membuat artikel setelah retry.'];
        }

        $this->update_job($job['job_id'], ['status' => 'image_processing', 'router_response' => $article]);
        $image_id = 0;
        for ($image_attempt = 1; $image_attempt <= self::MAX_IMAGE_RETRY; $image_attempt++) {
            $logs[] = "Featured image attempt {$image_attempt}/" . self::MAX_IMAGE_RETRY . '.';
            $candidate_id = $this->resolve_article_image($article, $work, $options, $logs, $image_attempt);
            if ($this->is_valid_featured_image($candidate_id)) {
                $image_id = (int) $candidate_id;
                break;
            }
            $logs[] = "Featured image attempt {$image_attempt} belum menghasilkan attachment valid.";
        }

        if (!$image_id) {
            $error = 'Featured image wajib, tetapi semua sumber gambar gagal setelah ' . self::MAX_IMAGE_RETRY . ' attempt. Artikel tidak dipublish.';
            $logs[] = $error;
            $this->update_job($job['job_id'], ['status' => 'failed', 'error_message' => $error]);
            $this->mark_angle_failed($work['keyword'], $work['angle_number'], $error);
            return ['success' => false, 'message' => $error];
        }

        $this->update_job($job['job_id'], ['status' => 'publishing']);
        $post_id = $this->publish_article($article, $work, $options, $image_id, $logs);
        if (!$post_id || is_wp_error($post_id)) {
            $error = is_wp_error($post_id) ? $post_id->get_error_message() : 'wp_insert_post gagal.';
            $this->update_job($job['job_id'], ['status' => 'failed', 'error_message' => $error]);
            $this->mark_angle_failed($work['keyword'], $work['angle_number'], $error);
            return ['success' => false, 'message' => $error];
        }

        $this->mark_angle_published($work['keyword'], $work['angle_number'], $post_id, $article);
        $this->update_job($job['job_id'], ['status' => 'done', 'post_id' => $post_id, 'error_message' => '']);

        if (($options['auto_index_google'] ?? '1') === '1') {
            $this->verify_sitemap_readiness($logs, $post_id);
        }

        return [
            'success' => true,
            'post_id' => $post_id,
            'title' => get_the_title($post_id),
            'url' => get_permalink($post_id),
        ];
    }

    private function process_article_job_safely($job, $work, $options, &$logs)
    {
        try {
            $result = $this->process_article_job($job, $work, $options, $logs);
            $this->send_telegram_job_notification($job, $work, $options, $result, $logs);
            $this->release_lock();
            return $result;
        } catch (Throwable $error) {
            $detail = sanitize_text_field(wp_strip_all_tags($error->getMessage()));
            $message = $detail !== '' ? 'Proses dihentikan aman: ' . $detail : 'Proses dihentikan karena kesalahan internal.';
            $logs[] = $message;

            $job_id = $this->scalar_string($job['job_id'] ?? '');
            if ($job_id !== '') {
                $this->update_job($job_id, ['status' => 'failed', 'error_message' => $message]);
            }
            if (!empty($work['keyword']) && !empty($work['angle_number'])) {
                $this->mark_angle_failed($work['keyword'], $work['angle_number'], $message);
            }

            $result = ['success' => false, 'message' => $message];
            $this->send_telegram_job_notification($job, $work, $options, $result, $logs);
            $this->release_lock();
            return $result;
        }
    }

    private function send_telegram_job_notification($job, $work, $options, $result, &$logs)
    {
        if (($options['telegram_enabled'] ?? '') !== '1') {
            return;
        }

        $token = trim($this->scalar_string($options['telegram_bot_token'] ?? ''));
        $chat_id = trim($this->scalar_string($options['telegram_chat_id'] ?? ''));
        if ($token === '' || $chat_id === '') {
            $logs[] = 'Telegram: bot token atau chat ID belum diisi.';
            return;
        }

        $message = $this->build_telegram_job_message($job, $work, $options, $result, $logs);
        $sent = $this->telegram_send_message($token, $chat_id, $message);
        if ($sent === true) {
            $logs[] = 'Telegram: notifikasi terkirim.';
            return;
        }

        $logs[] = 'Telegram: gagal mengirim notifikasi' . (is_string($sent) && $sent !== '' ? ' - ' . $sent : '.');
    }

    private function build_telegram_job_message($job, $work, $options, $result, $logs)
    {
        $success = !empty($result['success']);
        $lines = [
            'JDH Auto SEO Publisher',
            'Status: ' . ($success ? 'Berhasil publish' : 'Gagal generate'),
            'Situs: ' . get_bloginfo('name') . ' (' . home_url('/') . ')',
            'Job: ' . $this->scalar_string($job['job_id'] ?? '-'),
            'Keyword: ' . $this->scalar_string($work['keyword'] ?? ($job['keyword'] ?? '-')),
            'Angle: ' . $this->scalar_string($work['angle_number'] ?? ($job['angle_number'] ?? '-')),
            'Waktu: ' . current_time('mysql'),
        ];

        if ($success) {
            $lines[] = 'Post ID: ' . $this->scalar_string($result['post_id'] ?? '');
            $lines[] = 'Judul: ' . $this->scalar_string($result['title'] ?? '');
            $lines[] = 'URL: ' . $this->scalar_string($result['url'] ?? '');
        } else {
            $lines[] = 'Error: ' . $this->scalar_string($result['message'] ?? ($job['error_message'] ?? 'Gagal membuat artikel.'));
        }

        if (($options['telegram_include_logs'] ?? '1') === '1') {
            $log_text = $this->compact_telegram_logs($logs);
            if ($log_text !== '') {
                $lines[] = '';
                $lines[] = 'Log controller:';
                $lines[] = $log_text;
            }
        }

        return $this->truncate_text(implode("\n", $lines), 3900);
    }

    private function compact_telegram_logs($logs)
    {
        $lines = [];
        foreach (is_array($logs) ? $logs : [] as $line) {
            $line = trim(wp_strip_all_tags($this->scalar_string($line)));
            if ($line !== '') {
                $lines[] = '- ' . $line;
            }
        }

        if (empty($lines)) {
            return '';
        }

        return $this->truncate_text(implode("\n", array_slice($lines, -35)), 2400);
    }

    private function truncate_text($text, $max_length)
    {
        $text = $this->scalar_string($text);
        $max_length = max(0, (int) $max_length);
        if ($max_length === 0 || strlen($text) <= $max_length) {
            return $text;
        }

        return rtrim(substr($text, 0, max(0, $max_length - 3))) . '...';
    }

    private function telegram_send_message($token, $chat_id, $message)
    {
        $token = preg_replace('/[^0-9A-Za-z:_-]/', '', $this->scalar_string($token));
        $chat_id = trim($this->scalar_string($chat_id));
        if ($token === '' || $chat_id === '' || trim($message) === '') {
            return 'konfigurasi atau pesan kosong';
        }

        $response = wp_remote_post('https://api.telegram.org/bot' . $token . '/sendMessage', [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'chat_id' => $chat_id,
                'text' => $message,
                'disable_web_page_preview' => true,
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return true;
        }

        $body = trim(wp_strip_all_tags(wp_remote_retrieve_body($response)));
        return 'HTTP ' . $code . ($body !== '' ? ': ' . $this->truncate_text($body, 160) : '');
    }

    private function generate_article_payload($work, $options, &$logs, $attempt = 1, $rewrite_reason = '')
    {
        if (!empty($options['router_enabled']) && !empty($options['writing_router_endpoint'])) {
            return $this->call_writing_router($work, $options, $logs, $attempt, $rewrite_reason);
        }

        return $this->generate_article_fallback($work, $options, $logs, $attempt, $rewrite_reason);
    }

    private function call_writing_router($work, $options, &$logs, $attempt = 1, $rewrite_reason = '')
    {
        if (($options['router_api_mode'] ?? 'openai_compatible') === 'openai_compatible') {
            return $this->call_openai_compatible_writing_router($work, $options, $logs, $attempt, $rewrite_reason);
        }

        $payload = $this->build_router_request($work, $options, $attempt, $rewrite_reason);
        $headers = ['Content-Type' => 'application/json'];
        if (!empty($options['router_api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $options['router_api_key'];
        }

        $response = wp_remote_post($options['writing_router_endpoint'], [
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'timeout' => 180,
        ]);

        if (is_wp_error($response)) {
            $logs[] = 'Writing Router error: ' . $response->get_error_message();
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            $logs[] = "Writing Router HTTP $code";
            return false;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $logs[] = 'Writing Router response bukan JSON valid.';
            return false;
        }

        $logs[] = 'Writing Router OK.';
        return $data;
    }

    private function call_openai_compatible_writing_router($work, $options, &$logs, $attempt = 1, $rewrite_reason = '')
    {
        $request_payload = $this->build_router_request($work, $options, $attempt, $rewrite_reason);
        $logs[] = 'Nine Router: mulai pipeline artikel terstruktur (5 tahap).';

        $strategy = $this->call_openai_compatible_stage(
            $options,
            $logs,
            '1/5 strategi judul',
            $this->build_structured_title_prompt($request_payload),
            8192,
            0.45
        );
        if (!$strategy || $this->scalar_string($strategy['status'] ?? 'ready') !== 'ready') {
            $logs[] = 'Pipeline berhenti: strategi judul tidak siap.';
            return false;
        }
        $strategy = $this->normalize_strategy_response($strategy, $request_payload, $logs);

        $title = sanitize_text_field($this->scalar_string($strategy['title'] ?? ''));
        $angle = sanitize_text_field($this->scalar_string($strategy['angle'] ?? ''));
        $intent = sanitize_text_field($this->scalar_string($strategy['intent'] ?? 'informational', 'informational'));
        $meta_description = sanitize_text_field($this->scalar_string($strategy['meta_description'] ?? ''));
        if ($title === '' || $angle === '' || $meta_description === '') {
            $logs[] = 'Pipeline berhenti: title, angle, atau meta description kosong.';
            return false;
        }

        $outline_response = $this->call_openai_compatible_stage(
            $options,
            $logs,
            '2/5 outline',
            $this->build_structured_outline_prompt($request_payload, $strategy),
            8192,
            0.35
        );
        $sections = $this->normalize_outline_sections($outline_response);
        if (count($sections) !== 6) {
            $logs[] = 'Pipeline berhenti: outline wajib berisi 6 section isi yang unik.';
            return false;
        }

        $batch_one_response = $this->call_openai_compatible_stage(
            $options,
            $logs,
            '3/5 pembuka dan section 1-2',
            $this->build_structured_batch_prompt($request_payload, $strategy, $sections, 1, ''),
            8192,
            0.58
        );
        $batch_one_html = $this->extract_stage_html($batch_one_response);
        $batch_one_html = $this->repair_stage_html_if_needed(
            $options,
            $logs,
            '3/5 repair batch 1',
            $request_payload,
            $strategy,
            array_slice($sections, 0, 2),
            $batch_one_html,
            430,
            'Batch 1'
        );
        if (!$this->validate_stage_html($batch_one_html, array_slice($sections, 0, 2), 400, 'Batch 1', $logs)) {
            return false;
        }

        $batch_two_response = $this->call_openai_compatible_stage(
            $options,
            $logs,
            '4/5 section 3-5',
            $this->build_structured_batch_prompt(
                $request_payload,
                $strategy,
                $sections,
                2,
                $this->get_content_tail($batch_one_html, 180)
            ),
            8192,
            0.58
        );
        $batch_two_html = $this->extract_stage_html($batch_two_response);
        $batch_two_html = $this->repair_stage_html_if_needed(
            $options,
            $logs,
            '4/5 repair batch 2',
            $request_payload,
            $strategy,
            array_slice($sections, 2, 3),
            $batch_two_html,
            540,
            'Batch 2'
        );
        if (!$this->validate_stage_html($batch_two_html, array_slice($sections, 2, 3), 500, 'Batch 2', $logs)) {
            return false;
        }

        $draft_html = trim($batch_one_html . "\n" . $batch_two_html);
        $final_response = $this->call_openai_compatible_stage(
            $options,
            $logs,
            '5/5 FAQ, kesimpulan, dan review',
            $this->build_structured_final_prompt($request_payload, $strategy, $outline_response, $sections, $draft_html),
            8192,
            0.48
        );
        if (!$final_response || $this->scalar_string($final_response['status'] ?? '') !== 'ready') {
            $logs[] = 'Pipeline berhenti: tahap final belum berstatus ready.';
            return false;
        }

        $final_html = $this->extract_stage_html($final_response);
        $final_headings = [
            $sections[5],
            ['heading' => 'Kesimpulan'],
        ];
        $final_html = $this->repair_stage_html_if_needed(
            $options,
            $logs,
            '5/5 repair final',
            $request_payload,
            $strategy,
            $final_headings,
            $final_html,
            280,
            'Bagian final'
        );
        if (!$this->validate_stage_html($final_html, $final_headings, 250, 'Bagian final', $logs)) {
            return false;
        }

        $faq = $this->normalize_faq_items($final_response['faq'] ?? []);
        if (count($faq) < 4) {
            $logs[] = 'Pipeline berhenti: tahap final wajib menghasilkan minimal 4 FAQ yang lengkap.';
            return false;
        }

        $image = is_array($final_response['image'] ?? null) ? $final_response['image'] : [];
        if (empty($image['prompt']) || empty($image['alt'])) {
            $logs[] = 'Pipeline berhenti: prompt atau alt gambar final kosong.';
            return false;
        }

        $faq_html = $this->build_faq_content_html($faq, $work['keyword']);
        $content_html = trim($draft_html . "\n" . $final_html . "\n" . $faq_html);
        $tags = $this->normalize_string_list($strategy['tags'] ?? [], 8);
        $warnings = [];
        foreach ([$strategy, $outline_response, $final_response] as $stage_response) {
            if (!empty($stage_response['warnings']) && is_array($stage_response['warnings'])) {
                $warnings = array_merge($warnings, $this->normalize_string_list($stage_response['warnings'], 10));
            }
        }

        $article = [
            'job_id' => $request_payload['job_id'],
            'status' => 'ready',
            'keyword' => sanitize_text_field($request_payload['keyword']),
            'intent' => $intent,
            'angle_number' => (int) $request_payload['angle_number'],
            'max_angles_per_keyword' => (int) $request_payload['max_angles_per_keyword'],
            'angle' => $angle,
            'title' => $title,
            'slug' => sanitize_title($this->scalar_string($strategy['slug'] ?? $title, $title)),
            'excerpt' => sanitize_text_field($this->scalar_string($strategy['excerpt'] ?? $meta_description, $meta_description)),
            'meta_description' => $meta_description,
            'content_html' => $content_html,
            'faq' => $faq,
            'faq_embedded' => true,
            'tags' => $tags,
            'category' => sanitize_text_field($this->scalar_string($strategy['category'] ?? ($request_payload['site']['niche'] ?? 'Artikel'), 'Artikel')),
            'image' => [
                'source_type' => sanitize_text_field($this->scalar_string($image['source_type'] ?? 'auto', 'auto')),
                'prompt' => sanitize_textarea_field($this->scalar_string($image['prompt'])),
                'alt' => sanitize_text_field($this->scalar_string($image['alt'])),
                'caption' => sanitize_text_field($this->scalar_string($image['caption'] ?? $title, $title)),
                'url' => esc_url_raw($this->scalar_string($image['url'] ?? '')),
                'watermark_required' => !empty($request_payload['image']['watermark']['enabled']),
            ],
            'seo_score' => abs($this->scalar_int($final_response['seo_score'] ?? 0)),
            'quality_score' => abs($this->scalar_int($final_response['quality_score'] ?? 0)),
            'duplicate_risk' => sanitize_text_field($this->scalar_string($final_response['duplicate_risk'] ?? 'high', 'high')),
            'warnings' => array_values(array_unique($warnings)),
        ];
        $word_count = $this->count_words($content_html);
        $h2_count = $this->count_html_heading($content_html, 'h2');
        $keyword_count = $this->count_keyword_occurrences($content_html, $article['keyword']);
        $article['quality_score'] = min(100, max(
            abs($this->scalar_int($article['quality_score'])),
            $this->calculate_objective_article_score($article, $word_count, $h2_count, count($faq), $keyword_count, 'quality')
        ));
        $article['seo_score'] = min(100, max(
            abs($this->scalar_int($article['seo_score'])),
            $this->calculate_objective_article_score($article, $word_count, $h2_count, count($faq), $keyword_count, 'seo')
        ));

        $logs[] = sprintf(
            'Pipeline selesai: %d kata, %d H2, %d FAQ.',
            $word_count,
            $h2_count,
            count($faq)
        );
        $logs[] = 'Nine Router OpenAI-compatible OK.';

        return $article;
    }

    private function call_openai_compatible_stage($options, &$logs, $stage, $prompt, $max_tokens, $temperature)
    {
        $last_failure = '';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $stage_prompt = $attempt === 1
                ? $prompt
                : $this->build_stage_repair_prompt($prompt, $last_failure);
            $decoded = $this->call_openai_compatible_stage_once(
                $options,
                $logs,
                $stage,
                $stage_prompt,
                $max_tokens,
                $attempt === 1 ? $temperature : max(0.1, (float) $temperature - 0.15),
                $last_failure
            );
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return false;
    }

    private function call_openai_compatible_stage_once($options, &$logs, $stage, $prompt, $max_tokens, $temperature, &$failure_reason)
    {
        $endpoint = $this->openai_compatible_url($options['writing_router_endpoint'], 'chat/completions');
        $headers = ['Content-Type' => 'application/json'];
        if (!empty($options['router_api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $options['router_api_key'];
        }

        $response = wp_remote_post($endpoint, [
            'headers' => $headers,
            'body' => wp_json_encode([
                'model' => $options['router_model'] ?: self::DEFAULT_WRITING_COMBO,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Anda adalah mesin editorial SEO untuk JDH Auto SEO Publisher. Kerjakan hanya tahap yang diminta. Balas dengan tepat satu objek JSON valid, tanpa markdown fence dan tanpa penjelasan di luar JSON.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => (float) $temperature,
                'max_tokens' => max(512, min(8192, absint($max_tokens))),
                'stream' => false,
            ]),
            'timeout' => 240,
        ]);

        if (is_wp_error($response)) {
            $failure_reason = 'Request error: ' . $response->get_error_message();
            $logs[] = "Nine Router tahap {$stage} error: " . $response->get_error_message();
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            $failure_reason = "HTTP {$code}";
            $logs[] = "Nine Router tahap {$stage} HTTP {$code}.";
            if ($body !== '') {
                $logs[] = 'Response: ' . wp_trim_words(wp_strip_all_tags($body), 40, '...');
            }
            return false;
        }

        $content = $this->extract_openai_compatible_content($body);
        if ($content === '') {
            $failure_reason = 'Response kosong pada message.content.';
            $logs[] = "Nine Router tahap {$stage}: response kosong.";
            return false;
        }

        $decoded = $this->decode_router_json_content($content);
        if (!is_array($decoded)) {
            $failure_reason = 'Output bukan JSON valid. Potongan: ' . wp_trim_words(wp_strip_all_tags($content), 30, '...');
            $logs[] = "Nine Router tahap {$stage}: output bukan JSON valid.";
            $logs[] = 'Potongan output: ' . wp_trim_words(wp_strip_all_tags($content), 35, '...');
            return false;
        }

        $logs[] = "Nine Router tahap {$stage}: OK.";
        return $decoded;
    }

    private function build_stage_repair_prompt($original_prompt, $failure_reason)
    {
        return "ULANGI TAHAP INI DAN PERBAIKI FORMAT OUTPUT.\n"
            . 'Kegagalan sebelumnya: ' . sanitize_text_field($this->scalar_string($failure_reason)) . "\n"
            . "Balas hanya satu objek JSON valid yang lengkap. Jangan gunakan markdown, penjelasan, komentar, reasoning, atau teks di luar JSON.\n"
            . "Pastikan semua tanda kurung, koma, kutip, dan array tertutup dengan benar.\n\n"
            . "PROMPT ASLI:\n"
            . (string) $original_prompt;
    }

    private function build_structured_title_prompt($request_payload)
    {
        $rewrite_reason = trim((string) ($request_payload['rewrite_reason'] ?? ''));
        $rewrite_rule = $rewrite_reason !== ''
            ? "Kegagalan percobaan sebelumnya: {$rewrite_reason}. Buat strategi baru yang secara spesifik memperbaikinya.\n"
            : '';

        return "TAHAP 1 DARI 5: STRATEGI, ANGLE, DAN JUDUL.\n"
            . "Baca content_guide dan dynamic_content_guide dalam request. Pada tahap ini, gunakan aturan kontennya tetapi balas memakai schema tahap di bawah.\n"
            . $rewrite_rule
            . "\nAturan:\n"
            . "- Tentukan search intent yang paling tepat.\n"
            . "- Angle harus fokus pada keyword, niche, dan target audience.\n"
            . "- Angle dan judul harus berbeda dari duplicate_context dan angle sebelumnya.\n"
            . "- Judul ideal 50-70 karakter, menarik tetapi tidak clickbait, dan memuat keyword secara natural.\n"
            . "- Meta description 120-155 karakter dan menjelaskan manfaat artikel.\n"
            . "- Jangan membahas harga, SARA, politik, atau membuat klaim/data yang tidak tersedia.\n"
            . "- Jika keyword melanggar topik terlarang, return status failed.\n\n"
            . "Schema JSON tahap 1:\n"
            . '{"status":"ready","intent":"informational","angle":"","title":"","slug":"","excerpt":"","meta_description":"","category":"","tags":[],"warnings":[]}'
            . "\n\nREQUEST:\n"
            . $this->build_pipeline_request_context($request_payload, true);
    }

    private function build_structured_outline_prompt($request_payload, $strategy)
    {
        return "TAHAP 2 DARI 5: OUTLINE EDITORIAL.\n"
            . "Buat outline berdasarkan strategi yang sudah dipilih. Jangan menulis artikel.\n\n"
            . $this->pipeline_quality_rules($request_payload)
            . "\nAturan outline:\n"
            . "- sections wajib tepat 6 item, semuanya unik dan berurutan logis.\n"
            . "- Section pertama harus menjawab inti keyword secara langsung.\n"
            . "- Section berikutnya memberi kedalaman: alasan, proses, pilihan, kesalahan, atau langkah praktis sesuai intent.\n"
            . "- Jangan masukkan FAQ atau Kesimpulan ke dalam 6 section; keduanya dibuat di tahap final.\n"
            . "- Setiap heading harus spesifik terhadap topik, bukan heading generik.\n"
            . "- faq_questions berisi 4-5 pertanyaan nyata dan berbeda yang relevan dengan search intent.\n\n"
            . "Schema JSON tahap 2:\n"
            . '{"intro_strategy":"","related_terms":[],"sections":[{"heading":"","purpose":"","key_points":[],"example":""}],"faq_questions":[],"warnings":[]}'
            . "\n\nSTRATEGI:\n"
            . wp_json_encode($strategy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nKONTEKS REQUEST:\n"
            . $this->build_pipeline_request_context($request_payload, false);
    }

    private function build_structured_batch_prompt($request_payload, $strategy, $sections, $batch_number, $previous_tail)
    {
        $is_first = (int) $batch_number === 1;
        $selected_sections = $is_first ? array_slice($sections, 0, 2) : array_slice($sections, 2, 3);
        $word_target = $is_first ? '500-650' : '550-750';
        $opening_rule = $is_first
            ? "Mulai dengan 2-3 paragraf intro. Paragraf pertama harus langsung menjawab intent keyword tanpa frasa basa-basi seperti 'Pada artikel ini' atau 'Di era digital'."
            : "Ini lanjutan artikel. Mulai dengan transisi natural dari konteks sebelumnya tanpa mengulang intro atau section lama.";
        $link_rule = $is_first
            ? 'Boleh gunakan maksimal 1 internal link dari daftar request jika benar-benar relevan.'
            : 'Boleh gunakan maksimal 1 external/social link yang diberikan request jika benar-benar relevan.';
        $previous_context = $previous_tail !== ''
            ? "\nKONTEKS AKHIR BATCH SEBELUMNYA (jangan diulang):\n{$previous_tail}\n"
            : '';

        return 'TAHAP ' . ($is_first ? '3' : '4') . " DARI 5: TULIS BATCH ARTIKEL.\n"
            . "Tulis hanya bagian yang ditugaskan dalam HTML, lalu bungkus sebagai JSON.\n\n"
            . $this->pipeline_quality_rules($request_payload)
            . "\nAturan batch:\n"
            . "- Target {$word_target} kata bahasa Indonesia.\n"
            . "- {$opening_rule}\n"
            . "- Pakai heading H2 persis seperti field heading yang diberikan dan sesuai urutan.\n"
            . "- Setiap H2 wajib memiliki minimal 2 paragraf substantif; tambahkan daftar hanya jika membantu.\n"
            . "- Setiap section harus menjelaskan konsep, alasan pentingnya, dan contoh/skenario yang realistis.\n"
            . "- Gunakan keyword utama dan related terms secara natural. Jangan keyword stuffing.\n"
            . "- Jangan mengarang pengalaman pribadi, statistik, kutipan, studi, atau URL.\n"
            . "- {$link_rule}\n"
            . "- Tag yang boleh: p, h2, h3, ul, ol, li, strong, em, a, blockquote. Jangan gunakan H1.\n"
            . "- Jangan menulis FAQ atau kesimpulan pada batch ini.\n\n"
            . "Schema JSON: {\"content_html\":\"<p>...</p><h2>...</h2>\"}\n\n"
            . "STRATEGI:\n"
            . wp_json_encode($strategy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nSECTION YANG WAJIB DITULIS:\n"
            . wp_json_encode($selected_sections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . $previous_context
            . "\nLINK YANG DIIZINKAN:\n"
            . wp_json_encode([
                'internal_links' => $request_payload['internal_links'] ?? [],
                'external_links' => $request_payload['links'] ?? [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function build_structured_final_prompt($request_payload, $strategy, $outline_response, $sections, $draft_html)
    {
        $faq_questions = $this->normalize_string_list($outline_response['faq_questions'] ?? [], 5);

        return "TAHAP 5 DARI 5: SECTION TERAKHIR, KESIMPULAN, FAQ, IMAGE BRIEF, DAN REVIEW.\n"
            . "Baca draft lengkap. Tambahkan hanya section keenam dan Kesimpulan; jangan menulis ulang draft. FAQ wajib berada di array faq, bukan di content_html.\n\n"
            . $this->pipeline_quality_rules($request_payload)
            . "\nAturan final:\n"
            . "- content_html berisi tepat 2 H2: heading section keenam persis seperti outline, lalu <h2>Kesimpulan</h2>.\n"
            . "- Target content_html 300-450 kata dengan minimal 2 paragraf substantif per H2.\n"
            . "- Kesimpulan memberi keputusan atau langkah berikutnya, bukan mengulang semua isi.\n"
            . "- faq berisi 4-5 Q&A; setiap jawaban 40-80 kata, langsung, faktual, dan dapat dipahami tanpa konteks lain.\n"
            . "- image.prompt wajib bahasa Inggris, konkret, realistis, editorial, landscape, relevan dengan keyword/judul/isi, tanpa teks dan logo.\n"
            . "- image.alt dan caption wajib bahasa Indonesia serta menggambarkan scene secara natural.\n"
            . "- Nilai seo_score dan quality_score secara jujur untuk draft gabungan. Jangan memberi skor tinggi jika isi belum layak publish.\n"
            . "- duplicate_risk harus menilai kemiripan dengan duplicate_context.\n"
            . "- status hanya ready jika artikel gabungan layak publish.\n\n"
            . "Schema JSON tahap 5:\n"
            . '{"status":"ready","content_html":"","faq":[{"question":"","answer":""}],"image":{"source_type":"auto","prompt":"","alt":"","caption":"","url":"","watermark_required":true},"seo_score":0,"quality_score":0,"duplicate_risk":"low","warnings":[]}'
            . "\n\nSTRATEGI:\n"
            . wp_json_encode($strategy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nSECTION KEENAM YANG WAJIB DITULIS:\n"
            . wp_json_encode($sections[5], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nPERTANYAAN FAQ RUJUKAN:\n"
            . wp_json_encode($faq_questions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nDUPLICATE CONTEXT:\n"
            . wp_json_encode($request_payload['duplicate_context'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nDRAFT YANG HARUS DIREVIEW DAN DILANJUTKAN:\n"
            . $draft_html;
    }

    private function build_pipeline_request_context($request_payload, $include_guide)
    {
        $context = [
            'job_id' => $request_payload['job_id'] ?? '',
            'keyword' => $request_payload['keyword'] ?? '',
            'generation_mode' => $request_payload['generation_mode'] ?? 'explore_angles',
            'angle_number' => $request_payload['angle_number'] ?? 1,
            'max_angles_per_keyword' => $request_payload['max_angles_per_keyword'] ?? 5,
            'attempt' => $request_payload['attempt'] ?? 1,
            'rewrite_reason' => $request_payload['rewrite_reason'] ?? '',
            'site' => $request_payload['site'] ?? [],
            'seo' => $request_payload['seo'] ?? [],
            'dynamic_content_guide' => $request_payload['dynamic_content_guide'] ?? '',
            'internal_links' => $request_payload['internal_links'] ?? [],
            'duplicate_context' => $request_payload['duplicate_context'] ?? [],
            'links' => $request_payload['links'] ?? [],
            'image' => $request_payload['image'] ?? [],
        ];
        if ($include_guide) {
            $context['content_guide'] = $request_payload['content_guide'] ?? '';
        }

        return wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function pipeline_quality_rules($request_payload)
    {
        $site = $request_payload['site'] ?? [];
        $keyword = sanitize_text_field($request_payload['keyword'] ?? '');
        $niche = sanitize_text_field($site['niche'] ?? '');
        $audience = sanitize_text_field($site['target_audience'] ?? 'pembaca umum');
        $tone = sanitize_text_field($site['brand_tone'] ?? 'edukatif, jelas, praktis, profesional');
        $dynamic_guide = trim((string) ($request_payload['dynamic_content_guide'] ?? ''));

        return "ATURAN KONTEN TETAP:\n"
            . "- Keyword utama: {$keyword}. Scope niche: {$niche}. Target pembaca: {$audience}.\n"
            . "- Gaya brand: {$tone}. Gunakan bahasa Indonesia natural dan profesional.\n"
            . "- Tetap di dalam scope keyword dan niche; jangan melebar ke topik yang tidak membantu intent pembaca.\n"
            . "- Hindari SARA, politik, harga/biaya, klaim absolut, serta fakta yang tidak dapat dipastikan.\n"
            . "- Jangan mengarang data, pengalaman pribadi, sumber, nama ahli, kutipan, atau URL.\n"
            . "- Berikan alasan, langkah praktis, trade-off, dan contoh realistis bila relevan.\n"
            . "- Gunakan keyword, sinonim, dan entitas terkait secara natural tanpa stuffing.\n"
            . "- Hanya gunakan URL yang tersedia di request.\n"
            . "- Dynamic guide wajib dipatuhi:\n{$dynamic_guide}\n";
    }

    private function normalize_outline_sections($outline_response)
    {
        if (!is_array($outline_response)) {
            return [];
        }

        $source = $outline_response['sections'] ?? ($outline_response['outline'] ?? []);
        if (!is_array($source)) {
            return [];
        }

        $sections = [];
        $seen = [];
        foreach ($source as $item) {
            if (is_string($item)) {
                $heading = $item;
                $purpose = '';
                $key_points = [];
                $example = '';
            } elseif (is_array($item)) {
                $heading = $item['heading'] ?? ($item['h2'] ?? ($item['title'] ?? ''));
                $purpose = $item['purpose'] ?? '';
                $key_points = $this->normalize_string_list($item['key_points'] ?? ($item['points'] ?? []), 6);
                $example = $item['example'] ?? '';
            } else {
                continue;
            }

            $heading = sanitize_text_field(wp_strip_all_tags($this->scalar_string($heading)));
            $purpose = sanitize_text_field(wp_strip_all_tags($this->scalar_string($purpose)));
            $example = sanitize_text_field(wp_strip_all_tags($this->scalar_string($example)));
            $normalized_heading = $this->normalize_keyword($heading);
            if ($heading === '' || isset($seen[$normalized_heading])) {
                continue;
            }
            if (preg_match('/\bfaq\b|pertanyaan yang sering|\bkesimpulan\b/i', $heading)) {
                continue;
            }

            $seen[$normalized_heading] = true;
            $sections[] = [
                'heading' => $heading,
                'purpose' => $purpose,
                'key_points' => $key_points,
                'example' => $example,
            ];
            if (count($sections) === 6) {
                break;
            }
        }

        return $sections;
    }

    private function normalize_strategy_response($strategy, $request_payload, &$logs)
    {
        if (!is_array($strategy)) {
            return [];
        }

        $keyword = sanitize_text_field($this->scalar_string($request_payload['keyword'] ?? 'Artikel'));
        $site = is_array($request_payload['site'] ?? null) ? $request_payload['site'] : [];
        $niche = sanitize_text_field($this->scalar_string($site['niche'] ?? 'topik utama'));
        $target = sanitize_text_field($this->scalar_string($site['target_audience'] ?? 'pembaca'));
        $normalized = $strategy;

        $normalized['status'] = $this->first_strategy_value($strategy, ['status', 'state'], 'ready');
        $normalized['intent'] = $this->first_strategy_value($strategy, ['intent', 'search_intent', 'maksud_pencarian'], 'informational');
        $normalized['title'] = $this->first_strategy_value($strategy, ['title', 'judul', 'headline', 'seo_title', 'recommended_title'], '');
        $normalized['angle'] = $this->first_strategy_value($strategy, ['angle', 'sudut_pandang', 'viewpoint', 'hook', 'topic_angle'], '');
        $normalized['slug'] = $this->first_strategy_value($strategy, ['slug', 'url_slug', 'permalink'], '');
        $normalized['excerpt'] = $this->first_strategy_value($strategy, ['excerpt', 'ringkasan', 'summary', 'deskripsi_singkat'], '');
        $normalized['meta_description'] = $this->first_strategy_value($strategy, ['meta_description', 'meta_deskripsi', 'seo_description', 'description', 'deskripsi_meta'], '');
        $normalized['category'] = $this->first_strategy_value($strategy, ['category', 'kategori', 'topic_category'], '');

        if (empty($normalized['tags']) && !empty($strategy['tag'])) {
            $normalized['tags'] = $strategy['tag'];
        }
        if (empty($normalized['warnings']) && !empty($strategy['warning'])) {
            $normalized['warnings'] = $strategy['warning'];
        }

        $used_fallback = false;
        if ($normalized['title'] === '') {
            $normalized['title'] = trim($keyword . ': Panduan Praktis untuk ' . $niche);
            $used_fallback = true;
        }
        if ($normalized['angle'] === '') {
            $normalized['angle'] = 'panduan praktis untuk ' . $target . ' dalam scope ' . $niche;
            $used_fallback = true;
        }
        if ($normalized['excerpt'] === '') {
            $normalized['excerpt'] = 'Panduan praktis tentang ' . $keyword . ' yang tetap relevan dengan kebutuhan ' . $target . '.';
        }
        if ($normalized['meta_description'] === '') {
            $normalized['meta_description'] = $normalized['excerpt'];
            $used_fallback = true;
        }
        if ($normalized['slug'] === '') {
            $normalized['slug'] = sanitize_title($normalized['title']);
        }
        if ($normalized['category'] === '') {
            $normalized['category'] = $niche !== '' ? $niche : 'Artikel';
        }

        if ($used_fallback) {
            $logs[] = 'Tahap strategi: field kosong/berbeda schema dinormalisasi dari keyword dan profil website.';
        }

        return $normalized;
    }

    private function first_strategy_value($source, $keys, $default = '')
    {
        if (!is_array($source)) {
            return $default;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $value = sanitize_text_field(wp_strip_all_tags($this->scalar_string($source[$key])));
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }

    private function normalize_string_list($items, $limit = 10)
    {
        if (is_string($items)) {
            $items = preg_split('/\R+|\s*,\s*/', $items);
        }
        if (!is_array($items)) {
            return [];
        }

        $result = [];
        $seen = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $item = $item['text'] ?? ($item['label'] ?? '');
            }
            $value = sanitize_text_field(wp_strip_all_tags($this->scalar_string($item)));
            $key = $this->normalize_keyword($value);
            if ($value === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $value;
            if (count($result) >= max(1, absint($limit))) {
                break;
            }
        }

        return $result;
    }

    private function extract_stage_html($stage_response)
    {
        if (!is_array($stage_response)) {
            return '';
        }

        $html = $stage_response['content_html'] ?? ($stage_response['html'] ?? ($stage_response['content'] ?? ''));
        if (!is_scalar($html)) {
            return '';
        }

        $html = trim((string) $html);
        $html = preg_replace('/^```(?:html)?\s*/i', '', $html);
        $html = preg_replace('/\s*```$/', '', $html);
        return trim($html);
    }

    private function repair_stage_html_if_needed($options, &$logs, $stage, $request_payload, $strategy, $expected_sections, $html, $target_words, $label)
    {
        $html = trim((string) $html);
        if ($html === '') {
            return $html;
        }

        $word_count = $this->count_words($html);
        if ($word_count >= absint($target_words)) {
            return $html;
        }

        $logs[] = "{$label}: {$word_count} kata, minta ekspansi otomatis ke minimal {$target_words} kata.";
        $repair_response = $this->call_openai_compatible_stage(
            $options,
            $logs,
            $stage,
            $this->build_stage_html_repair_prompt($request_payload, $strategy, $expected_sections, $html, $target_words, $label),
            8192,
            0.42
        );
        $repaired_html = $this->extract_stage_html($repair_response);
        if ($repaired_html === '') {
            $logs[] = "{$label}: ekspansi otomatis gagal, memakai output awal.";
            return $html;
        }

        $repaired_words = $this->count_words($repaired_html);
        if ($repaired_words <= $word_count) {
            $logs[] = "{$label}: ekspansi otomatis tidak menambah panjang, memakai output awal.";
            return $html;
        }

        $logs[] = "{$label}: ekspansi otomatis menjadi {$repaired_words} kata.";
        return $repaired_html;
    }

    private function build_stage_html_repair_prompt($request_payload, $strategy, $expected_sections, $html, $target_words, $label)
    {
        return "PERBAIKI PANJANG {$label} TANPA MENGUBAH STRUKTUR.\n"
            . "Output sebelumnya terlalu pendek. Perluas paragraf yang sudah ada agar minimal {$target_words} kata.\n"
            . "Balas hanya JSON valid: {\"content_html\":\"...\"}\n\n"
            . $this->pipeline_quality_rules($request_payload)
            . "\nAturan repair:\n"
            . "- Jangan menambah, menghapus, atau mengganti H2.\n"
            . "- H2 wajib persis sama dengan daftar section yang diberikan.\n"
            . "- Setiap H2 minimal 2 paragraf substantif.\n"
            . "- Tambahkan detail praktis, alasan, trade-off, contoh penerapan, dan catatan kesalahan umum yang relevan.\n"
            . "- Jangan menambah FAQ, kesimpulan, gambar, skor, markdown fence, H1, script, style, atau iframe.\n"
            . "- Jangan mengarang harga, data statistik, sumber, pengalaman pribadi, kutipan, atau URL.\n\n"
            . "STRATEGI:\n"
            . wp_json_encode($strategy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nSECTION WAJIB:\n"
            . wp_json_encode($expected_sections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nHTML AWAL YANG HARUS DIPERLUAS:\n"
            . (string) $html;
    }

    private function validate_stage_html($html, $expected_sections, $minimum_words, $label, &$logs)
    {
        if ($html === '') {
            $logs[] = "{$label} gagal: content_html kosong.";
            return false;
        }
        if (preg_match('/<(?:h1|script|style|iframe)\b/i', $html)) {
            $logs[] = "{$label} gagal: HTML mengandung tag terlarang.";
            return false;
        }

        $word_count = $this->count_words($html);
        if ($word_count < absint($minimum_words)) {
            $logs[] = "{$label} gagal: hanya {$word_count} kata, minimal {$minimum_words}.";
            return false;
        }

        $expected_count = count($expected_sections);
        $h2_count = $this->count_html_heading($html, 'h2');
        if ($h2_count !== $expected_count) {
            $logs[] = "{$label} gagal: jumlah H2 {$h2_count}, seharusnya {$expected_count}.";
            return false;
        }

        if ($this->count_html_heading($html, 'p') < ($expected_count * 2)) {
            $logs[] = "{$label} gagal: setiap H2 harus memiliki minimal 2 paragraf substantif.";
            return false;
        }

        preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>/is', $html, $matches);
        $actual_headings = array_map(function ($heading) {
            return $this->normalize_keyword(wp_strip_all_tags($heading));
        }, $matches[1]);
        foreach ($expected_sections as $section) {
            $section = is_array($section) ? $section : [];
            $expected_heading = $this->normalize_keyword($section['heading'] ?? '');
            if ($expected_heading === '' || !in_array($expected_heading, $actual_headings, true)) {
                $logs[] = "{$label} gagal: heading '" . sanitize_text_field($section['heading'] ?? '') . "' tidak ditulis persis sesuai outline.";
                return false;
            }
        }

        $logs[] = "{$label}: {$word_count} kata, {$h2_count} H2.";
        return true;
    }

    private function normalize_faq_items($items)
    {
        if (!is_array($items)) {
            return [];
        }

        $faq = [];
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = sanitize_text_field(wp_strip_all_tags($this->scalar_string($item['question'] ?? ($item['q'] ?? ''))));
            $answer = trim(wp_strip_all_tags($this->scalar_string($item['answer'] ?? ($item['a'] ?? ''))));
            $key = $this->normalize_keyword($question);
            if ($question === '' || $answer === '' || isset($seen[$key]) || $this->count_words($answer) < 20) {
                continue;
            }
            $seen[$key] = true;
            $faq[] = [
                'question' => $question,
                'answer' => $answer,
            ];
            if (count($faq) === 5) {
                break;
            }
        }

        return $faq;
    }

    private function build_faq_content_html($faq, $keyword)
    {
        $heading = 'Pertanyaan yang Sering Diajukan tentang ' . sanitize_text_field($keyword);
        $html = '<h2>' . esc_html($heading) . '</h2>';
        foreach ($faq as $item) {
            if (!is_array($item)) {
                continue;
            }
            $html .= '<h3>' . esc_html($this->scalar_string($item['question'] ?? '')) . '</h3>';
            $html .= '<p>' . esc_html($this->scalar_string($item['answer'] ?? '')) . '</p>';
        }
        return $html;
    }

    private function get_content_tail($html, $word_limit = 180)
    {
        $plain = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $html)));
        if ($plain === '') {
            return '';
        }

        $words = preg_split('/\s+/', $plain);
        return is_array($words) ? implode(' ', array_slice($words, -max(1, absint($word_limit)))) : '';
    }

    private function count_html_heading($html, $tag)
    {
        $tag = preg_replace('/[^a-z0-9]/i', '', (string) $tag);
        if ($tag === '') {
            return 0;
        }
        return (int) preg_match_all('/<' . preg_quote($tag, '/') . '\b/i', (string) $html);
    }

    private function extract_openai_compatible_content($body)
    {
        $body = trim((string) $body);
        if ($body === '') {
            return '';
        }

        $data = json_decode($body, true);
        if (is_array($data)) {
            $content = $this->message_content_to_string($data['choices'][0]['message']['content'] ?? '');
            if ($content !== '') {
                return trim($content);
            }
            $reasoning_content = $this->message_content_to_string($data['choices'][0]['message']['reasoning_content'] ?? '');
            if ($reasoning_content !== '' && $this->looks_like_json_payload($reasoning_content)) {
                return trim($reasoning_content);
            }
        }

        if (strpos($body, 'data:') !== false) {
            $content = '';
            $lines = preg_split('/\R+/', $body);
            foreach (is_array($lines) ? $lines : [] as $line) {
                $line = trim($line);
                if (strpos($line, 'data:') !== 0) {
                    continue;
                }
                $json = trim(substr($line, 5));
                if ($json === '' || $json === '[DONE]') {
                    continue;
                }
                $chunk = json_decode($json, true);
                if (!is_array($chunk)) {
                    continue;
                }
                $content .= $this->message_content_to_string($chunk['choices'][0]['delta']['content'] ?? '');
                $content .= $this->message_content_to_string($chunk['choices'][0]['message']['content'] ?? '');
                $reasoning_content = $this->message_content_to_string($chunk['choices'][0]['delta']['reasoning_content'] ?? '');
                $reasoning_content .= $this->message_content_to_string($chunk['choices'][0]['message']['reasoning_content'] ?? '');
                if ($content === '' && $this->looks_like_json_payload($reasoning_content)) {
                    $content .= $reasoning_content;
                }
            }
            return trim($content);
        }

        return '';
    }

    private function message_content_to_string($content)
    {
        if (is_scalar($content)) {
            return (string) $content;
        }
        if (!is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $part) {
            if (is_scalar($part)) {
                $text .= (string) $part;
                continue;
            }
            if (!is_array($part)) {
                continue;
            }
            $text .= $this->message_content_to_string($part['text'] ?? ($part['content'] ?? ''));
        }
        return $text;
    }

    private function decode_router_json_content($content)
    {
        $content = trim((string) $content);
        $without_opening_fence = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = is_string($without_opening_fence) ? $without_opening_fence : $content;
        $without_closing_fence = preg_replace('/\s*```$/', '', $content);
        $content = is_string($without_closing_fence) ? $without_closing_fence : $content;
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $content, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function looks_like_json_payload($content)
    {
        $content = trim((string) $content);
        return $content !== '' && (strpos($content, '{') !== false || strpos($content, '[') !== false);
    }

    private function openai_compatible_url($base_url, $path)
    {
        $base_url = rtrim((string) $base_url, '/');
        $path = ltrim((string) $path, '/');

        if (preg_match('#/' . preg_quote($path, '#') . '$#', $base_url)) {
            return $base_url;
        }

        return $base_url . '/' . $path;
    }

    private function build_router_request($work, $options, $attempt = 1, $rewrite_reason = '')
    {
        return [
            'job_id' => 'jdh_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false),
            'keyword' => $work['keyword'],
            'generation_mode' => 'explore_angles',
            'angle_number' => (int) $work['angle_number'],
            'max_angles_per_keyword' => (int) $options['max_angles_per_keyword'],
            'attempt' => (int) $attempt,
            'rewrite_reason' => $rewrite_reason,
            'site' => [
                'name' => get_bloginfo('name'),
                'url' => home_url('/'),
                'niche' => $options['site_niche'],
                'target_audience' => $options['target_audience'],
                'language' => $options['content_language'],
                'brand_tone' => $options['brand_tone'],
                'forbidden_topics' => $this->split_lines($options['forbidden_topics']),
            ],
            'seo' => [
                'language' => $options['content_language'],
                'country' => 'ID',
                'min_words' => self::ARTICLE_MIN_WORDS,
                'max_words' => self::ARTICLE_MAX_WORDS,
                'quality_min_score' => (int) $options['content_quality_min_score'],
            ],
            'content_guide' => $this->read_base_guide(),
            'dynamic_content_guide' => $this->build_dynamic_guide($options),
            'internal_links' => $this->get_internal_link_context(),
            'duplicate_context' => $this->get_duplicate_context($work),
            'links' => [
                'social_or_external_links' => $this->parse_external_links($options),
                'primary_external_url' => $options['external_url'],
                'allow_contextual_backlink' => true,
            ],
            'image' => [
                'required' => true,
                'preferred_style' => 'realistic editorial photo',
                'orientation' => 'landscape',
                'source_priority' => $this->build_image_source_priority($options),
                'watermark' => [
                    'enabled' => !empty($options['watermark_enabled']),
                    'text_or_logo' => $this->get_watermark_text($options),
                ],
                'avoid' => ['abstract concept', 'random office photo', 'text overlay', 'political symbol', 'SARA content'],
            ],
        ];
    }

    private function build_image_source_priority($options)
    {
        $priority = [];
        if (!empty($options['use_media_image'])) {
            $priority[] = 'media_library';
        }
        if (!empty($options['pexels_enabled'])) {
            $priority[] = 'pexels';
        }
        $priority[] = 'image_router';

        return array_values(array_unique($priority));
    }

    private function generate_article_fallback($work, $options, &$logs, $attempt = 1, $rewrite_reason = '')
    {
        if (empty($options['user_api_key'])) {
            $logs[] = 'Router belum aktif dan fallback Groq API key kosong.';
            return false;
        }

        $prompt = $this->build_fallback_prompt($work, $options, $attempt, $rewrite_reason);
        $content = $this->call_groq_api($options['user_api_key'], $prompt, $logs, 180);
        if (!$content) {
            return false;
        }

        $data = json_decode($content, true);
        if (is_array($data)) {
            return $data;
        }

        $logs[] = 'Fallback Groq tidak mengembalikan JSON valid.';
        return false;
    }

    private function build_fallback_prompt($work, $options, $attempt, $rewrite_reason)
    {
        $guide = $this->read_base_guide() . "\n\n" . $this->build_dynamic_guide($options);
        return "Return valid JSON only using the required schema from the guide.\n\nKeyword: {$work['keyword']}\nAngle number: {$work['angle_number']} of {$options['max_angles_per_keyword']}\nAttempt: {$attempt}\nRewrite reason: {$rewrite_reason}\n\nGuide:\n{$guide}\n\nWrite a publish-ready Indonesian SEO article. Avoid prices, SARA, and politics. Include image prompt only, not generated image.";
    }

    private function call_groq_api($api_key, $prompt, &$logs = null, $timeout = 120)
    {
        $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => 'openai/gpt-oss-120b',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a strict JSON-producing SEO content engine. Return JSON only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.65,
            ]),
            'timeout' => $timeout,
        ]);

        if (is_wp_error($response)) {
            if ($logs !== null) {
                $logs[] = 'Groq error: ' . $response->get_error_message();
            }
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code === 200 && isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }

        if ($logs !== null) {
            $logs[] = 'Groq HTTP ' . $code;
        }
        return false;
    }

    private function validate_article_payload($article, $options)
    {
        if (!is_array($article)) {
            return ['valid' => false, 'message' => 'Payload artikel bukan object JSON.'];
        }

        $required = ['title', 'slug', 'meta_description', 'content_html', 'keyword', 'angle'];
        foreach ($required as $key) {
            if (!isset($article[$key]) || !is_scalar($article[$key]) || trim((string) $article[$key]) === '') {
                return ['valid' => false, 'message' => "Field '$key' kosong."];
            }
        }
        if (!is_array($article['image'] ?? null)) {
            return ['valid' => false, 'message' => "Field 'image' bukan object JSON valid."];
        }

        if ($this->scalar_string($article['status'] ?? 'ready', 'ready') !== 'ready') {
            return ['valid' => false, 'message' => 'Status router belum ready.'];
        }

        $minimum_score = abs($this->scalar_int($options['content_quality_min_score'] ?? 80, 80));

        if ($this->scalar_string($article['duplicate_risk'] ?? 'low', 'low') === 'high') {
            return ['valid' => false, 'message' => 'Router menandai duplicate risk high.'];
        }

        $plain = trim(wp_strip_all_tags($article['content_html']));
        $word_count = $this->count_words($plain);
        if ($word_count < self::ARTICLE_MIN_WORDS) {
            return ['valid' => false, 'message' => "Artikel terlalu pendek ($word_count kata). Minimal " . self::ARTICLE_MIN_WORDS . ' kata.'];
        }
        if ($word_count > self::ARTICLE_MAX_WORDS) {
            return ['valid' => false, 'message' => "Artikel terlalu panjang ($word_count kata). Maksimal " . self::ARTICLE_MAX_WORDS . ' kata.'];
        }

        $h2_count = $this->count_html_heading($article['content_html'], 'h2');
        if ($h2_count < self::ARTICLE_MIN_H2) {
            return ['valid' => false, 'message' => "Artikel hanya memiliki $h2_count H2. Minimal " . self::ARTICLE_MIN_H2 . ' H2.'];
        }

        if (preg_match('/<(?:h1|script|style)\b/i', $article['content_html'])) {
            return ['valid' => false, 'message' => 'Artikel mengandung tag HTML yang tidak diizinkan.'];
        }

        $faq_count = count($this->normalize_faq_items($article['faq'] ?? []));
        if ($faq_count < 4) {
            return ['valid' => false, 'message' => "FAQ hanya $faq_count. Minimal 4 FAQ."];
        }

        $title_length = function_exists('mb_strlen') ? mb_strlen($article['title']) : strlen($article['title']);
        if ($title_length < 35 || $title_length > 80) {
            return ['valid' => false, 'message' => "Panjang judul tidak ideal ($title_length karakter)."];
        }

        $meta_length = function_exists('mb_strlen') ? mb_strlen($article['meta_description']) : strlen($article['meta_description']);
        if ($meta_length < 100 || $meta_length > 170) {
            return ['valid' => false, 'message' => "Panjang meta description tidak ideal ($meta_length karakter)."];
        }

        $content_keyword_count = $this->count_keyword_occurrences($plain, $article['keyword']);
        if ($content_keyword_count < 3) {
            return ['valid' => false, 'message' => "Keyword utama hanya muncul $content_keyword_count kali di isi artikel. Minimal 3 kali."];
        }
        if ($content_keyword_count > 18) {
            return ['valid' => false, 'message' => "Keyword utama muncul $content_keyword_count kali dan berisiko stuffing."];
        }

        if (!is_scalar($article['image']['prompt'] ?? null) || trim((string) $article['image']['prompt']) === ''
            || !is_scalar($article['image']['alt'] ?? null) || trim((string) $article['image']['alt']) === '') {
            return ['valid' => false, 'message' => 'Prompt/alt gambar kosong.'];
        }

        $quality = min(100, max(
            abs($this->scalar_int($article['quality_score'] ?? 0)),
            $this->calculate_objective_article_score($article, $word_count, $h2_count, $faq_count, $content_keyword_count, 'quality')
        ));
        if ($quality < $minimum_score) {
            return ['valid' => false, 'message' => "Quality score rendah ($quality)."];
        }

        $seo_score = min(100, max(
            abs($this->scalar_int($article['seo_score'] ?? 0)),
            $this->calculate_objective_article_score($article, $word_count, $h2_count, $faq_count, $content_keyword_count, 'seo')
        ));
        if ($seo_score < $minimum_score) {
            return ['valid' => false, 'message' => "SEO score rendah ($seo_score)."];
        }

        $article['quality_score'] = $quality;
        $article['seo_score'] = $seo_score;

        return ['valid' => true, 'message' => 'OK'];
    }

    private function calculate_objective_article_score($article, $word_count, $h2_count, $faq_count, $keyword_count, $mode = 'quality')
    {
        $score = 50;
        if ($word_count >= self::ARTICLE_MIN_WORDS && $word_count <= self::ARTICLE_MAX_WORDS) {
            $score += 18;
        }
        if ($h2_count >= self::ARTICLE_MIN_H2) {
            $score += 10;
        }
        if ($faq_count >= 4) {
            $score += 8;
        }
        if ($keyword_count >= 3 && $keyword_count <= 18) {
            $score += $mode === 'seo' ? 12 : 6;
        }
        if (!empty($article['meta_description']) && !empty($article['slug']) && !empty($article['title'])) {
            $score += $mode === 'seo' ? 8 : 4;
        }
        if (!empty($article['image']['prompt']) && !empty($article['image']['alt'])) {
            $score += 4;
        }

        return min(100, $score);
    }

    private function count_keyword_occurrences($text, $keyword)
    {
        $keyword = strtolower(trim((string) $keyword));
        if ($keyword === '') {
            return 0;
        }

        $text = strtolower(wp_strip_all_tags((string) $text));
        $pattern = '/\b' . preg_quote($keyword, '/') . '\b/u';
        return preg_match_all($pattern, $text, $matches);
    }

    private function count_words($text)
    {
        $text = wp_strip_all_tags((string) $text);
        preg_match_all('/[\p{L}\p{N}]+(?:[-\'][\p{L}\p{N}]+)*/u', $text, $matches);
        return count($matches[0]);
    }

    private function is_forbidden_topic($article, $options)
    {
        $forbidden = $this->split_lines($options['forbidden_topics'] ?? '');
        $haystack = strtolower(wp_strip_all_tags(($article['title'] ?? '') . ' ' . ($article['content_html'] ?? '') . ' ' . ($article['keyword'] ?? '')));
        foreach ($forbidden as $topic) {
            $topic = strtolower(trim($topic));
            if ($topic !== '' && preg_match('/\b' . preg_quote($topic, '/') . '\b/u', $haystack)) {
                return true;
            }
        }
        return false;
    }

    private function detect_duplicate_article($article, $work)
    {
        $slug = sanitize_title($article['slug'] ?? $work['keyword']);
        if ($this->post_exists_by_slug($slug)) {
            return ['duplicate' => true, 'message' => "Slug '$slug' sudah ada."];
        }

        $title = sanitize_text_field($article['title'] ?? '');
        $existing = get_posts([
            'post_type' => 'post',
            'post_status' => ['publish', 'draft', 'future'],
            'posts_per_page' => 20,
            's' => $this->scalar_string($work['keyword'] ?? ''),
        ]);

        foreach ($existing as $post) {
            similar_text(strtolower($title), strtolower(get_the_title($post)), $pct_title);
            if ($pct_title >= 82) {
                return ['duplicate' => true, 'message' => 'Judul terlalu mirip dengan artikel lama.'];
            }

            $old = wp_trim_words(wp_strip_all_tags($this->scalar_string($post->post_content)), 180, '');
            $new = wp_trim_words(wp_strip_all_tags($article['content_html']), 180, '');
            similar_text(strtolower($old), strtolower($new), $pct_content);
            if ($pct_content >= 70) {
                return ['duplicate' => true, 'message' => 'Konten terlalu mirip dengan artikel lama.'];
            }
        }

        return ['duplicate' => false, 'message' => 'OK'];
    }

    private function post_exists_by_slug($slug)
    {
        $post = get_page_by_path($slug, OBJECT, 'post');
        return !empty($post);
    }

    private function resolve_article_image($article, $work, $options, &$logs, $image_attempt = 1)
    {
        $image = is_array($article['image'] ?? null) ? $article['image'] : [];
        $keyword = sanitize_text_field($work['keyword']);
        $prompt = $this->build_relevant_image_prompt($article, $work, $options);
        $title = sanitize_text_field($article['title'] ?? $keyword);
        $priority = $this->build_image_source_priority($options);
        $logs[] = 'Image source priority: ' . implode(' -> ', $priority) . '.';

        if (!empty($options['use_media_image'])) {
            $media_id = $this->find_media_image($keyword, $prompt, false, $options);
            if ($this->is_valid_featured_image($media_id)) {
                $logs[] = "Image: Media Library relevan (ID $media_id).";
                return $this->maybe_watermark_image($media_id, $options, $logs);
            }
            $logs[] = 'Image: Media Library tidak menemukan gambar relevan.';
        } else {
            $logs[] = 'Image: Media Library dilewati karena tidak dicentang.';
        }

        if (!empty($options['pexels_enabled'])) {
            $pexels_id = $this->fetch_image_from_pexels($prompt, $title, $options, $logs, $image_attempt);
            if ($this->is_valid_featured_image($pexels_id)) {
                update_post_meta($pexels_id, '_wp_attachment_image_alt', sanitize_text_field($image['alt'] ?? $keyword));
                return $this->maybe_watermark_image($pexels_id, $options, $logs);
            }
        } else {
            $logs[] = 'Image: Pexels dilewati karena tidak dicentang.';
        }

        $router_image = $this->call_image_router($article, $work, $options, $logs);
        if ($this->is_valid_featured_image($router_image)) {
            return $this->maybe_watermark_image($router_image, $options, $logs);
        }

        $logs[] = 'Image: sumber yang dipilih gagal menghasilkan attachment valid.';
        return 0;
    }

    private function is_valid_featured_image($attachment_id)
    {
        $attachment_id = absint($attachment_id);
        return $attachment_id > 0
            && wp_attachment_is_image($attachment_id)
            && (bool) wp_get_attachment_url($attachment_id);
    }

    private function build_relevant_image_prompt($article, $work, $options)
    {
        $keyword = sanitize_text_field($work['keyword']);
        $title = sanitize_text_field($article['title'] ?? $keyword);
        $article_image = is_array($article['image'] ?? null) ? $article['image'] : [];
        $router_prompt = sanitize_text_field($this->scalar_string($article_image['prompt'] ?? ''));
        $niche = sanitize_text_field($options['site_niche'] ?? '');
        $audience = sanitize_text_field($options['target_audience'] ?? '');

        $parts = array_filter([
            $router_prompt,
            "main subject: {$keyword}",
            "article title: {$title}",
            $niche ? "business niche: {$niche}" : '',
            $audience ? "target audience: {$audience}" : '',
            'realistic editorial photo, concrete real-world scene, no text overlay, no logo, no political or sensitive symbols',
        ]);

        return trim(implode(', ', $parts));
    }

    private function find_media_image($keyword, $prompt, $random = false, $options = [])
    {
        $query = $random ? $this->first_visual_word($keyword . ' ' . $prompt . ' ' . ($options['site_niche'] ?? '')) : $keyword;
        $images = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => $random ? 8 : 5,
            's' => $query,
            'orderby' => $random ? 'rand' : 'relevance',
            'fields' => 'ids',
        ]);

        foreach ($images as $id) {
            if ($this->is_attachment_relevant((int) $id, $keyword, $prompt, $options)) {
                return (int) $id;
            }
        }

        return 0;
    }

    private function is_attachment_relevant($attachment_id, $keyword, $prompt, $options)
    {
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return false;
        }

        $haystack = strtolower(implode(' ', [
            $this->scalar_string($attachment->post_title),
            $this->scalar_string($attachment->post_excerpt),
            $this->scalar_string(get_post_meta($attachment_id, '_wp_attachment_image_alt', true)),
            basename((string) get_attached_file($attachment_id)),
        ]));

        $keyword_terms = preg_split('/[\s,-]+/', strtolower($this->scalar_string($keyword)));
        $niche_terms = preg_split('/[\s,-]+/', strtolower($this->scalar_string($options['site_niche'] ?? '')));
        $prompt_terms = preg_split('/[\s,-]+/', strtolower($this->scalar_string($prompt)));
        $terms = array_unique(array_filter(array_merge(
            is_array($keyword_terms) ? $keyword_terms : [],
            is_array($niche_terms) ? $niche_terms : [],
            array_slice(is_array($prompt_terms) ? $prompt_terms : [], 0, 10)
        )));

        $score = 0;
        foreach ($terms as $term) {
            $term = trim(preg_replace('/[^a-z0-9\p{L}]/u', '', $term));
            if (strlen($term) < 4) {
                continue;
            }
            if (strpos($haystack, $term) !== false) {
                $score++;
            }
        }

        return $score >= 1;
    }

    private function first_visual_word($text)
    {
        $parts = preg_split('/[\s,-]+/', strtolower($this->scalar_string($text)));
        foreach (is_array($parts) ? $parts : [] as $part) {
            if (strlen($part) > 4) {
                return $part;
            }
        }
        return sanitize_text_field($text);
    }

    private function fetch_image_from_pexels($query, $context_title = '', $options = [], &$logs = null, $page = 1)
    {
        $pexels_api_key = sanitize_text_field($options['pexels_api_key'] ?? '');
        if (empty($pexels_api_key)) {
            if ($logs !== null) {
                $logs[] = 'Pexels: API key kosong, dilewati.';
            }
            return 0;
        }

        $url = add_query_arg(array_filter([
            'query' => $query,
            'per_page' => min(max((int) $this->pexels_per_page, 1), 20),
            'orientation' => $this->pexels_orientation,
            'size' => 'large',
            'locale' => 'en-US',
            'page' => max(1, min(80, absint($page))),
        ]), 'https://api.pexels.com/v1/search');

        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => $pexels_api_key],
            'timeout' => 25,
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            if ($logs !== null) {
                $logs[] = 'Pexels: tidak tersedia.';
            }
            return 0;
        }

        $pexels_data = json_decode(wp_remote_retrieve_body($response), true);
        $photos = is_array($pexels_data) && is_array($pexels_data['photos'] ?? null) ? $pexels_data['photos'] : [];
        if (empty($photos)) {
            if ($logs !== null) {
                $logs[] = 'Pexels: response tidak berisi daftar foto valid.';
            }
            return 0;
        }

        $photo = reset($photos);
        if (!is_array($photo) || !is_array($photo['src'] ?? null)) {
            if ($logs !== null) {
                $logs[] = 'Pexels: data foto pertama tidak valid.';
            }
            return 0;
        }
        $img_url = $this->scalar_string($photo['src']['large2x'] ?? ($photo['src']['large'] ?? ''));
        $pexels_id = $this->scalar_string($photo['id'] ?? '');
        $photographer = sanitize_text_field($this->scalar_string($photo['photographer'] ?? 'Pexels', 'Pexels'));
        if (!$img_url || !filter_var($img_url, FILTER_VALIDATE_URL)) {
            return 0;
        }

        $existing = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'meta_query' => [['key' => '_pexels_photo_id', 'value' => $pexels_id]],
            'fields' => 'ids',
        ]);
        if (!empty($existing)) {
            return (int) $existing[0];
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attach_id = media_sideload_image($img_url, 0, "Photo by $photographer on Pexels | $query", 'id');
        if (is_wp_error($attach_id)) {
            return 0;
        }

        update_post_meta($attach_id, '_pexels_photo_id', $pexels_id);
        update_post_meta($attach_id, '_pexels_photographer', $photographer);
        wp_update_post([
            'ID' => $attach_id,
            'post_title' => sanitize_title(wp_trim_words($context_title ?: $query, 8, '')) . '-' . $pexels_id,
            'post_excerpt' => "Photo by $photographer on Pexels",
        ]);

        if ($logs !== null) {
            $logs[] = "Pexels: OK (ID $attach_id).";
        }
        return (int) $attach_id;
    }

    private function call_image_router($article, $work, $options, &$logs)
    {
        if (empty($options['router_enabled'])) {
            return 0;
        }

        if (($options['router_api_mode'] ?? 'openai_compatible') === 'openai_compatible') {
            return $this->call_openai_compatible_image_router($article, $work, $options, $logs);
        }

        if (empty($options['image_router_endpoint'])) {
            return 0;
        }

        $payload = [
            'keyword' => $work['keyword'],
            'title' => $article['title'],
            'image' => array_merge((array) ($article['image'] ?? []), [
                'prompt' => $this->build_relevant_image_prompt($article, $work, $options),
            ]),
            'dynamic_content_guide' => $this->build_dynamic_guide($options),
        ];
        $headers = ['Content-Type' => 'application/json'];
        if (!empty($options['router_api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $options['router_api_key'];
        }

        $response = wp_remote_post($options['image_router_endpoint'], [
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'timeout' => 120,
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300) {
            $logs[] = 'Image Router: gagal.';
            return 0;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            $logs[] = 'Image Router: response bukan JSON valid.';
            return 0;
        }
        $url = $this->scalar_string($data['url'] ?? ($data['image_url'] ?? ''));
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attach_id = media_sideload_image($url, 0, 'Router Image: ' . $work['keyword'], 'id');
        if (is_wp_error($attach_id)) {
            return 0;
        }
        $logs[] = "Image Router: OK (ID $attach_id).";
        return (int) $attach_id;
    }

    private function call_openai_compatible_image_router($article, $work, $options, &$logs)
    {
        if (empty($options['writing_router_endpoint']) || empty($options['router_image_model'])) {
            return 0;
        }

        $prompt = $this->build_relevant_image_prompt($article, $work, $options);
        if ($prompt === '') {
            return 0;
        }

        $endpoint = $this->openai_compatible_url($options['writing_router_endpoint'], 'images/generations');
        $headers = ['Content-Type' => 'application/json'];
        if (!empty($options['router_api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $options['router_api_key'];
        }

        $response = wp_remote_post($endpoint, [
            'headers' => $headers,
            'body' => wp_json_encode([
                'model' => $options['router_image_model'],
                'prompt' => $prompt,
                'size' => '1792x1024',
                'n' => 1,
                'response_format' => 'url',
            ]),
            'timeout' => 180,
        ]);

        if (is_wp_error($response)) {
            $logs[] = '9Router Image error: ' . $response->get_error_message();
            return 0;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            $logs[] = "9Router Image HTTP $code.";
            if ($body !== '') {
                $logs[] = '9Router Image response: ' . wp_trim_words(wp_strip_all_tags($body), 40, '...');
            }
            return 0;
        }

        $data = json_decode($body, true);
        $image_rows = is_array($data) && is_array($data['data'] ?? null) ? $data['data'] : [];
        $first_image = reset($image_rows);
        if (!is_array($first_image)) {
            $logs[] = '9Router Image: response JSON tidak memiliki data gambar valid.';
            return 0;
        }
        $url = $this->scalar_string($first_image['url'] ?? '');
        $b64 = $this->scalar_string($first_image['b64_json'] ?? '');

        if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $attach_id = media_sideload_image($url, 0, '9Router Image: ' . $work['keyword'], 'id');
            if (!is_wp_error($attach_id)) {
                $logs[] = "9Router Image: URL OK (ID $attach_id).";
                return (int) $attach_id;
            }
            $logs[] = '9Router Image: sideload URL gagal.';
            return 0;
        }

        if ($b64) {
            $attach_id = $this->save_base64_image_to_media($b64, '9router-image-' . sanitize_title($work['keyword']) . '.png', $work['keyword'], $logs);
            if ($attach_id) {
                $logs[] = "9Router Image: base64 OK (ID $attach_id).";
                return (int) $attach_id;
            }
        }

        $logs[] = '9Router Image: response tidak punya url/b64_json.';
        return 0;
    }

    private function save_base64_image_to_media($b64, $filename, $title, &$logs)
    {
        $b64 = $this->scalar_string($b64);
        if (strpos($b64, 'base64,') !== false) {
            $b64 = substr($b64, strpos($b64, 'base64,') + 7);
        }
        $raw = base64_decode($b64, true);
        if (!is_string($raw) || $raw === '') {
            $logs[] = '9Router Image: base64 tidak valid.';
            return 0;
        }

        $upload = wp_upload_bits(sanitize_file_name($filename), null, $raw);
        if (!empty($upload['error']) || empty($upload['file'])) {
            $upload_error = $this->scalar_string($upload['error']);
            $logs[] = '9Router Image upload gagal' . ($upload_error !== '' ? ': ' . $upload_error : '.');
            return 0;
        }

        $filetype = wp_check_filetype($upload['file'], null);
        $attachment = [
            'post_mime_type' => !empty($filetype['type']) ? $filetype['type'] : 'image/png',
            'post_title' => sanitize_text_field($title),
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $upload['file'], 0, true);
        if (is_wp_error($attach_id)) {
            $logs[] = '9Router Image: gagal membuat attachment WordPress.';
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attach_id, $upload['file']);
        if (!empty($metadata)) {
            wp_update_attachment_metadata($attach_id, $metadata);
        }

        return (int) $attach_id;
    }

    private function generate_pollinations_image($prompt, $keyword, &$logs, $variation = 1)
    {
        $seed = sprintf('%u', crc32($keyword . '|' . max(1, absint($variation))));
        $url = 'https://image.pollinations.ai/prompt/' . rawurlencode($prompt) . '?width=1280&height=720&nologo=true&enhance=true&model=flux&seed=' . rawurlencode($seed);

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attach_id = media_sideload_image($url, 0, 'AI Image: ' . $keyword, 'id');
        if (is_wp_error($attach_id)) {
            $logs[] = 'Pollinations: gagal.';
            return 0;
        }
        $logs[] = "Pollinations: OK (ID $attach_id).";
        return (int) $attach_id;
    }

    private function maybe_watermark_image($attachment_id, $options, &$logs)
    {
        if (empty($attachment_id) || empty($options['watermark_enabled'])) {
            return $attachment_id;
        }

        if (get_post_meta($attachment_id, '_jdh_watermarked', true) === '1') {
            $logs[] = 'Watermark: gambar sudah pernah diproses.';
            return $attachment_id;
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !is_file($file) || !is_readable($file) || !is_writable($file)) {
            $logs[] = 'Watermark: file gambar tidak tersedia atau tidak dapat ditulis.';
            return $attachment_id;
        }

        $mime = get_post_mime_type($attachment_id);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $attachment_id;
        }

        $text = $this->get_watermark_text($options);
        if (!$text) {
            return $attachment_id;
        }

        $ok = $this->apply_text_watermark($file, $mime, $text);
        if ($ok) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata($attachment_id, $file);
            if (!empty($metadata)) {
                wp_update_attachment_metadata($attachment_id, $metadata);
            }
            update_post_meta($attachment_id, '_jdh_watermarked', '1');
            $logs[] = 'Watermark: OK.';
        } else {
            $logs[] = 'Watermark: dilewati, GD tidak tersedia atau file tidak bisa diedit.';
        }
        return $attachment_id;
    }

    private function apply_text_watermark($file, $mime, $text)
    {
        if (!function_exists('imagecreatetruecolor') || !is_file($file) || !is_readable($file) || !is_writable($file)) {
            return false;
        }

        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
            $img = @imagecreatefromjpeg($file);
        } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
            $img = @imagecreatefrompng($file);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $img = @imagecreatefromwebp($file);
        } else {
            return false;
        }

        if (!$img) {
            return false;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $font_size = max(1, min(5, (int) round(min($w, $h) / 110)));
        $padding = max(14, (int) round($w / 70));
        $display_text = function_exists('remove_accents') ? remove_accents($text) : $text;
        $display_text = preg_replace('/[^\x20-\x7E]/', '', (string) $display_text);
        $display_text = trim(is_string($display_text) ? $display_text : '');
        if ($display_text === '') {
            return false;
        }

        $character_width = imagefontwidth($font_size);
        $max_characters = max(1, (int) floor(max(1, $w - ($padding * 2)) / max(1, $character_width)));
        $display_text = substr($display_text, 0, $max_characters);
        $text_width = $character_width * strlen($display_text);
        $text_height = imagefontheight($font_size);
        $x = max($padding, $w - $text_width - $padding);
        $y = max($padding, $h - $text_height - $padding);

        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 45);
        $white = imagecolorallocatealpha($img, 255, 255, 255, 15);
        imagestring($img, $font_size, $x + 1, $y + 1, $display_text, $shadow);
        imagestring($img, $font_size, $x, $y, $display_text, $white);

        if ($mime === 'image/jpeg') {
            $saved = @imagejpeg($img, $file, 88);
        } elseif ($mime === 'image/png') {
            imagesavealpha($img, true);
            $saved = @imagepng($img, $file, 6);
        } elseif (function_exists('imagewebp')) {
            $saved = @imagewebp($img, $file, 88);
        } else {
            $saved = false;
        }

        return (bool) $saved;
    }

    private function publish_article($article, $work, $options, $image_id, &$logs)
    {
        if (!$this->is_valid_featured_image($image_id)) {
            return new WP_Error('jdh_featured_image_required', 'Featured image wajib dan attachment gambar tidak valid.');
        }

        $content = $this->prepare_final_content($article, $options, $image_id);
        $cat_ids = $this->get_auto_category_ids($article, $work);

        $post_id = wp_insert_post([
            'post_title' => sanitize_text_field($article['title']),
            'post_content' => $this->safe_post_content($content),
            'post_excerpt' => sanitize_text_field($article['excerpt'] ?? ''),
            'post_status' => 'draft',
            'post_author' => get_current_user_id() ?: 1,
            'post_type' => 'post',
            'post_category' => $cat_ids,
            'post_name' => sanitize_title($article['slug']),
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $thumbnail_set = set_post_thumbnail($post_id, $image_id);
        if (!$thumbnail_set || (int) get_post_thumbnail_id($post_id) !== (int) $image_id) {
            wp_delete_post($post_id, true);
            return new WP_Error('jdh_featured_image_attach_failed', 'Featured image gagal dipasang. Artikel tidak dipublish.');
        }

        if (!empty($cat_ids)) {
            update_post_meta($post_id, '_yoast_wpseo_primary_category', (int) $cat_ids[0]);
            update_post_meta($post_id, 'rank_math_primary_category', (int) $cat_ids[0]);
        }

        $this->set_article_tags($post_id, $article, $work);
        $this->set_seo_meta($post_id, $article, $work);
        update_post_meta($post_id, '_jdh_keyword', sanitize_text_field($work['keyword']));
        update_post_meta($post_id, '_jdh_angle_number', (int) $work['angle_number']);
        update_post_meta($post_id, '_jdh_content_hash', md5(wp_strip_all_tags($article['content_html'])));
        update_post_meta($post_id, '_jdh_quality_score', absint($article['quality_score'] ?? 0));
        update_post_meta($post_id, '_jdh_seo_score', absint($article['seo_score'] ?? 0));

        $published_id = wp_update_post([
            'ID' => $post_id,
            'post_status' => 'publish',
        ], true);
        if (is_wp_error($published_id)) {
            wp_delete_post($post_id, true);
            return $published_id;
        }

        $logs[] = "Publish: Post ID $post_id.";
        return $post_id;
    }

    private function prepare_final_content($article, $options, $image_id)
    {
        $content = $article['content_html'];

        if ($image_id) {
            $alt = $article['image']['alt'] ?? $article['keyword'];
            update_post_meta($image_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
            $image_html = '<div class="wp-block-image"><figure class="aligncenter">' . wp_get_attachment_image($image_id, 'large', false, ['alt' => sanitize_text_field($alt)]) . '</figure></div>';
            $parts = explode('</p>', $content);
            if (count($parts) > 2) {
                $parts[1] .= '</p>' . $image_html;
                $content = implode('</p>', $parts);
            } else {
                $content = $image_html . $content;
            }
        }

        $faq_embedded = !empty($article['faq_embedded'])
            || preg_match('/<h2\b[^>]*>[^<]*(?:FAQ|Pertanyaan yang Sering)/i', $content);
        if (!empty($article['faq']) && is_array($article['faq']) && !$faq_embedded) {
            $content .= "\n<h2>Pertanyaan yang Sering Diajukan</h2>";
            foreach ($article['faq'] as $faq) {
                if (!is_array($faq)) {
                    continue;
                }
                $question = $this->scalar_string($faq['question'] ?? '');
                $answer = $this->scalar_string($faq['answer'] ?? '');
                if ($question !== '' && $answer !== '') {
                    $content .= '<h3>' . esc_html($question) . '</h3><p>' . esc_html($answer) . '</p>';
                }
            }
        }

        $video = $this->format_video_html($options['youtube_link'] ?? '');
        if ($video) {
            $content .= '<hr><h2>Video Terkait</h2>' . $video;
        }

        if (!empty($options['external_url'])) {
            $content .= '<h2>Info Lanjutan</h2><p><a href="' . esc_url($options['external_url']) . '" target="_blank" rel="nofollow">Pelajari informasi selengkapnya</a>.</p>';
        }

        $social_links = $this->parse_external_links($options);
        if (!empty($social_links)) {
            $content .= '<h2>Terhubung dengan Kami</h2><ul>';
            foreach (array_slice($social_links, 0, 3) as $link) {
                $content .= '<li><a href="' . esc_url($link['url']) . '" target="_blank" rel="nofollow noopener">' . esc_html($link['label']) . '</a></li>';
            }
            $content .= '</ul>';
        }

        return $content;
    }

    private function safe_post_content($content)
    {
        $allowed = wp_kses_allowed_html('post');
        $allowed['iframe'] = [
            'src' => true,
            'width' => true,
            'height' => true,
            'frameborder' => true,
            'allow' => true,
            'allowfullscreen' => true,
            'loading' => true,
            'title' => true,
            'class' => true,
        ];
        $allowed['video'] = [
            'src' => true,
            'width' => true,
            'height' => true,
            'controls' => true,
            'class' => true,
            'preload' => true,
            'autoplay' => true,
            'muted' => true,
            'loop' => true,
        ];
        $allowed['source'] = ['src' => true, 'type' => true];
        return wp_kses($content, $allowed);
    }

    private function format_video_html($url)
    {
        $url = trim((string) $url);
        if (!$url) {
            return '';
        }
        if (strpos($url, '<iframe') !== false) {
            return '<div class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper">' . $url . '</div></div>';
        }
        if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([a-zA-Z0-9_-]{11})#', $url, $m)) {
            return '<div class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper"><iframe width="560" height="315" src="https://www.youtube.com/embed/' . esc_attr($m[1]) . '?autoplay=1&mute=1" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy" title="Video"></iframe></div></div>';
        }
        if (preg_match('#vimeo\.com/(\d+)#', $url, $m)) {
            return '<div class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper"><iframe width="560" height="315" src="https://player.vimeo.com/video/' . esc_attr($m[1]) . '?autoplay=1" frameborder="0" allow="autoplay; fullscreen" allowfullscreen loading="lazy" title="Video Vimeo"></iframe></div></div>';
        }
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return '<p><a href="' . esc_url($url) . '" target="_blank" rel="nofollow noopener">Lihat video terkait</a></p>';
        }
        return '';
    }

    private function get_auto_category_ids($article, $work)
    {
        $target = strtolower(sanitize_text_field($article['category'] ?? ''));
        $cats = get_categories(['hide_empty' => 0]);
        foreach ($cats as $cat) {
            if ($target && strtolower($cat->name) === $target) {
                return [(int) $cat->term_id];
            }
        }

        if ($target) {
            $created = wp_insert_term(sanitize_text_field($article['category']), 'category');
            if (!is_wp_error($created) && !empty($created['term_id'])) {
                return [(int) $created['term_id']];
            }
        }

        foreach ($cats as $cat) {
            if ((int) $cat->term_id !== 1 && strtolower($cat->slug) !== 'uncategorized') {
                return [(int) $cat->term_id];
            }
        }

        return [];
    }

    private function set_article_tags($post_id, $article, $work)
    {
        $tags = $this->normalize_string_list($article['tags'] ?? [], 9);
        array_unshift($tags, sanitize_text_field($work['keyword']));
        $tags = array_values(array_unique(array_slice($tags, 0, 10)));
        if (!empty($tags)) {
            wp_set_post_tags($post_id, $tags, false);
        }
    }

    private function set_seo_meta($post_id, $article, $work)
    {
        $keyword = sanitize_text_field($work['keyword']);
        $title = sanitize_text_field($article['title']);
        $site = get_bloginfo('name');
        $title_length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        $seo_title = $title_length <= 50 && $site ? "$title | $site" : $title;
        $seo_title_length = function_exists('mb_strlen') ? mb_strlen($seo_title) : strlen($seo_title);
        if ($seo_title_length > 60) {
            $seo_title = (function_exists('mb_substr') ? mb_substr($seo_title, 0, 57) : substr($seo_title, 0, 57)) . '...';
        }
        $desc = sanitize_text_field($article['meta_description']);
        $desc_length = function_exists('mb_strlen') ? mb_strlen($desc) : strlen($desc);
        if ($desc_length > 160) {
            $desc = (function_exists('mb_substr') ? mb_substr($desc, 0, 157) : substr($desc, 0, 157)) . '...';
        }

        $fields = [
            '_yoast_wpseo_focuskw' => $keyword,
            '_yoast_wpseo_title' => $seo_title,
            '_yoast_wpseo_metadesc' => $desc,
            '_aioseo_title' => $seo_title,
            '_aioseo_description' => $desc,
            'rank_math_title' => $seo_title,
            'rank_math_description' => $desc,
            'rank_math_focus_keyword' => $keyword,
        ];
        foreach ($fields as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        if (class_exists('WPSEO_Meta')) {
            WPSEO_Meta::set_value('focuskw', $keyword, $post_id);
            WPSEO_Meta::set_value('title', $seo_title, $post_id);
            WPSEO_Meta::set_value('metadesc', $desc, $post_id);
        }
    }

    private function get_keyword_state()
    {
        $state = get_option(self::KEYWORD_STATE_KEY, []);
        return is_array($state) ? $state : [];
    }

    private function save_keyword_state($state)
    {
        update_option(self::KEYWORD_STATE_KEY, is_array($state) ? $state : []);
    }

    private function acquire_lock()
    {
        $lock = get_option(self::LOCK_KEY, 0);
        if ($lock && (time() - (int) $lock) < self::QUEUED_TIMEOUT_SECONDS) {
            return false;
        }
        update_option(self::LOCK_KEY, time(), false);
        return true;
    }

    private function release_lock()
    {
        delete_option(self::LOCK_KEY);
    }

    private function get_next_work_item($options, $reserve = false)
    {
        $keywords = $this->split_lines($options['target_keywords']);
        if (empty($keywords)) {
            return false;
        }

        $max_angles = max(1, min(5, absint($options['max_angles_per_keyword'] ?? 5)));
        $state = $this->get_keyword_state();
        $now = time();

        foreach ($keywords as $keyword) {
            $key = $this->normalize_keyword($keyword);
            if (!isset($state[$key]) || !is_array($state[$key])) {
                $state[$key] = ['keyword' => $keyword, 'angles' => []];
            }
            if (!isset($state[$key]['angles']) || !is_array($state[$key]['angles'])) {
                $state[$key]['angles'] = [];
            }

            for ($angle = 1; $angle <= $max_angles; $angle++) {
                $angle_state = is_array($state[$key]['angles'][$angle] ?? null) ? $state[$key]['angles'][$angle] : [];
                $status = $this->scalar_string($angle_state['status'] ?? 'new', 'new');

                if ($status === 'queued') {
                    $updated_at = strtotime($angle_state['updated_at'] ?? '');
                    if ($updated_at && ($now - $updated_at) > self::QUEUED_TIMEOUT_SECONDS) {
                        $state[$key]['angles'][$angle]['status'] = 'failed';
                        $state[$key]['angles'][$angle]['error_message'] = 'Proses timeout, status queued terlalu lama.';
                        $this->save_keyword_state($state);
                        $status = 'failed';
                    }
                }

                if (in_array($status, ['new', 'failed', 'retry'], true)) {
                    if ($reserve) {
                        if (!$this->acquire_lock()) {
                            return false;
                        }
                        $state[$key]['angles'][$angle]['status'] = 'queued';
                        $state[$key]['angles'][$angle]['updated_at'] = current_time('mysql');
                        $this->save_keyword_state($state);
                    }
                    return ['keyword' => $keyword, 'keyword_key' => $key, 'angle_number' => $angle];
                }
            }
        }

        return false;
    }

    private function mark_angle_published($keyword, $angle_number, $post_id, $article)
    {
        $state = $this->get_keyword_state();
        $key = $this->normalize_keyword($keyword);
        if (!isset($state[$key]) || !is_array($state[$key])) {
            $state[$key] = ['keyword' => $keyword, 'angles' => []];
        }
        if (!isset($state[$key]['angles']) || !is_array($state[$key]['angles'])) {
            $state[$key]['angles'] = [];
        }
        $state[$key]['angles'][(int) $angle_number] = [
            'status' => 'published',
            'post_id' => (int) $post_id,
            'title' => sanitize_text_field($this->scalar_string($article['title'] ?? '')),
            'angle' => sanitize_text_field($this->scalar_string($article['angle'] ?? '')),
            'updated_at' => current_time('mysql'),
        ];
        $this->save_keyword_state($state);
    }

    private function mark_angle_failed($keyword, $angle_number, $message)
    {
        $state = $this->get_keyword_state();
        $key = $this->normalize_keyword($keyword);
        if (!isset($state[$key]) || !is_array($state[$key])) {
            $state[$key] = ['keyword' => $keyword, 'angles' => []];
        }
        if (!isset($state[$key]['angles']) || !is_array($state[$key]['angles'])) {
            $state[$key]['angles'] = [];
        }

        $angle_state = is_array($state[$key]['angles'][(int) $angle_number] ?? null) ? $state[$key]['angles'][(int) $angle_number] : [];
        $attempts = abs($this->scalar_int($angle_state['attempts'] ?? 0)) + 1;
        $status = $attempts >= self::MAX_ANGLE_ATTEMPTS ? 'failed' : 'retry';
        $state[$key]['angles'][(int) $angle_number] = [
            'status' => $status,
            'attempts' => $attempts,
            'error_message' => sanitize_text_field($this->scalar_string($message)),
            'updated_at' => current_time('mysql'),
        ];
        $this->save_keyword_state($state);
    }

    private function mark_angle_skipped($keyword, $angle_number, $message)
    {
        $state = $this->get_keyword_state();
        $key = $this->normalize_keyword($keyword);
        if (!isset($state[$key]) || !is_array($state[$key])) {
            $state[$key] = ['keyword' => $keyword, 'angles' => []];
        }
        if (!isset($state[$key]['angles']) || !is_array($state[$key]['angles'])) {
            $state[$key]['angles'] = [];
        }

        $state[$key]['angles'][(int) $angle_number] = [
            'status' => 'skipped',
            'attempts' => 0,
            'error_message' => sanitize_text_field($this->scalar_string($message)),
            'updated_at' => current_time('mysql'),
        ];
        $this->save_keyword_state($state);
    }

    private function count_published_angles($state)
    {
        $count = 0;
        foreach (is_array($state) ? $state : [] as $row) {
            $angles = is_array($row) && is_array($row['angles'] ?? null) ? $row['angles'] : [];
            foreach ($angles as $angle) {
                if (is_array($angle) && ($angle['status'] ?? '') === 'published') {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function create_job($keyword, $angle_number)
    {
        $job = [
            'job_id' => 'jdh_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false),
            'keyword' => $keyword,
            'angle_number' => (int) $angle_number,
            'status' => 'queued',
            'attempt_count' => 0,
            'router_request' => null,
            'router_response' => null,
            'error_message' => '',
            'post_id' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];
        $jobs = $this->get_jobs();
        $jobs[] = $job;
        $this->save_jobs($jobs);
        return $job;
    }

    private function get_jobs()
    {
        $jobs = get_option(self::JOBS_KEY, []);
        return is_array($jobs) ? $jobs : [];
    }

    private function save_jobs($jobs)
    {
        $jobs = is_array($jobs) ? array_values(array_filter($jobs, 'is_array')) : [];
        if (count($jobs) > self::MAX_JOBS) {
            $jobs = array_slice($jobs, -self::MAX_JOBS);
        }
        update_option(self::JOBS_KEY, $jobs);
    }

    private function update_job($job_id, $patch)
    {
        $jobs = $this->get_jobs();
        foreach ($jobs as &$job) {
            if (!is_array($job)) {
                continue;
            }
            if (($job['job_id'] ?? '') === $job_id) {
                $job = array_merge($job, is_array($patch) ? $patch : [], ['updated_at' => current_time('mysql')]);
                break;
            }
        }
        unset($job);
        $this->save_jobs($jobs);
    }

    private function get_internal_link_context()
    {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $links = [];
        foreach ($posts as $post) {
            if (empty($post->ID)) {
                continue;
            }
            $links[] = ['title' => get_the_title($post), 'url' => get_permalink($post)];
        }
        return $links;
    }

    private function get_duplicate_context($work)
    {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => ['publish', 'draft', 'future'],
            'numberposts' => 20,
            's' => $this->scalar_string($work['keyword'] ?? ''),
        ]);
        $existing_titles = [];
        $similar_posts = [];
        foreach ($posts as $post) {
            if (empty($post->ID)) {
                continue;
            }
            $existing_titles[] = get_the_title($post);
            $similar_posts[] = [
                'id' => (int) $post->ID,
                'title' => get_the_title($post),
                'excerpt' => wp_trim_words(wp_strip_all_tags($this->scalar_string($post->post_content)), 45, ''),
            ];
        }
        return [
            'existing_titles' => $existing_titles,
            'used_keywords' => array_keys($this->get_keyword_state()),
            'similar_posts' => $similar_posts,
        ];
    }

    private function parse_external_links($options)
    {
        $links = [];
        foreach ($this->split_lines($options['social_or_external_links'] ?? '') as $line) {
            $label = '';
            $url = '';
            if (strpos($line, '|') !== false) {
                [$label, $url] = array_map('trim', explode('|', $line, 2));
            } elseif (filter_var($line, FILTER_VALIDATE_URL)) {
                $url = $line;
                $label = parse_url($line, PHP_URL_HOST) ?: $line;
            }
            if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                $links[] = ['label' => sanitize_text_field($label ?: $url), 'url' => esc_url_raw($url)];
            }
        }
        return $links;
    }

    private function get_watermark_text($options)
    {
        $configured = $this->scalar_string($options['watermark_text_or_logo'] ?? '');
        return sanitize_text_field($configured !== '' ? $configured : get_bloginfo('name'));
    }

    public function run_daily_task(&$debug_messages = null)
    {
        $this->extend_execution_time(900);
        $logs = is_array($debug_messages) ? $debug_messages : [];
        $options = $this->get_options();

        $schedule_end = $options['schedule_end_date'] ?? '';
        if ($schedule_end && current_time('Y-m-d') > $schedule_end) {
            wp_clear_scheduled_hook('jdh_daily_article_generation');
            return;
        }

        $num = max(1, min(5, absint($options['articles_per_day'] ?? 1)));
        for ($i = 0; $i < $num; $i++) {
            $work = $this->get_next_work_item($options, true);
            if (!$work) {
                break;
            }
            $job = $this->create_job($work['keyword'], $work['angle_number']);
            $this->process_article_job_safely($job, $work, $options, $logs);
        }

        if (is_array($debug_messages)) {
            $debug_messages = $logs;
        }
    }

    public function ajax_reset_tracker()
    {
        check_ajax_referer('jdh_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['msg' => 'Tidak punya izin.']);
        }
        delete_option(self::KEYWORD_STATE_KEY);
        delete_option(self::JOBS_KEY);
        wp_send_json_success(['msg' => 'Tracker keyword, angle, dan riwayat job berhasil di-reset.']);
    }

    public function ajax_test_router()
    {
        check_ajax_referer('jdh_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['msg' => 'Tidak punya izin.']);
        }

        $options = $this->get_options();
        $logs = [];

        if (empty($options['router_enabled'])) {
            wp_send_json_error([
                'msg' => 'Nine Router belum diaktifkan. Centang "Gunakan Nine Router" di Pengaturan Lanjutan.',
                'logs' => ['router_enabled kosong.'],
            ]);
        }

        $writing = $this->test_router_endpoint('writing', $options['writing_router_endpoint'], $options, $logs);
        $image = true;
        if (!empty($options['image_router_endpoint'])) {
            $image = $this->test_router_endpoint('image', $options['image_router_endpoint'], $options, $logs);
        } else {
            $logs[] = 'Image Router endpoint kosong, dilewati. Ini valid jika image fallback belum dipakai.';
        }

        if ($writing && $image) {
            wp_send_json_success([
                'msg' => 'Router berhasil dihubungi. Writing Router OK' . (!empty($options['image_router_endpoint']) ? ' dan Image Router OK.' : '.'),
                'logs' => $logs,
            ]);
        }

        wp_send_json_error([
            'msg' => 'Router belum siap. Cek endpoint, API key, jaringan Docker, dan response webhook.',
            'logs' => $logs,
        ]);
    }

    private function test_router_endpoint($kind, $endpoint, $options, &$logs)
    {
        $endpoint = trim((string) $endpoint);
        if (!$endpoint || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            $logs[] = ucfirst($kind) . ' Router: endpoint kosong/tidak valid.';
            return false;
        }

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($options['router_api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $options['router_api_key'];
        }

        if (($options['router_api_mode'] ?? 'openai_compatible') === 'openai_compatible' && $kind === 'writing') {
            return $this->test_openai_compatible_router($endpoint, $headers, $options, $logs);
        }

        $response = wp_remote_post($endpoint, [
            'headers' => $headers,
            'body' => wp_json_encode([
                'type' => 'health_check',
                'source' => 'jdh-auto-seo-publisher',
                'router_kind' => $kind,
                'timestamp' => gmdate('c'),
                'expected_response' => ['ok' => true],
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $logs[] = ucfirst($kind) . ' Router error: ' . $response->get_error_message();
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = trim(wp_remote_retrieve_body($response));
        $logs[] = ucfirst($kind) . " Router HTTP $code.";

        if ($code < 200 || $code >= 300) {
            if ($body !== '') {
                $logs[] = ucfirst($kind) . ' Router body: ' . wp_trim_words(wp_strip_all_tags($body), 30, '...');
            }
            return false;
        }

        if ($body !== '') {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $logs[] = ucfirst($kind) . ' Router JSON OK.';
            } else {
                $logs[] = ucfirst($kind) . ' Router membalas 2xx, tapi body bukan JSON. Masih dianggap reachable untuk health-check.';
            }
        }

        return true;
    }

    private function test_openai_compatible_router($endpoint, $headers, $options, &$logs)
    {
        $models_url = $this->openai_compatible_url($endpoint, 'models');
        $response = wp_remote_get($models_url, [
            'headers' => $headers,
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $logs[] = 'Nine Router /models error: ' . $response->get_error_message();
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = trim(wp_remote_retrieve_body($response));
        $logs[] = "Nine Router /models HTTP $code.";

        if ($code < 200 || $code >= 300) {
            if ($body !== '') {
                $logs[] = 'Response: ' . wp_trim_words(wp_strip_all_tags($body), 40, '...');
            }
            return false;
        }

        $data = json_decode($body, true);
        if (is_array($data)) {
            $model = sanitize_text_field($options['router_model'] ?? '');
            $available = [];
            $model_rows = is_array($data['data'] ?? null) ? $data['data'] : [];
            foreach ($model_rows as $row) {
                if (is_array($row) && !empty($row['id']) && is_scalar($row['id'])) {
                    $available[] = (string) $row['id'];
                }
            }
            if ($model && !empty($available) && !in_array($model, $available, true)) {
                $logs[] = "Combo artikel '$model' tidak terlihat di /models. Cek model ID combo di 9Router.";
                $logs[] = 'Contoh ID tersedia: ' . implode(', ', array_slice($available, 0, 5));
            } elseif ($model) {
                $logs[] = "Combo artikel '$model' tersedia atau /models tidak membatasi daftar.";
            }
            $logs[] = 'Nine Router OpenAI-compatible reachable.';
        } else {
            $logs[] = 'Nine Router /models membalas 2xx tapi bukan JSON. Endpoint reachable.';
        }

        $this->test_openai_compatible_image_models($endpoint, $headers, $options, $logs);

        return true;
    }

    private function test_openai_compatible_image_models($endpoint, $headers, $options, &$logs)
    {
        $models_url = $this->openai_compatible_url($endpoint, 'models/image');
        $response = wp_remote_get($models_url, [
            'headers' => $headers,
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $logs[] = 'Nine Router /models/image error: ' . $response->get_error_message();
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = trim(wp_remote_retrieve_body($response));
        $logs[] = "Nine Router /models/image HTTP $code.";

        if ($code < 200 || $code >= 300) {
            return;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return;
        }

        $model = sanitize_text_field($options['router_image_model'] ?? '');
        $available = [];
        $model_rows = is_array($data['data'] ?? null) ? $data['data'] : [];
        foreach ($model_rows as $row) {
            if (is_array($row) && !empty($row['id']) && is_scalar($row['id'])) {
                $available[] = (string) $row['id'];
            }
        }

        if ($model && !empty($available) && !in_array($model, $available, true)) {
            $logs[] = "Image model '$model' tidak terlihat di /models/image.";
            $logs[] = 'Contoh image model tersedia: ' . implode(', ', array_slice($available, 0, 5));
        } elseif ($model) {
            $logs[] = "Image model '$model' tersedia.";
        }
    }

    public function ajax_js_cron_check()
    {
        check_ajax_referer('jdh_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['msg' => 'Tidak punya izin.']);
        }

        $options = $this->get_options();
        if (empty($options['js_cron_enabled'])) {
            wp_send_json_error(['msg' => 'JS Cron tidak aktif.']);
        }

        $next_ts = wp_next_scheduled('jdh_daily_article_generation');
        wp_send_json_success([
            'wp_cron_scheduled' => (bool) $next_ts,
            'wp_cron_soon' => $next_ts ? (($next_ts - time()) > 0 && ($next_ts - time()) < 600) : false,
            'msg' => $next_ts ? 'WP-Cron terjadwal: ' . date('Y-m-d H:i', $next_ts) : 'WP-Cron belum terjadwal.',
        ]);
    }

    private function verify_sitemap_readiness(&$logs = null, $post_id = null)
    {
        $sitemap = $this->get_sitemap_url();
        $resp = wp_remote_get($sitemap, ['timeout' => 10, 'redirection' => 3]);
        $code = is_wp_error($resp) ? 'Error' : wp_remote_retrieve_response_code($resp);
        if ($logs !== null) {
            $logs[] = "Crawl readiness: sitemap tersedia dengan HTTP $code.";
            if ($post_id) {
                $logs[] = 'URL siap crawl: ' . get_permalink($post_id);
            }
        }
    }

    private function get_sitemap_url()
    {
        if (class_exists('WPSEO_Sitemaps')) {
            return home_url('sitemap_index.xml');
        }
        if (class_exists('RankMath')) {
            return home_url('sitemap_index.xml');
        }
        if (function_exists('aioseo')) {
            return home_url('sitemap.xml');
        }
        return home_url('wp-sitemap.xml');
    }

    public function inject_sitemap_to_robots($output, $public)
    {
        if ($public && strpos($output, 'Sitemap:') === false) {
            $output = rtrim($output) . "\nSitemap: " . esc_url($this->get_sitemap_url()) . "\n";
        }
        return $output;
    }

    public function add_seo_meta_tags()
    {
        if (!is_single() || get_post_type() !== 'post') {
            return;
        }

        if (defined('WPSEO_VERSION') || class_exists('RankMath') || function_exists('aioseo')) {
            return;
        }

        $post = get_post();
        if (!($post instanceof WP_Post)) {
            return;
        }
        $img_url = wp_get_attachment_url(get_post_thumbnail_id($post->ID));
        $desc = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true) ?: wp_trim_words(wp_strip_all_tags($post->post_content), 28, '');
        $title = get_the_title($post->ID);
        $permalink = get_permalink($post->ID);
        $site = get_bloginfo('name');
        $author = get_the_author_meta('display_name', (int) $post->post_author);
        $post_tags = wp_get_post_tags($post->ID, ['fields' => 'names']);
        $tag_names = is_array($post_tags) ? array_values(array_filter($post_tags, 'is_string')) : [];

        echo '<link rel="canonical" href="' . esc_url($permalink) . '" />' . "\n";
        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr("$title - $site") . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url($permalink) . '" />' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr($site) . '" />' . "\n";
        if ($img_url) {
            echo '<meta property="og:image" content="' . esc_url($img_url) . '" />' . "\n";
        }
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($desc) . '" />' . "\n";
        if ($img_url) {
            echo '<meta name="twitter:image" content="' . esc_url($img_url) . '" />' . "\n";
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $permalink],
            'headline' => $title,
            'description' => $desc,
            'image' => $img_url ?: '',
            'datePublished' => get_the_date('c', $post->ID),
            'dateModified' => get_the_modified_date('c', $post->ID),
            'author' => ['@type' => 'Person', 'name' => $author],
            'publisher' => ['@type' => 'Organization', 'name' => $site],
            'keywords' => implode(', ', $tag_names),
        ];
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }

    public function show_notices()
    {
        // Logs are shown on the plugin page.
    }

    private function get_github_release_info()
    {
        $cache_key = 'jdh_github_release_' . self::GITHUB_REPO;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $url = 'https://api.github.com/repos/' . self::GITHUB_USER . '/' . self::GITHUB_REPO . '/releases/latest';
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'JDH-Auto-SEO-Publisher/' . self::VERSION,
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($cache_key, null, HOUR_IN_SECONDS);
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['tag_name'])) {
            set_transient($cache_key, null, HOUR_IN_SECONDS);
            return null;
        }

        $info = [
            'version' => ltrim($data['tag_name'], 'v'),
            'download_url' => '',
            'published_at' => $data['published_at'] ?? '',
            'body' => $data['body'] ?? '',
            'html_url' => $data['html_url'] ?? '',
        ];

        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (!empty($asset['browser_download_url']) && strpos($asset['name'], '.zip') !== false) {
                    $info['download_url'] = $asset['browser_download_url'];
                    break;
                }
            }
        }

        if (empty($info['download_url'])) {
            $info['download_url'] = 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO . '/archive/refs/tags/' . $data['tag_name'] . '.zip';
        }

        set_transient($cache_key, $info, 6 * HOUR_IN_SECONDS);
        return $info;
    }

    public function check_github_update($transient)
    {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }

        if (!isset($transient->checked) || !is_array($transient->checked)) {
            return $transient;
        }

        $plugin_file = plugin_basename(__FILE__);
        if (!isset($transient->checked[$plugin_file])) {
            return $transient;
        }

        $release = $this->get_github_release_info();
        if (!$release || empty($release['version'])) {
            return $transient;
        }

        if (version_compare($release['version'], self::VERSION, '>')) {
            $obj = new stdClass();
            $obj->slug = dirname($plugin_file);
            $obj->new_version = $release['version'];
            $obj->url = 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO;
            $obj->package = $release['download_url'];
            $obj->plugin = $plugin_file;
            $transient->response[$plugin_file] = $obj;
        }

        return $transient;
    }

    public function github_plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        $plugin_file = plugin_basename(__FILE__);
        $plugin_slug = dirname($plugin_file);

        if (!isset($args->slug) || $args->slug !== $plugin_slug) {
            return $result;
        }

        $release = $this->get_github_release_info();
        if (!$release) {
            return $result;
        }

        $info = new stdClass();
        $info->name = 'Auto SEO Saas';
        $info->slug = $plugin_slug;
        $info->version = $release['version'];
        $info->author = 'Zhacksdev';
        $info->homepage = 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO;
        $info->download_link = $release['download_url'];
        $info->sections = [
            'description' => 'AI SEO publisher with SaaS site profiles, Nine Router writing/image pipeline, keyword angle tracking, duplicate rewrite, watermarking, SEO metadata, crawl and daily reporting telegram.',
            'changelog' => $this->parse_changelog($release['body'] ?? ''),
        ];
        $info->last_updated = $release['published_at'] ?? '';
        $info->requires = '5.0';
        $info->requires_php = '7.4';
        $info->tested = '6.5';

        return $info;
    }

    private function parse_changelog($body)
    {
        $body = sanitize_textarea_field($body);
        if ($body === '') {
            return 'Lihat changelog di <a href="https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO . '/releases" target="_blank">GitHub Releases</a>.';
        }
        return '<pre style="white-space:pre-wrap;">' . esc_html($body) . '</pre>';
    }

    public function fix_github_folder_name($source, $remote_source, $upgrader, $hook_extra)
    {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) {
            return $source;
        }

        $expected = self::GITHUB_REPO;
        $found = glob(trailingslashit($remote_source) . '*');
        if ($found && is_array($found) && isset($found[0])) {
            $actual = basename($found[0]);
            if ($actual !== $expected && strpos($actual, self::GITHUB_REPO) !== false) {
                $new_source = trailingslashit($remote_source) . $expected;
                if (rename($found[0], $new_source)) {
                    return $new_source;
                }
            }
        }

        return $source;
    }

    public function after_plugin_update($upgrader, $options)
    {
        if (!isset($options['type']) || $options['type'] !== 'plugin') {
            return;
        }

        if (!isset($options['plugins']) || !is_array($options['plugins'])) {
            return;
        }

        $plugin_file = plugin_basename(__FILE__);
        if (!in_array($plugin_file, $options['plugins'], true)) {
            return;
        }

        delete_transient('jdh_github_release_' . self::GITHUB_REPO);
        $this->reschedule_daily_event($this->get_options());
    }
}

$jdh_plugin = new JDH_Auto_SEO_Publisher();
register_activation_hook(__FILE__, [$jdh_plugin, 'activate']);
register_deactivation_hook(__FILE__, [$jdh_plugin, 'deactivate']);
