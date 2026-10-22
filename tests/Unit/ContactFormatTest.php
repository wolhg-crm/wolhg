<?php

declare(strict_types=1);

use function ChurchCare\format_phone;
use function ChurchCare\format_telegram;
use function ChurchCare\normalize_email;
use function ChurchCare\normalize_phone;
use function ChurchCare\normalize_telegram;
use function ChurchCare\telegram_username;

return [
    'normalize_phone accepts common russian variants' => function (TestCase $test): void {
        $expected = '+79778189773';
        $test->assertSame($expected, normalize_phone('+7 977 818-97-73'));
        $test->assertSame($expected, normalize_phone('89778189773'));
        $test->assertSame($expected, normalize_phone('9778189773'));
        $test->assertSame($expected, normalize_phone('+7 (977) 818-97-73'));
        $test->assertSame($expected, normalize_phone('7 977 818 97 73'));
    },
    'normalize_phone treats empty prefix as null' => function (TestCase $test): void {
        $test->assertNull(normalize_phone(null));
        $test->assertNull(normalize_phone(''));
        $test->assertNull(normalize_phone('+7'));
        $test->assertNull(normalize_phone('+7 '));
        $test->assertNull(normalize_phone('977 818'));
    },
    'format_phone renders the human mask' => function (TestCase $test): void {
        $test->assertSame('+7 977 818-97-73', format_phone('+79778189773'));
        $test->assertSame('+7 977 818-97-73', format_phone('8 977 818 97 73'));
        $test->assertSame('', format_phone(null));
        $test->assertSame('+7 977', format_phone('7977'));
    },
    'normalize_telegram strips urls and at sign' => function (TestCase $test): void {
        $test->assertSame('sadakov', normalize_telegram('@sadakov'));
        $test->assertSame('sadakov', normalize_telegram('sadakov'));
        $test->assertSame('sadakov', normalize_telegram('https://t.me/sadakov'));
        $test->assertSame('sadakov', normalize_telegram('t.me/sadakov?start=1'));
        $test->assertNull(normalize_telegram('@'));
        $test->assertNull(normalize_telegram(''));
        $test->assertNull(normalize_telegram('ab'));
        $test->assertNull(normalize_telegram('abcd'));
        $test->assertNull(normalize_telegram('1badname'));
        $test->assertSame('abcde', normalize_telegram('abcde'));
    },
    'format_telegram always shows at prefix' => function (TestCase $test): void {
        $test->assertSame('@sadakov', format_telegram('sadakov'));
        $test->assertSame('@sadakov', format_telegram('@sadakov'));
        $test->assertSame('', format_telegram(null));
        $test->assertSame('sadakov', telegram_username('@sadakov'));
    },
    'normalize_email validates and lowercases' => function (TestCase $test): void {
        $test->assertSame('user@example.com', normalize_email('User@Example.com'));
        $test->assertNull(normalize_email(''));
        $test->assertNull(normalize_email('not-an-email'));
        $test->assertNull(normalize_email('missing@'));
    },
    'person row telegram action uses monochrome svg icon' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');

        $test->assertStringContains('function telegram_icon_svg', $bootstrap);
        $test->assertStringContains('icon-telegram', $bootstrap);
        $test->assertStringContains('fill="currentColor"', $bootstrap);
        $test->assertStringContains('telegram_icon_svg()', $index);
        $test->assertStringContains('aria-label="Telegram"', $index);
        $test->assertStringNotContains('title="Telegram">✉', $index);
        $test->assertStringContains('.icon-btn .icon-telegram', $css);
    },
];
