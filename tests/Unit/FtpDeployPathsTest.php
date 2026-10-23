<?php

declare(strict_types=1);

require_once __DIR__ . '/../../scripts/deploy/paths.php';

return [
    'runtime and tooling paths stay off production FTP' => function (TestCase $test): void {
        foreach ([
            'tests/Unit/FooTest.php',
            'docs/shared-hosting.md',
            'scripts/deploy/ftp-deploy.php',
            'Dockerfile',
            'docker-compose.yml',
            'Makefile',
            'package.json',
            'README.md',
            'LICENSE',
            'SECURITY.md',
            'CONTRIBUTING.md',
            '.gitlab-ci.yml',
            '.github/workflows/test.yml',
            '.gitignore',
            'config/local.php',
            'config/ftp.deploy.example.env',
            '.env',
            'composer.lock',
            'vendor/autoload.php',
            'public/_once_migrate_church.php',
            'public/uploads/knowledge/photo.jpg',
            'public/uploads/church/photo.jpg',
            '.deploy-revision',
        ] as $path) {
            $test->assertFalse(deploy_is_deployable_path($path), 'should exclude ' . $path);
        }
    },

    'application paths are deployable' => function (TestCase $test): void {
        foreach ([
            'index.php',
            '.htaccess',
            'public/index.php',
            'public/assets/app.min.js',
            'public/service-worker.js',
            'src/PwaManifest.php',
            'src/bootstrap.php',
            'src/CliLock.php',
            'config/app.php',
            'config/db_connect.php',
            'config/local.example.php',
            'bin/send-bot-notifications.php',
            'database/migrations/025_church_deleted_at.sql',
            'bin/verify-schema.php',
            'composer.json',
            'public/uploads/knowledge/.htaccess',
            'public/uploads/knowledge/.gitkeep',
            'public/uploads/church/.htaccess',
            'public/uploads/church/.gitkeep',
            'preza/index.html',
            'public/assets/fonts/montserrat-400.woff2',
        ] as $path) {
            $test->assertTrue(deploy_is_deployable_path($path), 'should allow ' . $path);
        }
    },

    'path normalization strips dots and duplicate slashes' => function (TestCase $test): void {
        $test->assertSame('src/Account.php', deploy_normalize_path('./src//Account.php'));
        $test->assertSame('public/index.php', deploy_normalize_path('/public/index.php'));
        $test->assertFalse(deploy_is_deployable_path('../etc/passwd'));
        $test->assertFalse(deploy_is_deployable_path('src/../../etc/passwd'));
    },

    'name-status parser handles add modify delete and rename' => function (TestCase $test): void {
        $changes = deploy_parse_name_status(
            "A\tsrc/New.php\n" .
            "M\tpublic/index.php\n" .
            "D\ttests/OldTest.php\n" .
            "R100\told/path.php\n" .
            "src/path.php\n"
        );

        $test->assertSame(4, count($changes));
        $test->assertSame('A', $changes[0]['status']);
        $test->assertSame('src/New.php', $changes[0]['path']);
        $test->assertSame('R', $changes[3]['status']);
        $test->assertSame('old/path.php', $changes[3]['from']);
        $test->assertSame('src/path.php', $changes[3]['path']);
    },

    'deploy plan uploads only deployable paths and deletes renames' => function (TestCase $test): void {
        $plan = deploy_plan_from_changes([
            ['status' => 'A', 'path' => 'src/ChurchTenant.php'],
            ['status' => 'M', 'path' => 'tests/Unit/ChurchTenantTest.php'],
            ['status' => 'M', 'path' => 'docs/shared-hosting.md'],
            ['status' => 'D', 'path' => 'src/Obsolete.php'],
            ['status' => 'D', 'path' => 'Makefile'],
            ['status' => 'R', 'path' => 'src/Brand.php', 'from' => 'src/OldBrand.php'],
            ['status' => 'A', 'path' => 'public/_once_migrate_church.php'],
        ]);

        $test->assertSame(['src/Brand.php', 'src/ChurchTenant.php'], $plan['upload']);
        $test->assertSame(['src/Obsolete.php', 'src/OldBrand.php'], $plan['delete']);
    },

    'upload wins over delete for the same path' => function (TestCase $test): void {
        $plan = deploy_plan_from_changes([
            ['status' => 'D', 'path' => 'src/Tmp.php'],
            ['status' => 'A', 'path' => 'src/Tmp.php'],
        ]);
        $test->assertSame(['src/Tmp.php'], $plan['upload']);
        $test->assertSame([], $plan['delete']);
    },

    'required directories are ordered parent-first' => function (TestCase $test): void {
        $dirs = deploy_required_directories([
            'public/assets/app.min.js',
            'database/migrations/025_church_deleted_at.sql',
        ]);
        $test->assertTrue(array_search('public', $dirs, true) < array_search('public/assets', $dirs, true));
        $test->assertTrue(array_search('database', $dirs, true) < array_search('database/migrations', $dirs, true));
    },

    'resolve mode prefers explicit from then remote revision' => function (TestCase $test): void {
        $forced = deploy_resolve_mode('abc1234', 'deadbeef', true, '1111222233334444555566667777888899990000');
        $test->assertSame('full', $forced['mode']);

        $explicit = deploy_resolve_mode('abc1234', 'deadbee', false, '1111222');
        $test->assertSame('incremental', $explicit['mode']);
        $test->assertSame('deadbee', $explicit['from']);

        $remote = deploy_resolve_mode('abc1234', null, false, '1111222');
        $test->assertSame('incremental', $remote['mode']);
        $test->assertSame('abc1234', $remote['from']);

        $none = deploy_resolve_mode(null, null, false, '1111222');
        $test->assertSame('full', $none['mode']);

        $same = deploy_resolve_mode('1111222', null, false, '1111222');
        $test->assertSame('incremental', $same['mode']);
        $test->assertSame('1111222', $same['from']);
    },

    'git sha validation rejects garbage' => function (TestCase $test): void {
        $test->assertTrue(deploy_is_valid_git_sha('8b91ac5'));
        $test->assertTrue(deploy_is_valid_git_sha(str_repeat('a', 40)));
        $test->assertFalse(deploy_is_valid_git_sha(''));
        $test->assertFalse(deploy_is_valid_git_sha('not-a-sha'));
        $test->assertFalse(deploy_is_valid_git_sha('xyz'));
    },

    'filter helper de-duplicates and sorts' => function (TestCase $test): void {
        $paths = deploy_filter_deployable_paths([
            'src/B.php',
            'tests/x.php',
            'src/A.php',
            'src/A.php',
            '',
        ]);
        $test->assertSame(['src/A.php', 'src/B.php'], $paths);
    },

    'staging paths stay off production FTP and nest under nonce' => function (TestCase $test): void {
        $test->assertFalse(deploy_is_deployable_path('.deploy-staging/src/Account.php'));
        $test->assertSame('tmp', deploy_staging_nonce('!!!'));
        $test->assertSame('abc123', deploy_staging_nonce('ABC123'));
        $test->assertSame(
            '.deploy-staging/abc123/src/Account.php',
            deploy_staging_remote_path('src/Account.php', 'ABC123')
        );
    },

    'atomic upload plan puts then renames onto the live path' => function (TestCase $test): void {
        $plan = deploy_atomic_upload_plan(['src/Account.php', './bin/send-emails.php'], 'deadbeef');
        $test->assertSame([
            [
                'final' => 'src/Account.php',
                'staging' => '.deploy-staging/deadbeef/src/Account.php',
            ],
            [
                'final' => 'bin/send-emails.php',
                'staging' => '.deploy-staging/deadbeef/bin/send-emails.php',
            ],
        ], $plan);

        $dirs = deploy_required_directories_for_atomic_upload(['src/Account.php'], 'deadbeef');
        $test->assertTrue(in_array('.deploy-staging', $dirs, true));
        $test->assertTrue(in_array('.deploy-staging/deadbeef', $dirs, true));
        $test->assertTrue(in_array('.deploy-staging/deadbeef/src', $dirs, true));
        $test->assertTrue(in_array('src', $dirs, true));
        $test->assertTrue(
            array_search('.deploy-staging', $dirs, true) < array_search('.deploy-staging/deadbeef/src', $dirs, true)
        );
    },

    'lftp transfer body uploads via staging then renames' => function (TestCase $test): void {
        require_once __DIR__ . '/../../scripts/deploy/ftp-deploy.php';
        $body = deploy_lftp_transfer_body(
            '/repo',
            ['src/Account.php'],
            ['src/Gone.php'],
            '/tmp/rev',
            'abc123'
        );
        $joined = implode("\n", $body);
        $test->assertStringContains('put "/repo/src/Account.php" -o ".deploy-staging/abc123/src/Account.php"', $joined);
        $test->assertStringContains('mv ".deploy-staging/abc123/src/Account.php" "src/Account.php"', $joined);
        $test->assertStringContains('rm -f "src/Gone.php"', $joined);
        $test->assertStringContains('put "/tmp/rev" -o ".deploy-staging/abc123/.deploy-revision"', $joined);
        $test->assertStringContains('mv ".deploy-staging/abc123/.deploy-revision" ".deploy-revision"', $joined);
        $test->assertStringContains('rm -r -f ".deploy-staging/abc123"', $joined);
        $test->assertStringNotContains('put "/repo/src/Account.php" -o "src/Account.php"', $joined);
    },
];
