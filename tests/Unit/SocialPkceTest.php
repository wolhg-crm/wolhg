<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/YandexAddress.php';
require_once __DIR__ . '/../../src/Social.php';

return [
    'vk pkce challenge uses S256 base64url without padding' => function (TestCase $test): void {
        $verifier = 'KnHKAdqyW57MUbjRcScaZRU9Bw26Kez9zwBgti779zU';
        $challenge = social_pkce_challenge($verifier);

        $test->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $challenge
        );
        $test->assertStringNotContains('=', $challenge);
        $test->assertStringNotContains('+', $challenge);
        $test->assertStringNotContains('/', $challenge);
    },

    'vk pkce verifier length is within rfc 7636 bounds' => function (TestCase $test): void {
        $verifier = social_pkce_verifier();
        $length = strlen($verifier);

        $test->assertTrue($length >= 43 && $length <= 128, 'verifier length must be 43-128, got ' . $length);
        $test->assertTrue((bool) preg_match('/^[A-Za-z0-9_-]+$/', $verifier));
    },
];
