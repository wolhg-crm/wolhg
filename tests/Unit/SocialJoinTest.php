<?php

declare(strict_types=1);

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

require_once __DIR__ . '/../../src/Social.php';

return [
    'social join return target is kept local' => function (TestCase $test): void {
        $_SERVER['HTTP_HOST'] = 'wolhg.ru';

        $test->assertSame('/homegroup/join?group=4', social_safe_return_to('/homegroup/join?group=4', '/'));
        $test->assertSame('/homegroup/join?group=4', social_safe_return_to('https://wolhg.ru/homegroup/join?group=4', '/'));
        $test->assertSame('/homegroup/join', social_safe_return_to('https://evil.example/homegroup/join?group=4', '/homegroup/join'));
        $test->assertSame('/homegroup/join', social_safe_return_to('//evil.example/homegroup/join', '/homegroup/join'));
    },

    'social profile helpers extract join contact fields' => function (TestCase $test): void {
        $profile = [
            'default_email' => 'ivan@example.test',
            'default_phone' => ['number' => '+7 999 111-22-33'],
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ];

        $test->assertSame('ivan@example.test', social_profile_email($profile));
        $test->assertSame('+7 999 111-22-33', social_profile_phone($profile));
        $test->assertSame('Иван', social_first_string($profile, ['first_name']));
    },


    'vk scopes always request email for join prefill' => function (TestCase $test): void {
        $test->assertSame('vkid.personal_info email', social_normalize_vk_scopes('vkid.personal_info'));
        $test->assertSame('email vkid.personal_info', social_normalize_vk_scopes('email vkid.personal_info email'));
    },

    'vk profile payload keeps top-level email when user data is nested' => function (TestCase $test): void {
        $payload = social_profile_payload('vk', [
            'email' => 'vk-user@example.test',
            'user' => [
                'user_id' => 'vk-42',
                'first_name' => 'Иван',
                'last_name' => 'Петров',
            ],
        ]);

        $test->assertSame('vk-42', (string) ($payload['user_id'] ?? ''));
        $test->assertSame('vk-user@example.test', social_profile_email($payload));
    },

    'vk profile payload extracts email from id token claims' => function (TestCase $test): void {
        $encode = static function (array $payload): string {
            return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
        };
        $idToken = $encode(['alg' => 'none']) . '.' . $encode(['email' => 'id-token@example.test']) . '.signature';

        $payload = social_profile_payload('vk', [
            'user' => [
                'user_id' => 'vk-43',
                'first_name' => 'Петр',
            ],
        ], ['id_token' => $idToken]);

        $test->assertSame('vk-43', (string) ($payload['user_id'] ?? ''));
        $test->assertSame('id-token@example.test', social_profile_email($payload));
    },


    'social join choice renders provider buttons even before oauth config' => function (TestCase $test): void {
        $social = file_get_contents(__DIR__ . '/../../src/Social.php');

        $test->assertStringContains('function render_social_join_choice', $social);
        $test->assertStringContains("foreach (['yandex', 'vk'] as \$provider)", $social);
        $test->assertStringContains("render_social_provider_button(\$provider, \$href, 'login')", $social);
        $test->assertStringContains('is-disabled', $social);
        $test->assertStringContains('OAuth Client ID/Secret', $social);
    },

    'social provider button renderer uses sdk-like markup' => function (TestCase $test): void {
        $vk = render_social_provider_button('vk', '/social/start?provider=vk&mode=login', 'login');
        $yandexDisabled = render_social_provider_button('yandex', null, 'login');

        $test->assertStringContains('social-sdk-button social-sdk-button--vk', $vk);
        $test->assertStringContains('Войти с VK ID', $vk);
        $test->assertStringContains('<svg viewBox=', $vk);
        $test->assertStringContains('data-no-ajax', $vk);
        $test->assertStringContains('social-sdk-button social-sdk-button--yandex is-disabled', $yandexDisabled);
        $test->assertStringContains('aria-disabled="true"', $yandexDisabled);
    },

    'social button stylesheet keeps provider colors and sdk dimensions' => function (TestCase $test): void {
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');

        $test->assertStringContains('.social-sdk-button', $css);
        $test->assertStringContains('min-height: 44px', $css);
        $test->assertStringContains('border-radius: 8px', $css);
        $test->assertStringContains('.social-sdk-button--yandex', $css);
        $test->assertStringContains('#000', $css);
        $test->assertStringContains('.social-sdk-button--vk', $css);
        $test->assertStringContains('#0077ff', $css);
    },

    'social join profile is stored in session with expiry' => function (TestCase $test): void {
        $_SESSION = [];

        social_join_session_store('vk', [
            'external_user_id' => 'vk-42',
            'profile_name' => 'Иван Петров',
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'email' => 'ivan@example.test',
            'phone' => '+79991112233',
        ]);

        $stored = social_join_profile();
        $test->assertSame('vk', $stored['provider'] ?? null);
        $test->assertSame('VK ID', $stored['provider_label'] ?? null);
        $test->assertSame('vk-42', $stored['external_user_id'] ?? null);
        $test->assertSame('ivan@example.test', $stored['email'] ?? null);

        $_SESSION['social_join_profile']['expires_at'] = time() - 1;
        $test->assertNull(social_join_profile());
    },
];
