<?php
/**
 * Standalone tests for NeoService\ElementorAPI\Validator.
 *
 * Run: php tests/run.php   (or)   php tests/test-validator.php
 *
 * Pure-logic coverage: tree validation (shape, depth, size), element-id checks,
 * and the media-path traversal/LFI guard. No WordPress, no PHPUnit.
 */

use NeoService\ElementorAPI\Validator;

return function (Asserter $t): void {

    // ── validate_tree: happy paths ───────────────────────────
    $t->true(Validator::validate_tree([]) === true, 'empty tree is valid');

    $valid_tree = [[
        'id' => 'abc123', 'elType' => 'container', 'settings' => [],
        'elements' => [
            ['id' => 'def456', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Hi']],
        ],
    ]];
    $t->true(Validator::validate_tree($valid_tree) === true, 'well-formed container+widget tree is valid');

    $t->true(
        Validator::validate_tree([['id' => 'x', 'elType' => 'section', 'elements' => [
            ['id' => 'y', 'elType' => 'column', 'elements' => [
                ['id' => 'z', 'elType' => 'widget', 'widgetType' => 'text-editor'],
            ]],
        ]]]) === true,
        'legacy section/column/widget tree is valid'
    );

    // ── validate_tree: rejections ────────────────────────────
    $r = Validator::validate_tree([['id' => 'a', 'elType' => 'widget']]); // missing widgetType
    $t->true(is_wp_error($r) && $r->get_error_code() === 'missing_widget_type', 'widget without widgetType rejected');

    $r = Validator::validate_tree([['id' => 'a', 'elType' => 'bogus']]);
    $t->true(is_wp_error($r) && $r->get_error_code() === 'invalid_eltype', 'unknown elType rejected');

    $r = Validator::validate_tree([['id' => 'a']]); // missing elType
    $t->true(is_wp_error($r) && $r->get_error_code() === 'invalid_eltype', 'missing elType rejected');

    $r = Validator::validate_tree([['elType' => 'container', 'settings' => 'not-an-array']]);
    $t->true(is_wp_error($r) && $r->get_error_code() === 'invalid_settings', 'non-array settings rejected');

    $r = Validator::validate_tree([['elType' => 'container', 'elements' => 'nope']]);
    $t->true(is_wp_error($r) && $r->get_error_code() === 'invalid_children', 'non-array children rejected');

    $r = Validator::validate_tree(['not-an-object']);
    $t->true(is_wp_error($r) && $r->get_error_code() === 'invalid_node', 'scalar node rejected');

    // depth guard: build a tree deeper than MAX_DEPTH
    $deep = ['elType' => 'container', 'elements' => []];
    $cursor =& $deep;
    for ($i = 0; $i < 40; $i++) {
        $cursor['elements'] = [['elType' => 'container', 'elements' => []]];
        $cursor =& $cursor['elements'][0];
    }
    unset($cursor);
    $r = Validator::validate_tree([$deep]);
    $t->true(is_wp_error($r) && $r->get_error_code() === 'tree_too_deep', 'over-deep tree rejected');

    // size guard
    $many = [];
    for ($i = 0; $i < 5001; $i++) {
        $many[] = ['elType' => 'widget', 'widgetType' => 'spacer'];
    }
    $r = Validator::validate_tree($many);
    $t->true(is_wp_error($r) && $r->get_error_code() === 'tree_too_large', 'oversized tree rejected');

    // ── check_body_size (payload ceiling, SHOULD #6) ─────────
    $t->true(Validator::check_body_size('') === true, 'empty body within limit');
    $t->true(Validator::check_body_size(str_repeat('x', 1024)) === true, '1KB body within limit');
    $t->true(Validator::check_body_size(str_repeat('x', Validator::MAX_BODY_BYTES)) === true, 'body at exact limit accepted');
    $r = Validator::check_body_size(str_repeat('x', Validator::MAX_BODY_BYTES + 1));
    $t->true(is_wp_error($r) && $r->get_error_code() === 'payload_too_large', 'over-limit body rejected');

    // ── is_valid_element_id ──────────────────────────────────
    $t->true(Validator::is_valid_element_id('f8703b57'), 'hex id accepted');
    $t->true(Validator::is_valid_element_id('ABC123'), 'mixed-case alnum id accepted');
    $t->true(!Validator::is_valid_element_id('has space'), 'id with space rejected');
    $t->true(!Validator::is_valid_element_id('../etc'), 'id with traversal chars rejected');
    $t->true(!Validator::is_valid_element_id(''), 'empty id rejected');
    $t->true(!Validator::is_valid_element_id(12345), 'non-string id rejected');
    $t->true(!Validator::is_valid_element_id(str_repeat('a', 17)), 'over-long id rejected');

    // ── resolve_media_path: traversal / LFI guard ────────────
    $sandbox = sys_get_temp_dir() . '/neoservice-test-' . uniqid();
    $uploads = $sandbox . '/uploads';
    mkdir($uploads, 0777, true);
    $GLOBALS['__test_upload_basedir'] = $uploads;

    // a legit image inside uploads
    $good = $uploads . '/photo.png';
    file_put_contents($good, 'PNGDATA');
    $res = Validator::resolve_media_path($good);
    $t->true(!is_wp_error($res) && $res === realpath($good), 'image inside uploads resolves');

    // a file OUTSIDE uploads (the classic LFI target)
    $outside = $sandbox . '/secret.png';
    file_put_contents($outside, 'SECRET');
    $res = Validator::resolve_media_path($outside);
    $t->true(is_wp_error($res) && $res->get_error_code() === 'path_outside_uploads', 'file outside uploads rejected');

    // traversal string that escapes uploads
    $res = Validator::resolve_media_path($uploads . '/../secret.png');
    $t->true(is_wp_error($res) && $res->get_error_code() === 'path_outside_uploads', '../ traversal rejected');

    // remote URL rejected
    $res = Validator::resolve_media_path('https://evil.test/x.png');
    $t->true(is_wp_error($res) && $res->get_error_code() === 'remote_path_rejected', 'remote URL rejected');

    // non-image inside uploads rejected (e.g. a PHP file staged in uploads)
    $php = $uploads . '/shell.php';
    file_put_contents($php, '<?php');
    $res = Validator::resolve_media_path($php);
    $t->true(is_wp_error($res) && $res->get_error_code() === 'unsupported_media_type', 'non-image in uploads rejected');

    // SVG rejected even inside uploads (XSS vector — MUST-FIX-1).
    $svg = $uploads . '/icon.svg';
    file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $res = Validator::resolve_media_path($svg);
    $t->true(is_wp_error($res) && $res->get_error_code() === 'unsupported_media_type', 'SVG in uploads rejected (XSS)');
    @unlink($svg);

    // missing path
    $res = Validator::resolve_media_path('');
    $t->true(is_wp_error($res) && $res->get_error_code() === 'missing_path', 'empty path rejected');

    // nonexistent path
    $res = Validator::resolve_media_path($uploads . '/ghost.png');
    $t->true(is_wp_error($res) && $res->get_error_code() === 'path_not_found', 'nonexistent path rejected');

    // cleanup
    @unlink($good); @unlink($outside); @unlink($php);
    @rmdir($uploads); @rmdir($sandbox);
    unset($GLOBALS['__test_upload_basedir']);
};
