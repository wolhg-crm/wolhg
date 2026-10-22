<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Features.php';

return [
    'generate_vapid_keys produces url-safe pair of expected sizes' => function (TestCase $test): void {
        $keys = generate_vapid_keys();

        $test->assertTrue(isset($keys['publicKey'], $keys['privateKey']));
        $test->assertTrue((bool) preg_match('/^[A-Za-z0-9_-]+$/', $keys['publicKey']));
        $test->assertTrue((bool) preg_match('/^[A-Za-z0-9_-]+$/', $keys['privateKey']));

        $pad = static fn(string $value): string => $value . str_repeat('=', (4 - strlen($value) % 4) % 4);
        $public = base64_decode(strtr($pad($keys['publicKey']), '-_', '+/'), true);
        $private = base64_decode(strtr($pad($keys['privateKey']), '-_', '+/'), true);

        $test->assertSame(65, strlen((string) $public));
        $test->assertSame(32, strlen((string) $private));
        $test->assertSame("\x04", $public[0] ?? '');
    },
    'normalize_vapid_subject accepts email and https url' => function (TestCase $test): void {
        $test->assertSame('mailto:admin@example.com', normalize_vapid_subject('admin@example.com'));
        $test->assertSame('mailto:admin@example.com', normalize_vapid_subject('mailto:admin@example.com'));
        $test->assertSame('https://church.example', normalize_vapid_subject('https://church.example'));
        $test->assertSame('', normalize_vapid_subject('not-a-contact'));
        $test->assertSame('', normalize_vapid_subject(''));
    },
];
