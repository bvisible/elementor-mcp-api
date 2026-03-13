<?php
namespace NeoService\ElementorAPI;

/**
 * Registers NeoService abilities for the WordPress Abilities API + MCP Adapter.
 * Each ability is exposed as an MCP tool via the default MCP server.
 */
class Abilities_Provider {

    /**
     * MCP meta for read-only abilities.
     */
    private static function meta_read(): array {
        return [
            'mcp' => ['public' => true, 'type' => 'tool'],
            'annotations' => [
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ],
        ];
    }

    /**
     * MCP meta for write abilities.
     */
    private static function meta_write(bool $destructive = false): array {
        return [
            'mcp' => ['public' => true, 'type' => 'tool'],
            'annotations' => [
                'readonly'    => false,
                'destructive' => $destructive,
                'idempotent'  => false,
            ],
        ];
    }

    /**
     * Permission: can read posts.
     */
    public static function can_read(): bool {
        return current_user_can('read');
    }

    /**
     * Permission: can edit posts.
     */
    public static function can_edit(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Register the ability category and all abilities.
     */
    public static function register_category(): void {
        wp_register_ability_category('neoservice-elementor', [
            'label'       => 'NeoService Elementor',
            'description' => 'AI-driven Elementor page building tools for creating, reading, and modifying Elementor pages, elements, templates, and global settings.',
        ]);
    }

    /**
     * Register all NeoService abilities.
     */
    public static function register(): void {
        self::register_page_abilities();
        self::register_element_abilities();
        self::register_template_abilities();
        self::register_kit_abilities();
        self::register_widget_abilities();
        self::register_utility_abilities();
    }

    // ── Page Abilities ──────────────────────────────────────

    private static function register_page_abilities(): void {

        wp_register_ability('neoservice/list-pages', [
            'label'       => 'List Pages',
            'description' => 'List all WordPress pages with their Elementor status. Returns page ID, title, slug, status, URL, and whether the page uses Elementor.',
            'category'    => 'neoservice-elementor',
            'output_schema' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'    => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'slug'  => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'url'   => ['type' => 'string'],
                        'has_elementor' => ['type' => 'boolean'],
                    ],
                ],
            ],
            'execute_callback' => function () {
                $pages  = get_pages(['sort_column' => 'post_title']);
                $result = [];
                foreach ($pages as $page) {
                    $result[] = [
                        'id'            => $page->ID,
                        'title'         => $page->post_title,
                        'slug'          => $page->post_name,
                        'status'        => $page->post_status,
                        'url'           => get_permalink($page->ID),
                        'has_elementor' => !empty(get_post_meta($page->ID, '_elementor_data', true)),
                    ];
                }
                return $result;
            },
            'permission_callback' => [self::class, 'can_read'],
            'meta' => self::meta_read(),
        ]);

        wp_register_ability('neoservice/get-page-structure', [
            'label'       => 'Get Page Structure',
            'description' => 'Get a compact summary of an Elementor page structure showing element IDs, types, widget types, and key settings hints. Use this to understand the page layout before making changes.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'The WordPress page/post ID.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'id'        => ['type' => 'integer'],
                    'title'     => ['type' => 'string'],
                    'structure' => ['type' => 'array'],
                ],
            ],
            'execute_callback' => function ($input) {
                $post_id   = (int) $input['post_id'];
                $structure = Elementor_Data::get_page_structure($post_id);
                if (!$structure) {
                    return new \WP_Error('not_found', 'Page not found or has no Elementor data.');
                }
                return [
                    'id'        => $post_id,
                    'title'     => get_the_title($post_id),
                    'structure' => $structure,
                ];
            },
            'permission_callback' => [self::class, 'can_read'],
            'meta' => self::meta_read(),
        ]);

        wp_register_ability('neoservice/get-page-data', [
            'label'       => 'Get Page Data',
            'description' => 'Get the full Elementor element tree (JSON) for a page. Returns the complete data structure including all settings. Use get-page-structure for a lighter overview.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'The WordPress page/post ID.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'id'    => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'data'  => ['type' => 'array'],
                ],
            ],
            'execute_callback' => function ($input) {
                $post_id = (int) $input['post_id'];
                $data    = Elementor_Data::get_page_data($post_id);
                if (!$data) {
                    return new \WP_Error('not_found', 'Page not found or has no Elementor data.');
                }
                return [
                    'id'    => $post_id,
                    'title' => get_the_title($post_id),
                    'data'  => $data,
                ];
            },
            'permission_callback' => [self::class, 'can_read'],
            'meta' => self::meta_read(),
        ]);

        wp_register_ability('neoservice/save-page-data', [
            'label'       => 'Save Page Data',
            'description' => 'Save the full Elementor element tree for a page. Replaces all existing page content. Use update-element for granular changes.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'data'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'The WordPress page/post ID.',
                    ],
                    'data' => [
                        'type'        => 'array',
                        'description' => 'The full Elementor element tree to save.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'post_id' => ['type' => 'integer'],
                ],
            ],
            'execute_callback' => function ($input) {
                $post_id = (int) $input['post_id'];
                $success = Elementor_Data::save_page_data($post_id, $input['data']);
                return ['success' => $success, 'post_id' => $post_id];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);

        wp_register_ability('neoservice/create-page', [
            'label'       => 'Create Page',
            'description' => 'Create a new WordPress page with optional Elementor content. Returns the new page ID and URL.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['title'],
                'properties' => [
                    'title' => [
                        'type'        => 'string',
                        'description' => 'The page title.',
                    ],
                    'slug' => [
                        'type'        => 'string',
                        'description' => 'The page slug (URL-friendly name). Auto-generated from title if omitted.',
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => 'The page status.',
                        'enum'        => ['publish', 'draft', 'pending'],
                        'default'     => 'publish',
                    ],
                    'data' => [
                        'type'        => 'array',
                        'description' => 'Optional Elementor element tree to set as page content.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer'],
                    'url'     => ['type' => 'string'],
                ],
            ],
            'execute_callback' => function ($input) {
                $post_id = wp_insert_post([
                    'post_title'  => sanitize_text_field($input['title']),
                    'post_name'   => sanitize_title($input['slug'] ?? $input['title']),
                    'post_type'   => 'page',
                    'post_status' => $input['status'] ?? 'publish',
                ]);
                if (is_wp_error($post_id)) return $post_id;
                if (!empty($input['data'])) {
                    Elementor_Data::save_page_data($post_id, $input['data']);
                }
                return ['post_id' => $post_id, 'url' => get_permalink($post_id)];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);
    }

    // ── Element Abilities ───────────────────────────────────

    private static function register_element_abilities(): void {

        wp_register_ability('neoservice/get-element', [
            'label'       => 'Get Element',
            'description' => 'Get the full data (settings, children) of a single Elementor element by ID. Avoids fetching the entire page when you only need one element.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'element_id'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'The WordPress page/post ID.',
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => 'The Elementor element ID (8-char hex string).',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'description' => 'The full element object including id, elType, widgetType, settings, and elements (children).',
            ],
            'execute_callback' => function ($input) {
                $post_id = (int) $input['post_id'];
                $data    = Elementor_Data::get_page_data($post_id);
                if (!$data) return new \WP_Error('not_found', 'Page not found.');

                $found = Elementor_Data::find_element($data, $input['element_id']);
                if (!$found) return new \WP_Error('not_found', 'Element not found.');

                return $found['element'];
            },
            'permission_callback' => [self::class, 'can_read'],
            'meta' => self::meta_read(),
        ]);

        wp_register_ability('neoservice/move-element', [
            'label'       => 'Move Element',
            'description' => 'Move an Elementor element to a new position within the page. Can move to root level or inside a specific parent container. The element is removed from its current location and inserted at the new position.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'element_id'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'The WordPress page/post ID.',
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => 'The element ID to move.',
                    ],
                    'parent_id' => [
                        'type'        => 'string',
                        'description' => 'Target parent element ID. Omit or null for root level.',
                    ],
                    'position' => [
                        'type'        => 'integer',
                        'description' => 'Position index in the target parent. -1 = append at end.',
                        'default'     => -1,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'    => ['type' => 'boolean'],
                    'element_id' => ['type' => 'string'],
                ],
            ],
            'execute_callback' => function ($input) {
                $post_id = (int) $input['post_id'];
                $data    = Elementor_Data::get_page_data($post_id);
                if (!$data) return new \WP_Error('not_found', 'Page not found.');

                $found = Elementor_Data::find_element($data, $input['element_id']);
                if (!$found) return new \WP_Error('not_found', 'Element not found.');

                $element = $found['element'];
                Elementor_Data::remove_element($data, $input['element_id']);

                $position = (int) ($input['position'] ?? -1);
                if (!empty($input['parent_id'])) {
                    $parent = Elementor_Data::find_element($data, $input['parent_id']);
                    if (!$parent) return new \WP_Error('not_found', 'Target parent not found.');
                    if (!isset($parent['element']['elements'])) {
                        $parent['element']['elements'] = [];
                    }
                    Elementor_Data::insert_element($parent['element']['elements'], $element, $position);
                } else {
                    Elementor_Data::insert_element($data, $element, $position);
                }

                Elementor_Data::save_page_data($post_id, $data);
                return ['success' => true, 'element_id' => $input['element_id']];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);

        wp_register_ability('neoservice/update-element', [
            'label'       => 'Update Element Settings',
            'description' => 'Update the settings of a specific Elementor element by its ID. Merges new settings with existing ones. Use get-page-structure first to find element IDs.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'element_id', 'settings'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'The WordPress page/post ID.',
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => 'The Elementor element ID (8-char hex string).',
                    ],
                    'settings' => [
                        'type'        => 'object',
                        'description' => 'Settings to merge into the element. Use get-widget-schema to see available settings for a widget type.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'    => ['type' => 'boolean'],
                    'element_id' => ['type' => 'string'],
                ],
            ],
            'execute_callback' => function ($input) {
                $post_id = (int) $input['post_id'];
                $data    = Elementor_Data::get_page_data($post_id);
                if (!$data) return new \WP_Error('not_found', 'Page not found.');

                $ok = Elementor_Data::update_element_settings($data, $input['element_id'], $input['settings']);
                if (!$ok) return new \WP_Error('not_found', 'Element not found.');

                Elementor_Data::save_page_data($post_id, $data);
                return ['success' => true, 'element_id' => $input['element_id']];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);

        wp_register_ability('neoservice/add-element', [
            'label'       => 'Add Element',
            'description' => 'Add a new Elementor element (container or widget) to a page. Can be added to the root level or inside a specific parent container. Use Element Factory format for the element structure.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'element'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'The WordPress page/post ID.',
                    ],
                    'parent_id' => [
                        'type'        => 'string',
                        'description' => 'Optional parent element ID to add inside. If omitted, adds to root level.',
                    ],
                    'position' => [
                        'type'        => 'integer',
                        'description' => 'Optional position index. -1 or omitted = append at end.',
                        'default'     => -1,
                    ],
                    'element' => [
                        'type'        => 'object',
                        'description' => 'The Elementor element to add. Must include id, elType, settings, elements. Use generate-element to create well-formed elements.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'element_id' => ['type' => 'string'],
                ],
            ],
            'execute_callback' => function ($input) {
                $post_id = (int) $input['post_id'];
                $data    = Elementor_Data::get_page_data($post_id);
                if (!$data) {
                    $data = [];
                }

                $element  = $input['element'];
                $position = (int) ($input['position'] ?? -1);

                if (!empty($input['parent_id'])) {
                    $found = Elementor_Data::find_element($data, $input['parent_id']);
                    if (!$found) return new \WP_Error('not_found', 'Parent element not found.');
                    Elementor_Data::insert_element($found['element']['elements'], $element, $position);
                } else {
                    Elementor_Data::insert_element($data, $element, $position);
                }

                Elementor_Data::save_page_data($post_id, $data);
                return ['success' => true, 'element_id' => $element['id'] ?? ''];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);

        wp_register_ability('neoservice/remove-element', [
            'label'       => 'Remove Element',
            'description' => 'Remove an Elementor element by its ID from a page. Also removes all children.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'element_id'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'The WordPress page/post ID.',
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => 'The Elementor element ID to remove.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                ],
            ],
            'execute_callback' => function ($input) {
                $post_id = (int) $input['post_id'];
                $data    = Elementor_Data::get_page_data($post_id);
                if (!$data) return new \WP_Error('not_found', 'Page not found.');

                $ok = Elementor_Data::remove_element($data, $input['element_id']);
                if (!$ok) return new \WP_Error('not_found', 'Element not found.');

                Elementor_Data::save_page_data($post_id, $data);
                return ['success' => true];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(true),
        ]);

        wp_register_ability('neoservice/duplicate-element', [
            'label'       => 'Duplicate Element',
            'description' => 'Duplicate an Elementor element by its ID. The clone is inserted right after the original with new IDs.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'element_id'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'The WordPress page/post ID.',
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => 'The Elementor element ID to duplicate.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'      => ['type' => 'boolean'],
                    'new_id'       => ['type' => 'string'],
                ],
            ],
            'execute_callback' => function ($input) {
                $post_id = (int) $input['post_id'];
                $data    = Elementor_Data::get_page_data($post_id);
                if (!$data) return new \WP_Error('not_found', 'Page not found.');

                $new_id = Elementor_Data::duplicate_element($data, $input['element_id']);
                if (!$new_id) return new \WP_Error('not_found', 'Element not found.');

                Elementor_Data::save_page_data($post_id, $data);
                return ['success' => true, 'new_id' => $new_id];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);

        wp_register_ability('neoservice/generate-element', [
            'label'       => 'Generate Element',
            'description' => 'Generate a well-formed Elementor element using the Element Factory. Supports containers, rows, columns, and all common widgets (heading, text, image, button, form, etc.) and composite patterns (hero, content-row). Returns the element JSON ready to be used with add-element or save-page-data.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['type'],
                'properties' => [
                    'type' => [
                        'type'        => 'string',
                        'description' => 'Element type to create.',
                        'enum'        => ['container', 'row', 'column', 'heading', 'text', 'image', 'button', 'divider', 'spacer', 'icon', 'social-icons', 'nav-menu', 'form', 'hero', 'content-row', 'widget'],
                    ],
                    'settings' => [
                        'type'        => 'object',
                        'description' => 'Settings for the element. Varies by type.',
                    ],
                    'widget_type' => [
                        'type'        => 'string',
                        'description' => 'For type=widget, the Elementor widget type name (e.g. "counter", "progress", "toggle").',
                    ],
                    'children' => [
                        'type'        => 'array',
                        'description' => 'For containers/rows, child element arrays.',
                    ],
                    'title' => ['type' => 'string', 'description' => 'For heading/hero: the title text.'],
                    'content' => ['type' => 'string', 'description' => 'For text: the HTML content.'],
                    'tag' => ['type' => 'string', 'description' => 'For heading: HTML tag (h1-h6).'],
                    'width' => ['type' => 'number', 'description' => 'For column: width percentage.'],
                    'attachment_id' => ['type' => 'integer', 'description' => 'For image/hero: WordPress attachment ID.'],
                    'text_content' => ['type' => 'string', 'description' => 'For content-row: body text.'],
                    'image_left' => ['type' => 'boolean', 'description' => 'For content-row: image on left side.'],
                    'url' => ['type' => 'string', 'description' => 'For button: link URL.'],
                    'button_text' => ['type' => 'string', 'description' => 'For button: button label.'],
                    'icons' => ['type' => 'object', 'description' => 'For social-icons: {platform: url} map.'],
                    'is_inner' => ['type' => 'boolean', 'description' => 'For container: whether it is a nested container.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'description' => 'The generated Elementor element ready for use.',
            ],
            'execute_callback' => function ($input) {
                $type     = $input['type'];
                $settings = $input['settings'] ?? [];
                $children = $input['children'] ?? [];

                switch ($type) {
                    case 'container':
                        return Element_Factory::container($settings, $children, $input['is_inner'] ?? false);
                    case 'row':
                        return Element_Factory::row($children, $settings);
                    case 'column':
                        return Element_Factory::column($children, $input['width'] ?? 50, $settings);
                    case 'heading':
                        return Element_Factory::heading($input['title'] ?? '', $input['tag'] ?? 'h2', $settings);
                    case 'text':
                        return Element_Factory::text($input['content'] ?? '', $settings);
                    case 'image':
                        return Element_Factory::image($input['attachment_id'] ?? 0, $settings);
                    case 'button':
                        return Element_Factory::button($input['button_text'] ?? 'Click', $input['url'] ?? '#', $settings);
                    case 'divider':
                        return Element_Factory::divider($settings);
                    case 'spacer':
                        return Element_Factory::spacer($input['size'] ?? 30, $settings);
                    case 'icon':
                        return Element_Factory::icon($input['icon'] ?? 'fas fa-gem', $settings);
                    case 'social-icons':
                        return Element_Factory::social_icons($input['icons'] ?? [], $settings);
                    case 'nav-menu':
                        return Element_Factory::nav_menu($input['menu'] ?? '', $settings);
                    case 'form':
                        return Element_Factory::form(
                            $input['form_name'] ?? 'Contact',
                            $input['fields'] ?? [],
                            $input['email_to'] ?? get_option('admin_email'),
                            $settings
                        );
                    case 'hero':
                        return Element_Factory::hero($input['title'] ?? '', $input['attachment_id'] ?? 0, $settings);
                    case 'content-row':
                        return Element_Factory::content_row(
                            $input['title'] ?? '',
                            $input['text_content'] ?? '',
                            $input['attachment_id'] ?? 0,
                            $input['image_left'] ?? true
                        );
                    case 'widget':
                        return Element_Factory::widget($input['widget_type'] ?? 'heading', $settings);
                    default:
                        return new \WP_Error('invalid_type', "Unknown element type: $type");
                }
            },
            'permission_callback' => [self::class, 'can_read'],
            'meta' => self::meta_read(),
        ]);
    }

    // ── Template Abilities ──────────────────────────────────

    private static function register_template_abilities(): void {

        wp_register_ability('neoservice/list-templates', [
            'label'       => 'List Templates',
            'description' => 'List all Elementor Theme Builder templates (headers, footers, single, archive, etc.) with their display conditions.',
            'category'    => 'neoservice-elementor',
            'output_schema' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'         => ['type' => 'integer'],
                        'title'      => ['type' => 'string'],
                        'type'       => ['type' => 'string'],
                        'conditions' => ['type' => 'array'],
                    ],
                ],
            ],
            'execute_callback' => function () {
                $templates = get_posts([
                    'post_type'   => 'elementor_library',
                    'numberposts' => -1,
                    'post_status' => 'publish',
                ]);
                $result = [];
                foreach ($templates as $tpl) {
                    $result[] = [
                        'id'         => $tpl->ID,
                        'title'      => $tpl->post_title,
                        'type'       => get_post_meta($tpl->ID, '_elementor_template_type', true) ?: 'unknown',
                        'conditions' => get_post_meta($tpl->ID, '_elementor_conditions', true) ?: [],
                    ];
                }
                return $result;
            },
            'permission_callback' => [self::class, 'can_read'],
            'meta' => self::meta_read(),
        ]);

        wp_register_ability('neoservice/create-template', [
            'label'       => 'Create Template',
            'description' => 'Create an Elementor Theme Builder template (header, footer, single, archive, etc.) with display conditions. Conditions format: ["include/general"] for entire site.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['title', 'type', 'data'],
                'properties' => [
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Template title.',
                    ],
                    'type' => [
                        'type'        => 'string',
                        'description' => 'Template type.',
                        'enum'        => ['header', 'footer', 'single', 'archive', 'error-404', 'popup', 'loop-item'],
                    ],
                    'data' => [
                        'type'        => 'array',
                        'description' => 'Elementor element tree for the template content.',
                    ],
                    'conditions' => [
                        'type'        => 'array',
                        'description' => 'Display conditions. Default: ["include/general"] (entire site).',
                        'items'       => ['type' => 'string'],
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer'],
                ],
            ],
            'execute_callback' => function ($input) {
                $conditions = $input['conditions'] ?? ['include/general'];
                $post_id = Elementor_Data::create_template(
                    sanitize_text_field($input['title']),
                    $input['type'],
                    $input['data'],
                    $conditions
                );
                if (!$post_id) return new \WP_Error('failed', 'Failed to create template.');
                return ['post_id' => $post_id];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);
    }

    // ── Kit / Global Settings ───────────────────────────────

    private static function register_kit_abilities(): void {

        wp_register_ability('neoservice/get-kit', [
            'label'       => 'Get Global Kit Settings',
            'description' => 'Get the Elementor global kit settings including site colors, typography, button styles, and layout defaults.',
            'category'    => 'neoservice-elementor',
            'output_schema' => [
                'type' => 'object',
            ],
            'execute_callback' => function () {
                return Elementor_Data::get_kit_settings();
            },
            'permission_callback' => [self::class, 'can_read'],
            'meta' => self::meta_read(),
        ]);

        wp_register_ability('neoservice/update-kit', [
            'label'       => 'Update Global Kit Settings',
            'description' => 'Update Elementor global kit settings (colors, typography, buttons, etc.). Merges with existing settings. Automatically flushes all CSS cache.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['settings'],
                'properties' => [
                    'settings' => [
                        'type'        => 'object',
                        'description' => 'Kit settings to merge. Keys include: custom_colors, custom_typography, button_*, body_*, etc.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                ],
            ],
            'execute_callback' => function ($input) {
                $ok = Elementor_Data::update_kit_settings($input['settings']);
                if (!$ok) return new \WP_Error('failed', 'Kit not found or update failed.');
                return ['success' => true];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);
    }

    // ── Widget Discovery ────────────────────────────────────

    private static function register_widget_abilities(): void {

        wp_register_ability('neoservice/list-widgets', [
            'label'       => 'List Widgets',
            'description' => 'List all available Elementor widgets with their names, titles, icons, and categories.',
            'category'    => 'neoservice-elementor',
            'output_schema' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'       => ['type' => 'string'],
                        'title'      => ['type' => 'string'],
                        'icon'       => ['type' => 'string'],
                        'categories' => ['type' => 'array'],
                    ],
                ],
            ],
            'execute_callback' => function () {
                return Elementor_Data::list_widgets();
            },
            'permission_callback' => [self::class, 'can_read'],
            'meta' => self::meta_read(),
        ]);

        wp_register_ability('neoservice/get-widget-schema', [
            'label'       => 'Get Widget Schema',
            'description' => 'Get the full control schema for a specific Elementor widget, showing all available settings with their types, labels, defaults, and options. Essential for knowing what settings to pass when creating or updating widgets.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['widget_name'],
                'properties' => [
                    'widget_name' => [
                        'type'        => 'string',
                        'description' => 'The widget type name (e.g. "heading", "text-editor", "image", "button", "form").',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
            ],
            'execute_callback' => function ($input) {
                $schema = Elementor_Data::get_widget_schema($input['widget_name']);
                if (!$schema) return new \WP_Error('not_found', 'Widget not found.');
                return $schema;
            },
            'permission_callback' => [self::class, 'can_read'],
            'meta' => self::meta_read(),
        ]);
    }

    // ── Utility Abilities ───────────────────────────────────

    private static function register_utility_abilities(): void {

        wp_register_ability('neoservice/flush-css', [
            'label'       => 'Flush CSS Cache',
            'description' => 'Flush the Elementor CSS cache. Optionally for a specific post, or all posts if no post_id is given.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Optional post ID. If omitted, flushes all CSS.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                ],
            ],
            'execute_callback' => function ($input = []) {
                $post_id = (int) ($input['post_id'] ?? 0);
                if ($post_id) {
                    Elementor_Data::flush_css($post_id);
                } else {
                    Elementor_Data::flush_all_css();
                }
                return ['success' => true];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);

        wp_register_ability('neoservice/build-page', [
            'label'       => 'Build Complete Page',
            'description' => 'Create or update a complete Elementor page in a single call. Optionally creates the page, imports images, and saves the full Elementor element tree. This is the most powerful ability for building pages from scratch.',
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['data'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Existing page ID to update. If omitted, a new page is created.',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Page title (required when creating a new page).',
                    ],
                    'slug' => [
                        'type'        => 'string',
                        'description' => 'Page slug (for new pages).',
                    ],
                    'data' => [
                        'type'        => 'array',
                        'description' => 'The full Elementor element tree.',
                    ],
                    'images' => [
                        'type'        => 'array',
                        'description' => 'Optional array of {source_path, title} objects to import into the media library before building.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'source_path' => ['type' => 'string'],
                                'title'       => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => ['type' => 'integer'],
                    'url'        => ['type' => 'string'],
                    'image_ids'  => ['type' => 'object'],
                ],
            ],
            'execute_callback' => function ($input) {
                // Import images first
                $image_ids = [];
                if (!empty($input['images'])) {
                    foreach ($input['images'] as $img) {
                        $id = Elementor_Data::import_image($img['source_path'], $img['title'] ?? '');
                        if ($id) $image_ids[$img['title'] ?? basename($img['source_path'])] = $id;
                    }
                }

                // Create or get page
                $post_id = $input['post_id'] ?? 0;
                if (!$post_id) {
                    if (empty($input['title'])) {
                        return new \WP_Error('missing_title', 'Title is required when creating a new page.');
                    }
                    $post_id = wp_insert_post([
                        'post_title'  => sanitize_text_field($input['title']),
                        'post_name'   => sanitize_title($input['slug'] ?? $input['title']),
                        'post_type'   => 'page',
                        'post_status' => 'publish',
                    ]);
                    if (is_wp_error($post_id)) return $post_id;
                }

                // Save Elementor data
                Elementor_Data::save_page_data($post_id, $input['data']);

                return [
                    'post_id'   => $post_id,
                    'url'       => get_permalink($post_id),
                    'image_ids' => $image_ids,
                ];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);
    }
}
