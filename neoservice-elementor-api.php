<?php
/**
 * Plugin Name: NeoService Elementor API
 * Description: REST API + MCP tools for AI-driven Elementor page building. Exposes endpoints to create, read, update pages, elements, templates, and global settings programmatically. Compatible with WordPress MCP Adapter.
 * Version: 1.4.4
 * Author: NeoService
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-3.0
 */

if (!defined('ABSPATH')) exit;

// PHP 7.4 polyfill — the plugin declares "Requires PHP: 7.4" but the widget
// discovery code uses str_starts_with() (PHP 8.0+). Provide it on older runtimes
// so the declared floor is real rather than a latent fatal.
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

define('NEOSERVICE_ELEMENTOR_API_VERSION', '1.4.4');
define('NEOSERVICE_ELEMENTOR_API_PATH', plugin_dir_path(__FILE__));

// Load core includes
require_once NEOSERVICE_ELEMENTOR_API_PATH . 'includes/class-validator.php';
require_once NEOSERVICE_ELEMENTOR_API_PATH . 'includes/class-element-factory.php';
require_once NEOSERVICE_ELEMENTOR_API_PATH . 'includes/class-elementor-data.php';
require_once NEOSERVICE_ELEMENTOR_API_PATH . 'includes/class-rest-controller.php';

// ── REST API ────────────────────────────────────────────────
add_action('rest_api_init', function () {
    if (!did_action('elementor/loaded')) return;

    $controller = new NeoService\ElementorAPI\REST_Controller();
    $controller->register_routes();
});

// ── MCP Abilities (auto-detected if Abilities API is active) ─
// These hooks only fire if the Abilities API plugin is active.
// No conditional check needed — WordPress ignores hooks for actions that never fire.
add_action('wp_abilities_api_categories_init', function () {
    if (!did_action('elementor/loaded')) return;

    require_once NEOSERVICE_ELEMENTOR_API_PATH . 'includes/class-abilities-provider.php';
    NeoService\ElementorAPI\Abilities_Provider::register_category();
});

add_action('wp_abilities_api_init', function () {
    if (!did_action('elementor/loaded')) return;

    if (!class_exists('NeoService\\ElementorAPI\\Abilities_Provider')) {
        require_once NEOSERVICE_ELEMENTOR_API_PATH . 'includes/class-abilities-provider.php';
    }
    NeoService\ElementorAPI\Abilities_Provider::register();
});

// ── Post Meta Registration ──────────────────────────────────
add_action('init', function () {
    register_post_meta('page', '_elementor_data', [
        'show_in_rest' => true,
        'single' => true,
        'type' => 'string',
        // `_elementor_data` is the raw page layout (and can carry inline HTML/scripts).
        // Writing it directly via the core meta REST endpoint is site-global-sensitive,
        // so require administrator capability here. Per-page editing goes through this
        // plugin's own routes, which apply per-post checks.
        'auth_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);
});
