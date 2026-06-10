<?php
/**
 * v1.4.2 security tests — MCP (Abilities) surface.
 *
 * Fix 2: every write ability must reject an oversized input (the MCP surface has
 * no raw HTTP body, so guard_input_size applies the same MAX_BODY_BYTES ceiling
 * to the JSON-encoded input) BEFORE any other processing.
 *
 * Abilities are registered into a collector stub ($GLOBALS['__test_abilities'])
 * and their execute_callbacks invoked directly.
 *
 * Run: php tests/run.php
 */

use NeoService\ElementorAPI\Abilities_Provider;
use NeoService\ElementorAPI\Validator;

return function (Asserter $t): void {

    $GLOBALS['__test_abilities'] = [];
    $GLOBALS['__test_meta']      = [];
    $GLOBALS['__test_caps']      = [];
    $GLOBALS['__test_options']   = [];

    Abilities_Provider::register();
    $abilities = $GLOBALS['__test_abilities'];

    $t->true(count($abilities) === 24, 'all 24 abilities registered into the collector');

    // Partition by the MCP readonly annotation.
    $writes = array_filter($abilities, function ($def) {
        return ($def['meta']['annotations']['readonly'] ?? true) === false;
    });
    $t->true(count($writes) === 13, '13 write abilities found (readonly=false)');

    // ── guard_input_size unit behaviour ──────────────────────
    $small = Abilities_Provider::guard_input_size(['post_id' => 1, 'settings' => ['a' => 'b']]);
    $t->true($small === null, 'guard_input_size: small input passes');

    $big = Abilities_Provider::guard_input_size(['filler' => str_repeat('x', Validator::MAX_BODY_BYTES + 1)]);
    $t->true(is_wp_error($big) && $big->get_error_code() === 'payload_too_large',
        'guard_input_size: oversized input returns payload_too_large');

    // ── Every write ability rejects an oversized input up front ──
    // The guard runs before guard_edit_post / data loading, so a uniform
    // payload works for all of them regardless of their schema.
    $oversized_input = [
        'post_id' => 1,
        'filler'  => str_repeat('x', Validator::MAX_BODY_BYTES + 1),
    ];
    foreach ($writes as $name => $def) {
        $res = ($def['execute_callback'])($oversized_input);
        $t->true(
            is_wp_error($res) && $res->get_error_code() === 'payload_too_large',
            "$name: oversized input rejected with payload_too_large"
        );
    }

    // ── Positive control: a normal-sized write still goes through ──
    $GLOBALS['__test_meta'][7]['_elementor_data'] = json_encode([
        ['id' => 'ab12cd34', 'elType' => 'container', 'settings' => [], 'elements' => []],
    ]);
    $res = ($abilities['neoservice/update-element']['execute_callback'])([
        'post_id'    => 7,
        'element_id' => 'ab12cd34',
        'settings'   => ['content_width' => 'full'],
    ]);
    $t->true(!is_wp_error($res) && ($res['success'] ?? false) === true,
        'update-element: normal payload still accepted (no regression)');

    $GLOBALS['__test_meta']      = [];
    $GLOBALS['__test_abilities'] = [];
};
