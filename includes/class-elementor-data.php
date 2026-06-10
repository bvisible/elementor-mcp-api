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

    /** Post-meta key holding the previous `_elementor_data` for one-step rollback. */
    const BACKUP_META_KEY = '_neoservice_elementor_backup';

    /**
     * Save Elementor data for a page.
     * Tries native Elementor save first, falls back to direct meta write.
     *
     * Before overwriting, the current `_elementor_data` is snapshotted to
     * {@see BACKUP_META_KEY} so a bad write can be reverted with {@see restore_backup}.
     *
     * @return bool Success.
     */
    public static function save_page_data(int $post_id, array $data): bool {
        // Stored-XSS gate — applies to EVERY save path, including the native Elementor
        // one. Element JSON can carry raw HTML/scripts via widget settings (text-editor's
        // `editor`, the HTML widget's `html`, etc.). Previously this gate only existed on
        // the direct-meta fallback below, which never runs in production (Elementor active
        // → native path wins), so a caller WITHOUT `unfiltered_html` could persist
        // `<script>` to visitors. Now: callers lacking `unfiltered_html` (administrators
        // have it) get their HTML-carrying settings sanitized with wp_kses_post; callers
        // with the capability keep their content intact.
        if (function_exists('current_user_can') && !current_user_can('unfiltered_html')) {
            $data = self::kses_widget_html($data);
        }

        // Snapshot the current state for rollback (best-effort, never blocks the save).
        $previous = get_post_meta($post_id, '_elementor_data', true);
        if (!empty($previous)) {
            update_post_meta($post_id, self::BACKUP_META_KEY, $previous);
        }

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

        // Fallback: direct meta write. Stored-XSS is already neutralized by the
        // unconditional `unfiltered_html` kses gate at the top of this method (which
        // replaced the old fallback-only hard block — that block was dead code in
        // production because the native path above always wins when Elementor is active).
        $json = wp_slash(wp_json_encode($data));
        update_post_meta($post_id, '_elementor_data', $json);
        update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION ?? '3.35.7');

        // Manual CSS cache invalidation
        self::flush_css($post_id);

        return true;
    }

    /**
     * Settings keys that carry caller-supplied HTML rendered to visitors.
     *
     * Derived from the widgets Element_Factory emits plus the common Elementor core
     * widgets: text-editor (`editor`), HTML widget (`html`), heading (`title`),
     * button (`text`), icon-box/image-box (`title_text`, `description_text`,
     * `description`), image (`caption`), testimonial (`testimonial_content`),
     * generic rich-text (`content`), tabs/accordion/toggle (`tab_content`),
     * alert (`alert_title`, `alert_description`), Pro widgets (`inner_text`).
     * Plain text in these keys passes through
     * wp_kses_post unchanged; scripts/event handlers are stripped.
     */
    const HTML_SETTING_KEYS = [
        'editor', 'html', 'title', 'text', 'content', 'description', 'caption',
        'title_text', 'description_text', 'testimonial_content',
        'tab_content', 'alert_title', 'alert_description', 'inner_text',
    ];

    /**
     * Recursively sanitize the HTML-carrying settings of an element tree with
     * wp_kses_post. Used by {@see save_page_data} when the caller lacks the
     * `unfiltered_html` capability, so a non-admin cannot persist `<script>` or
     * event-handler attributes into `_elementor_data` (stored XSS to visitors).
     *
     * Walks `settings` (including repeater item arrays, e.g. icon lists or
     * testimonials, which nest settings-shaped arrays) and child `elements`.
     *
     * @param array $elements Element tree (or subtree) as stored in `_elementor_data`.
     * @return array Sanitized tree.
     */
    public static function kses_widget_html(array $elements): array {
        foreach ($elements as &$el) {
            if (!is_array($el)) continue;
            if (!empty($el['settings']) && is_array($el['settings'])) {
                $el['settings'] = self::kses_settings($el['settings']);
            }
            if (!empty($el['elements']) && is_array($el['elements'])) {
                $el['elements'] = self::kses_widget_html($el['elements']);
            }
        }
        unset($el);
        return $elements;
    }

    /**
     * Worker for {@see kses_widget_html}: kses string values under HTML-carrying
     * keys; recurse into nested arrays (repeater items carry their own settings).
     */
    private static function kses_settings(array $settings): array {
        foreach ($settings as $key => &$value) {
            if (is_string($value) && in_array((string) $key, self::HTML_SETTING_KEYS, true)) {
                $value = wp_kses_post($value);
            } elseif (is_array($value)) {
                $value = self::kses_settings($value);
            }
        }
        unset($value);
        return $settings;
    }

    /**
     * Restore the previous `_elementor_data` snapshot taken by the last
     * {@see save_page_data} call. One level deep — there is exactly one backup slot.
     *
     * @return bool True if a backup existed and was restored, false if none.
     */
    public static function restore_backup(int $post_id): bool {
        $backup = get_post_meta($post_id, self::BACKUP_META_KEY, true);
        if (empty($backup)) {
            return false;
        }

        $decoded = is_string($backup) ? json_decode($backup, true) : $backup;
        if (!is_array($decoded)) {
            return false;
        }

        // Re-save through the normal path (which itself snapshots, enabling redo).
        return self::save_page_data($post_id, $decoded);
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
        if (!empty($settings['editor'])) $identifiers['text'] = mb_substr(strip_tags($settings['editor']), 0, 50);
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
     *
     * Defaults to 'draft' (defense in depth): a published header/footer with the
     * default ['include/general'] conditions goes LIVE site-wide the moment it is
     * created. Publishing must be an explicit caller decision ($status = 'publish').
     */
    public static function create_template(string $title, string $type, array $data, array $conditions = ['include/general'], string $status = 'draft'): int {
        $post_id = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'elementor_library',
            'post_status' => in_array($status, ['draft', 'publish'], true) ? $status : 'draft',
        ]);

        if (is_wp_error($post_id)) return 0;

        update_post_meta($post_id, '_elementor_template_type', $type);
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION ?? '3.35.7');
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

    /**
     * Delete an Elementor Theme Builder template (rollback path for create_template).
     *
     * Default = trash (restorable in WP admin). $force = permanent delete, which also
     * unregisters the template from the Elementor Pro theme-builder conditions map so
     * no orphaned condition entry remains. Returns false when the post does not exist
     * or is not an elementor_library template (never touches other post types).
     */
    public static function delete_template(int $post_id, bool $force = false): bool {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'elementor_library') {
            return false;
        }

        if (!$force) {
            return (bool) wp_trash_post($post_id);
        }

        // Permanent delete: clean the conditions registry first.
        $all_conditions = get_option('elementor_pro_theme_builder_conditions', []);
        if (is_array($all_conditions)) {
            foreach ($all_conditions as $type => $entries) {
                if (is_array($entries) && array_key_exists($post_id, $entries)) {
                    unset($all_conditions[$type][$post_id]);
                }
            }
            update_option('elementor_pro_theme_builder_conditions', $all_conditions);
        }

        return (bool) wp_delete_post($post_id, true);
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

    /**
     * Get the design-system globals declared in the active Kit.
     *
     * Returns the global colors and fonts as a flat, agent-friendly map so the
     * generation side can reference them via Elementor's `__globals__` mechanism
     * (e.g. `globals/colors?id=primary`) instead of hardcoding inline hex — the
     * single biggest lever for producing *professional*, brand-consistent pages.
     *
     * Shape:
     *   {
     *     "colors": [{"_id":"primary","title":"Primary","color":"#FF0000"}, ...],
     *     "typography": [{"_id":"primary","title":"Primary","family":"Inter","weight":"600"}, ...]
     *   }
     */
    public static function get_kit_globals(): array {
        $settings = self::get_kit_settings();

        $colors = [];
        foreach (['system_colors', 'custom_colors'] as $bucket) {
            if (!empty($settings[$bucket]) && is_array($settings[$bucket])) {
                foreach ($settings[$bucket] as $c) {
                    $colors[] = [
                        '_id'   => $c['_id'] ?? '',
                        'title' => $c['title'] ?? '',
                        'color' => $c['color'] ?? '',
                        'bucket' => $bucket === 'system_colors' ? 'system' : 'custom',
                    ];
                }
            }
        }

        $typography = [];
        foreach (['system_typography', 'custom_typography'] as $bucket) {
            if (!empty($settings[$bucket]) && is_array($settings[$bucket])) {
                foreach ($settings[$bucket] as $t) {
                    $typography[] = [
                        '_id'    => $t['_id'] ?? '',
                        'title'  => $t['title'] ?? '',
                        'family' => $t['typography_font_family'] ?? '',
                        'weight' => $t['typography_font_weight'] ?? '',
                        'bucket' => $bucket === 'system_typography' ? 'system' : 'custom',
                    ];
                }
            }
        }

        return ['colors' => $colors, 'typography' => $typography];
    }

    // ── Media ────────────────────────────────────────────────

    /**
     * Import an image from a file path into the WP media library.
     *
     * Dedup is keyed on the file's SHA-1 content hash (stored as attachment meta),
     * not the title — two different images sharing a title must NOT collapse into one,
     * and re-importing the identical file must be idempotent.
     */
    public static function import_image(string $source_path, string $title = ''): int {
        if (!file_exists($source_path)) return 0;

        $filename = basename($source_path);
        $title    = $title ?: pathinfo($filename, PATHINFO_FILENAME);

        // Content-hash dedup: re-importing the same bytes returns the existing attachment.
        $hash = @sha1_file($source_path);
        if ($hash) {
            global $wpdb;
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_neoservice_source_hash' AND meta_value=%s LIMIT 1",
                $hash
            ));
            if ($existing) return (int) $existing;
        }

        $upload_dir = wp_upload_dir();
        // Collision-safe destination filename inside the uploads dir.
        $dest = $upload_dir['path'] . '/' . wp_unique_filename($upload_dir['path'], $filename);

        if (!@copy($source_path, $dest)) {
            return 0;
        }

        $filetype   = wp_check_filetype($filename);
        $attach_id  = wp_insert_attachment([
            'post_mime_type' => $filetype['type'],
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $dest);

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attach_id, $dest);
        wp_update_attachment_metadata($attach_id, $metadata);

        if ($hash && $attach_id) {
            update_post_meta($attach_id, '_neoservice_source_hash', $hash);
        }

        return $attach_id;
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
                unlink($css_path);
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
