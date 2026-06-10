<?php
/**
 * Dependency-free test runner for the NeoService Elementor API plugin.
 *
 *   php tests/run.php
 *
 * Exit code 0 = all pass, 1 = at least one failure. Designed to run in CI with
 * nothing but a bare PHP CLI — no composer, no PHPUnit. Covers the plugin's
 * pure-logic surface (Validator, Element_Factory). Runtime behaviour that needs a
 * live WordPress + Elementor (data save, kit, REST wiring) is out of scope here
 * and must be verified on a real install.
 */

error_reporting(E_ALL);

/** Tiny assertion collector. */
class Asserter {
    public int $passed = 0;
    public int $failed = 0;
    private array $failures = [];

    public function true(bool $cond, string $label): void {
        if ($cond) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = $label;
        }
    }

    public function report(): void {
        foreach ($this->failures as $f) {
            fwrite(STDERR, "  FAIL: $f\n");
        }
        $total = $this->passed + $this->failed;
        echo sprintf("\n%d/%d assertions passed (%d failed)\n", $this->passed, $total, $this->failed);
    }
}

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/class-validator.php';
require __DIR__ . '/../includes/class-element-factory.php';
require __DIR__ . '/../includes/class-elementor-data.php';
require __DIR__ . '/../includes/class-rest-controller.php';
require __DIR__ . '/../includes/class-abilities-provider.php';

$t = new Asserter();

$suites = [
    'Validator'          => __DIR__ . '/test-validator.php',
    'Element_Factory'    => __DIR__ . '/test-element-factory.php',
    'REST_Security'      => __DIR__ . '/test-rest-security.php',
    'Abilities_Security' => __DIR__ . '/test-abilities-security.php',
    'Kses_Gate'          => __DIR__ . '/test-kses-gate.php',
    'Template_Safety'    => __DIR__ . '/test-template-safety.php',
];

foreach ($suites as $name => $file) {
    echo "── $name ──\n";
    $suite = require $file;
    $suite($t);
}

$t->report();
exit($t->failed > 0 ? 1 : 0);
