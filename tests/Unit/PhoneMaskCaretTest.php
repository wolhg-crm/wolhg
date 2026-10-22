<?php

declare(strict_types=1);

return [
    'phone mask keeps national digit order while typing' => function (TestCase $test): void {
        $js = file_get_contents(__DIR__ . '/../../public/assets/app.js');

        $test->assertStringContains('skippedCountry', $js);
        $test->assertStringContains('National digits only', $js);

        $script = __DIR__ . '/../js/phone-mask-verify.mjs';
        $output = [];
        $exitCode = 0;
        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        $test->assertSame(0, $exitCode, implode("\n", $output));
        $test->assertStringContains('phone-mask-verify: ok', implode("\n", $output));
    },
];
