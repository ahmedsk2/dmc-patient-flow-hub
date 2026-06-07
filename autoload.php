<?php
/**
 * autoload.php — minimal PSR-4 autoloader (no Composer), maps the DMC\ namespace to src/.
 *
 *   require_once __DIR__ . '/autoload.php';
 *   $report = new \DMC\Reports\YearlyReport($mysqli);
 *
 * This is the seed of the Phase-4 "strangler-fig" layer: new domain logic lives under src/ behind
 * namespaced classes, while the legacy procedural pages keep working. Real Composer/PSR-4 can be
 * dropped in later where it's available — the on-disk layout already matches (DMC\Foo\Bar ->
 * src/Foo/Bar.php), so no class moves are needed at that point.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'DMC\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
