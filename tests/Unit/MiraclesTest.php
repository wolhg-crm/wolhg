<?php

declare(strict_types=1);

use function ChurchCare\miracle_author_label;
use function ChurchCare\miracle_body_required_for_testimony;
use function ChurchCare\miracle_default_title_from_prayer;
use function ChurchCare\miracle_excerpt;
use function ChurchCare\miracle_prepare_payload;
use function ChurchCare\miracle_status_label;
use function ChurchCare\miracle_status_labels;

require_once __DIR__ . '/../../src/Domain.php';

return [
    'miracle status labels are russian' => function (TestCase $test): void {
        $labels = miracle_status_labels();
        $test->assertSame('Опубликовано', $labels['published']);
        $test->assertSame('В архиве', $labels['archived']);
        $test->assertSame('Опубликовано', miracle_status_label('published'));
        $test->assertSame('В архиве', miracle_status_label('archived'));
        $test->assertSame('draft', miracle_status_label('draft'));
    },

    'miracle default title prefixes prayer title' => function (TestCase $test): void {
        $test->assertSame('Свидетельство', miracle_default_title_from_prayer(''));
        $test->assertSame('Свидетельство: Здоровье мамы', miracle_default_title_from_prayer('Здоровье мамы'));
        $test->assertSame('Свидетельство по работе', miracle_default_title_from_prayer('Свидетельство по работе'));
        $test->assertSame('Чудо исцеления', miracle_default_title_from_prayer('Чудо исцеления'));
    },

    'miracle payload requires title and body' => function (TestCase $test): void {
        $test->assertNull(miracle_prepare_payload('', 'текст', false));
        $test->assertNull(miracle_prepare_payload('Заголовок', '  ', true));
        $payload = miracle_prepare_payload('  Ответ  ', " Господь помог.\n", true);
        $test->assertTrue($payload !== null);
        $test->assertSame('Ответ', $payload['title']);
        $test->assertSame('Господь помог.', $payload['body']);
        $test->assertSame(1, $payload['is_anonymous']);
    },

    'miracle excerpt truncates with ellipsis' => function (TestCase $test): void {
        $short = 'Короткий текст';
        $test->assertSame($short, miracle_excerpt($short, 40));
        $long = str_repeat('слово ', 40);
        $excerpt = miracle_excerpt($long, 30);
        $test->assertTrue(mb_strlen($excerpt) <= 30);
        $test->assertTrue(str_ends_with($excerpt, '…'));
    },

    'miracle author label respects anonymity' => function (TestCase $test): void {
        $test->assertSame(
            'Анонимное свидетельство',
            miracle_author_label(['is_anonymous' => 1, 'full_name' => 'Иван', 'group_name' => 'Центр'])
        );
        $test->assertSame(
            'Иван · Центр',
            miracle_author_label(['is_anonymous' => 0, 'full_name' => 'Иван', 'group_name' => 'Центр'])
        );
        $test->assertSame(
            'Иван',
            miracle_author_label(['is_anonymous' => 0, 'full_name' => 'Иван', 'group_name' => ''])
        );
        $test->assertSame(
            'Гость Анна',
            miracle_author_label(['is_anonymous' => 0, 'full_name' => '', 'submitter_name' => 'Гость Анна', 'group_name' => ''])
        );
        $test->assertSame(
            'Свидетельство домашней группы',
            miracle_author_label(['is_anonymous' => 0, 'full_name' => '', 'group_name' => ''])
        );
    },

    'testimony requires miracle body only when creating first time' => function (TestCase $test): void {
        $test->assertTrue(miracle_body_required_for_testimony(null, ''));
        $test->assertTrue(miracle_body_required_for_testimony(null, '   '));
        $test->assertFalse(miracle_body_required_for_testimony(null, 'Господь ответил'));
        $test->assertFalse(miracle_body_required_for_testimony(['id' => 1], ''));
        $test->assertFalse(miracle_body_required_for_testimony(['id' => 1], 'обновление'));
    },
];
