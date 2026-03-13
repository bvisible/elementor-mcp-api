<?php
namespace NeoService\ElementorAPI;

/**
 * Element Factory - creates properly structured Elementor JSON elements.
 * Inspired by msrbuilds/elementor-mcp Element Factory pattern.
 */
class Element_Factory {

    /**
     * Generate a unique 8-char hex ID for Elementor elements.
     */
    public static function generate_id(): string {
        return substr(md5(uniqid(mt_rand(), true)), 0, 8);
    }

    /**
     * Create a container element.
     */
    public static function container(array $settings = [], array $children = [], bool $is_inner = false): array {
        $defaults = [
            'content_width' => 'boxed',
            'flex_direction' => 'column',
        ];

        return [
            'id'       => self::generate_id(),
            'elType'   => 'container',
            'isInner'  => $is_inner,
            'settings' => array_merge($defaults, $settings),
            'elements' => $children,
        ];
    }

    /**
     * Create a row container (horizontal flex).
     */
    public static function row(array $children = [], array $settings = []): array {
        return self::container(array_merge([
            'content_width'  => 'full',
            'flex_direction'  => 'row',
            'flex_wrap'       => 'wrap',
            'flex_gap'        => ['size' => 20, 'unit' => 'px', 'column' => '20', 'row' => '20'],
        ], $settings), $children, true);
    }

    /**
     * Create a column container with width.
     */
    public static function column(array $children = [], float $width = 50, array $settings = []): array {
        return self::container(array_merge([
            'content_width' => 'full',
            'width'         => ['size' => $width, 'unit' => '%'],
            'width_tablet'  => ['size' => 100, 'unit' => '%'],
            'flex_direction' => 'column',
        ], $settings), $children, true);
    }

    /**
     * Create a widget element.
     */
    public static function widget(string $type, array $settings = []): array {
        return [
            'id'         => self::generate_id(),
            'elType'     => 'widget',
            'widgetType' => $type,
            'settings'   => $settings,
            'elements'   => [],
        ];
    }

    // ── Widget shortcuts ─────────────────────────────────────

    public static function heading(string $title, string $tag = 'h2', array $settings = []): array {
        return self::widget('heading', array_merge([
            'title'       => $title,
            'header_size' => $tag,
        ], $settings));
    }

    public static function text(string $content, array $settings = []): array {
        return self::widget('text-editor', array_merge([
            'editor' => $content,
        ], $settings));
    }

    public static function image(int $attachment_id, array $settings = []): array {
        $url = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
        return self::widget('image', array_merge([
            'image'      => ['url' => $url, 'id' => $attachment_id],
            'image_size' => 'large',
        ], $settings));
    }

    public static function button(string $text, string $url = '#', array $settings = []): array {
        return self::widget('button', array_merge([
            'text' => $text,
            'link' => ['url' => $url],
        ], $settings));
    }

    public static function divider(array $settings = []): array {
        return self::widget('divider', $settings);
    }

    public static function spacer(int $size = 30, array $settings = []): array {
        return self::widget('spacer', array_merge([
            'space' => ['size' => $size, 'unit' => 'px'],
        ], $settings));
    }

    public static function icon(string $icon = 'fas fa-gem', array $settings = []): array {
        return self::widget('icon', array_merge([
            'selected_icon' => ['value' => $icon, 'library' => 'fa-solid'],
        ], $settings));
    }

    public static function social_icons(array $icons, array $settings = []): array {
        $list = [];
        foreach ($icons as $name => $url) {
            $list[] = [
                '_id'         => self::generate_id(),
                'social_icon' => ['value' => "fab fa-$name", 'library' => 'fa-brands'],
                'link'        => ['url' => $url, 'is_external' => 'true'],
            ];
        }
        return self::widget('social-icons', array_merge([
            'social_icon_list' => $list,
        ], $settings));
    }

    public static function nav_menu(string $menu_slug, array $settings = []): array {
        return self::widget('nav-menu', array_merge([
            'menu'   => $menu_slug,
            'layout' => 'horizontal',
        ], $settings));
    }

    public static function form(string $name, array $fields, string $email_to, array $settings = []): array {
        $form_fields = [];
        foreach ($fields as $field) {
            $form_fields[] = array_merge([
                '_id'        => self::generate_id(),
                'field_type' => 'text',
                'width'      => '100',
            ], $field);
        }
        return self::widget('form', array_merge([
            'form_name'    => $name,
            'form_fields'  => $form_fields,
            'email_to'     => $email_to,
            'button_text'  => 'Envoyer',
        ], $settings));
    }

    // ── Composite builders ───────────────────────────────────

    /**
     * Create a hero section with background image and centered title.
     */
    public static function hero(string $title, int $bg_image_id = 0, array $settings = []): array {
        $bg_url = $bg_image_id ? wp_get_attachment_url($bg_image_id) : '';
        return self::container(array_merge([
            'content_width'                  => 'full',
            'min_height'                     => ['size' => 70, 'unit' => 'vh'],
            'background_background'          => 'classic',
            'background_color'               => '#2F251F',
            'background_image'               => ['url' => $bg_url, 'id' => $bg_image_id],
            'background_position'            => 'center center',
            'background_size'                => 'cover',
            'background_overlay_background'  => 'classic',
            'background_overlay_color'       => 'rgba(47,37,31,0.55)',
            'flex_direction'                 => 'column',
            'flex_justify_content'           => 'center',
            'flex_align_items'               => 'center',
        ], $settings), [
            self::heading($title, 'h1', [
                'align'       => 'center',
                'title_color' => '#FFFFFF',
                'typography_typography'   => 'custom',
                'typography_font_family'  => 'Bodoni Moda',
                'typography_font_size'    => ['size' => 65, 'unit' => 'px'],
                'typography_font_weight'  => '500',
            ]),
        ]);
    }

    /**
     * Create a two-column content section (image + text).
     */
    public static function content_row(string $title, string $text, int $image_id, bool $image_left = true): array {
        $img_col = self::column([self::image($image_id, ['width' => ['size' => 100, 'unit' => '%']])], 50);
        $txt_col = self::column([
            self::heading($title, 'h2', [
                'typography_typography'  => 'custom',
                'typography_font_family' => 'Cormorant Garamond',
                'typography_font_size'   => ['size' => 30, 'unit' => 'px'],
                'typography_font_weight' => '600',
                '_margin' => ['top' => '0', 'right' => '0', 'bottom' => '20', 'left' => '0', 'unit' => 'px', 'isLinked' => false],
            ]),
            self::text("<p>$text</p>"),
        ], 50, ['flex_justify_content' => 'center', 'padding' => ['top' => '20', 'right' => '30', 'bottom' => '20', 'left' => '30', 'unit' => 'px', 'isLinked' => false]]);

        $elements = $image_left ? [$img_col, $txt_col] : [$txt_col, $img_col];
        return self::container([
            'content_width'    => 'boxed',
            'flex_direction'   => 'row',
            'flex_wrap'        => 'wrap',
            'flex_align_items' => 'stretch',
            'flex_gap'         => ['size' => 0, 'unit' => 'px', 'column' => '0', 'row' => '0'],
        ], $elements);
    }

    /**
     * Reassign all IDs in an element tree (for duplication).
     */
    public static function reassign_ids(array &$element): void {
        $element['id'] = self::generate_id();
        if (!empty($element['elements'])) {
            foreach ($element['elements'] as &$child) {
                self::reassign_ids($child);
            }
        }
    }
}
