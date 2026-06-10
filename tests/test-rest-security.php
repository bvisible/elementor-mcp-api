<?php
/**
 * v1.4.2 security tests — REST surface.
 *
 * Fix 1: add_section must run the same validation as every other write
 *        (Validator::validate_tree ceilings) and the raw-body 4MB ceiling.
 * Fix 2: update_element / move_element / set_column_width must reject an
 *        oversized raw body (413) like the other write endpoints already did.
 *
 * Run: php tests/run.php
 */

use NeoService\ElementorAPI\REST_Controller;
use NeoService\ElementorAPI\Validator;

return function (Asserter $t): void {

    $controller = new REST_Controller();

    $reset = function (): void {
        $GLOBALS['__test_meta']    = [];
        $GLOBALS['__test_caps']    = [];
        $GLOBALS['__test_options'] = [];
    };

    $oversized_body = str_repeat('x', Validator::MAX_BODY_BYTES + 1);

    // ── Fix 1: add_section — depth ceiling ───────────────────
    $reset();
    $deep = ['id' => 'aaaa1111', 'elType' => 'container', 'elements' => []];
    $cursor =& $deep;
    for ($i = 0; $i < Validator::MAX_DEPTH + 5; $i++) {
        $cursor['elements'] = [['id' => 'bbbb2222', 'elType' => 'container', 'elements' => []]];
        $cursor =& $cursor['elements'][0];
    }
    unset($cursor);
    $res = $controller->add_section(new WP_REST_Request(['id' => 1], ['section' => $deep]));
    $t->true($res->get_status() === 400, 'add_section: tree deeper than MAX_DEPTH rejected with 400');
    $t->true(($res->get_data()['code'] ?? '') === 'tree_too_deep', 'add_section: deep tree error code is tree_too_deep');

    // ── Fix 1: add_section — element-count ceiling ───────────
    $reset();
    $children = [];
    for ($i = 0; $i <= Validator::MAX_ELEMENTS; $i++) {
        $children[] = ['id' => 'cccc3333', 'elType' => 'widget', 'widgetType' => 'heading'];
    }
    $fat = ['id' => 'dddd4444', 'elType' => 'container', 'elements' => $children];
    $res = $controller->add_section(new WP_REST_Request(['id' => 1], ['section' => $fat]));
    $t->true($res->get_status() === 400, 'add_section: tree above MAX_ELEMENTS rejected with 400');
    $t->true(($res->get_data()['code'] ?? '') === 'tree_too_large', 'add_section: fat tree error code is tree_too_large');

    // ── Fix 1: add_section — invalid elType now caught ───────
    $reset();
    $res = $controller->add_section(new WP_REST_Request(['id' => 1], ['section' => ['id' => 'eeee5555', 'elType' => 'bogus']]));
    $t->true($res->get_status() === 400 && ($res->get_data()['code'] ?? '') === 'invalid_eltype',
        'add_section: invalid elType rejected (validate_tree now runs)');

    // ── Fix 1: add_section — raw-body ceiling ────────────────
    $reset();
    $res = $controller->add_section(new WP_REST_Request(
        ['id' => 1],
        ['section' => ['id' => 'ffff6666', 'elType' => 'container']],
        $oversized_body
    ));
    $t->true($res->get_status() === 413, 'add_section: body over MAX_BODY_BYTES rejected with 413');
    $t->true(($res->get_data()['code'] ?? '') === 'payload_too_large', 'add_section: oversized body error code is payload_too_large');

    // ── Fix 1: positive control — valid section still inserts (201) ──
    $reset();
    $res = $controller->add_section(new WP_REST_Request(
        ['id' => 1],
        ['section' => ['id' => 'abcd1234', 'elType' => 'container', 'elements' => []]]
    ));
    $t->true($res->get_status() === 201 && ($res->get_data()['success'] ?? false) === true,
        'add_section: well-formed section still accepted (no regression)');

    // ── Fix 2: oversized raw body on the 3 previously-unguarded REST writes ──
    $page_tree = [['id' => 'ab12cd34', 'elType' => 'container', 'settings' => [], 'elements' => []]];

    $reset();
    $GLOBALS['__test_meta'][1]['_elementor_data'] = json_encode($page_tree);
    $res = $controller->update_element(new WP_REST_Request(
        ['id' => 1, 'element_id' => 'ab12cd34'],
        ['settings' => ['title' => 'x']],
        $oversized_body
    ));
    $t->true($res->get_status() === 413 && ($res->get_data()['code'] ?? '') === 'payload_too_large',
        'update_element: oversized body rejected with 413 payload_too_large');

    $reset();
    $GLOBALS['__test_meta'][1]['_elementor_data'] = json_encode($page_tree);
    $res = $controller->move_element(new WP_REST_Request(
        ['id' => 1, 'element_id' => 'ab12cd34'],
        ['position' => 0],
        $oversized_body
    ));
    $t->true($res->get_status() === 413 && ($res->get_data()['code'] ?? '') === 'payload_too_large',
        'move_element: oversized body rejected with 413 payload_too_large');

    $reset();
    $GLOBALS['__test_meta'][1]['_elementor_data'] = json_encode($page_tree);
    $res = $controller->set_column_width(new WP_REST_Request(
        ['id' => 1, 'element_id' => 'ab12cd34'],
        ['percent' => 50],
        $oversized_body
    ));
    $t->true($res->get_status() === 413 && ($res->get_data()['code'] ?? '') === 'payload_too_large',
        'set_column_width: oversized body rejected with 413 payload_too_large');

    // ── Fix 2: positive control — normal-sized update still works ──
    $reset();
    $GLOBALS['__test_meta'][1]['_elementor_data'] = json_encode($page_tree);
    $res = $controller->set_column_width(new WP_REST_Request(
        ['id' => 1, 'element_id' => 'ab12cd34'],
        ['percent' => 25]
    ));
    $t->true($res->get_status() === 200 && ($res->get_data()['success'] ?? false) === true,
        'set_column_width: normal payload still accepted (no regression)');

    $reset();
};
