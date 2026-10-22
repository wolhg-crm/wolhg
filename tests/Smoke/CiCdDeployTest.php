<?php

declare(strict_types=1);

return [
    'gitlab ci defines test then incremental ftp deploy' => function (TestCase $test): void {
        $ci = file_get_contents(__DIR__ . '/../../.gitlab-ci.yml');
        $test->assertFileExists(__DIR__ . '/../../.gitlab-ci.yml');
        $test->assertStringContains("stage: test", $ci);
        $test->assertStringContains('make test', $ci);
        $test->assertStringContains('deploy:ftp:', $ci);
        $test->assertStringContains('resource_group: production-ftp', $ci);
        $test->assertStringContains('php scripts/deploy/ftp-deploy.php', $ci);
        $test->assertStringContains('DEPLOY_DRY_RUN=1 php scripts/deploy/ftp-deploy.php', $ci);
        $test->assertStringContains('/system/migrate', $ci);
        $test->assertStringContains('MIGRATE_TOKEN', $ci);
        $test->assertStringContains('$CI_DEFAULT_BRANCH', $ci);
        $test->assertStringNotContains('FTP_PASSWORD:', $ci);
    },

    'deploy tooling and docs are present and protected from the web' => function (TestCase $test): void {
        $test->assertFileExists(__DIR__ . '/../../scripts/deploy/paths.php');
        $test->assertFileExists(__DIR__ . '/../../scripts/deploy/ftp-deploy.php');
        $test->assertFileExists(__DIR__ . '/../../docs/ci-cd.md');
        $test->assertFileExists(__DIR__ . '/../../config/ftp.deploy.example.env');

        $htaccess = file_get_contents(__DIR__ . '/../../.htaccess');
        $test->assertStringContains('scripts', $htaccess);
        $test->assertStringContains('.deploy-revision', $htaccess);
        $test->assertStringContains('.deploy-staging', $htaccess);
        $test->assertStringContains('gitlab-ci', $htaccess);

        $deploy = file_get_contents(__DIR__ . '/../../scripts/deploy/ftp-deploy.php');
        $test->assertStringContains('deploy_lftp_transfer_body', $deploy);
        $test->assertStringContains('deploy_atomic_upload_plan', $deploy);
        $test->assertStringContains('DEPLOY_STAGING_DIR', $deploy);

        $makefile = file_get_contents(__DIR__ . '/../../Makefile');
        $test->assertStringContains('deploy-dry-run:', $makefile);
        $test->assertStringContains('deploy-ftp:', $makefile);
        $test->assertStringContains('scripts/deploy/paths.php', $makefile);
    },

    'example ftp env has no real secrets' => function (TestCase $test): void {
        $example = file_get_contents(__DIR__ . '/../../config/ftp.deploy.example.env');
        $test->assertStringContains('FTP_HOST=', $example);
        $test->assertStringContains("FTP_PASSWORD=\n", $example);
        $test->assertStringContains('FTP_REMOTE_PATH=/public_html', $example);
        $test->assertFalse((bool) preg_match('/^FTP_PASSWORD=.+$/m', $example));
    },

    'github actions workflow runs make test' => function (TestCase $test): void {
        $workflow = file_get_contents(__DIR__ . '/../../.github/workflows/test.yml');
        $test->assertFileExists(__DIR__ . '/../../.github/workflows/test.yml');
        $test->assertStringContains('make test', $workflow);
        $test->assertStringContains("php-version: '8.5'", $workflow);
    },
];
