<?php
namespace NeoService\ElementorAPI;

/**
 * Elementor Data Manager - handles reading/writing Elementor page data.
 * Uses Elementor's native PHP APIs when available, with direct meta fallback.
 * Inspired by msrbuilds/elementor-mcp dual-path save pattern.
 */
class Elementor_Data {

    /**
     * Get the Elementor element tree for a page.
     *
     * @return array|null Element tree or null if not an Elementor page.
     */
    public static function get_page_data(int $post_id): ?array {
        // Try native Elementor API first
        if (class_exists('\Elementor\Plugin')) {
            $document = \Elementor\Plugin::$instance->documents->get($post_id);
            if ($document) {
                $data = $document->get_elements_data();
                if (!empty($data)) return $data;
            }
        }

        // Fallback: read from post meta
        $raw = get_post_meta($post_id, '_elementor_data', true);
        if (empty($raw)) return null;

        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($data) ? $data : null;
    }

    /**
     * Save Elementor data for a page.
     * Tries native Elementor save first, falls back to direct meta write.
     *
     * @return bool Success.
     */
    public static function save_page_data(int $post_id, array $data): bool {
        // Ensure Elementor meta flags are set
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', 'wp-page');
        update_post_meta($post_id, '_wp_page_template', 'elementor_header_footer');

        // Try native Elementor save (handles CSS regeneration)
        if (class_exists('\Elementor\Plugin')) {
            $document = \Elementor\Plugin::$instance->documents->get($post_id);
            if ($document) {
                $document->save(['elements' => $data]);
                return true;
            }
        }

        // Fallback: direct meta write
        $json = wp_slash(wp_json_encode($data));
        update_post_meta($post_id, '_elementor_data', $json);
        update_post_meta($post_id, '_elementor_version', self::elementor_version());

        // Manual CSS cache invalidation
        self::flush_css($post_id);

        return true;
    }

    /**
     * Get a compact page structure (IDs, types, widget types).
     */
    public static function get_page_structure(int $post_id): ?array {
        $data = self::get_page_data($post_id);
        if (!$data) return null;

        return array_map([self::class, 'summarize_element'], $data);
    }

    /**
     * Summarize an element to its essential structure.
     */
    private static function summarize_element(array $el): array {
        $summary = [
            'id'     => $el['id'] ?? '',
            'elType' => $el['elType'] ?? '',
        ];
        if (!empty($el['widgetType'])) {
            $summary['widgetType'] = $el['widgetType'];
        }
        if (!empty($el['isInner'])) {
            $summary['isInner'] = true;
        }

        // Include key settings for identification
        $settings = $el['settings'] ?? [];
        $identifiers = [];
        if (!empty($settings['title'])) $identifiers['title'] = mb_substr($settings['title'], 0, 50);
        if (!empty($settings['editor'])) $identifiers['text'] = mb_substr(wp_strip_all_tags($settings['editor']), 0, 50);
        if (!empty($settings['content_width'])) $identifiers['content_width'] = $settings['content_width'];
        if (!empty($settings['flex_direction'])) $identifiers['flex_direction'] = $settings['flex_direction'];
        if ($identifiers) $summary['hint'] = $identifiers;

        if (!empty($el['elements'])) {
            $summary['children'] = array_map([self::class, 'summarize_element'], $el['elements']);
        }

        return $summary;
    }

    // ── Tree Operations ──────────────────────────────────────

    /**
     * Find an element by ID in the tree.
     *
     * @return array|null [element, parent_elements_ref, index]
     */
    public static function find_element(array &$elements, string $id): ?array {
        foreach ($elements as $index => &$el) {
            if (($el['id'] ?? '') === $id) {
                return ['element' => &$el, 'parent' => &$elements, 'index' => $index];
            }
            if (!empty($el['elements'])) {
                $found = self::find_element($el['elements'], $id);
                if ($found) return $found;
            }
        }
        return null;
    }

    /**
     * Update settings of an element by ID.
     */
    public static function update_element_settings(array &$elements, string $id, array $new_settings): bool {
        $found = self::find_element($elements, $id);
        if (!$found) return false;

        $found['element']['settings'] = array_merge(
            $found['element']['settings'] ?? [],
            $new_settings
        );
        return true;
    }

    /**
     * Insert an element at a specific position.
     */
    public static function insert_element(array &$parent_elements, array $new_element, int $position = -1): void {
        if ($position < 0 || $position >= count($parent_elements)) {
            $parent_elements[] = $new_element;
        } else {
            array_splice($parent_elements, $position, 0, [$new_element]);
        }
    }

    /**
     * Remove an element by ID from the tree.
     */
    public static function remove_element(array &$elements, string $id): bool {
        foreach ($elements as $index => &$el) {
            if (($el['id'] ?? '') === $id) {
                array_splice($elements, $index, 1);
                return true;
            }
            if (!empty($el['elements']) && self::remove_element($el['elements'], $id)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Duplicate an element by ID.
     */
    public static function duplicate_element(array &$elements, string $id): ?string {
        $found = self::find_element($elements, $id);
        if (!$found) return null;

        $clone = $found['element'];
        Element_Factory::reassign_ids($clone);

        array_splice($found['parent'], $found['index'] + 1, 0, [$clone]);
        return $clone['id'];
    }

    // ── Templates ────────────────────────────────────────────

    /**
     * Create an Elementor Theme Builder template (header, footer, etc).
     */
    public static function create_template(string $title, string $type, array $data, array $conditions = ['include/general']): int {
        $post_id = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'elementor_library',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($post_id)) return 0;

        update_post_meta($post_id, '_elementor_template_type', $type);
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_version', self::elementor_version());
        update_post_meta($post_id, '_elementor_conditions', $conditions);

        // Save Elementor data
        $json = wp_slash(wp_json_encode($data));
        update_post_meta($post_id, '_elementor_data', $json);

        // Register conditions with Elementor Pro
        $all_conditions = get_option('elementor_pro_theme_builder_conditions', []);
        $all_conditions[$type] = $all_conditions[$type] ?? [];
        $all_conditions[$type][$post_id] = $conditions;
        update_option('elementor_pro_theme_builder_conditions', $all_conditions);

        return $post_id;
    }

    // ── Kit / Global Settings ────────────────────────────────

    /**
     * Get Elementor global kit settings.
     */
    public static function get_kit_settings(): array {
        $kit_id = get_option('elementor_active_kit');
        if (!$kit_id) return [];
        return get_post_meta($kit_id, '_elementor_page_settings', true) ?: [];
    }

    /**
     * Update Elementor global kit settings (merge).
     */
    public static function update_kit_settings(array $settings): bool {
        $kit_id = get_option('elementor_active_kit');
        if (!$kit_id) return false;

        $current = get_post_meta($kit_id, '_elementor_page_settings', true) ?: [];
        $merged = array_merge($current, $settings);
        update_post_meta($kit_id, '_elementor_page_settings', $merged);

        self::flush_all_css();
        return true;
    }

    // ── Media ────────────────────────────────────────────────

    /**
     * Directories image imports may be read from.
     *
     * Defaults to the uploads directory only. Sites that really need to import from
     * elsewhere on the server can allow extra directories with the
     * `neoservice_elementor_api_import_dirs` filter.
     *
     * @return string[] Resolved, absolute directory paths.
     */
    public static function allowed_import_dirs(): array {
        $upload_dir = wp_upload_dir();
        $dirs       = apply_filters('neoservice_elementor_api_import_dirs', [$upload_dir['basedir']]);

        $resolved = [];
        foreach ((array) $dirs as $dir) {
            $real = is_string($dir) ? realpath($dir) : false;
            if ($real) {
                $resolved[] = untrailingslashit(wp_normalize_path($real));
            }
        }
        return $resolved;
    }

    /**
     * Import an image from a file path into the WP media library.
     *
     * The path must resolve (after symlinks) to an image file inside one of the
     * allowed import directories. Anything else returns 0.
     */
    public static function import_image(string $source_path, string $title = ''): int {
        $real = realpath($source_path);
        if (!$real || !is_file($real)) return 0;

        $real    = wp_normalize_path($real);
        $allowed = false;
        foreach (self::allowed_import_dirs() as $dir) {
            if (str_starts_with($real, $dir . '/')) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) return 0;

        // Only real images, judged by content, not by the file name.
        $filename = basename($real);
        $check    = wp_check_filetype_and_ext($real, $filename);
        if (empty($check['type']) || !str_starts_with($check['type'], 'image/')) return 0;

        $title = $title ? sanitize_text_field($title) : pathinfo($filename, PATHINFO_FILENAME);

        // Check if already imported.
        $existing = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'title'          => $title,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        if ($existing) return (int) $existing[0];

        $upload_dir = wp_upload_dir();
        $dest       = $upload_dir['path'] . '/' . wp_unique_filename($upload_dir['path'], $filename);

        if ($real !== wp_normalize_path($dest) && !copy($real, $dest)) return 0;

        $attach_id = wp_insert_attachment([
            'post_mime_type' => $check['type'],
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $dest);
        if (is_wp_error($attach_id) || !$attach_id) return 0;

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attach_id, $dest);
        wp_update_attachment_metadata($attach_id, $metadata);

        return (int) $attach_id;
    }

    /**
     * Elementor version string stored alongside saved data.
     */
    private static function elementor_version(): string {
        return defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.35.7';
    }

    // ── Widget Discovery ─────────────────────────────────────

    /**
     * List all available Elementor widgets with their categories.
     */
    public static function list_widgets(): array {
        if (!class_exists('\Elementor\Plugin')) return [];

        $widgets = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
        $result  = [];

        foreach ($widgets as $name => $widget) {
            $result[] = [
                'name'       => $name,
                'title'      => $widget->get_title(),
                'icon'       => $widget->get_icon(),
                'categories' => $widget->get_categories(),
            ];
        }

        return $result;
    }

    /**
     * Get the control schema for a specific widget.
     */
    public static function get_widget_schema(string $widget_name): ?array {
        if (!class_exists('\Elementor\Plugin')) return null;

        $widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types($widget_name);
        if (!$widget) return null;

        $controls = $widget->get_controls();
        $schema   = [];

        foreach ($controls as $id => $control) {
            // Skip internal/hidden controls
            if (str_starts_with($id, '_')) continue;
            if (($control['type'] ?? '') === 'section') continue;

            $schema[$id] = [
                'type'    => $control['type'] ?? 'unknown',
                'label'   => $control['label'] ?? $id,
                'default' => $control['default'] ?? null,
            ];

            if (!empty($control['options'])) {
                $schema[$id]['options'] = $control['options'];
            }
        }

        return $schema;
    }

    /**
     * Get a ready-to-use element JSON template for a widget with all defaults populated.
     * Returns an Elementor element structure that can be directly inserted via add_element.
     */
    public static function get_widget_defaults(string $widget_name): ?array {
        if (!class_exists('\Elementor\Plugin')) return null;

        $widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types($widget_name);
        if (!$widget) return null;

        $controls = $widget->get_controls();
        $settings = [];

        foreach ($controls as $id => $control) {
            // Skip internal/hidden controls and section headers
            if (str_starts_with($id, '_')) continue;
            if (($control['type'] ?? '') === 'section') continue;
            if (($control['type'] ?? '') === 'tab') continue;

            $default = $control['default'] ?? null;
            if ($default !== null && $default !== '' && $default !== []) {
                $settings[$id] = $default;
            }
        }

        return [
            'id'         => Element_Factory::generate_id(),
            'elType'     => 'widget',
            'widgetType' => $widget_name,
            'settings'   => $settings,
            'elements'   => [],
        ];
    }

    // ── Cache ────────────────────────────────────────────────

    /**
     * Flush Elementor CSS cache for a specific post.
     */
    public static function flush_css(int $post_id = 0): void {
        if (!class_exists('\Elementor\Plugin')) return;

        if ($post_id) {
            // Delete post-specific CSS file
            $upload_dir = wp_upload_dir();
            $css_path   = $upload_dir['basedir'] . '/elementor/css/post-' . $post_id . '.css';
            if (file_exists($css_path)) {
                wp_delete_file($css_path);
            }
            delete_post_meta($post_id, '_elementor_css');
        }
    }

    /**
     * Flush all Elementor CSS.
     */
    public static function flush_all_css(): void {
        if (!class_exists('\Elementor\Plugin')) return;
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
}
