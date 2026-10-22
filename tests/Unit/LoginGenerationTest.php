<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Account.php';

return [
    'login is generated from email local part' => function (TestCase $test): void {
        $test->assertSame('msadakov', login_from_email('msadakov@yandex.ru'));
        $test->assertSame('ivan.petrov', login_from_email('Ivan.Petrov@example.test'));
    },
    'normalize_user_login lowercases and strips invalid characters' => function (TestCase $test): void {
        $test->assertSame('user123', normalize_user_login('User123'));
        $test->assertSame('john.doe', normalize_user_login('JOHN.DOE'));
        $test->assertSame('hello-world', normalize_user_login('Hello-World'));
        $test->assertSame('user', normalize_user_login('Иван'));
    },
];
