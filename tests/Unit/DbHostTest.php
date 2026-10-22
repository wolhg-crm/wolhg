<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db_host.php';

return [
    'docker db host stays db inside docker runtime' => function (TestCase $test): void {
        if (!application_runs_in_docker()) {
            $test->assertSame('localhost', resolve_db_host('db'));
            return;
        }

        $test->assertSame('db', resolve_db_host('db'));
    },

    'shared hosting resolves docker service name to localhost' => function (TestCase $test): void {
        if (application_runs_in_docker()) {
            return;
        }

        $test->assertSame('localhost', resolve_db_host('db'));
    },

    'explicit db host is preserved' => function (TestCase $test): void {
        $test->assertSame('mysql.example.net', resolve_db_host('mysql.example.net'));
        $test->assertSame('localhost', resolve_db_host('localhost'));
    },

    'app config resolves docker host outside docker' => function (TestCase $test): void {
        if (application_runs_in_docker()) {
            return;
        }

        $config = require __DIR__ . '/../../config/app.php';
        $test->assertSame('localhost', $config['db']['host']);
    },
];
