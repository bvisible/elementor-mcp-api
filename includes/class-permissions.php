<?php
namespace NeoService\ElementorAPI;

/**
 * Capability checks shared by the REST controller and the Abilities provider.
 *
 * Every check is object-level (edit_post on the page being touched) or maps to
 * the WordPress capability that guards the same action in wp-admin.
 */
class Permissions {

    /**
     * Statuses a caller may request when creating a page.
     */
    const PAGE_STATUSES = ['publish', 'draft', 'pending', 'private'];

    /**
     * Can the current user edit (and therefore read the raw data of) this post?
     */
    public static function can_edit_post($post_id): bool {
        $post_id = (int) $post_id;
        return $post_id > 0 && current_user_can('edit_post', $post_id);
    }

    /**
     * Can the current user create pages?
     */
    public static function can_create_pages(): bool {
        return current_user_can('edit_pages');
    }

    /**
     * Can the current user manage templates, global kit settings and the CSS cache?
     */
    public static function can_manage_design(): bool {
        return current_user_can('edit_theme_options');
    }

    /**
     * Can the current user upload files to the media library?
     */
    public static function can_upload(): bool {
        return current_user_can('upload_files');
    }

    /**
     * Can the current user use the read-only builder helpers (widget list, schemas)?
     */
    public static function can_use_builder(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Normalise a requested page status to one the current user is allowed to use.
     *
     * Users without the publish_pages capability can only create drafts.
     */
    public static function resolve_page_status($status): string {
        $status = is_string($status) ? sanitize_key($status) : 'publish';
        if (!in_array($status, self::PAGE_STATUSES, true)) {
            $status = 'publish';
        }
        if (!current_user_can('publish_pages')) {
            return 'draft';
        }
        return $status;
    }
}
