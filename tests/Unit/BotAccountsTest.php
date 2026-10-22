<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Bots.php';

return [
    'bot provider labels include telegram' => function (TestCase $test): void {
        $test->assertSame('Max', bot_provider_label('max'));
        $test->assertSame('VK', bot_provider_label('vk'));
        $test->assertSame('Telegram', bot_provider_label('telegram'));
        $test->assertSame(['max', 'vk', 'telegram'], bot_supported_providers());
    },

    'bot account display prefers display_name over chat_id' => function (TestCase $test): void {
        [$label, $value] = bot_account_display_pair([
            'provider' => 'max',
            'chat_id' => 'chat-42',
            'display_name' => '  Иван  ',
        ]);

        $test->assertSame('Max', $label);
        $test->assertSame('Иван', $value);
    },

    'bot account display falls back to chat_id' => function (TestCase $test): void {
        [$label, $value] = bot_account_display_pair([
            'provider' => 'vk',
            'chat_id' => '2000001',
            'display_name' => '   ',
        ]);

        $test->assertSame('VK', $label);
        $test->assertSame('2000001', $value);
    },

    'bot accounts for invalid user id skip database' => function (TestCase $test): void {
        $test->assertSame([], bot_accounts_for_user(0));
        $test->assertSame([], bot_accounts_for_user(-3));
    },

    'normalize link code accepts deep-link payloads' => function (TestCase $test): void {
        $test->assertSame('ABCDEF12', bot_normalize_link_code('abcdef12'));
        $test->assertSame('ABCDEF12', bot_normalize_link_code('  ABCDEF12 '));
        $test->assertNull(bot_normalize_link_code('short'));
        $test->assertNull(bot_normalize_link_code('called:12'));
        $test->assertNull(bot_normalize_link_code(''));
    },

    'link code from text supports start and russian bind' => function (TestCase $test): void {
        $test->assertSame('ABCDEF12', bot_link_code_from_text('/start ABCDEF12'));
        $test->assertSame('ABCDEF12', bot_link_code_from_text('/start@WolhgCrmBot ABCDEF12'));
        $test->assertSame('ABCDEF12', bot_link_code_from_text('привязать ABCDEF12'));
        $test->assertSame('ABCDEF12', bot_link_code_from_text('link ABCDEF12'));
        $test->assertNull(bot_link_code_from_text('/start'));
        $test->assertNull(bot_link_code_from_text('hello'));
    },

    'deep links are built for Max Telegram and VK' => function (TestCase $test): void {
        $test->assertSame(
            'https://max.ru/crm_groups_bot?start=ABCDEF12',
            bot_build_deep_link('max', 'ABCDEF12', ['username' => 'crm_groups_bot'])
        );
        $test->assertSame(
            'https://t.me/WolhgCrmBot?start=ABCDEF12',
            bot_build_deep_link('telegram', 'ABCDEF12', ['username' => 'WolhgCrmBot'])
        );
        $test->assertSame(
            'https://vk.me/church_bot?ref=ABCDEF12',
            bot_build_deep_link('vk', 'ABCDEF12', ['screen_name' => 'church_bot'])
        );
        $test->assertNull(bot_build_deep_link('max', 'ABCDEF12', ['username' => '']));
        $test->assertSame(
            'https://vk.me/club999?ref=ABCDEF12',
            bot_build_deep_link('vk', 'ABCDEF12', ['screen_name' => '', 'group_id' => '999'])
        );
    },

    'max bot_started update exposes start payload as link code' => function (TestCase $test): void {
        $parsed = bot_parse_update('max', [
            'update_type' => 'bot_started',
            'timestamp' => 1,
            'chat_id' => 778899,
            'user' => [
                'user_id' => 42,
                'first_name' => 'Иван',
                'last_name' => 'Петров',
            ],
            'payload' => 'ABCDEF12',
        ]);

        $test->assertSame('778899', $parsed['chat_id']);
        $test->assertSame('42', $parsed['external_user_id']);
        $test->assertSame('Иван Петров', $parsed['display_name']);
        $test->assertSame('ABCDEF12', $parsed['link_code']);
        $test->assertSame('ABCDEF12', $parsed['callback']);
        $test->assertSame('', $parsed['text']);
    },

    'max message_created reads body.text and recipient.chat_id' => function (TestCase $test): void {
        $parsed = bot_parse_update('max', [
            'update_type' => 'message_created',
            'message' => [
                'sender' => ['user_id' => 7, 'first_name' => 'Лидер'],
                'recipient' => ['chat_id' => 1001],
                'body' => ['text' => 'привязать ABCDEF12'],
            ],
        ]);

        $test->assertSame('1001', $parsed['chat_id']);
        $test->assertSame('7', $parsed['external_user_id']);
        $test->assertSame('привязать ABCDEF12', $parsed['text']);
        $test->assertSame('ABCDEF12', $parsed['link_code']);
        $test->assertSame('Лидер', $parsed['display_name']);
    },

    'telegram start deep link is parsed from message text' => function (TestCase $test): void {
        $parsed = bot_parse_update('telegram', [
            'message' => [
                'message_id' => 1,
                'text' => '/start ABCDEF12',
                'chat' => ['id' => 555],
                'from' => ['id' => 9, 'first_name' => 'Anna'],
            ],
        ]);

        $test->assertSame('555', $parsed['chat_id']);
        $test->assertSame('9', $parsed['external_user_id']);
        $test->assertSame('ABCDEF12', $parsed['link_code']);
        $test->assertSame('Anna', $parsed['display_name']);
    },

    'vk ref deep link is parsed from message ref' => function (TestCase $test): void {
        $parsed = bot_parse_update('vk', [
            'type' => 'message_new',
            'object' => [
                'message' => [
                    'peer_id' => 2000001,
                    'from_id' => 77,
                    'text' => 'привет',
                    'ref' => 'ABCDEF12',
                ],
            ],
        ]);

        $test->assertSame('2000001', $parsed['chat_id']);
        $test->assertSame('77', $parsed['external_user_id']);
        $test->assertSame('ABCDEF12', $parsed['link_code']);
        $test->assertSame('привет', $parsed['text']);
    },

    'legacy extract_update tuple stays compatible' => function (TestCase $test): void {
        [$chatId, $externalId, $text, $callback, $location, $raw] = bot_extract_update('max', [
            'update_type' => 'bot_started',
            'chat_id' => 1,
            'user' => ['user_id' => 2, 'name' => 'N'],
            'payload' => 'ABCDEF12',
        ]);
        $test->assertSame('1', $chatId);
        $test->assertSame('2', $externalId);
        $test->assertSame('', $text);
        $test->assertSame('ABCDEF12', $callback);
        $test->assertSame([], $location);
        $test->assertTrue(is_array($raw));
    },
];
