<?php
namespace NeoService\ElementorAPI;

/**
 * REST API Controller - exposes all Elementor operations as REST endpoints.
 * Authenticated via WordPress Application Passwords or cookie auth.
 */
class REST_Controller {

    const NAMESPACE = 'neoservice/v1';

    public function register_routes(): void {
        $editor = ['permission_callback' => [$this, 'check_edit_permission']];
        $reader = ['permission_callback' => [$this, 'check_read_permission']];
        // Site-global config (Kit, global templates) — administrator only.
        $admin  = ['permission_callback' => [$this, 'check_admin_permission']];

        // ── Pages ────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/pages', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_pages'],
            ...$reader,
        ]);

        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_page'],
            ...$reader,
        ]);

        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)/structure', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_page_structure'],
            ...$reader,
        ]);

        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)', [
            'methods'  => 'PUT',
            'callback' => [$this, 'update_page'],
            ...$editor,
        ]);

        register_rest_route(self::NAMESPACE, '/page', [
            'methods'  => 'POST',
            'callback' => [$this, 'create_page'],
            ...$editor,
        ]);

        // Roll back the last save (restores the snapshot taken before the most
        // recent write to this page). One level deep.
        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)/restore', [
            'methods'  => 'POST',
            'callback' => [$this, 'restore_page'],
            ...$editor,
        ]);

        // ── Elements (granular operations) ───────────────
        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)/element', [
            'methods'  => 'POST',
            'callback' => [$this, 'add_element'],
            ...$editor,
        ]);

        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)/element/(?P<element_id>[a-f0-9]+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_element'],
            ...$reader,
        ]);

        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)/element/(?P<element_id>[a-f0-9]+)', [
            'methods'  => 'PATCH',
            'callback' => [$this, 'update_element'],
            ...$editor,
        ]);

        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)/element/(?P<element_id>[a-f0-9]+)', [
            'methods'  => 'DELETE',
            'callback' => [$this, 'remove_element'],
            ...$editor,
        ]);

        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)/element/(?P<element_id>[a-f0-9]+)/duplicate', [
            'methods'  => 'POST',
            'callback' => [$this, 'duplicate_element'],
            ...$editor,
        ]);

        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)/element/(?P<element_id>[a-f0-9]+)/move', [
            'methods'  => 'POST',
            'callback' => [$this, 'move_element'],
            ...$editor,
        ]);

        // ── Bulk operations (1.3.0) ──────────────────────
        // Apply many PATCH operations in a single page load/save cycle.
        // Body: {"patches":[{"id":"abc","settings":{...}}, ...]}
        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)/elements/patch-bulk', [
            'methods'  => 'POST',
            'callback' => [$this, 'patch_elements_bulk'],
            ...$editor,
        ]);

        // Helper: set flex column width on a container (Elementor v4 requires
        // _flex_size + _inline_size + width together — this endpoint sets all three).
        // Body: {"percent":25, "tablet":50, "mobile":100}
        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)/element/(?P<element_id>[a-f0-9]+)/column-width', [
            'methods'  => 'PATCH',
            'callback' => [$this, 'set_column_width'],
            ...$editor,
        ]);

        // Find elements by widgetType. Returns array of {id, parent_id, depth}.
        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)/find', [
            'methods'  => 'GET',
            'callback' => [$this, 'find_elements'],
            ...$reader,
        ]);

        // ── Section-level insert ─────────────────────────
        register_rest_route(self::NAMESPACE, '/page/(?P<id>\d+)/section', [
            'methods'  => 'POST',
            'callback' => [$this, 'add_section'],
            ...$editor,
        ]);

        // ── Templates ────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/templates', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_templates'],
            ...$reader,
        ]);

        // Templates are theme-builder entities (headers/footers/global conditions)
        // that affect the whole site — administrator only.
        register_rest_route(self::NAMESPACE, '/template', [
            'methods'  => 'POST',
            'callback' => [$this, 'create_template'],
            ...$admin,
        ]);

        // Rollback path for create_template. Trash by default; ?force=true is
        // permanent (also unregisters theme-builder conditions). Admin only.
        register_rest_route(self::NAMESPACE, '/template/(?P<id>\d+)', [
            'methods'  => 'DELETE',
            'callback' => [$this, 'delete_template'],
            ...$admin,
        ]);

        // ── Kit / Global Settings ────────────────────────
        register_rest_route(self::NAMESPACE, '/kit', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_kit'],
            ...$reader,
        ]);

        // The Kit holds site-wide colors/typography/layout defaults — administrator only.
        register_rest_route(self::NAMESPACE, '/kit', [
            'methods'  => 'PUT',
            'callback' => [$this, 'update_kit'],
            ...$admin,
        ]);

        // Design-system globals (colors + fonts) in an agent-friendly flat shape,
        // ready to reference from widgets via `__globals__`.
        register_rest_route(self::NAMESPACE, '/kit/globals', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_kit_globals'],
            ...$reader,
        ]);

        // ── Widgets ──────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/widgets', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_widgets'],
            ...$reader,
        ]);

        register_rest_route(self::NAMESPACE, '/widget/(?P<name>[a-z0-9_-]+)/schema', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_widget_schema'],
            ...$reader,
        ]);

        register_rest_route(self::NAMESPACE, '/widget/(?P<name>[a-z0-9_-]+)/defaults', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_widget_defaults'],
            ...$reader,
        ]);

        // ── Media ────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/media/import', [
            'methods'  => 'POST',
            'callback' => [$this, 'import_media'],
            ...$editor,
        ]);

        // ── Cache ────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/flush-css', [
            'methods'  => 'POST',
            'callback' => [$this, 'flush_css'],
            ...$editor,
        ]);

        // ── Build (composite) ────────────────────────────
        register_rest_route(self::NAMESPACE, '/build-page', [
            'methods'  => 'POST',
            'callback' => [$this, 'build_page'],
            ...$editor,
        ]);

        // ── Health ────────────────────────────────────────
        // Public, read-only probe. Lets a client confirm the plugin is installed and
        // active (and which Elementor it sees) WITHOUT needing edit credentials.
        register_rest_route(self::NAMESPACE, '/health', [
            'methods'             => 'GET',
            'callback'            => [$this, 'health'],
            'permission_callback' => '__return_true',
        ]);
    }

    // ── Permission checks ────────────────────────────────────

    public function check_read_permission(?\WP_REST_Request $request = null): bool {
        // Per-post read check when an id is present (so drafts the caller can't
        // read are not exposed); generic read otherwise.
        if ($request && isset($request['id'])) {
            return current_user_can('read_post', (int) $request['id']);
        }
        return current_user_can('read');
    }

    public function check_edit_permission(?\WP_REST_Request $request = null): bool {
        // Per-post edit check when an id is present (maps to edit_page on the
        // 'page' post type), so an Author cannot rewrite another user's page.
        // For create routes (no id) require edit_pages — the broad edit_posts
        // would let any contributor create site pages.
        if ($request && isset($request['id'])) {
            return current_user_can('edit_post', (int) $request['id']);
        }
        return current_user_can('edit_pages');
    }

    /**
     * Site-global configuration (the Kit, global templates, raw `_elementor_data`
     * meta) must require administrator-level capability — it is not per-page content.
     */
    public function check_admin_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Convert a WP_Error (carrying an optional HTTP status in its data) into a
     * uniform REST response: {"error": "<message>", "code": "<code>"}.
     */
    /**
     * Reject an oversized raw request body up front. Returns a 413 response to send,
     * or null when the body is within limits.
     */
    private function reject_if_oversized(\WP_REST_Request $request): ?\WP_REST_Response {
        $check = Validator::check_body_size((string) $request->get_body());
        return is_wp_error($check) ? $this->error_response($check) : null;
    }

    private function error_response(\WP_Error $error): \WP_REST_Response {
        $data   = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
        return new \WP_REST_Response([
            'error' => $error->get_error_message(),
            'code'  => $error->get_error_code(),
        ], $status);
    }

    // ── Pages ────────────────────────────────────────────────

    public function list_pages(\WP_REST_Request $request): \WP_REST_Response {
        $pages = get_posts([
            'post_type'      => 'page',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ]);

        $result = [];
        foreach ($pages as $page) {
            // Do not leak drafts/pending pages to a caller who cannot edit them.
            if ($page->post_status !== 'publish' && !current_user_can('edit_post', $page->ID)) {
                continue;
            }
            $has_elementor = get_post_meta($page->ID, '_elementor_edit_mode', true) === 'builder';
            $result[] = [
                'id'            => $page->ID,
                'title'         => $page->post_title,
                'slug'          => $page->post_name,
                'status'        => $page->post_status,
                'url'           => get_permalink($page->ID),
                'has_elementor' => $has_elementor,
            ];
        }

        return new \WP_REST_Response($result, 200);
    }

    public function get_page(\WP_REST_Request $request): \WP_REST_Response {
        $id   = (int) $request['id'];
        $data = Elementor_Data::get_page_data($id);

        if ($data === null) {
            return new \WP_REST_Response(['error' => 'No Elementor data found'], 404);
        }

        return new \WP_REST_Response([
            'id'       => $id,
            'title'    => get_the_title($id),
            'sections' => count($data),
            'data'     => $data,
        ], 200);
    }

    public function get_page_structure(\WP_REST_Request $request): \WP_REST_Response {
        $id        = (int) $request['id'];
        $structure = Elementor_Data::get_page_structure($id);

        if ($structure === null) {
            return new \WP_REST_Response(['error' => 'No Elementor data found'], 404);
        }

        return new \WP_REST_Response([
            'id'        => $id,
            'title'     => get_the_title($id),
            'structure' => $structure,
        ], 200);
    }

    public function update_page(\WP_REST_Request $request): \WP_REST_Response {
        if ($r = $this->reject_if_oversized($request)) return $r;
        $id   = (int) $request['id'];
        $body = $request->get_json_params();

        if (empty($body['data']) || !is_array($body['data'])) {
            return new \WP_REST_Response(['error' => 'Missing or invalid "data" array'], 400);
        }

        $valid = Validator::validate_tree($body['data']);
        if (is_wp_error($valid)) {
            return $this->error_response($valid);
        }

        $success = Elementor_Data::save_page_data($id, $body['data']);

        return new \WP_REST_Response([
            'success'  => $success,
            'id'       => $id,
            'sections' => count($body['data']),
        ], $success ? 200 : 500);
    }

    public function create_page(\WP_REST_Request $request): \WP_REST_Response {
        if ($r = $this->reject_if_oversized($request)) return $r;
        $body = $request->get_json_params();
        $title = $body['title'] ?? 'New Page';
        $slug  = $body['slug'] ?? sanitize_title($title);

        $post_id = wp_insert_post([
            'post_title'  => $title,
            'post_name'   => $slug,
            'post_type'   => 'page',
            'post_status' => $body['status'] ?? 'publish',
        ]);

        if (is_wp_error($post_id)) {
            return new \WP_REST_Response(['error' => $post_id->get_error_message()], 500);
        }

        // If Elementor data provided, validate then save it
        if (!empty($body['data']) && is_array($body['data'])) {
            $valid = Validator::validate_tree($body['data']);
            if (is_wp_error($valid)) {
                return $this->error_response($valid);
            }
            Elementor_Data::save_page_data($post_id, $body['data']);
        }

        return new \WP_REST_Response([
            'id'    => $post_id,
            'title' => $title,
            'url'   => get_permalink($post_id),
        ], 201);
    }

    public function restore_page(\WP_REST_Request $request): \WP_REST_Response {
        $id = (int) $request['id'];

        $restored = Elementor_Data::restore_backup($id);
        if (!$restored) {
            return new \WP_REST_Response(['error' => 'No backup available to restore'], 404);
        }

        return new \WP_REST_Response(['success' => true, 'id' => $id], 200);
    }

    // ── Elements ─────────────────────────────────────────────

    public function get_element(\WP_REST_Request $request): \WP_REST_Response {
        $page_id    = (int) $request['id'];
        $element_id = $request['element_id'];
        $data       = Elementor_Data::get_page_data($page_id);

        if (!$data) {
            return new \WP_REST_Response(['error' => 'No Elementor data found'], 404);
        }

        $found = Elementor_Data::find_element($data, $element_id);
        if (!$found) {
            return new \WP_REST_Response(['error' => "Element '$element_id' not found"], 404);
        }

        return new \WP_REST_Response($found['element'], 200);
    }

    public function add_element(\WP_REST_Request $request): \WP_REST_Response {
        if ($r = $this->reject_if_oversized($request)) return $r;
        $page_id = (int) $request['id'];
        $body    = $request->get_json_params();
        $data    = Elementor_Data::get_page_data($page_id);

        if ($data === null) $data = [];

        $element   = $body['element'] ?? null;
        $parent_id = $body['parent_id'] ?? null;
        $position  = $body['position'] ?? -1;

        if (!$element || !is_array($element)) {
            return new \WP_REST_Response(['error' => 'Missing "element" object'], 400);
        }

        // Validate the element subtree before insertion.
        $valid = Validator::validate_tree([$element]);
        if (is_wp_error($valid)) {
            return $this->error_response($valid);
        }

        // Ensure element has a valid ID (generate one if missing or malformed).
        if (empty($element['id']) || !Validator::is_valid_element_id($element['id'])) {
            $element['id'] = Element_Factory::generate_id();
        }

        if ($parent_id) {
            // Insert inside a specific parent
            $found = Elementor_Data::find_element($data, $parent_id);
            if (!$found) {
                return new \WP_REST_Response(['error' => "Parent element '$parent_id' not found"], 404);
            }
            if (!isset($found['element']['elements'])) {
                $found['element']['elements'] = [];
            }
            Elementor_Data::insert_element($found['element']['elements'], $element, $position);
        } else {
            // Insert at root level
            Elementor_Data::insert_element($data, $element, $position);
        }

        Elementor_Data::save_page_data($page_id, $data);

        return new \WP_REST_Response([
            'success'    => true,
            'element_id' => $element['id'],
        ], 201);
    }

    public function update_element(\WP_REST_Request $request): \WP_REST_Response {
        if ($r = $this->reject_if_oversized($request)) return $r;
        $page_id    = (int) $request['id'];
        $element_id = $request['element_id'];
        $body       = $request->get_json_params();
        $data       = Elementor_Data::get_page_data($page_id);

        if (!$data) {
            return new \WP_REST_Response(['error' => 'No Elementor data found'], 404);
        }

        $settings = $body['settings'] ?? [];
        if (empty($settings)) {
            return new \WP_REST_Response(['error' => 'Missing "settings" object'], 400);
        }

        $updated = Elementor_Data::update_element_settings($data, $element_id, $settings);
        if (!$updated) {
            return new \WP_REST_Response(['error' => "Element '$element_id' not found"], 404);
        }

        Elementor_Data::save_page_data($page_id, $data);

        return new \WP_REST_Response(['success' => true, 'element_id' => $element_id], 200);
    }

    public function remove_element(\WP_REST_Request $request): \WP_REST_Response {
        $page_id    = (int) $request['id'];
        $element_id = $request['element_id'];
        $data       = Elementor_Data::get_page_data($page_id);

        if (!$data) {
            return new \WP_REST_Response(['error' => 'No Elementor data found'], 404);
        }

        $removed = Elementor_Data::remove_element($data, $element_id);
        if (!$removed) {
            return new \WP_REST_Response(['error' => "Element '$element_id' not found"], 404);
        }

        Elementor_Data::save_page_data($page_id, $data);

        return new \WP_REST_Response(['success' => true], 200);
    }

    public function duplicate_element(\WP_REST_Request $request): \WP_REST_Response {
        $page_id    = (int) $request['id'];
        $element_id = $request['element_id'];
        $data       = Elementor_Data::get_page_data($page_id);

        if (!$data) {
            return new \WP_REST_Response(['error' => 'No Elementor data found'], 404);
        }

        $new_id = Elementor_Data::duplicate_element($data, $element_id);
        if (!$new_id) {
            return new \WP_REST_Response(['error' => "Element '$element_id' not found"], 404);
        }

        Elementor_Data::save_page_data($page_id, $data);

        return new \WP_REST_Response(['success' => true, 'new_element_id' => $new_id], 201);
    }

    public function move_element(\WP_REST_Request $request): \WP_REST_Response {
        if ($r = $this->reject_if_oversized($request)) return $r;
        $page_id    = (int) $request['id'];
        $element_id = $request['element_id'];
        $body       = $request->get_json_params();
        $data       = Elementor_Data::get_page_data($page_id);

        if (!$data) {
            return new \WP_REST_Response(['error' => 'No Elementor data found'], 404);
        }

        $new_parent_id = $body['parent_id'] ?? null;
        $new_position  = $body['position'] ?? -1;

        // Extract the element from its current location
        $found = Elementor_Data::find_element($data, $element_id);
        if (!$found) {
            return new \WP_REST_Response(['error' => "Element '$element_id' not found"], 404);
        }

        $element = $found['element'];

        // Remove from current location
        Elementor_Data::remove_element($data, $element_id);

        // Insert at new location
        if ($new_parent_id) {
            $parent = Elementor_Data::find_element($data, $new_parent_id);
            if (!$parent) {
                return new \WP_REST_Response(['error' => "New parent '$new_parent_id' not found"], 404);
            }
            if (!isset($parent['element']['elements'])) {
                $parent['element']['elements'] = [];
            }
            Elementor_Data::insert_element($parent['element']['elements'], $element, $new_position);
        } else {
            Elementor_Data::insert_element($data, $element, $new_position);
        }

        Elementor_Data::save_page_data($page_id, $data);

        return new \WP_REST_Response([
            'success'    => true,
            'element_id' => $element_id,
            'parent_id'  => $new_parent_id,
            'position'   => $new_position,
        ], 200);
    }

    // ── Bulk operations (1.3.0) ──────────────────────────────

    /**
     * Apply many settings PATCHes to multiple elements in one page load/save cycle.
     * Body: {"patches":[{"id":"abc","settings":{...}}, ...]}
     * Patches are applied sequentially in the given order.
     */
    public function patch_elements_bulk(\WP_REST_Request $request): \WP_REST_Response {
        if ($r = $this->reject_if_oversized($request)) return $r;
        $page_id = (int) $request['id'];
        $body    = $request->get_json_params();
        $patches = $body['patches'] ?? [];

        if (!is_array($patches) || empty($patches)) {
            return new \WP_REST_Response(['error' => 'Missing "patches" array'], 400);
        }

        $data = Elementor_Data::get_page_data($page_id);
        if (!$data) {
            return new \WP_REST_Response(['error' => 'No Elementor data found'], 404);
        }

        $results = [];
        foreach ($patches as $i => $patch) {
            $eid      = $patch['id'] ?? '';
            $settings = $patch['settings'] ?? [];
            if (!$eid || !is_array($settings)) {
                $results[] = ['index' => $i, 'id' => $eid, 'status' => 'skipped', 'reason' => 'missing id or settings'];
                continue;
            }
            if (!Validator::is_valid_element_id($eid)) {
                $results[] = ['index' => $i, 'id' => $eid, 'status' => 'skipped', 'reason' => 'invalid element id'];
                continue;
            }
            $ok = Elementor_Data::update_element_settings($data, $eid, $settings);
            $results[] = [
                'index'  => $i,
                'id'     => $eid,
                'status' => $ok ? 'ok' : 'not_found',
            ];
        }

        Elementor_Data::save_page_data($page_id, $data);

        $ok_count = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
        return new \WP_REST_Response([
            'success'  => true,
            'applied'  => $ok_count,
            'total'    => count($patches),
            'results'  => $results,
        ], 200);
    }

    /**
     * Helper: set flex column width on a container.
     *
     * Elementor v4 requires _flex_size + _inline_size + width to be set together
     * for a flex item to actually take the declared width. This endpoint sets all
     * three plus their responsive variants in one call.
     *
     * Body: {"percent": 25, "tablet": 50, "mobile": 100}
     */
    public function set_column_width(\WP_REST_Request $request): \WP_REST_Response {
        if ($r = $this->reject_if_oversized($request)) return $r;
        $page_id    = (int) $request['id'];
        $element_id = $request['element_id'];
        $body       = $request->get_json_params();

        $percent = isset($body['percent']) ? (float) $body['percent'] : null;
        if ($percent === null) {
            return new \WP_REST_Response(['error' => 'Missing "percent" (0-100)'], 400);
        }
        $tablet  = isset($body['tablet']) ? (float) $body['tablet'] : null;
        $mobile  = isset($body['mobile']) ? (float) $body['mobile'] : null;

        $settings = [
            '_flex_size'    => 'custom',
            '_inline_size'  => $percent,
            'width'         => ['unit' => '%', 'size' => $percent, 'sizes' => []],
            'content_width' => 'full',
        ];
        if ($tablet !== null) {
            $settings['_inline_size_tablet'] = $tablet;
            $settings['width_tablet']        = ['unit' => '%', 'size' => $tablet, 'sizes' => []];
        }
        if ($mobile !== null) {
            $settings['_inline_size_mobile'] = $mobile;
            $settings['width_mobile']        = ['unit' => '%', 'size' => $mobile, 'sizes' => []];
        }

        $data = Elementor_Data::get_page_data($page_id);
        if (!$data) {
            return new \WP_REST_Response(['error' => 'No Elementor data found'], 404);
        }

        $ok = Elementor_Data::update_element_settings($data, $element_id, $settings);
        if (!$ok) {
            return new \WP_REST_Response(['error' => "Element '$element_id' not found"], 404);
        }

        Elementor_Data::save_page_data($page_id, $data);

        return new \WP_REST_Response([
            'success'    => true,
            'element_id' => $element_id,
            'applied'    => $settings,
        ], 200);
    }

    /**
     * Find elements by widgetType, elType, or text contained in settings.
     * Query params:
     *   - widget=wd_banner    → match widgetType
     *   - elType=container    → match elType
     *   - contains=Hello      → search text in JSON-serialized settings
     * Returns: [{id, widgetType, elType, depth, parent_id}, ...]
     */
    public function find_elements(\WP_REST_Request $request): \WP_REST_Response {
        $page_id = (int) $request['id'];
        $widget  = $request->get_param('widget');
        $eltype  = $request->get_param('elType');
        $needle  = $request->get_param('contains');

        if (!$widget && !$eltype && !$needle) {
            return new \WP_REST_Response(['error' => 'Provide at least one of: widget, elType, contains'], 400);
        }

        $data = Elementor_Data::get_page_data($page_id);
        if (!$data) {
            return new \WP_REST_Response(['error' => 'No Elementor data found'], 404);
        }

        $matches = [];
        $walk = function ($nodes, $parent_id = null, $depth = 0) use (&$walk, &$matches, $widget, $eltype, $needle) {
            foreach ($nodes as $node) {
                $match = true;
                if ($widget && ($node['widgetType'] ?? null) !== $widget) $match = false;
                if ($eltype && ($node['elType'] ?? null)     !== $eltype) $match = false;
                if ($needle) {
                    $haystack = json_encode($node['settings'] ?? []);
                    if (stripos($haystack, $needle) === false) $match = false;
                }
                if ($match) {
                    $matches[] = [
                        'id'         => $node['id'] ?? null,
                        'widgetType' => $node['widgetType'] ?? null,
                        'elType'     => $node['elType'] ?? null,
                        'depth'      => $depth,
                        'parent_id'  => $parent_id,
                    ];
                }
                if (!empty($node['elements']) && is_array($node['elements'])) {
                    $walk($node['elements'], $node['id'] ?? null, $depth + 1);
                }
            }
        };
        $walk($data);

        return new \WP_REST_Response([
            'count'   => count($matches),
            'matches' => $matches,
        ], 200);
    }

    // ── Sections ─────────────────────────────────────────────

    public function add_section(\WP_REST_Request $request): \WP_REST_Response {
        if ($r = $this->reject_if_oversized($request)) return $r;
        $page_id  = (int) $request['id'];
        $body     = $request->get_json_params();
        $data     = Elementor_Data::get_page_data($page_id) ?? [];
        $position = $body['position'] ?? -1;
        $section  = $body['section'] ?? null;

        if (!$section || !is_array($section)) {
            return new \WP_REST_Response(['error' => 'Missing "section" object'], 400);
        }

        // Validate the section subtree before insertion — same container contract
        // as add_element (depth/element-count ceilings, elType allowlist).
        $valid = Validator::validate_tree([$section]);
        if (is_wp_error($valid)) {
            return $this->error_response($valid);
        }

        if (empty($section['id'])) {
            $section['id'] = Element_Factory::generate_id();
        }

        Elementor_Data::insert_element($data, $section, $position);
        Elementor_Data::save_page_data($page_id, $data);

        return new \WP_REST_Response([
            'success'    => true,
            'section_id' => $section['id'],
            'total'      => count($data),
        ], 201);
    }

    // ── Templates ────────────────────────────────────────────

    public function list_templates(\WP_REST_Request $request): \WP_REST_Response {
        $templates = get_posts([
            'post_type'      => 'elementor_library',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ]);

        $result = [];
        foreach ($templates as $tpl) {
            $result[] = [
                'id'         => $tpl->ID,
                'title'      => $tpl->post_title,
                'type'       => get_post_meta($tpl->ID, '_elementor_template_type', true),
                'conditions' => get_post_meta($tpl->ID, '_elementor_conditions', true) ?: [],
            ];
        }

        return new \WP_REST_Response($result, 200);
    }

    public function create_template(\WP_REST_Request $request): \WP_REST_Response {
        if ($r = $this->reject_if_oversized($request)) return $r;
        $body       = $request->get_json_params();
        $title      = $body['title'] ?? 'Template';
        $type       = $body['type'] ?? 'section';
        $data       = $body['data'] ?? [];
        $conditions = $body['conditions'] ?? ['include/general'];

        // Footgun guard: templates are created as DRAFT unless the caller asks for
        // publish explicitly — a published header/footer with include/general goes
        // live site-wide at creation time.
        $status = $body['status'] ?? 'draft';
        if (!in_array($status, ['draft', 'publish'], true)) {
            return new \WP_REST_Response(
                ['error' => "Invalid status '{$status}' — allowed: draft, publish"], 400);
        }

        if (!empty($data) && is_array($data)) {
            $valid = Validator::validate_tree($data);
            if (is_wp_error($valid)) {
                return $this->error_response($valid);
            }
        }

        $post_id = Elementor_Data::create_template($title, $type, $data, $conditions, $status);

        if (!$post_id) {
            return new \WP_REST_Response(['error' => 'Failed to create template'], 500);
        }

        return new \WP_REST_Response([
            'id'     => $post_id,
            'title'  => $title,
            'type'   => $type,
            'status' => $status,
        ], 201);
    }

    public function delete_template(\WP_REST_Request $request): \WP_REST_Response {
        $post_id = (int) $request['id'];
        $force   = filter_var($request->get_param('force'), FILTER_VALIDATE_BOOLEAN);

        $ok = Elementor_Data::delete_template($post_id, $force);
        if (!$ok) {
            return new \WP_REST_Response(
                ['error' => 'Template not found (or not an elementor_library post)'], 404);
        }

        return new \WP_REST_Response([
            'id'      => $post_id,
            'deleted' => true,
            'mode'    => $force ? 'permanent' : 'trash',
        ], 200);
    }

    // ── Kit ──────────────────────────────────────────────────

    public function get_kit(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response(Elementor_Data::get_kit_settings(), 200);
    }

    public function update_kit(\WP_REST_Request $request): \WP_REST_Response {
        if ($r = $this->reject_if_oversized($request)) return $r;
        $body    = $request->get_json_params();
        $success = Elementor_Data::update_kit_settings($body);
        return new \WP_REST_Response(['success' => $success], $success ? 200 : 500);
    }

    public function get_kit_globals(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response(Elementor_Data::get_kit_globals(), 200);
    }

    // ── Widgets ──────────────────────────────────────────────

    public function list_widgets(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response(Elementor_Data::list_widgets(), 200);
    }

    public function get_widget_schema(\WP_REST_Request $request): \WP_REST_Response {
        $name   = $request['name'];
        $schema = Elementor_Data::get_widget_schema($name);

        if ($schema === null) {
            return new \WP_REST_Response(['error' => "Widget '$name' not found"], 404);
        }

        return new \WP_REST_Response($schema, 200);
    }

    public function get_widget_defaults(\WP_REST_Request $request): \WP_REST_Response {
        $name     = $request['name'];
        $defaults = Elementor_Data::get_widget_defaults($name);

        if ($defaults === null) {
            return new \WP_REST_Response(['error' => "Widget '$name' not found"], 404);
        }

        return new \WP_REST_Response($defaults, 200);
    }

    // ── Media ────────────────────────────────────────────────

    public function import_media(\WP_REST_Request $request): \WP_REST_Response {
        if ($r = $this->reject_if_oversized($request)) return $r;
        $body  = $request->get_json_params();
        // Accept `source_path` (canonical, matches MCP) and legacy `path`.
        $path  = $body['source_path'] ?? ($body['path'] ?? '');
        $title = $body['title'] ?? '';

        // Resolve + validate the path: must be a real image inside the uploads dir.
        // Guards against path traversal / LFI on this write-capable endpoint.
        $resolved = Validator::resolve_media_path((string) $path);
        if (is_wp_error($resolved)) {
            return $this->error_response($resolved);
        }

        $attach_id = Elementor_Data::import_image($resolved, $title);

        if (!$attach_id) {
            return new \WP_REST_Response(['error' => "Failed to import: $path"], 500);
        }

        return new \WP_REST_Response([
            'id'  => $attach_id,
            'url' => wp_get_attachment_url($attach_id),
        ], 201);
    }

    // ── Cache ────────────────────────────────────────────────

    public function flush_css(\WP_REST_Request $request): \WP_REST_Response {
        $body    = $request->get_json_params();
        $post_id = $body['post_id'] ?? 0;

        if ($post_id) {
            Elementor_Data::flush_css($post_id);
        } else {
            Elementor_Data::flush_all_css();
        }

        return new \WP_REST_Response(['success' => true, 'scope' => $post_id ? "post-$post_id" : 'all'], 200);
    }

    // ── Build Page (composite) ───────────────────────────────

    public function build_page(\WP_REST_Request $request): \WP_REST_Response {
        if ($r = $this->reject_if_oversized($request)) return $r;
        $body = $request->get_json_params();

        // Validate the element tree up front (if provided) — fail before creating a page.
        if (!empty($body['data']) && is_array($body['data'])) {
            $valid = Validator::validate_tree($body['data']);
            if (is_wp_error($valid)) {
                return $this->error_response($valid);
            }
        }

        // Create or update page
        $page_id = $body['page_id'] ?? 0;
        if (!$page_id) {
            $page_id = wp_insert_post([
                'post_title'  => $body['title'] ?? 'New Page',
                'post_name'   => $body['slug'] ?? '',
                'post_type'   => 'page',
                'post_status' => $body['status'] ?? 'publish',
            ]);
            if (is_wp_error($page_id)) {
                return new \WP_REST_Response(['error' => $page_id->get_error_message()], 500);
            }
        } else {
            // Updating an existing page — the route has no {id}, so the generic
            // permission_callback (edit_pages) ran; enforce per-post edit here.
            if (!current_user_can('edit_post', (int) $page_id)) {
                return $this->error_response(
                    new \WP_Error('forbidden', 'You do not have permission to edit this page.', ['status' => 403])
                );
            }
        }

        // Import images if provided (each path validated against traversal/LFI).
        // Accept both `source_path` (canonical, matches the MCP ability) and the
        // legacy `path` key so neither surface silently imports nothing.
        $media_map = [];
        if (!empty($body['images']) && is_array($body['images'])) {
            foreach ($body['images'] as $key => $img) {
                $src = $img['source_path'] ?? ($img['path'] ?? '');
                $resolved = Validator::resolve_media_path((string) $src);
                if (is_wp_error($resolved)) {
                    $media_map[$key] = ['id' => 0, 'url' => '', 'error' => $resolved->get_error_message()];
                    continue;
                }
                $attach_id = Elementor_Data::import_image($resolved, $img['title'] ?? '');
                $media_map[$key] = [
                    'id'  => $attach_id,
                    'url' => $attach_id ? wp_get_attachment_url($attach_id) : '',
                ];
            }
        }

        // Save Elementor data
        if (!empty($body['data']) && is_array($body['data'])) {
            Elementor_Data::save_page_data($page_id, $body['data']);
        }

        return new \WP_REST_Response([
            'success'   => true,
            'page_id'   => $page_id,
            'url'       => get_permalink($page_id),
            'media_map' => $media_map,
        ], 201);
    }

    // ── Health ───────────────────────────────────────────────

    public function health(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response([
            'ok'                => true,
            'plugin'            => 'neoservice-elementor-api',
            'plugin_version'    => defined('NEOSERVICE_ELEMENTOR_API_VERSION') ? NEOSERVICE_ELEMENTOR_API_VERSION : 'unknown',
            'elementor_active'  => did_action('elementor/loaded') > 0,
            'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
            'namespace'         => self::NAMESPACE,
        ], 200);
    }
}
