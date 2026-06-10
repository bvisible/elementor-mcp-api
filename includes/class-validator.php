<?php
namespace NeoService\ElementorAPI;

/**
 * Validator — defensive checks for an AI-driven WRITE API.
 *
 * This plugin lets an external agent replace `_elementor_data` post meta and import
 * media from server paths. Both are powerful and, without guard rails, dangerous:
 *
 *   - An unbounded / malformed element tree can brick a page or exhaust memory.
 *   - An arbitrary `path` passed to media import is a Local File Inclusion vector
 *     (read any file the web server can reach into the media library).
 *
 * Every public mutation entry point should pass user-supplied data through here
 * before it reaches {@see Elementor_Data}. All methods are static and side-effect free.
 */
class Validator {

    /** Maximum nesting depth of an element tree. Real pages rarely exceed ~8. */
    const MAX_DEPTH = 30;

    /** Maximum total number of elements in a single tree (DoS guard). */
    const MAX_ELEMENTS = 5000;

    /** Maximum raw write-request body size in bytes (DoS guard, ~4 MB). */
    const MAX_BODY_BYTES = 4194304;

    /** Allowed top-level element types. */
    const ALLOWED_ELTYPES = ['container', 'section', 'column', 'widget'];

    /**
     * Reject an oversized raw request body before it is JSON-decoded (a malicious or
     * runaway payload should not be parsed into memory at all).
     *
     * @param string $raw_body The raw request body.
     * @return true|\WP_Error
     */
    public static function check_body_size(string $raw_body) {
        if (strlen($raw_body) > self::MAX_BODY_BYTES) {
            return new \WP_Error(
                'payload_too_large',
                sprintf('Request body exceeds the %d-byte limit.', self::MAX_BODY_BYTES),
                ['status' => 413]
            );
        }
        return true;
    }

    /**
     * Validate an Elementor element tree (the array stored in `_elementor_data`).
     *
     * Checks structural sanity, bounded size/depth, and that every node carries the
     * minimal shape Elementor needs (`elType`, and `widgetType` for widgets). It does
     * NOT validate individual widget settings — those are widget-defined and resolved
     * at render time — it validates the *container contract*.
     *
     * @param array $tree The decoded element tree.
     * @return true|\WP_Error True if valid, WP_Error describing the first problem otherwise.
     */
    public static function validate_tree(array $tree) {
        $count = 0;
        return self::validate_nodes($tree, 0, $count);
    }

    /**
     * Recursive worker for {@see validate_tree}.
     *
     * @param array $nodes  Sibling nodes at this level.
     * @param int   $depth  Current depth (root = 0).
     * @param int   $count  Running element count, passed by reference.
     * @return true|\WP_Error
     */
    private static function validate_nodes(array $nodes, int $depth, int &$count) {
        if ($depth > self::MAX_DEPTH) {
            return new \WP_Error(
                'tree_too_deep',
                sprintf('Element tree exceeds max depth of %d.', self::MAX_DEPTH),
                ['status' => 400]
            );
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                return new \WP_Error('invalid_node', 'Every element must be an object.', ['status' => 400]);
            }

            if (++$count > self::MAX_ELEMENTS) {
                return new \WP_Error(
                    'tree_too_large',
                    sprintf('Element tree exceeds max of %d elements.', self::MAX_ELEMENTS),
                    ['status' => 400]
                );
            }

            $el_type = $node['elType'] ?? null;
            if (!is_string($el_type) || !in_array($el_type, self::ALLOWED_ELTYPES, true)) {
                return new \WP_Error(
                    'invalid_eltype',
                    sprintf('Invalid or missing elType: %s', is_scalar($el_type) ? (string) $el_type : gettype($el_type)),
                    ['status' => 400]
                );
            }

            if ($el_type === 'widget' && empty($node['widgetType'])) {
                return new \WP_Error('missing_widget_type', 'Widget elements require a widgetType.', ['status' => 400]);
            }

            if (isset($node['settings']) && !is_array($node['settings'])) {
                return new \WP_Error('invalid_settings', 'Element settings must be an object.', ['status' => 400]);
            }

            if (!empty($node['elements'])) {
                if (!is_array($node['elements'])) {
                    return new \WP_Error('invalid_children', 'Element children must be an array.', ['status' => 400]);
                }
                $result = self::validate_nodes($node['elements'], $depth + 1, $count);
                if ($result !== true) {
                    return $result;
                }
            }
        }

        return true;
    }

    /**
     * Validate a single element ID coming from a request body (not the URL — the URL
     * route already constrains the pattern to `[a-f0-9]+`). Bodies (`add_element`,
     * `patch-bulk`) are unconstrained, so check them explicitly.
     *
     * @param mixed $id
     * @return bool
     */
    public static function is_valid_element_id($id): bool {
        return is_string($id) && (bool) preg_match('/^[a-z0-9]{1,16}$/i', $id);
    }

    /**
     * Resolve and validate a media import source path against traversal / LFI.
     *
     * Accepts a path INSIDE the WordPress uploads directory only (the canonical place
     * an agent stages assets), rejecting symlink escapes and `../` traversal. Callers
     * that need remote URLs should download to uploads first; this method intentionally
     * does not fetch URLs.
     *
     * @param string $path Raw path from the request.
     * @return string|\WP_Error Canonical absolute path on success, WP_Error on rejection.
     */
    public static function resolve_media_path(string $path) {
        if ($path === '') {
            return new \WP_Error('missing_path', 'Missing media "path".', ['status' => 400]);
        }

        // Reject obvious remote schemes early — this endpoint is local-path only.
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $path)) {
            return new \WP_Error(
                'remote_path_rejected',
                'Remote URLs are not accepted by media import. Stage the file in the uploads directory first.',
                ['status' => 400]
            );
        }

        $upload_dir = wp_upload_dir();
        $base = $upload_dir['basedir'] ?? '';
        if (!$base) {
            return new \WP_Error('no_upload_dir', 'Could not resolve the uploads directory.', ['status' => 500]);
        }

        // Resolve to a real, canonical path. realpath() collapses `..` and follows symlinks,
        // so comparing the canonical child against the canonical base defeats traversal.
        $real_path = realpath($path);
        $real_base = realpath($base);

        if ($real_path === false || $real_base === false) {
            return new \WP_Error('path_not_found', 'Media path does not exist.', ['status' => 404]);
        }

        // Ensure the resolved path is the base itself or a descendant of it.
        $prefix = rtrim($real_base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($real_path !== $real_base && strpos($real_path, $prefix) !== 0) {
            return new \WP_Error(
                'path_outside_uploads',
                'Media path must be inside the WordPress uploads directory.',
                ['status' => 403]
            );
        }

        if (!is_file($real_path)) {
            return new \WP_Error('not_a_file', 'Media path is not a regular file.', ['status' => 400]);
        }

        // Only allow real, RASTER image MIME types that WordPress recognises.
        // SVG is intentionally excluded: it is XML that can carry <script>, and the
        // import does a raw copy() with no sanitization → stored XSS on the site domain.
        // WordPress blocks SVG uploads by default for the same reason. Do not re-enable
        // without a dedicated safe-SVG sanitization library.
        $check = wp_check_filetype(basename($real_path));
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
        if (empty($check['type']) || !in_array($check['type'], $allowed, true)) {
            return new \WP_Error(
                'unsupported_media_type',
                'Only raster image files (jpg, png, gif, webp, avif) can be imported. SVG is rejected (XSS risk).',
                ['status' => 415]
            );
        }

        return $real_path;
    }
}
