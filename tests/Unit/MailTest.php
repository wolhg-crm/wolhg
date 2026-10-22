<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Mail.php';

return [
    'mail_encode_header leaves ascii unchanged' => function (TestCase $test): void {
        $test->assertSame('Password reset', mail_encode_header('Password reset'));
    },
    'mail_encode_header encodes utf8 subject' => function (TestCase $test): void {
        $encoded = mail_encode_header('Восстановление пароля CRM');
        $test->assertTrue(str_starts_with($encoded, '=?UTF-8?B?'));
        $test->assertTrue(str_ends_with($encoded, '?='));
    },
    'mail_build_smtp_data escapes leading dots and uses crlf' => function (TestCase $test): void {
        $data = mail_build_smtp_data('from@example.com', 'to@example.com', 'Тема', "line1\n.secret\nline3");
        $test->assertTrue(str_contains($data, "Subject: =?UTF-8?B?"));
        $test->assertTrue(str_contains($data, "\r\n\r\n"));
        $test->assertTrue(str_contains($data, "\r\n..secret\r\n"));
    },
];
