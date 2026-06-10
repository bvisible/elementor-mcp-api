<?php
/**
 * Minimal WordPress function/class stubs so the plugin's *pure-logic* classes
 * (Validator, Element_Factory) can be unit-tested with bare PHP — no full WP,
 * no PHPUnit, no composer. Only the functions actually reached by the code under
 * test are stubbed; anything WP-runtime-specific stays out of these tests.
 */

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;
        private $data;
        public function __construct($code = '', $message = '', $data = '') {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool {
        return $thing instanceof WP_Error;
    }
}

/**
 * Test-controllable uploads basedir. Tests set $GLOBALS['__test_upload_basedir'].
 */
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(): array {
        $base = $GLOBALS['__test_upload_basedir'] ?? sys_get_temp_dir();
        return [
            'basedir' => $base,
            'path'    => $base,
            'url'     => 'http://example.test/uploads',
        ];
    }
}

/**
 * ── Runtime stubs for the security suites (REST controller, Abilities, Data) ──
 * These let the v1.4.2 security fixes (validation ceilings on add_section, the
 * MAX_BODY_BYTES guards, and the unfiltered_html kses gate) be exercised without
 * a live WordPress. Behaviour-approximating only — real WP semantics are richer.
 */

// The Elementor_Data fallback save path references this constant directly.
if (!defined('ELEMENTOR_VERSION')) {
    define('ELEMENTOR_VERSION', '3.35.7');
}

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}

if (!class_exists('WP_REST_Request')) {
    /** Minimal request double: URL params via ArrayAccess, JSON body, raw body. */
    class WP_REST_Request implements ArrayAccess {
        private array $url_params;
        private array $json;
        private string $body;
        public function __construct(array $url_params = [], array $json = [], ?string $body = null) {
            $this->url_params = $url_params;
            $this->json       = $json;
            $this->body       = $body ?? (json_encode($json) ?: '');
        }
        public function get_body(): string { return $this->body; }
        public function get_json_params(): array { return $this->json; }
        #[\ReturnTypeWillChange]
        public function get_param($key) { return $this->json[$key] ?? ($this->url_params[$key] ?? null); }
        #[\ReturnTypeWillChange]
        public function offsetExists($offset) { return isset($this->url_params[$offset]); }
        #[\ReturnTypeWillChange]
        public function offsetGet($offset) { return $this->url_params[$offset] ?? null; }
        #[\ReturnTypeWillChange]
        public function offsetSet($offset, $value) { $this->url_params[$offset] = $value; }
        #[\ReturnTypeWillChange]
        public function offsetUnset($offset) { unset($this->url_params[$offset]); }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        private $data;
        private int $status;
        public function __construct($data = null, int $status = 200) {
            $this->data   = $data;
            $this->status = $status;
        }
        #[\ReturnTypeWillChange]
        public function get_data() { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}

/**
 * Capability gate controlled per-test: $GLOBALS['__test_caps']['unfiltered_html'] = false;
 * Unlisted capabilities default to GRANTED so unrelated guards stay out of the way.
 */
if (!function_exists('current_user_can')) {
    function current_user_can(string $cap, ...$args): bool {
        return $GLOBALS['__test_caps'][$cap] ?? true;
    }
}

/** In-memory post-meta store: $GLOBALS['__test_meta'][post_id][key] = value. */
if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false) {
        return $GLOBALS['__test_meta'][$post_id][$key] ?? '';
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $key, $value): bool {
        $GLOBALS['__test_meta'][$post_id][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $post_id, string $key): bool {
        unset($GLOBALS['__test_meta'][$post_id][$key]);
        return true;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('wp_slash')) {
    function wp_slash($value) { return $value; }
}

/**
 * Test approximation of wp_kses_post: strips <script> blocks/tags and inline
 * on* event-handler attributes, keeps benign markup. Enough to assert the
 * unfiltered_html gate's observable behaviour; NOT a kses reimplementation.
 */
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($content): string {
        $content = preg_replace('#<script\b[^>]*>.*?</script>#is', '', (string) $content);
        $content = preg_replace('#</?script\b[^>]*>#i', '', $content);
        $content = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $content);
        return $content;
    }
}

/** Ability registry collector: $GLOBALS['__test_abilities'][name] = definition. */
if (!function_exists('wp_register_ability')) {
    function wp_register_ability(string $name, array $definition): void {
        $GLOBALS['__test_abilities'][$name] = $definition;
    }
}
if (!function_exists('wp_register_ability_category')) {
    function wp_register_ability_category(string $name, array $definition): void {}
}

// Misc one-liners reached by the handlers under test.
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str): string { return trim(strip_tags((string) $str)); }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title): string {
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) $title), '-'));
    }
}
if (!function_exists('get_permalink')) {
    function get_permalink($post_id): string { return "http://example.test/?p=$post_id"; }
}
if (!function_exists('get_the_title')) {
    function get_the_title($post_id): string { return "Post $post_id"; }
}
if (!function_exists('wp_insert_post')) {
    /** Records each insert in $GLOBALS['__test_inserted_posts'] so tests can assert args. */
    function wp_insert_post(array $args) {
        static $next_id = 1000;
        $id = ++$next_id;
        $GLOBALS['__test_inserted_posts'][$id] = $args;
        return $id;
    }
}

/** In-memory post store for delete paths: $GLOBALS['__test_posts'][id] = (object). */
if (!function_exists('get_post')) {
    function get_post(int $post_id) {
        return $GLOBALS['__test_posts'][$post_id] ?? null;
    }
}
if (!function_exists('wp_trash_post')) {
    function wp_trash_post(int $post_id) {
        $GLOBALS['__test_trashed'][] = $post_id;
        return $GLOBALS['__test_posts'][$post_id] ?? false;
    }
}
if (!function_exists('wp_delete_post')) {
    function wp_delete_post(int $post_id, bool $force = false) {
        $GLOBALS['__test_deleted'][] = ['id' => $post_id, 'force' => $force];
        $post = $GLOBALS['__test_posts'][$post_id] ?? false;
        unset($GLOBALS['__test_posts'][$post_id]);
        return $post;
    }
}
if (!function_exists('get_option')) {
    function get_option(string $name, $default = false) {
        return $GLOBALS['__test_options'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $name, $value): bool {
        $GLOBALS['__test_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('wp_check_filetype')) {
    function wp_check_filetype(string $filename): array {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = [
            'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',  'gif'  => 'image/gif',
            'webp' => 'image/webp', 'svg'  => 'image/svg+xml',
            'avif' => 'image/avif', 'php'  => false, 'txt' => false,
        ];
        $type = $map[$ext] ?? false;
        return ['ext' => $type ? $ext : false, 'type' => $type ?: null];
    }
}
