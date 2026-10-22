#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Incremental FTP deploy driven by git history.
 *
 * Planning is pure PHP (see paths.php). Transfers use lftp for reliable
 * passive-mode uploads on shared hosting.
 *
 * PHP files are published atomically: put into `.deploy-staging/<nonce>/…`,
 * then `mv` onto the live path so cron/web never include a half-written file.
 *
 * Required env:
 *   FTP_HOST, FTP_USER, FTP_PASSWORD
 *
 * Optional env:
 *   FTP_PORT (21), FTP_REMOTE_PATH (/public_html), FTP_SSL (0|1)
 *   DEPLOY_TO (git ref, default HEAD)
 *   DEPLOY_FROM (force base sha)
 *   DEPLOY_FULL (1 = upload every deployable tracked file)
 *   DEPLOY_DRY_RUN (1 = plan only)
 */

require_once __DIR__ . '/paths.php';

/**
 * @return never
 */
function deploy_fail(string $message, int $code = 1): void
{
    fwrite(STDERR, 'ftp-deploy: ' . $message . PHP_EOL);
    exit($code);
}

function deploy_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function deploy_repo_root(): string
{
    $root = realpath(dirname(__DIR__, 2));
    if ($root === false) {
        deploy_fail('cannot resolve repository root');
    }

    return $root;
}

/**
 * @return list<string>
 */
function deploy_run_git(string $repoRoot, array $args): array
{
    $cmd = array_merge(['git', '-C', $repoRoot], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repoRoot);
    if (!is_resource($proc)) {
        deploy_fail('failed to start git: ' . implode(' ', $args));
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);
    if ($status !== 0) {
        deploy_fail('git ' . implode(' ', $args) . ' failed: ' . trim((string) $stderr));
    }

    return preg_split("/\r\n|\n|\r/", trim((string) $stdout)) ?: [];
}

function deploy_git_rev_parse(string $repoRoot, string $ref): string
{
    $lines = deploy_run_git($repoRoot, ['rev-parse', $ref]);
    $sha = trim($lines[0] ?? '');
    if (!deploy_is_valid_git_sha($sha)) {
        deploy_fail('invalid git revision for ' . $ref);
    }

    return strtolower($sha);
}

function deploy_git_is_ancestor(string $repoRoot, string $maybeAncestor, string $descendant): bool
{
    $cmd = ['git', '-C', $repoRoot, 'merge-base', '--is-ancestor', $maybeAncestor, $descendant];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repoRoot);
    if (!is_resource($proc)) {
        return false;
    }
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($proc) === 0;
}

/**
 * @return list<string>
 */
function deploy_tracked_files(string $repoRoot): array
{
    $cmd = ['git', '-C', $repoRoot, 'ls-files', '-z'];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repoRoot);
    if (!is_resource($proc)) {
        deploy_fail('git ls-files failed to start');
    }
    $raw = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        deploy_fail('git ls-files failed');
    }
    $parts = array_values(array_filter(explode("\0", (string) $raw), static fn (string $p): bool => $p !== ''));

    return deploy_filter_deployable_paths($parts);
}

/**
 * @return array{upload: list<string>, delete: list<string>, _fallback_full?: bool}
 */
function deploy_plan_incremental(string $repoRoot, string $from, string $to): array
{
    if (!deploy_git_is_ancestor($repoRoot, $from, $to)) {
        fwrite(STDERR, "ftp-deploy: base {$from} is not an ancestor of {$to}; falling back to full deploy\n");
        return [
            'upload' => deploy_tracked_files($repoRoot),
            'delete' => [],
            '_fallback_full' => true,
        ];
    }

    $cmd = ['git', '-C', $repoRoot, 'diff', '--name-status', '--no-renames', '-z', $from . '...' . $to];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repoRoot);
    if (!is_resource($proc)) {
        deploy_fail('git diff failed to start');
    }
    $raw = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        deploy_fail('git diff failed: ' . trim($stderr));
    }

    $tokens = array_values(array_filter(explode("\0", $raw), static fn (string $t): bool => $t !== ''));
    $lines = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i += 2) {
        $status = $tokens[$i];
        $path = $tokens[$i + 1] ?? '';
        if ($path === '') {
            continue;
        }
        $lines[] = substr($status, 0, 1) . "\t" . $path;
    }

    return deploy_plan_from_changes(deploy_parse_name_status(implode("\n", $lines)));
}

function deploy_require_lftp(): void
{
    $which = trim((string) shell_exec('command -v lftp 2>/dev/null'));
    if ($which === '') {
        deploy_fail('lftp is required for FTP transfers (apt install lftp)');
    }
}

function deploy_lftp_quote(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

/**
 * @return array{0: string, 1: string} script path and revision tempfile (may be empty string)
 */
function deploy_lftp_write_script(
    string $host,
    string $user,
    string $password,
    int $port,
    string $remotePath,
    bool $ssl,
    string $body,
    bool $failExit = true
): array {
    $scriptFile = tempnam(sys_get_temp_dir(), 'lftp');
    if ($scriptFile === false) {
        deploy_fail('tempnam failed');
    }
    @chmod($scriptFile, 0600);

    $remotePath = '/' . trim(str_replace('\\', '/', $remotePath), '/');
    $lines = [
        'set cmd:fail-exit ' . ($failExit ? 'true' : 'false'),
        'set ftp:passive-mode true',
        'set ftp:prefer-epsv false',
        'set ftp:use-feat false',
        'set net:max-retries 3',
        'set net:reconnect-interval-base 2',
        'set net:timeout 45',
        'set xfer:clobber true',
    ];
    if ($ssl) {
        $lines[] = 'set ftp:ssl-force true';
        $lines[] = 'set ssl:verify-certificate false';
    }
    // ProFTPD on this host rejects some URL-form logins; -u user,pass host works.
    $lines[] = sprintf(
        'open -p %d -u %s,%s %s',
        $port,
        deploy_lftp_quote($user),
        deploy_lftp_quote($password),
        $host
    );
    $lines[] = 'cd ' . deploy_lftp_quote($remotePath);
    $script = implode("\n", $lines) . "\n" . $body . "\nbye\n";
    if (file_put_contents($scriptFile, $script) === false) {
        @unlink($scriptFile);
        deploy_fail('cannot write lftp script');
    }

    return [$scriptFile, ''];
}

/**
 * @param list<string> $upload
 * @param list<string> $delete
 * @return list<string>
 */
function deploy_lftp_transfer_body(
    string $repoRoot,
    array $upload,
    array $delete,
    string $revFile,
    string $nonce
): array {
    $atomic = deploy_atomic_upload_plan($upload, $nonce);
    $mkdirs = deploy_required_directories_for_atomic_upload($upload, $nonce);
    $body = [];

    if ($mkdirs !== []) {
        $body[] = 'set cmd:fail-exit false';
        foreach ($mkdirs as $dir) {
            // ProFTPD returns 550 when the directory already exists.
            $body[] = 'mkdir -p ' . deploy_lftp_quote($dir);
        }
        $body[] = 'set cmd:fail-exit true';
    }

    foreach ($atomic as $item) {
        $body[] = sprintf(
            'put %s -o %s',
            deploy_lftp_quote($repoRoot . '/' . $item['final']),
            deploy_lftp_quote($item['staging'])
        );
        $body[] = sprintf(
            'mv %s %s',
            deploy_lftp_quote($item['staging']),
            deploy_lftp_quote($item['final'])
        );
    }

    foreach ($delete as $path) {
        $body[] = 'rm -f ' . deploy_lftp_quote($path);
    }

    $revStaging = deploy_staging_remote_path(DEPLOY_REVISION_FILE, $nonce);
    $revParents = deploy_required_directories([$revStaging]);
    if ($revParents !== []) {
        $body[] = 'set cmd:fail-exit false';
        foreach ($revParents as $dir) {
            $body[] = 'mkdir -p ' . deploy_lftp_quote($dir);
        }
        $body[] = 'set cmd:fail-exit true';
    }
    $body[] = sprintf(
        'put %s -o %s',
        deploy_lftp_quote($revFile),
        deploy_lftp_quote($revStaging)
    );
    $body[] = sprintf(
        'mv %s %s',
        deploy_lftp_quote($revStaging),
        deploy_lftp_quote(DEPLOY_REVISION_FILE)
    );

    // Drop this release's staging tree (best-effort).
    $body[] = 'set cmd:fail-exit false';
    $body[] = 'rm -r -f ' . deploy_lftp_quote(DEPLOY_STAGING_DIR . '/' . deploy_staging_nonce($nonce));
    $body[] = 'set cmd:fail-exit true';

    return $body;
}

/**
 * @param list<string> $upload
 * @param list<string> $delete
 */
function deploy_lftp_sync(
    string $host,
    int $port,
    string $user,
    string $password,
    string $remotePath,
    bool $ssl,
    string $repoRoot,
    array $upload,
    array $delete,
    string $toSha
): void {
    deploy_require_lftp();

    $revFile = tempnam(sys_get_temp_dir(), 'drev');
    if ($revFile === false) {
        deploy_fail('tempnam failed');
    }
    @chmod($revFile, 0600);
    if (file_put_contents($revFile, $toSha . "\n") === false) {
        @unlink($revFile);
        deploy_fail('cannot write revision tempfile');
    }

    $nonce = deploy_staging_nonce($toSha);
    $body = deploy_lftp_transfer_body($repoRoot, $upload, $delete, $revFile, $nonce);

    [$scriptFile] = deploy_lftp_write_script(
        $host,
        $user,
        $password,
        $port,
        $remotePath,
        $ssl,
        implode("\n", $body),
        true
    );

    $proc = proc_open(
        ['lftp', '-f', $scriptFile],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $repoRoot
    );
    if (!is_resource($proc)) {
        @unlink($scriptFile);
        @unlink($revFile);
        deploy_fail('failed to start lftp');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);
    @unlink($scriptFile);
    @unlink($revFile);

    if ($stdout !== '') {
        fwrite(STDOUT, $stdout);
    }
    if ($status !== 0) {
        deploy_fail('lftp failed: ' . trim($stderr !== '' ? $stderr : $stdout));
    }
}

function deploy_lftp_get_revision(
    string $host,
    int $port,
    string $user,
    string $password,
    string $remotePath,
    bool $ssl
): ?string {
    deploy_require_lftp();
    $outFile = tempnam(sys_get_temp_dir(), 'drev');
    if ($outFile === false) {
        return null;
    }
    @unlink($outFile);

    $body = 'get ' . deploy_lftp_quote(DEPLOY_REVISION_FILE) . ' -o ' . deploy_lftp_quote($outFile);
    [$scriptFile] = deploy_lftp_write_script(
        $host,
        $user,
        $password,
        $port,
        $remotePath,
        $ssl,
        $body,
        false
    );

    $proc = proc_open(['lftp', '-f', $scriptFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        @unlink($scriptFile);
        return null;
    }
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($scriptFile);

    if (!is_file($outFile)) {
        return null;
    }
    $data = trim((string) file_get_contents($outFile));
    @unlink($outFile);

    return $data !== '' ? $data : null;
}

// --- main ---

if (PHP_SAPI !== 'cli' || realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== realpath(__FILE__)) {
    return;
}

$repoRoot = deploy_repo_root();
$host = deploy_env('FTP_HOST');
$user = deploy_env('FTP_USER');
$password = deploy_env('FTP_PASSWORD');
$remotePath = deploy_env('FTP_REMOTE_PATH', '/public_html') ?? '/public_html';
$port = (int) (deploy_env('FTP_PORT', '21') ?? '21');
$ssl = in_array(strtolower((string) deploy_env('FTP_SSL', '0')), ['1', 'true', 'yes'], true);
$dryRun = in_array(strtolower((string) deploy_env('DEPLOY_DRY_RUN', '0')), ['1', 'true', 'yes'], true);
$forceFull = in_array(strtolower((string) deploy_env('DEPLOY_FULL', '0')), ['1', 'true', 'yes'], true);
$toRef = deploy_env('DEPLOY_TO', 'HEAD') ?? 'HEAD';
$explicitFrom = deploy_env('DEPLOY_FROM');

if (!$dryRun) {
    if ($host === null || $user === null || $password === null) {
        deploy_fail('FTP_HOST, FTP_USER and FTP_PASSWORD are required (or set DEPLOY_DRY_RUN=1)');
    }
}

$toSha = deploy_git_rev_parse($repoRoot, $toRef);
$remoteRevision = null;

if (!$dryRun) {
    /** @var string $host */
    /** @var string $user */
    /** @var string $password */
    $remoteRevision = deploy_lftp_get_revision($host, $port, $user, $password, $remotePath, $ssl);
}

$mode = deploy_resolve_mode($remoteRevision, $explicitFrom, $forceFull, $toSha);
$fallbackFull = false;

if ($mode['mode'] === 'full') {
    $plan = [
        'upload' => deploy_tracked_files($repoRoot),
        'delete' => [],
    ];
} else {
    /** @var string $from */
    $from = $mode['from'];
    if ($from === $toSha) {
        $plan = ['upload' => [], 'delete' => []];
    } else {
        $plan = deploy_plan_incremental($repoRoot, $from, $toSha);
        if (isset($plan['_fallback_full'])) {
            $fallbackFull = true;
            unset($plan['_fallback_full']);
            $mode['mode'] = 'full';
            $mode['reason'] = 'base not ancestor; full deploy';
        }
    }
}

$upload = $plan['upload'];
$delete = $plan['delete'];

foreach ($upload as $path) {
    if (!is_file($repoRoot . '/' . $path)) {
        deploy_fail('missing local file for upload: ' . $path);
    }
}

fwrite(STDOUT, sprintf(
    "ftp-deploy: mode=%s reason=%s to=%s upload=%d delete=%d dry_run=%s\n",
    $mode['mode'],
    $mode['reason'],
    $toSha,
    count($upload),
    count($delete),
    $dryRun ? 'yes' : 'no'
));

if ($mode['from'] !== null) {
    fwrite(STDOUT, 'ftp-deploy: from=' . $mode['from'] . ($fallbackFull ? " (ignored)\n" : "\n"));
}

foreach ($upload as $path) {
    fwrite(STDOUT, "UPLOAD {$path}\n");
}
foreach ($delete as $path) {
    fwrite(STDOUT, "DELETE {$path}\n");
}

if ($dryRun) {
    fwrite(STDOUT, "ftp-deploy: dry-run complete\n");
    exit(0);
}

if ($upload === [] && $delete === []) {
    // Still refresh the marker so CI stays aligned after no-op deploys.
    /** @var string $host */
    /** @var string $user */
    /** @var string $password */
    deploy_lftp_sync($host, $port, $user, $password, $remotePath, $ssl, $repoRoot, [], [], $toSha);
    fwrite(STDOUT, "ftp-deploy: done uploaded=0 deleted=0 revision={$toSha}\n");
    exit(0);
}

/** @var string $host */
/** @var string $user */
/** @var string $password */
deploy_lftp_sync($host, $port, $user, $password, $remotePath, $ssl, $repoRoot, $upload, $delete, $toSha);

fwrite(STDOUT, sprintf(
    "ftp-deploy: done uploaded=%d deleted=%d revision=%s\n",
    count($upload),
    count($delete),
    $toSha
));
