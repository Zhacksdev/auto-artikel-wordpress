<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

define('ABSPATH', __DIR__ . '/wp-stub/');
define('OBJECT', 'OBJECT');

class WP_Error
{
    private $message;

    public function __construct($message = 'WordPress error')
    {
        $this->message = (string) $message;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

class WP_Post
{
    public $ID;
    public $post_title;
    public $post_excerpt;
    public $post_content;
    public $post_author;
    public $post_status;

    public function __construct($id, $title = '', $content = '', $author = 1, $status = 'publish')
    {
        $this->ID = (int) $id;
        $this->post_title = (string) $title;
        $this->post_excerpt = '';
        $this->post_content = (string) $content;
        $this->post_author = (int) $author;
        $this->post_status = (string) $status;
    }
}

class WP_Term
{
    public $term_id;
    public $name;
    public $slug;

    public function __construct($id, $name, $slug)
    {
        $this->term_id = (int) $id;
        $this->name = (string) $name;
        $this->slug = (string) $slug;
    }
}

$GLOBALS['jdh_test_options'] = [];
$GLOBALS['jdh_test_meta'] = [];
$GLOBALS['jdh_test_posts'] = [];
$GLOBALS['jdh_test_current_post'] = null;
$GLOBALS['jdh_test_cron'] = 0;
$GLOBALS['jdh_test_post_id'] = 900;
$GLOBALS['jdh_test_throw_router'] = false;
$GLOBALS['jdh_test_images_valid'] = true;
$GLOBALS['jdh_test_thumbnail_success'] = true;
$GLOBALS['jdh_test_thumbnails'] = [];
$GLOBALS['jdh_test_requested_models'] = [];

function is_admin() { return false; }
function add_action() { return true; }
function add_filter() { return true; }
function register_activation_hook() { return true; }
function register_deactivation_hook() { return true; }
function register_setting() { return true; }
function add_settings_section() { return true; }
function add_settings_field() { return true; }
function __return_null() { return null; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, is_array($args) ? $args : []); }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['jdh_test_options']) ? $GLOBALS['jdh_test_options'][$key] : $default; }
function update_option($key, $value) { $GLOBALS['jdh_test_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['jdh_test_options'][$key]); return true; }
function wp_clear_scheduled_hook() { $GLOBALS['jdh_test_cron'] = 0; return 1; }
function wp_schedule_event($timestamp) { $GLOBALS['jdh_test_cron'] = (int) $timestamp; return true; }
function wp_next_scheduled() { return $GLOBALS['jdh_test_cron'] ?: false; }
function wp_timezone() { return new DateTimeZone('Asia/Jakarta'); }
function wp_date($format, $timestamp) { return (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone(wp_timezone())->format($format); }
function current_time($format) { return $format === 'mysql' ? '2026-07-11 12:00:00' : (new DateTimeImmutable('2026-07-11 12:00:00', wp_timezone()))->format($format); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_title($value) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $value)), '-'); }
function sanitize_file_name($value) { return basename((string) $value); }
function esc_url_raw($value) { return filter_var((string) $value, FILTER_VALIDATE_URL) ? (string) $value : ''; }
function esc_url($value) { return esc_url_raw($value); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function absint($value) { return abs((int) $value); }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function plugin_dir_path($file) { return dirname($file) . DIRECTORY_SEPARATOR; }
function wp_generate_password() { return 'abc123'; }
function get_bloginfo($key) { return $key === 'name' ? 'Demo Site' : ''; }
function home_url($path = '/') { return 'https://example.test/' . ltrim((string) $path, '/'); }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim((string) $path, '/'); }
function get_current_user_id() { return 1; }
function wp_trim_words($text, $limit, $more = '') {
    $words = preg_split('/\s+/', trim(strip_tags((string) $text)));
    $words = is_array($words) ? $words : [];
    return implode(' ', array_slice($words, 0, (int) $limit)) . (count($words) > $limit ? $more : '');
}
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function remove_accents($text) {
    $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $text);
    return is_string($converted) ? $converted : (string) $text;
}

function jdh_fake_section($heading, $lead, $repeat = 11) {
    $filler = trim(str_repeat(' penjelasan praktis relevan terarah membantu pembaca memahami langkah dan pertimbangan bangunan', (int) $repeat));
    return '<h2>' . $heading . '</h2><p>' . $lead . $filler . '.</p><p>Contoh penerapan' . $filler . '.</p>';
}

function jdh_openai_response($payload) {
    return [
        'code' => 200,
        'body' => json_encode(['choices' => [['message' => ['content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]]]),
    ];
}

function wp_remote_post($url, $args = []) {
    if (!empty($GLOBALS['jdh_test_throw_router'])) {
        throw new RuntimeException('Simulated router exception');
    }
    $request = json_decode((string) ($args['body'] ?? ''), true);
    if (is_array($request) && isset($request['model'])) {
        $GLOBALS['jdh_test_requested_models'][] = (string) $request['model'];
    }
    if (strpos((string) $url, '/images/generations') !== false) {
        return ['code' => 200, 'body' => json_encode(['data' => [['url' => 'https://images.example.test/acp.png']]])];
    }

    $prompt = is_array($request) ? (string) ($request['messages'][1]['content'] ?? '') : '';
    if (strpos($prompt, 'TAHAP 1 DARI 5') !== false) {
        return jdh_openai_response([
            'status' => 'ready',
            'intent' => 'commercial',
            'angle' => 'panduan profesional untuk pemilik bangunan',
            'title' => 'ACP Indonesia: Panduan Profesional untuk Fasad Bangunan',
            'slug' => 'acp-indonesia-panduan-fasad',
            'excerpt' => 'Panduan praktis untuk merencanakan penggunaan ACP pada fasad bangunan.',
            'meta_description' => 'Pelajari penggunaan ACP Indonesia untuk fasad bangunan, dari perencanaan dan pemilihan material sampai pemeriksaan hasil pemasangan.',
            'category' => 'Konstruksi',
            'tags' => ['acp indonesia', 'fasad bangunan', 'material bangunan'],
            'warnings' => [],
        ]);
    }
    if (strpos($prompt, 'TAHAP 2 DARI 5') !== false) {
        $sections = [];
        for ($i = 1; $i <= 6; $i++) {
            $sections[] = ['heading' => "Pembahasan ACP Indonesia Bagian $i", 'purpose' => "Tujuan $i", 'key_points' => ['poin'], 'example' => 'contoh'];
        }
        return jdh_openai_response([
            'intro_strategy' => 'jawaban langsung',
            'related_terms' => ['fasad', 'panel'],
            'sections' => $sections,
            'faq_questions' => ['Apa itu ACP?', 'Bagaimana memilih ACP?', 'Bagaimana memasang ACP?', 'Bagaimana memeriksa hasil ACP?'],
            'warnings' => [],
        ]);
    }
    if (strpos($prompt, 'TAHAP 3 DARI 5') !== false) {
        $html = '<p>ACP Indonesia dapat dipakai sebagai bagian dari rancangan fasad ketika kebutuhan teknis dan proses pemasangan dianalisis sejak awal.</p>';
        $html .= '<p>Panduan ini berfokus pada keputusan praktis yang mengikuti kondisi bangunan.</p>';
        $html .= jdh_fake_section('Pembahasan ACP Indonesia Bagian 1', 'ACP Indonesia', 13);
        $html .= jdh_fake_section('Pembahasan ACP Indonesia Bagian 2', 'Material panel', 13);
        return jdh_openai_response(['content_html' => $html]);
    }
    if (strpos($prompt, 'TAHAP 4 DARI 5') !== false) {
        $html = jdh_fake_section('Pembahasan ACP Indonesia Bagian 3', 'Perencanaan fasad', 11);
        $html .= jdh_fake_section('Pembahasan ACP Indonesia Bagian 4', 'ACP Indonesia', 11);
        $html .= jdh_fake_section('Pembahasan ACP Indonesia Bagian 5', 'Pemeriksaan hasil', 11);
        return jdh_openai_response(['content_html' => $html]);
    }
    if (strpos($prompt, 'PERBAIKI PANJANG Batch 1') !== false) {
        $html = jdh_fake_section('Pembahasan ACP Indonesia Bagian 1', 'ACP Indonesia', 14);
        $html .= jdh_fake_section('Pembahasan ACP Indonesia Bagian 2', 'Material panel', 14);
        return jdh_openai_response(['content_html' => $html]);
    }

    $html = jdh_fake_section('Pembahasan ACP Indonesia Bagian 6', 'ACP Indonesia', 9);
    $html .= jdh_fake_section('Kesimpulan', 'Langkah berikutnya', 9);
    $faq = [];
    for ($i = 1; $i <= 4; $i++) {
        $faq[] = [
            'question' => "Apa hal penting tentang ACP Indonesia nomor $i?",
            'answer' => trim(str_repeat('Jawaban ini menjelaskan pilihan praktis secara jelas dan menyesuaikan kondisi bangunan serta kebutuhan pengguna. ', 3)),
        ];
    }
    return jdh_openai_response([
        'status' => 'ready',
        'content_html' => $html,
        'faq' => $faq,
        'image' => [
            'source_type' => 'auto',
            'prompt' => 'realistic editorial photograph of workers inspecting modern ACP facade panels on an Indonesian commercial building, daylight, landscape',
            'alt' => 'Pekerja memeriksa panel ACP pada fasad bangunan',
            'caption' => 'Pemeriksaan fasad ACP setelah pemasangan.',
            'url' => '',
            'watermark_required' => true,
        ],
        'seo_score' => 90,
        'quality_score' => 92,
        'duplicate_risk' => 'low',
        'warnings' => [],
    ]);
}

function wp_remote_get($url, $args = []) {
    if (substr((string) $url, -13) === '/models/image') {
        return ['code' => 200, 'body' => json_encode(['data' => [['id' => 'test-image-model']]])];
    }
    if (substr((string) $url, -7) === '/models') {
        return ['code' => 200, 'body' => json_encode(['data' => [['id' => 'Artikel']]])];
    }
    return ['code' => 200, 'body' => '{}'];
}
function wp_remote_retrieve_response_code($response) { return is_array($response) ? (int) ($response['code'] ?? 0) : 0; }
function wp_remote_retrieve_body($response) { return is_array($response) ? (string) ($response['body'] ?? '') : ''; }

function get_posts($args = []) { return []; }
function get_page_by_path() { return null; }
function get_post($id = null) {
    if ($id !== null && isset($GLOBALS['jdh_test_posts'][(int) $id])) {
        return $GLOBALS['jdh_test_posts'][(int) $id];
    }
    return $GLOBALS['jdh_test_current_post'];
}
function get_the_title($post = 0) {
    if ($post instanceof WP_Post) { return $post->post_title; }
    $id = (int) $post;
    return isset($GLOBALS['jdh_test_posts'][$id]) ? $GLOBALS['jdh_test_posts'][$id]->post_title : 'Artikel Demo';
}
function get_permalink($post = 0) { $id = $post instanceof WP_Post ? $post->ID : (int) $post; return 'https://example.test/post-' . $id . '/'; }
function get_edit_post_link($post_id) { return 'https://example.test/wp-admin/post.php?post=' . (int) $post_id; }
function wp_insert_post($data, $wp_error = false) {
    $id = ++$GLOBALS['jdh_test_post_id'];
    $GLOBALS['jdh_test_posts'][$id] = new WP_Post($id, $data['post_title'] ?? '', $data['post_content'] ?? '', $data['post_author'] ?? 1, $data['post_status'] ?? 'draft');
    $GLOBALS['jdh_test_current_post'] = $GLOBALS['jdh_test_posts'][$id];
    return $id;
}
function get_categories() { return [new WP_Term(7, 'Konstruksi', 'konstruksi')]; }
function wp_insert_term($name) { return ['term_id' => 8, 'term_taxonomy_id' => 8]; }
function wp_set_post_tags() { return []; }
function update_post_meta($post_id, $key, $value) { $GLOBALS['jdh_test_meta'][(int) $post_id][$key] = $value; return true; }
function get_post_meta($post_id, $key, $single = false) { return $GLOBALS['jdh_test_meta'][(int) $post_id][$key] ?? ''; }
function set_post_thumbnail($post_id, $image_id) {
    if (empty($GLOBALS['jdh_test_thumbnail_success'])) {
        return false;
    }
    $GLOBALS['jdh_test_thumbnails'][(int) $post_id] = (int) $image_id;
    return true;
}
function wp_get_attachment_image($id, $size, $icon, $attr = []) { return '<img src="https://images.example.test/' . (int) $id . '.png" alt="' . esc_attr($attr['alt'] ?? '') . '">'; }
function media_sideload_image() { return 501; }
function get_attached_file($id) { return $GLOBALS['jdh_test_meta'][(int) $id]['_file'] ?? ''; }
function get_post_mime_type() { return 'image/png'; }
function wp_upload_bits($name, $deprecated, $bits) {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . sanitize_file_name($name);
    file_put_contents($file, $bits);
    return ['file' => $file, 'url' => 'https://example.test/uploads/' . basename($file), 'type' => 'image/png', 'error' => false];
}
function wp_check_filetype() { return ['ext' => 'png', 'type' => 'image/png']; }
function wp_insert_attachment() { return 601; }
function wp_generate_attachment_metadata() { return ['width' => 1, 'height' => 1]; }
function wp_update_attachment_metadata() { return true; }
function wp_update_post($data, $wp_error = false) {
    $id = (int) ($data['ID'] ?? 0);
    if (!$id || !isset($GLOBALS['jdh_test_posts'][$id])) {
        return $wp_error ? new WP_Error('Post not found') : 0;
    }
    if (isset($data['post_status'])) {
        $GLOBALS['jdh_test_posts'][$id]->post_status = (string) $data['post_status'];
    }
    return $id;
}
function wp_delete_post($post_id) {
    unset($GLOBALS['jdh_test_posts'][(int) $post_id], $GLOBALS['jdh_test_thumbnails'][(int) $post_id]);
    return true;
}
function wp_get_attachment_url($id) { return $id ? 'https://images.example.test/' . (int) $id . '.png' : false; }
function wp_attachment_is_image($id) { return !empty($GLOBALS['jdh_test_images_valid']) && (int) $id > 0; }
function get_post_thumbnail_id($post_id = 0) { return $GLOBALS['jdh_test_thumbnails'][(int) $post_id] ?? 0; }
function wp_kses_allowed_html() { return ['p' => [], 'h2' => [], 'h3' => [], 'a' => ['href' => true, 'target' => true, 'rel' => true], 'ul' => [], 'li' => [], 'div' => ['class' => true], 'figure' => ['class' => true], 'img' => ['src' => true, 'alt' => true, 'class' => true]]; }
function wp_kses($content) { return (string) $content; }
function is_single() { return true; }
function get_post_type() { return 'post'; }
function get_the_author_meta($field, $user_id = false) { return 'Admin Demo'; }
function get_the_date() { return '2026-07-11T12:00:00+07:00'; }
function get_the_modified_date() { return '2026-07-11T12:00:00+07:00'; }
function wp_get_post_tags() { return ['acp indonesia', 'fasad']; }

require dirname(__DIR__) . '/jdh-auto-seo-publisher.php';

function jdh_assert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$reflection = new ReflectionClass('JDH_Auto_SEO_Publisher');
$plugin = $reflection->newInstanceWithoutConstructor();
$invoke = static function ($method_name, array $args = []) use ($reflection, $plugin) {
    return $reflection->getMethod($method_name)->invokeArgs($plugin, $args);
};

$default_options = $invoke('default_options');
$options = array_merge($default_options, [
    'router_enabled' => '1',
    'writing_router_endpoint' => 'https://router.example.test/v1',
    'router_api_key' => 'test-key',
    'router_model' => 'Artikel',
    'router_image_model' => 'test-image-model',
    'site_niche' => 'jasa fasad bangunan',
    'target_audience' => 'pemilik bangunan',
    'target_keywords' => 'ACP Indonesia',
    'forbidden_topics' => "SARA\npolitik",
    'watermark_enabled' => '',
    'pexels_api_key' => '',
    'use_media_image' => '',
    'auto_index_google' => '1',
]);

jdh_assert($default_options['router_model'] === 'Artikel', 'Default writing combo must be Artikel.');
$GLOBALS['jdh_test_options'][JDH_Auto_SEO_Publisher::OPTION_KEY] = ['router_model' => 'gemini/gemini-3.1-flash-lite-preview'];
$migrated_options = $invoke('get_options');
jdh_assert($migrated_options['router_model'] === 'Artikel', 'Legacy provider model was not migrated to Artikel combo.');

$malformed = [
    'router_api_mode' => ['invalid'],
    'writing_router_endpoint' => ['invalid'],
    'router_model' => ['invalid'],
    'articles_per_day' => ['invalid'],
    'daily_run_time' => '99:99',
    'target_keywords' => ['invalid'],
];
$clean = $invoke('sanitize_options', [$malformed]);
jdh_assert($clean['router_api_mode'] === 'openai_compatible', 'Malformed router mode was not normalized.');
jdh_assert($clean['daily_run_time'] === '09:00', 'Invalid daily time was not normalized.');
jdh_assert($clean['articles_per_day'] === 1, 'Malformed article count was not normalized.');
jdh_assert($invoke('build_image_source_priority', [array_merge($options, ['use_media_image' => '', 'pexels_enabled' => ''])]) === ['image_router'], 'Unchecked image sources must default to Image Router only.');
jdh_assert($invoke('build_image_source_priority', [array_merge($options, ['use_media_image' => '1', 'pexels_enabled' => '1'])]) === ['media_library', 'pexels', 'image_router'], 'Checked image sources must preserve explicit image priority.');

$strategy_logs = [];
$strategy_request = [
    'keyword' => 'ACP Bandung',
    'site' => [
        'niche' => 'jasa fasad bangunan',
        'target_audience' => 'pemilik bangunan',
    ],
];
$aliased_strategy = $invoke('normalize_strategy_response', [[
    'status' => 'ready',
    'judul' => 'ACP Bandung untuk Fasad Bangunan Modern',
    'sudut_pandang' => 'kesalahan umum pemilihan material',
    'meta_deskripsi' => 'Panduan memilih ACP Bandung untuk fasad bangunan agar hasil lebih rapi, relevan, dan sesuai kebutuhan pemilik bangunan.',
    'kategori' => 'Konstruksi',
], $strategy_request, &$strategy_logs]);
jdh_assert($aliased_strategy['title'] === 'ACP Bandung untuk Fasad Bangunan Modern', 'Aliased strategy title was not normalized.');
jdh_assert($aliased_strategy['angle'] === 'kesalahan umum pemilihan material', 'Aliased strategy angle was not normalized.');
jdh_assert($aliased_strategy['meta_description'] !== '', 'Aliased strategy meta description was not normalized.');

$short_batch_html = jdh_fake_section('Pembahasan ACP Indonesia Bagian 1', 'ACP Indonesia', 2)
    . jdh_fake_section('Pembahasan ACP Indonesia Bagian 2', 'Material panel', 2);
$repair_logs = [];
$repair_sections = [
    ['heading' => 'Pembahasan ACP Indonesia Bagian 1'],
    ['heading' => 'Pembahasan ACP Indonesia Bagian 2'],
];
$repair_args = [$options, &$repair_logs, '3/5 repair batch 1', $strategy_request, $aliased_strategy, $repair_sections, $short_batch_html, 430, 'Batch 1'];
$repaired_batch_html = $reflection->getMethod('repair_stage_html_if_needed')->invokeArgs($plugin, $repair_args);
jdh_assert($invoke('count_words', [$repaired_batch_html]) > $invoke('count_words', [$short_batch_html]), 'Short batch was not expanded.');
jdh_assert($invoke('validate_stage_html', [$repaired_batch_html, $repair_sections, 400, 'Batch 1 repair test', &$repair_logs]) === true, 'Expanded short batch did not pass validation.');

$array_content_body = json_encode(['choices' => [['message' => ['content' => [['type' => 'text', 'text' => '{"ok":true}']]]]]]);
jdh_assert($invoke('extract_openai_compatible_content', [$array_content_body]) === '{"ok":true}', 'Array message content extraction failed.');
$reasoning_content_body = json_encode(['choices' => [['message' => ['content' => '', 'reasoning_content' => '{"ok":true}']]]]);
jdh_assert($invoke('extract_openai_compatible_content', [$reasoning_content_body]) === '{"ok":true}', 'Reasoning content JSON extraction failed.');
$sse_body = "data: {\"choices\":[{\"delta\":{\"content\":[{\"text\":\"{\\\"ok\\\":\"}]}}]}\n"
    . "data: {\"choices\":[{\"delta\":{\"content\":[{\"text\":\"true}\"}]}}]}\n"
    . "data: [DONE]\n";
jdh_assert($invoke('extract_openai_compatible_content', [$sse_body]) === '{"ok":true}', 'SSE message content extraction failed.');

$invalid_article = ['title' => ['not-scalar']];
$invalid_result = $invoke('validate_article_payload', [$invalid_article, $options]);
jdh_assert(empty($invalid_result['valid']), 'Malformed article payload should be rejected.');

$GLOBALS['jdh_test_options'][JDH_Auto_SEO_Publisher::KEYWORD_STATE_KEY] = ['acp indonesia' => 'corrupt'];
$work_item = $invoke('get_next_work_item', [$options, true]);
jdh_assert(is_array($work_item) && $work_item['angle_number'] === 1, 'Corrupt keyword state was not recovered.');

$logs = [];
$work = ['keyword' => 'ACP Indonesia', 'angle_number' => 1];
$pipeline_args = [$work, $options, &$logs, 1, ''];
$article = $reflection->getMethod('call_openai_compatible_writing_router')->invokeArgs($plugin, $pipeline_args);
jdh_assert(is_array($article), 'Five-stage router pipeline returned false.');
$valid_result = $invoke('validate_article_payload', [$article, $options]);
jdh_assert(!empty($valid_result['valid']), 'Assembled article failed validation: ' . ($valid_result['message'] ?? 'unknown'));
$low_self_score_article = array_merge($article, ['quality_score' => 70, 'seo_score' => 70]);
$low_self_score_result = $invoke('validate_article_payload', [$low_self_score_article, $options]);
jdh_assert(!empty($low_self_score_result['valid']), 'Objectively valid article should not fail on low router self-score.');
jdh_assert(substr_count(strtolower($article['content_html']), '<h2') === 8, 'Assembled article must contain 8 H2 headings.');
jdh_assert(count($article['faq']) === 4, 'Assembled article must contain 4 FAQ items.');

$health_logs = [];
$health_args = ['https://router.example.test/v1', ['Content-Type' => 'application/json'], $options, &$health_logs];
jdh_assert($reflection->getMethod('test_openai_compatible_router')->invokeArgs($plugin, $health_args) === true, 'Combo health check failed.');
jdh_assert(strpos(implode(' ', $health_logs), "Combo artikel 'Artikel'") !== false, 'Health check did not validate the Artikel combo ID.');

$bad_b64_logs = [];
$bad_b64_args = ['%%%not-base64%%%', 'bad.png', 'Bad image', &$bad_b64_logs];
jdh_assert($reflection->getMethod('save_base64_image_to_media')->invokeArgs($plugin, $bad_b64_args) === 0, 'Invalid base64 should be rejected.');

$tiny_image = imagecreatetruecolor(2, 2);
ob_start();
imagepng($tiny_image);
$tiny_png = ob_get_clean();
$valid_b64_logs = [];
$valid_filename = 'jdh-valid-base64-' . getmypid() . '.png';
$valid_b64_args = [base64_encode($tiny_png), $valid_filename, 'Valid image', &$valid_b64_logs];
jdh_assert($reflection->getMethod('save_base64_image_to_media')->invokeArgs($plugin, $valid_b64_args) === 601, 'Valid base64 image upload failed.');
$valid_upload_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $valid_filename;
if (is_file($valid_upload_path)) {
    unlink($valid_upload_path);
}

$pexels_logs = [];
$pexels_options = array_merge($options, ['pexels_api_key' => 'test-pexels-key']);
$pexels_args = ['ACP facade', 'ACP Indonesia', $pexels_options, &$pexels_logs];
jdh_assert($reflection->getMethod('fetch_image_from_pexels')->invokeArgs($plugin, $pexels_args) === 0, 'Malformed Pexels response should be skipped safely.');

if (!function_exists('imagecreatetruecolor')) {
    throw new RuntimeException('GD extension is required for watermark smoke test.');
}
$watermark_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jdh-watermark-test-' . getmypid() . '.png';
$canvas = imagecreatetruecolor(1800, 1200);
$background = imagecolorallocate($canvas, 240, 240, 240);
imagefill($canvas, 0, 0, $background);
imagepng($canvas, $watermark_file);
jdh_assert($invoke('apply_text_watermark', [$watermark_file, 'image/png', 'Demo Site Watermark Panjang']) === true, 'Large-image watermark failed.');
unlink($watermark_file);

$corrupt_image_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jdh-corrupt-image-' . getmypid() . '.png';
file_put_contents($corrupt_image_file, 'not-an-image');
jdh_assert($invoke('apply_text_watermark', [$corrupt_image_file, 'image/png', 'Demo Site']) === false, 'Corrupt image should be rejected without emitting a warning.');
unlink($corrupt_image_file);

$publish_logs = [];
$post_counter_before_invalid = $GLOBALS['jdh_test_post_id'];
$invalid_publish_args = [$article, $work, $options, 0, &$publish_logs];
$invalid_publish = $reflection->getMethod('publish_article')->invokeArgs($plugin, $invalid_publish_args);
jdh_assert($invalid_publish instanceof WP_Error, 'Publish without featured image must be rejected.');
jdh_assert($GLOBALS['jdh_test_post_id'] === $post_counter_before_invalid, 'Invalid image must be rejected before creating a post.');

$posts_before_thumbnail_failure = count($GLOBALS['jdh_test_posts']);
$GLOBALS['jdh_test_thumbnail_success'] = false;
$failed_thumbnail_args = [$article, $work, $options, 501, &$publish_logs];
$failed_thumbnail_publish = $reflection->getMethod('publish_article')->invokeArgs($plugin, $failed_thumbnail_args);
$GLOBALS['jdh_test_thumbnail_success'] = true;
jdh_assert($failed_thumbnail_publish instanceof WP_Error, 'Thumbnail attachment failure must block publish.');
jdh_assert(count($GLOBALS['jdh_test_posts']) === $posts_before_thumbnail_failure, 'Temporary draft must be removed after thumbnail failure.');

$publish_args = [$article, $work, $options, 501, &$publish_logs];
$published_id = $reflection->getMethod('publish_article')->invokeArgs($plugin, $publish_args);
jdh_assert(is_int($published_id) && $published_id > 0, 'Publish flow failed.');
jdh_assert(($GLOBALS['jdh_test_posts'][$published_id]->post_status ?? '') === 'publish', 'Post was not promoted from draft to publish.');
jdh_assert(get_post_thumbnail_id($published_id) === 501, 'Featured image was not attached to published post.');

ob_start();
$plugin->add_seo_meta_tags();
$seo_output = ob_get_clean();
jdh_assert(strpos($seo_output, 'application/ld+json') !== false, 'SEO metadata output failed.');

$GLOBALS['jdh_test_options'][JDH_Auto_SEO_Publisher::JOBS_KEY] = [];
$GLOBALS['jdh_test_options'][JDH_Auto_SEO_Publisher::KEYWORD_STATE_KEY] = [];
$safe_job = $invoke('create_job', ['ACP Indonesia', 1]);
$GLOBALS['jdh_test_throw_router'] = true;
$safe_logs = [];
$safe_args = [$safe_job, $work, $options, &$safe_logs];
$safe_result = $reflection->getMethod('process_article_job_safely')->invokeArgs($plugin, $safe_args);
$GLOBALS['jdh_test_throw_router'] = false;
jdh_assert(empty($safe_result['success']) && strpos($safe_result['message'], 'Proses dihentikan aman') === 0, 'Job exception was not contained safely.');

$GLOBALS['jdh_test_options'][JDH_Auto_SEO_Publisher::JOBS_KEY] = [];
$GLOBALS['jdh_test_options'][JDH_Auto_SEO_Publisher::KEYWORD_STATE_KEY] = [];
$image_failure_job = $invoke('create_job', ['ACP Indonesia', 1]);
$post_counter_before_image_failure = $GLOBALS['jdh_test_post_id'];
$GLOBALS['jdh_test_images_valid'] = false;
$image_failure_logs = [];
$image_failure_args = [$image_failure_job, $work, $options, &$image_failure_logs];
$image_failure_result = $reflection->getMethod('process_article_job_safely')->invokeArgs($plugin, $image_failure_args);
$GLOBALS['jdh_test_images_valid'] = true;
jdh_assert(empty($image_failure_result['success']) && strpos($image_failure_result['message'], 'Featured image wajib') === 0, 'Image source failure must block article publication.');
jdh_assert($GLOBALS['jdh_test_post_id'] === $post_counter_before_image_failure, 'Article post must not be created when all image sources fail.');

$GLOBALS['jdh_test_options'][JDH_Auto_SEO_Publisher::OPTION_KEY] = $options;
$GLOBALS['jdh_test_options'][JDH_Auto_SEO_Publisher::KEYWORD_STATE_KEY] = [];
$GLOBALS['jdh_test_options'][JDH_Auto_SEO_Publisher::JOBS_KEY] = [];
$cron_logs = [];
$plugin->run_daily_task($cron_logs);
$jobs = $GLOBALS['jdh_test_options'][JDH_Auto_SEO_Publisher::JOBS_KEY];
jdh_assert(count($jobs) === 1 && ($jobs[0]['status'] ?? '') === 'done', 'Cron end-to-end job did not finish.');
jdh_assert(!empty($jobs[0]['post_id']), 'Cron end-to-end job did not publish a post.');
jdh_assert(get_post_thumbnail_id($jobs[0]['post_id']) > 0, 'Cron published a post without featured image.');
jdh_assert(in_array('Artikel', $GLOBALS['jdh_test_requested_models'], true), 'Writing requests did not send the Artikel combo ID.');

$GLOBALS['jdh_test_options'][JDH_Auto_SEO_Publisher::LOCK_KEY] = 1700000000;
$legacy_lock = $invoke('get_lock_info');
jdh_assert(($legacy_lock['time'] ?? 0) === 1700000000, 'Legacy integer lock was not read as lock info.');
jdh_assert(($legacy_lock['stage'] ?? '') === 'unknown', 'Legacy lock info missing default stage.');

$GLOBALS['jdh_test_options'][JDH_Auto_SEO_Publisher::LOCK_KEY] = ['time' => 1700000000, 'stage' => 'publishing', 'job_id' => 'jdh_test', 'keyword' => 'ACP Indonesia', 'angle' => 2, 'started' => '2026-07-11 12:00:00'];
$array_lock = $invoke('get_lock_info');
jdh_assert(($array_lock['stage'] ?? '') === 'publishing' && ($array_lock['job_id'] ?? '') === 'jdh_test', 'Array lock info was not preserved.');

$GLOBALS['jdh_test_options'][JDH_Auto_SEO_Publisher::LOCK_KEY] = 0;
jdh_assert($invoke('acquire_lock') === true, 'Acquiring a free lock must succeed.');
$acquired_lock = $invoke('get_lock_info');
jdh_assert(is_array($acquired_lock) && ($acquired_lock['stage'] ?? '') === 'locked' && ($acquired_lock['time'] ?? 0) > 0, 'Acquired lock must store stage metadata as an array.');
jdh_assert($invoke('acquire_lock') === false, 'Acquiring an already-held lock must fail.');

$invoke('update_lock_stage', ['image_processing', 'jdh_x', 'KW', 1]);
$updated_lock = $invoke('get_lock_info');
jdh_assert(($updated_lock['stage'] ?? '') === 'image_processing' && ($updated_lock['job_id'] ?? '') === 'jdh_x', 'update_lock_stage did not update metadata.');
$invoke('release_lock');
jdh_assert(($invoke('get_lock_info')['time'] ?? 0) === 0, 'release_lock did not clear the lock.');

echo 'PASS runtime-smoke version=' . JDH_Auto_SEO_Publisher::VERSION
    . ' words=' . $invoke('count_words', [$article['content_html']])
    . ' h2=8 faq=4 featured=required cron=done warnings=0 lock=ok' . PHP_EOL;
