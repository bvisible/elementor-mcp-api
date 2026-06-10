<?php
/**
 * v1.4.4 template-safety tests.
 *
 * Gap (orphan-command review, 2026-06-10): POST /template created the post as
 * 'publish' with default conditions ['include/general'] — a header/footer went
 * LIVE site-wide at creation time, with no rollback endpoint.
 *
 * Fix 1: create_template defaults to 'draft' on every surface (REST handler,
 *        MCP ability, Elementor_Data); publish must be explicit; invalid
 *        statuses are rejected (REST 400 / ability WP_Error) and clamped to
 *        draft at the data layer (defense in depth).
 * Fix 3: DELETE /template/{id} — trash by default, ?force=true permanent
 *        (also unregisters theme-builder conditions); 404 for missing posts
 *        and for posts that are not elementor_library.
 *
 * Run: php tests/run.php
 */

use NeoService\ElementorAPI\Abilities_Provider;
use NeoService\ElementorAPI\Elementor_Data;
use NeoService\ElementorAPI\REST_Controller;

return function (Asserter $t): void {

    $controller = new REST_Controller();

    $reset = function (): void {
        $GLOBALS['__test_meta']           = [];
        $GLOBALS['__test_caps']           = [];
        $GLOBALS['__test_options']        = [];
        $GLOBALS['__test_posts']          = [];
        $GLOBALS['__test_inserted_posts'] = [];
        $GLOBALS['__test_trashed']        = [];
        $GLOBALS['__test_deleted']        = [];
    };

    // ── Fix 1 (REST): no status in body → created as DRAFT ───
    $reset();
    $res = $controller->create_template(new WP_REST_Request([], [
        'title' => 'Header X', 'type' => 'header', 'data' => [],
    ]));
    $inserted = end($GLOBALS['__test_inserted_posts']);
    $t->true($res->get_status() === 201, 'create_template REST: no status → 201');
    $t->true($inserted['post_status'] === 'draft', 'create_template REST: no status → post inserted as draft');
    $t->true(($res->get_data()['status'] ?? '') === 'draft', 'create_template REST: response echoes status draft');

    // ── Fix 1 (REST): explicit publish still honored (compat) ──
    $reset();
    $res = $controller->create_template(new WP_REST_Request([], [
        'title' => 'Header Y', 'type' => 'header', 'data' => [], 'status' => 'publish',
    ]));
    $inserted = end($GLOBALS['__test_inserted_posts']);
    $t->true($res->get_status() === 201 && $inserted['post_status'] === 'publish',
        'create_template REST: explicit status=publish still publishes (compat)');

    // ── Fix 1 (REST): invalid status rejected, nothing inserted ──
    $reset();
    $res = $controller->create_template(new WP_REST_Request([], [
        'title' => 'Header Z', 'type' => 'header', 'data' => [], 'status' => 'pending',
    ]));
    $t->true($res->get_status() === 400, 'create_template REST: invalid status → 400');
    $t->true(empty($GLOBALS['__test_inserted_posts']), 'create_template REST: invalid status → no post inserted');

    // ── Fix 1 (data layer): unknown status clamped to draft ──
    $reset();
    Elementor_Data::create_template('T', 'header', [], ['include/general'], 'future');
    $inserted = end($GLOBALS['__test_inserted_posts']);
    $t->true($inserted['post_status'] === 'draft',
        'Elementor_Data::create_template: unknown status clamped to draft (defense in depth)');

    // ── Fix 1 (ability): default draft / explicit publish / invalid ──
    $GLOBALS['__test_abilities'] = [];
    Abilities_Provider::register();
    $create = $GLOBALS['__test_abilities']['neoservice/create-template']['execute_callback'];

    $reset();
    $res = $create(['title' => 'Footer A', 'type' => 'footer', 'data' => []]);
    $inserted = end($GLOBALS['__test_inserted_posts']);
    $t->true(!is_wp_error($res) && $inserted['post_status'] === 'draft',
        'create-template ability: no status → draft');

    $reset();
    $res = $create(['title' => 'Footer B', 'type' => 'footer', 'data' => [], 'status' => 'publish']);
    $inserted = end($GLOBALS['__test_inserted_posts']);
    $t->true(!is_wp_error($res) && $inserted['post_status'] === 'publish',
        'create-template ability: explicit publish honored');

    $reset();
    $res = $create(['title' => 'Footer C', 'type' => 'footer', 'data' => [], 'status' => 'private']);
    $t->true(is_wp_error($res) && $res->get_error_code() === 'invalid_status',
        'create-template ability: invalid status → WP_Error invalid_status');
    $t->true(empty($GLOBALS['__test_inserted_posts']), 'create-template ability: invalid status → no post inserted');

    $GLOBALS['__test_abilities'] = [];

    // ── Fix 3: delete_template — trash by default ────────────
    $reset();
    $GLOBALS['__test_posts'][501] = (object) ['ID' => 501, 'post_type' => 'elementor_library'];
    $res = $controller->delete_template(new WP_REST_Request(['id' => 501]));
    $t->true($res->get_status() === 200, 'delete_template: existing template → 200');
    $t->true(($res->get_data()['mode'] ?? '') === 'trash', 'delete_template: default mode is trash');
    $t->true($GLOBALS['__test_trashed'] === [501], 'delete_template: wp_trash_post called (restorable)');
    $t->true(empty($GLOBALS['__test_deleted']), 'delete_template: default does NOT permanently delete');

    // ── Fix 3: force=true → permanent + conditions cleanup ───
    $reset();
    $GLOBALS['__test_posts'][502] = (object) ['ID' => 502, 'post_type' => 'elementor_library'];
    $GLOBALS['__test_options']['elementor_pro_theme_builder_conditions'] = [
        'header' => [502 => ['include/general'], 777 => ['include/general']],
    ];
    $res = $controller->delete_template(new WP_REST_Request(['id' => 502], ['force' => 'true']));
    $t->true($res->get_status() === 200 && ($res->get_data()['mode'] ?? '') === 'permanent',
        'delete_template: force=true → permanent mode');
    $t->true($GLOBALS['__test_deleted'] === [['id' => 502, 'force' => true]],
        'delete_template: wp_delete_post(id, true) called on force');
    $conds = $GLOBALS['__test_options']['elementor_pro_theme_builder_conditions'];
    $t->true(!isset($conds['header'][502]) && isset($conds['header'][777]),
        'delete_template: force removes ONLY this template from the conditions map');

    // ── Fix 3: missing post → 404, nothing touched ───────────
    $reset();
    $res = $controller->delete_template(new WP_REST_Request(['id' => 999]));
    $t->true($res->get_status() === 404, 'delete_template: missing post → 404');
    $t->true(empty($GLOBALS['__test_trashed']) && empty($GLOBALS['__test_deleted']),
        'delete_template: missing post → no trash/delete call');

    // ── Fix 3: wrong post type (a real page) is NEVER touched ──
    $reset();
    $GLOBALS['__test_posts'][503] = (object) ['ID' => 503, 'post_type' => 'page'];
    $res = $controller->delete_template(new WP_REST_Request(['id' => 503], ['force' => 'true']));
    $t->true($res->get_status() === 404, 'delete_template: non-elementor_library post → 404');
    $t->true(empty($GLOBALS['__test_trashed']) && empty($GLOBALS['__test_deleted']),
        'delete_template: non-elementor_library post → never trashed/deleted');

    $reset();
};
