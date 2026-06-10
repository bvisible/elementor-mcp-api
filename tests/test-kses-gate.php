<?php
/**
 * v1.4.2 security tests — unfiltered_html kses gate (Fix 3, stored-XSS).
 *
 * Before: the `unfiltered_html` gate only existed on the direct-meta fallback of
 * save_page_data, which never runs in production (native Elementor path wins),
 * so a caller WITHOUT unfiltered_html could persist <script> to visitors.
 * After: the gate runs at the top of save_page_data for EVERY path — callers
 * lacking unfiltered_html get HTML-carrying settings sanitized via wp_kses_post;
 * privileged callers (admins) keep content byte-identical.
 *
 * Run: php tests/run.php
 */

use NeoService\ElementorAPI\Elementor_Data;

return function (Asserter $t): void {

    $payload = '<script>alert(1)</script><p onclick="evil()">ok</p>';

    $make_tree = function () use ($payload): array {
        return [[
            'id' => 'aaaa1111', 'elType' => 'container', 'settings' => [],
            'elements' => [
                ['id' => 'bbbb2222', 'elType' => 'widget', 'widgetType' => 'text-editor',
                 'settings' => ['editor' => $payload]],
                ['id' => 'cccc3333', 'elType' => 'widget', 'widgetType' => 'html',
                 'settings' => ['html' => $payload]],
                // Repeater-style nesting: items carry their own settings arrays.
                ['id' => 'dddd4444', 'elType' => 'widget', 'widgetType' => 'icon-list',
                 'settings' => ['icon_list' => [['_id' => 'e5f6a7b8', 'text' => $payload]]]],
                // v1.4.3: tab_content rides the `tabs` repeater (tabs/accordion/toggle).
                ['id' => 'eeee5555', 'elType' => 'widget', 'widgetType' => 'accordion',
                 'settings' => ['tabs' => [['_id' => 'f1a2b3c4', 'tab_title' => 'T1',
                     'tab_content' => $payload]]]],
                // v1.4.3: alert widget carries HTML in alert_description.
                ['id' => 'ffff6666', 'elType' => 'widget', 'widgetType' => 'alert',
                 'settings' => ['alert_title' => 'Heads up', 'alert_description' => $payload]],
            ],
        ]];
    };

    $saved_editor = function (int $post_id, int $child, string $key): string {
        $raw  = $GLOBALS['__test_meta'][$post_id]['_elementor_data'] ?? '';
        $tree = is_string($raw) ? json_decode($raw, true) : $raw;
        $val  = $tree[0]['elements'][$child]['settings'][$key] ?? null;
        if (is_array($val)) { // repeater
            $val = $val[0]['text'] ?? '';
        }
        return (string) $val;
    };

    // ── (a) caller WITHOUT unfiltered_html → script stripped, benign HTML kept ──
    $GLOBALS['__test_meta'] = [];
    $GLOBALS['__test_caps'] = ['unfiltered_html' => false];

    $ok = Elementor_Data::save_page_data(11, $make_tree());
    $t->true($ok === true, 'kses gate: save succeeds for caller without unfiltered_html (sanitize, not block)');

    $editor = $saved_editor(11, 0, 'editor');
    $t->true(stripos($editor, '<script') === false, 'kses gate: <script> stripped from text-editor `editor`');
    $t->true(stripos($editor, 'onclick') === false, 'kses gate: on* event handler stripped from `editor`');
    $t->true(strpos($editor, '<p') !== false && strpos($editor, 'ok') !== false,
        'kses gate: benign markup/text preserved in `editor`');

    $html = $saved_editor(11, 1, 'html');
    $t->true(stripos($html, '<script') === false, 'kses gate: <script> stripped from HTML widget `html`');

    $repeater = $saved_editor(11, 2, 'icon_list');
    $t->true(stripos($repeater, '<script') === false, 'kses gate: <script> stripped inside repeater item `text`');

    // v1.4.3 allowlist additions: tab_content (tabs repeater) + alert_description.
    $tree11 = json_decode($GLOBALS['__test_meta'][11]['_elementor_data'], true);
    $tab_content = (string) ($tree11[0]['elements'][3]['settings']['tabs'][0]['tab_content'] ?? '');
    $t->true(stripos($tab_content, '<script') === false,
        'kses gate v1.4.3: <script> stripped from accordion `tab_content`');
    $t->true(stripos($tab_content, 'onclick') === false,
        'kses gate v1.4.3: on* handler stripped from `tab_content`');
    $t->true(strpos($tab_content, 'ok') !== false,
        'kses gate v1.4.3: benign text preserved in `tab_content`');
    $alert_desc = (string) ($tree11[0]['elements'][4]['settings']['alert_description'] ?? '');
    $t->true(stripos($alert_desc, '<script') === false,
        'kses gate v1.4.3: <script> stripped from alert `alert_description`');

    // Non-HTML settings keys are untouched.
    $raw  = json_decode($GLOBALS['__test_meta'][11]['_elementor_data'], true);
    $t->true(($raw[0]['elements'][0]['widgetType'] ?? '') === 'text-editor',
        'kses gate: structural fields (widgetType) untouched');

    // ── (b) caller WITH unfiltered_html → content preserved byte-identical ──
    $GLOBALS['__test_meta'] = [];
    $GLOBALS['__test_caps'] = ['unfiltered_html' => true];

    $ok = Elementor_Data::save_page_data(12, $make_tree());
    $t->true($ok === true, 'kses gate: admin save succeeds');
    $t->true($saved_editor(12, 0, 'editor') === $payload,
        'kses gate: unfiltered_html caller keeps `editor` content intact (script preserved)');
    $t->true($saved_editor(12, 1, 'html') === $payload,
        'kses gate: unfiltered_html caller keeps `html` content intact');
    $tree12 = json_decode($GLOBALS['__test_meta'][12]['_elementor_data'], true);
    $t->true(($tree12[0]['elements'][3]['settings']['tabs'][0]['tab_content'] ?? null) === $payload,
        'kses gate v1.4.3: unfiltered_html caller keeps `tab_content` byte-identical');

    // ── kses_widget_html helper: pure-function behaviour ──
    $sanitized = Elementor_Data::kses_widget_html($make_tree());
    $t->true(stripos(json_encode($sanitized), '<script') === false,
        'kses_widget_html: no <script> survives anywhere in the tree');
    $t->true(($sanitized[0]['id'] ?? '') === 'aaaa1111' && ($sanitized[0]['elType'] ?? '') === 'container',
        'kses_widget_html: tree structure (ids, elType, nesting) preserved');

    $GLOBALS['__test_meta'] = [];
    $GLOBALS['__test_caps'] = [];
};
