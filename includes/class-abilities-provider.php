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
            'label'       => __('NeoService Elementor', 'elementor-mcp-api'),
            'description' => __('AI-driven Elementor page building tools for creating, reading, and modifying Elementor pages, elements, templates, and global settings.', 'elementor-mcp-api'),
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
            'label'       => __('List Pages', 'elementor-mcp-api'),
            'description' => __('List all WordPress pages with their Elementor status. Returns page ID, title, slug, status, URL, and whether the page uses Elementor.', 'elementor-mcp-api'),
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
            'label'       => __('Get Page Structure', 'elementor-mcp-api'),
            'description' => __('Get a compact summary of an Elementor page structure showing element IDs, types, widget types, and key settings hints. Use this to understand the page layout before making changes.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => __('The WordPress page/post ID.', 'elementor-mcp-api'),
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
                    return new \WP_Error('not_found', __('Page not found or has no Elementor data.', 'elementor-mcp-api'));
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
            'label'       => __('Get Page Data', 'elementor-mcp-api'),
            'description' => __('Get the full Elementor element tree (JSON) for a page. Returns the complete data structure including all settings. Use get-page-structure for a lighter overview.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => __('The WordPress page/post ID.', 'elementor-mcp-api'),
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
                    return new \WP_Error('not_found', __('Page not found or has no Elementor data.', 'elementor-mcp-api'));
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
            'label'       => __('Save Page Data', 'elementor-mcp-api'),
            'description' => __('Save the full Elementor element tree for a page. Replaces all existing page content. Use update-element for granular changes.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'data'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => __('The WordPress page/post ID.', 'elementor-mcp-api'),
                    ],
                    'data' => [
                        'type'        => 'array',
                        'description' => __('The full Elementor element tree to save.', 'elementor-mcp-api'),
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
            'label'       => __('Create Page', 'elementor-mcp-api'),
            'description' => __('Create a new WordPress page with optional Elementor content. Returns the new page ID and URL.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['title'],
                'properties' => [
                    'title' => [
                        'type'        => 'string',
                        'description' => __('The page title.', 'elementor-mcp-api'),
                    ],
                    'slug' => [
                        'type'        => 'string',
                        'description' => __('The page slug (URL-friendly name). Auto-generated from title if omitted.', 'elementor-mcp-api'),
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => __('The page status.', 'elementor-mcp-api'),
                        'enum'        => ['publish', 'draft', 'pending'],
                        'default'     => 'publish',
                    ],
                    'data' => [
                        'type'        => 'array',
                        'description' => __('Optional Elementor element tree to set as page content.', 'elementor-mcp-api'),
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
            'label'       => __('Get Element', 'elementor-mcp-api'),
            'description' => __('Get the full data (settings, children) of a single Elementor element by ID. Avoids fetching the entire page when you only need one element.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'element_id'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => __('The WordPress page/post ID.', 'elementor-mcp-api'),
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => __('The Elementor element ID (8-char hex string).', 'elementor-mcp-api'),
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'description' => __('The full element object including id, elType, widgetType, settings, and elements (children).', 'elementor-mcp-api'),
            ],
            'execute_callback' => function ($input) {
                $post_id = (int) $input['post_id'];
                $data    = Elementor_Data::get_page_data($post_id);
                if (!$data) return new \WP_Error('not_found', __('Page not found.', 'elementor-mcp-api'));

                $found = Elementor_Data::find_element($data, $input['element_id']);
                if (!$found) return new \WP_Error('not_found', __('Element not found.', 'elementor-mcp-api'));

                return $found['element'];
            },
            'permission_callback' => [self::class, 'can_read'],
            'meta' => self::meta_read(),
        ]);

        wp_register_ability('neoservice/move-element', [
            'label'       => __('Move Element', 'elementor-mcp-api'),
            'description' => __('Move an Elementor element to a new position within the page. Can move to root level or inside a specific parent container. The element is removed from its current location and inserted at the new position.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'element_id'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => __('The WordPress page/post ID.', 'elementor-mcp-api'),
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => __('The element ID to move.', 'elementor-mcp-api'),
                    ],
                    'parent_id' => [
                        'type'        => 'string',
                        'description' => __('Target parent element ID. Omit or null for root level.', 'elementor-mcp-api'),
                    ],
                    'position' => [
                        'type'        => 'integer',
                        'description' => __('Position index in the target parent. -1 = append at end.', 'elementor-mcp-api'),
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
                if (!$data) return new \WP_Error('not_found', __('Page not found.', 'elementor-mcp-api'));

                $found = Elementor_Data::find_element($data, $input['element_id']);
                if (!$found) return new \WP_Error('not_found', __('Element not found.', 'elementor-mcp-api'));

                $element = $found['element'];
                Elementor_Data::remove_element($data, $input['element_id']);

                $position = (int) ($input['position'] ?? -1);
                if (!empty($input['parent_id'])) {
                    $parent = Elementor_Data::find_element($data, $input['parent_id']);
                    if (!$parent) return new \WP_Error('not_found', __('Target parent not found.', 'elementor-mcp-api'));
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
            'label'       => __('Update Element Settings', 'elementor-mcp-api'),
            'description' => __('Update the settings of a specific Elementor element by its ID. Merges new settings with existing ones. Use get-page-structure first to find element IDs.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'element_id', 'settings'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => __('The WordPress page/post ID.', 'elementor-mcp-api'),
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => __('The Elementor element ID (8-char hex string).', 'elementor-mcp-api'),
                    ],
                    'settings' => [
                        'type'        => 'object',
                        'description' => __('Settings to merge into the element. Use get-widget-schema to see available settings for a widget type.', 'elementor-mcp-api'),
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
                if (!$data) return new \WP_Error('not_found', __('Page not found.', 'elementor-mcp-api'));

                $ok = Elementor_Data::update_element_settings($data, $input['element_id'], $input['settings']);
                if (!$ok) return new \WP_Error('not_found', __('Element not found.', 'elementor-mcp-api'));

                Elementor_Data::save_page_data($post_id, $data);
                return ['success' => true, 'element_id' => $input['element_id']];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);

        wp_register_ability('neoservice/add-element', [
            'label'       => __('Add Element', 'elementor-mcp-api'),
            'description' => __('Add a new Elementor element (container or widget) to a page. Can be added to the root level or inside a specific parent container. Use Element Factory format for the element structure.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'element'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => __('The WordPress page/post ID.', 'elementor-mcp-api'),
                    ],
                    'parent_id' => [
                        'type'        => 'string',
                        'description' => __('Optional parent element ID to add inside. If omitted, adds to root level.', 'elementor-mcp-api'),
                    ],
                    'position' => [
                        'type'        => 'integer',
                        'description' => __('Optional position index. -1 or omitted = append at end.', 'elementor-mcp-api'),
                        'default'     => -1,
                    ],
                    'element' => [
                        'type'        => 'object',
                        'description' => __('The Elementor element to add. Must include id, elType, settings, elements. Use generate-element to create well-formed elements.', 'elementor-mcp-api'),
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
                    if (!$found) return new \WP_Error('not_found', __('Parent element not found.', 'elementor-mcp-api'));
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
            'label'       => __('Remove Element', 'elementor-mcp-api'),
            'description' => __('Remove an Elementor element by its ID from a page. Also removes all children.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'element_id'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => __('The WordPress page/post ID.', 'elementor-mcp-api'),
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => __('The Elementor element ID to remove.', 'elementor-mcp-api'),
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
                if (!$data) return new \WP_Error('not_found', __('Page not found.', 'elementor-mcp-api'));

                $ok = Elementor_Data::remove_element($data, $input['element_id']);
                if (!$ok) return new \WP_Error('not_found', __('Element not found.', 'elementor-mcp-api'));

                Elementor_Data::save_page_data($post_id, $data);
                return ['success' => true];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(true),
        ]);

        wp_register_ability('neoservice/duplicate-element', [
            'label'       => __('Duplicate Element', 'elementor-mcp-api'),
            'description' => __('Duplicate an Elementor element by its ID. The clone is inserted right after the original with new IDs.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_id', 'element_id'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => __('The WordPress page/post ID.', 'elementor-mcp-api'),
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => __('The Elementor element ID to duplicate.', 'elementor-mcp-api'),
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
                if (!$data) return new \WP_Error('not_found', __('Page not found.', 'elementor-mcp-api'));

                $new_id = Elementor_Data::duplicate_element($data, $input['element_id']);
                if (!$new_id) return new \WP_Error('not_found', __('Element not found.', 'elementor-mcp-api'));

                Elementor_Data::save_page_data($post_id, $data);
                return ['success' => true, 'new_id' => $new_id];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);

        wp_register_ability('neoservice/generate-element', [
            'label'       => __('Generate Element', 'elementor-mcp-api'),
            'description' => __('Generate a well-formed Elementor element using the Element Factory. Supports containers, rows, columns, and all common widgets (heading, text, image, button, form, etc.) and composite patterns (hero, content-row). Returns the element JSON ready to be used with add-element or save-page-data.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['type'],
                'properties' => [
                    'type' => [
                        'type'        => 'string',
                        'description' => __('Element type to create.', 'elementor-mcp-api'),
                        'enum'        => ['container', 'row', 'column', 'heading', 'text', 'image', 'button', 'divider', 'spacer', 'icon', 'social-icons', 'nav-menu', 'form', 'hero', 'content-row', 'widget'],
                    ],
                    'settings' => [
                        'type'        => 'object',
                        'description' => __('Settings for the element. Varies by type.', 'elementor-mcp-api'),
                    ],
                    'widget_type' => [
                        'type'        => 'string',
                        'description' => __('For type=widget, the Elementor widget type name (e.g. "counter", "progress", "toggle").', 'elementor-mcp-api'),
                    ],
                    'children' => [
                        'type'        => 'array',
                        'description' => __('For containers/rows, child element arrays.', 'elementor-mcp-api'),
                    ],
                    'title' => ['type' => 'string', 'description' => __('For heading/hero: the title text.', 'elementor-mcp-api')],
                    'content' => ['type' => 'string', 'description' => __('For text: the HTML content.', 'elementor-mcp-api')],
                    'tag' => ['type' => 'string', 'description' => __('For heading: HTML tag (h1-h6).', 'elementor-mcp-api')],
                    'width' => ['type' => 'number', 'description' => __('For column: width percentage.', 'elementor-mcp-api')],
                    'attachment_id' => ['type' => 'integer', 'description' => __('For image/hero: WordPress attachment ID.', 'elementor-mcp-api')],
                    'text_content' => ['type' => 'string', 'description' => __('For content-row: body text.', 'elementor-mcp-api')],
                    'image_left' => ['type' => 'boolean', 'description' => __('For content-row: image on left side.', 'elementor-mcp-api')],
                    'url' => ['type' => 'string', 'description' => __('For button: link URL.', 'elementor-mcp-api')],
                    'button_text' => ['type' => 'string', 'description' => __('For button: button label.', 'elementor-mcp-api')],
                    'icons' => ['type' => 'object', 'description' => __('For social-icons: {platform: url} map.', 'elementor-mcp-api')],
                    'is_inner' => ['type' => 'boolean', 'description' => __('For container: whether it is a nested container.', 'elementor-mcp-api')],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'description' => __('The generated Elementor element ready for use.', 'elementor-mcp-api'),
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
                        return new \WP_Error(
                            'invalid_type',
                            /* translators: %s: element type name. */
                            sprintf(__('Unknown element type: %s', 'elementor-mcp-api'), $type)
                        );
                }
            },
            'permission_callback' => [self::class, 'can_read'],
            'meta' => self::meta_read(),
        ]);
    }

    // ── Template Abilities ──────────────────────────────────

    private static function register_template_abilities(): void {

        wp_register_ability('neoservice/list-templates', [
            'label'       => __('List Templates', 'elementor-mcp-api'),
            'description' => __('List all Elementor Theme Builder templates (headers, footers, single, archive, etc.) with their display conditions.', 'elementor-mcp-api'),
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
            'label'       => __('Create Template', 'elementor-mcp-api'),
            'description' => __('Create an Elementor Theme Builder template (header, footer, single, archive, etc.) with display conditions. Conditions format: ["include/general"] for entire site.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['title', 'type', 'data'],
                'properties' => [
                    'title' => [
                        'type'        => 'string',
                        'description' => __('Template title.', 'elementor-mcp-api'),
                    ],
                    'type' => [
                        'type'        => 'string',
                        'description' => __('Template type.', 'elementor-mcp-api'),
                        'enum'        => ['header', 'footer', 'single', 'archive', 'error-404', 'popup', 'loop-item'],
                    ],
                    'data' => [
                        'type'        => 'array',
                        'description' => __('Elementor element tree for the template content.', 'elementor-mcp-api'),
                    ],
                    'conditions' => [
                        'type'        => 'array',
                        'description' => __('Display conditions. Default: ["include/general"] (entire site).', 'elementor-mcp-api'),
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
                if (!$post_id) return new \WP_Error('failed', __('Failed to create template.', 'elementor-mcp-api'));
                return ['post_id' => $post_id];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);
    }

    // ── Kit / Global Settings ───────────────────────────────

    private static function register_kit_abilities(): void {

        wp_register_ability('neoservice/get-kit', [
            'label'       => __('Get Global Kit Settings', 'elementor-mcp-api'),
            'description' => __('Get the Elementor global kit settings including site colors, typography, button styles, and layout defaults.', 'elementor-mcp-api'),
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
            'label'       => __('Update Global Kit Settings', 'elementor-mcp-api'),
            'description' => __('Update Elementor global kit settings (colors, typography, buttons, etc.). Merges with existing settings. Automatically flushes all CSS cache.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['settings'],
                'properties' => [
                    'settings' => [
                        'type'        => 'object',
                        'description' => __('Kit settings to merge. Keys include: custom_colors, custom_typography, button_*, body_*, etc.', 'elementor-mcp-api'),
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
                if (!$ok) return new \WP_Error('failed', __('Kit not found or update failed.', 'elementor-mcp-api'));
                return ['success' => true];
            },
            'permission_callback' => [self::class, 'can_edit'],
            'meta' => self::meta_write(),
        ]);
    }

    // ── Widget Discovery ────────────────────────────────────

    private static function register_widget_abilities(): void {

        wp_register_ability('neoservice/list-widgets', [
            'label'       => __('List Widgets', 'elementor-mcp-api'),
            'description' => __('List all available Elementor widgets with their names, titles, icons, and categories.', 'elementor-mcp-api'),
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
            'label'       => __('Get Widget Schema', 'elementor-mcp-api'),
            'description' => __('Get the full control schema for a specific Elementor widget, showing all available settings with their types, labels, defaults, and options. Essential for knowing what settings to pass when creating or updating widgets.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['widget_name'],
                'properties' => [
                    'widget_name' => [
                        'type'        => 'string',
                        'description' => __('The widget type name (e.g. "heading", "text-editor", "image", "button", "form").', 'elementor-mcp-api'),
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
            ],
            'execute_callback' => function ($input) {
                $schema = Elementor_Data::get_widget_schema($input['widget_name']);
                if (!$schema) return new \WP_Error('not_found', __('Widget not found.', 'elementor-mcp-api'));
                return $schema;
            },
            'permission_callback' => [self::class, 'can_read'],
            'meta' => self::meta_read(),
        ]);
    }

    // ── Utility Abilities ───────────────────────────────────

    private static function register_utility_abilities(): void {

        wp_register_ability('neoservice/flush-css', [
            'label'       => __('Flush CSS Cache', 'elementor-mcp-api'),
            'description' => __('Flush the Elementor CSS cache. Optionally for a specific post, or all posts if no post_id is given.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => __('Optional post ID. If omitted, flushes all CSS.', 'elementor-mcp-api'),
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
            'label'       => __('Build Complete Page', 'elementor-mcp-api'),
            'description' => __('Create or update a complete Elementor page in a single call. Optionally creates the page, imports images, and saves the full Elementor element tree. This is the most powerful ability for building pages from scratch.', 'elementor-mcp-api'),
            'category'    => 'neoservice-elementor',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['data'],
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => __('Existing page ID to update. If omitted, a new page is created.', 'elementor-mcp-api'),
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => __('Page title (required when creating a new page).', 'elementor-mcp-api'),
                    ],
                    'slug' => [
                        'type'        => 'string',
                        'description' => __('Page slug (for new pages).', 'elementor-mcp-api'),
                    ],
                    'data' => [
                        'type'        => 'array',
                        'description' => __('The full Elementor element tree.', 'elementor-mcp-api'),
                    ],
                    'images' => [
                        'type'        => 'array',
                        'description' => __('Optional array of {source_path, title} objects to import into the media library before building.', 'elementor-mcp-api'),
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
                        return new \WP_Error('missing_title', __('Title is required when creating a new page.', 'elementor-mcp-api'));
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
