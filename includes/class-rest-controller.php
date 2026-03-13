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

        register_rest_route(self::NAMESPACE, '/template', [
            'methods'  => 'POST',
            'callback' => [$this, 'create_template'],
            ...$editor,
        ]);

        // ── Kit / Global Settings ────────────────────────
        register_rest_route(self::NAMESPACE, '/kit', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_kit'],
            ...$reader,
        ]);

        register_rest_route(self::NAMESPACE, '/kit', [
            'methods'  => 'PUT',
            'callback' => [$this, 'update_kit'],
            ...$editor,
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
    }

    // ── Permission checks ────────────────────────────────────

    public function check_read_permission(): bool {
        return current_user_can('read');
    }

    public function check_edit_permission(): bool {
        return current_user_can('edit_posts');
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
        $id   = (int) $request['id'];
        $body = $request->get_json_params();

        if (empty($body['data']) || !is_array($body['data'])) {
            return new \WP_REST_Response(['error' => 'Missing or invalid "data" array'], 400);
        }

        $success = Elementor_Data::save_page_data($id, $body['data']);

        return new \WP_REST_Response([
            'success'  => $success,
            'id'       => $id,
            'sections' => count($body['data']),
        ], $success ? 200 : 500);
    }

    public function create_page(\WP_REST_Request $request): \WP_REST_Response {
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

        // If Elementor data provided, save it
        if (!empty($body['data']) && is_array($body['data'])) {
            Elementor_Data::save_page_data($post_id, $body['data']);
        }

        return new \WP_REST_Response([
            'id'    => $post_id,
            'title' => $title,
            'url'   => get_permalink($post_id),
        ], 201);
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

        // Ensure element has an ID
        if (empty($element['id'])) {
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

    // ── Sections ─────────────────────────────────────────────

    public function add_section(\WP_REST_Request $request): \WP_REST_Response {
        $page_id  = (int) $request['id'];
        $body     = $request->get_json_params();
        $data     = Elementor_Data::get_page_data($page_id) ?? [];
        $position = $body['position'] ?? -1;
        $section  = $body['section'] ?? null;

        if (!$section || !is_array($section)) {
            return new \WP_REST_Response(['error' => 'Missing "section" object'], 400);
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
        $body       = $request->get_json_params();
        $title      = $body['title'] ?? 'Template';
        $type       = $body['type'] ?? 'section';
        $data       = $body['data'] ?? [];
        $conditions = $body['conditions'] ?? ['include/general'];

        $post_id = Elementor_Data::create_template($title, $type, $data, $conditions);

        if (!$post_id) {
            return new \WP_REST_Response(['error' => 'Failed to create template'], 500);
        }

        return new \WP_REST_Response([
            'id'    => $post_id,
            'title' => $title,
            'type'  => $type,
        ], 201);
    }

    // ── Kit ──────────────────────────────────────────────────

    public function get_kit(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response(Elementor_Data::get_kit_settings(), 200);
    }

    public function update_kit(\WP_REST_Request $request): \WP_REST_Response {
        $body    = $request->get_json_params();
        $success = Elementor_Data::update_kit_settings($body);
        return new \WP_REST_Response(['success' => $success], $success ? 200 : 500);
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
        $body  = $request->get_json_params();
        $path  = $body['path'] ?? '';
        $title = $body['title'] ?? '';

        if (empty($path)) {
            return new \WP_REST_Response(['error' => 'Missing "path"'], 400);
        }

        $attach_id = Elementor_Data::import_image($path, $title);

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
        $body = $request->get_json_params();

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
        }

        // Import images if provided
        $media_map = [];
        if (!empty($body['images']) && is_array($body['images'])) {
            foreach ($body['images'] as $key => $img) {
                $attach_id = Elementor_Data::import_image($img['path'] ?? '', $img['title'] ?? '');
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
}
