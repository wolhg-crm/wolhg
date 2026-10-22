<?php

declare(strict_types=1);

require __DIR__ . '/../src/Domain.php';
require __DIR__ . '/TestCase.php';

$files = glob(__DIR__ . '/{Unit,Smoke}/*Test.php', GLOB_BRACE);
sort($files);

$total = 0;
$assertions = 0;
$failures = [];

foreach ($files as $file) {
    $tests = require $file;
    foreach ($tests as $name => $test) {
        $total++;
        $case = new TestCase();
        try {
            $test($case);
            $assertions += $case->assertions();
            echo ".";
        } catch (Throwable $e) {
            $failures[] = [$file, $name, $e];
            echo "F";
        }
    }
}

echo PHP_EOL;

if ($failures) {
    foreach ($failures as [$file, $name, $error]) {
        fwrite(STDERR, PHP_EOL . basename($file) . ' :: ' . $name . PHP_EOL);
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
    }
    fwrite(STDERR, PHP_EOL . sprintf('FAILURES: %d failed, %d tests, %d assertions', count($failures), $total, $assertions) . PHP_EOL);
    exit(1);
}

echo sprintf('OK: %d tests, %d assertions', $total, $assertions) . PHP_EOL;
