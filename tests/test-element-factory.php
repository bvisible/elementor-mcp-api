<?php
/**
 * Standalone tests for NeoService\ElementorAPI\Element_Factory structural output.
 * No WordPress, no PHPUnit. Covers ID generation, container/widget shape, and
 * the recursive ID reassignment used by element duplication.
 */

use NeoService\ElementorAPI\Element_Factory;

return function (Asserter $t): void {

    // ── generate_id ──────────────────────────────────────────
    $id = Element_Factory::generate_id();
    $t->true(is_string($id) && strlen($id) === 8, 'generate_id returns 8 chars');
    $t->true((bool) preg_match('/^[a-f0-9]{8}$/', $id), 'generate_id is lowercase hex');
    $t->true(Element_Factory::generate_id() !== Element_Factory::generate_id(), 'generate_id is unique per call');

    // ── container ────────────────────────────────────────────
    $c = Element_Factory::container(['flex_direction' => 'row'], [], true);
    $t->true($c['elType'] === 'container', 'container elType');
    $t->true($c['isInner'] === true, 'container isInner honoured');
    $t->true($c['settings']['flex_direction'] === 'row', 'container setting override applied');
    $t->true($c['settings']['content_width'] === 'boxed', 'container default preserved');
    $t->true(isset($c['id']) && isset($c['elements']), 'container has id + elements');

    // ── widget ───────────────────────────────────────────────
    $w = Element_Factory::widget('counter', ['starting_number' => 0]);
    $t->true($w['elType'] === 'widget' && $w['widgetType'] === 'counter', 'widget type set');
    $t->true($w['settings']['starting_number'] === 0, 'widget settings passed through');

    // ── heading ──────────────────────────────────────────────
    $h = Element_Factory::heading('Title', 'h1');
    $t->true($h['widgetType'] === 'heading', 'heading widgetType');
    $t->true($h['settings']['title'] === 'Title' && $h['settings']['header_size'] === 'h1', 'heading title+tag');

    // ── reassign_ids (used by duplicate) ─────────────────────
    $tree = [
        'id' => 'old-root', 'elType' => 'container',
        'elements' => [
            ['id' => 'old-child-1', 'elType' => 'widget', 'widgetType' => 'heading', 'elements' => []],
            ['id' => 'old-child-2', 'elType' => 'container', 'elements' => [
                ['id' => 'old-grandchild', 'elType' => 'widget', 'widgetType' => 'text-editor', 'elements' => []],
            ]],
        ],
    ];
    $before = json_encode($tree);
    Element_Factory::reassign_ids($tree);

    $ids = [];
    $collect = function ($node) use (&$collect, &$ids) {
        $ids[] = $node['id'];
        foreach ($node['elements'] ?? [] as $child) $collect($child);
    };
    $collect($tree);

    $t->true(!in_array('old-root', $ids, true), 'root id reassigned');
    $t->true(!in_array('old-grandchild', $ids, true), 'nested id reassigned recursively');
    $t->true(count($ids) === count(array_unique($ids)), 'all reassigned ids are unique');
    $t->true(count($ids) === 4, 'all 4 nodes retained after reassign');
};
