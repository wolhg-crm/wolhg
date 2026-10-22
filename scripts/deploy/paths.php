<?php

declare(strict_types=1);

/**
 * Pure deploy-path policy for incremental FTP releases.
 * Kept free of I/O so unit tests can exercise every branch.
 */

const DEPLOY_REVISION_FILE = '.deploy-revision';

/**
 * Remote-only directory used for put-then-rename publishes.
 * Keeps PHP/cron from reading a half-written file mid-upload.
 */
const DEPLOY_STAGING_DIR = '.deploy-staging';

/**
 * Paths that must never leave the build machine via FTP deploy.
 *
 * @return list<string>
 */
function deploy_excluded_prefixes(): array
{
    return [
        '.git/',
        '.github/',
        '.cursor/',
        '.codex/',
        '.vscode/',
        '.gitlab-runner/',
        'tests/',
        'docs/',
        'scripts/',
        'tmp/',
        'vendor/',
        DEPLOY_STAGING_DIR . '/',
    ];
}

/**
 * Exact relative paths that are never uploaded.
 *
 * @return list<string>
 */
function deploy_excluded_files(): array
{
    return [
        '.gitignore',
        '.gitattributes',
        '.gitlab-ci.yml',
        '.env',
        'Dockerfile',
        'docker-compose.yml',
        'docker-entrypoint.sh',
        'apache.conf',
        'Makefile',
        'package.json',
        'composer.lock',
        'README.md',
        'LICENSE',
        'SECURITY.md',
        'CONTRIBUTING.md',
        'config/local.php',
        'config/ftp.deploy.example.env',
        DEPLOY_REVISION_FILE,
    ];
}

/**
 * Basename patterns that stay off production (one-shot migrators, local junk).
 *
 * @return list<string>
 */
function deploy_excluded_basename_globs(): array
{
    return [
        '_once_*.php',
        '.DS_Store',
        '*.log',
        '*.swp',
        '*~',
    ];
}

function deploy_normalize_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#^\./#', '', $path) ?? $path;
    $path = preg_replace('#/+#', '/', $path) ?? $path;

    return ltrim($path, '/');
}

function deploy_path_matches_glob(string $basename, string $glob): bool
{
    return fnmatch($glob, $basename, FNM_PATHNAME | FNM_CASEFOLD);
}

function deploy_is_user_upload_content(string $path): bool
{
    $path = deploy_normalize_path($path);
    if (!str_starts_with($path, 'public/uploads/')) {
        return false;
    }

    // Keep directory guards from the repo; never overwrite/delete user media.
    $allowed = [
        'public/uploads/knowledge/.gitkeep',
        'public/uploads/knowledge/.htaccess',
        'public/uploads/church/.gitkeep',
        'public/uploads/church/.htaccess',
    ];

    return !in_array($path, $allowed, true);
}

function deploy_is_deployable_path(string $path): bool
{
    $path = deploy_normalize_path($path);
    if ($path === '' || str_contains($path, "\0")) {
        return false;
    }
    if (str_starts_with($path, '../') || str_contains($path, '/../')) {
        return false;
    }
    if (in_array($path, deploy_excluded_files(), true)) {
        return false;
    }
    foreach (deploy_excluded_prefixes() as $prefix) {
        if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
            return false;
        }
    }
    $basename = basename($path);
    foreach (deploy_excluded_basename_globs() as $glob) {
        if (deploy_path_matches_glob($basename, $glob)) {
            return false;
        }
    }
    if (deploy_is_user_upload_content($path)) {
        return false;
    }

    return true;
}

/**
 * Parse `git diff --name-status -z` / line-oriented name-status output.
 *
 * @return list<array{status: string, path: string, from?: string}>
 */
function deploy_parse_name_status(string $output): array
{
    $output = str_replace("\0", "\n", $output);
    $lines = preg_split("/\r\n|\n|\r/", trim($output)) ?: [];
    $changes = [];

    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        $line = trim($lines[$i]);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^([AMD])\t(.+)$/', $line, $m) === 1) {
            $changes[] = [
                'status' => $m[1],
                'path' => deploy_normalize_path($m[2]),
            ];
            continue;
        }

        if (preg_match('/^([CR])\d*\t(.+)$/', $line, $m) === 1) {
            $from = deploy_normalize_path($m[2]);
            $to = '';
            if ($i + 1 < $n) {
                $to = deploy_normalize_path(trim($lines[$i + 1]));
                $i++;
            }
            if ($to === '') {
                continue;
            }
            $changes[] = [
                'status' => $m[1],
                'path' => $to,
                'from' => $from,
            ];
            continue;
        }

        // Fallback: status SP path (no tab), common in hand-written fixtures.
        if (preg_match('/^([AMD])\s+(.+)$/', $line, $m) === 1) {
            $changes[] = [
                'status' => $m[1],
                'path' => deploy_normalize_path($m[2]),
            ];
        }
    }

    return $changes;
}

/**
 * Build upload/delete sets from name-status changes.
 *
 * @param list<array{status: string, path: string, from?: string}> $changes
 * @return array{upload: list<string>, delete: list<string>}
 */
function deploy_plan_from_changes(array $changes): array
{
    $upload = [];
    $delete = [];

    foreach ($changes as $change) {
        $status = strtoupper((string) ($change['status'] ?? ''));
        $path = deploy_normalize_path((string) ($change['path'] ?? ''));
        $from = deploy_normalize_path((string) ($change['from'] ?? ''));

        if ($status === 'A' || $status === 'M') {
            if (deploy_is_deployable_path($path)) {
                $upload[$path] = true;
            }
            continue;
        }

        if ($status === 'D') {
            if (deploy_is_deployable_path($path)) {
                $delete[$path] = true;
            }
            continue;
        }

        if ($status === 'C' || $status === 'R') {
            if ($from !== '' && deploy_is_deployable_path($from)) {
                $delete[$from] = true;
            }
            if (deploy_is_deployable_path($path)) {
                $upload[$path] = true;
            }
        }
    }

    $uploadList = array_keys($upload);
    $deleteList = array_keys($delete);
    sort($uploadList);
    sort($deleteList);

    // Prefer upload over delete when the same path appears in both (re-add after rename dance).
    $deleteList = array_values(array_filter(
        $deleteList,
        static fn (string $path): bool => !isset($upload[$path])
    ));

    return [
        'upload' => $uploadList,
        'delete' => $deleteList,
    ];
}

/**
 * @param list<string> $paths
 * @return list<string>
 */
function deploy_filter_deployable_paths(array $paths): array
{
    $out = [];
    foreach ($paths as $path) {
        $path = deploy_normalize_path((string) $path);
        if ($path !== '' && deploy_is_deployable_path($path)) {
            $out[$path] = true;
        }
    }
    $list = array_keys($out);
    sort($list);

    return $list;
}

/**
 * Parent directories that must exist before an FTP put (relative, posix).
 *
 * @param list<string> $paths
 * @return list<string>
 */
function deploy_required_directories(array $paths): array
{
    $dirs = [];
    foreach ($paths as $path) {
        $path = deploy_normalize_path($path);
        $dir = dirname($path);
        while ($dir !== '.' && $dir !== '/' && $dir !== '') {
            $dirs[$dir] = true;
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }
    $list = array_keys($dirs);
    // Create shallow parents first.
    usort($list, static function (string $a, string $b): int {
        $da = substr_count($a, '/');
        $db = substr_count($b, '/');
        return $da <=> $db ?: strcmp($a, $b);
    });

    return $list;
}

function deploy_is_valid_git_sha(string $sha): bool
{
    return (bool) preg_match('/^[0-9a-f]{7,40}$/i', trim($sha));
}

/**
 * Sanitize a deploy nonce used inside the remote staging tree.
 */
function deploy_staging_nonce(string $nonce): string
{
    $nonce = strtolower(preg_replace('/[^a-f0-9]/i', '', $nonce) ?? '');

    return $nonce !== '' ? $nonce : 'tmp';
}

/**
 * Remote staging path for an eventual final path (posix, relative to FTP root).
 */
function deploy_staging_remote_path(string $finalPath, string $nonce): string
{
    $finalPath = deploy_normalize_path($finalPath);
    if ($finalPath === '') {
        return DEPLOY_STAGING_DIR . '/' . deploy_staging_nonce($nonce);
    }

    return DEPLOY_STAGING_DIR . '/' . deploy_staging_nonce($nonce) . '/' . $finalPath;
}

/**
 * Plan atomic publishes: upload to staging, then rename onto the live path.
 *
 * @param list<string> $upload
 * @return list<array{final: string, staging: string}>
 */
function deploy_atomic_upload_plan(array $upload, string $nonce): array
{
    $plan = [];
    foreach ($upload as $path) {
        $final = deploy_normalize_path((string) $path);
        if ($final === '') {
            continue;
        }
        $plan[] = [
            'final' => $final,
            'staging' => deploy_staging_remote_path($final, $nonce),
        ];
    }

    return $plan;
}

/**
 * Directories that must exist before atomic puts (staging + final parents).
 *
 * @param list<string> $upload
 * @return list<string>
 */
function deploy_required_directories_for_atomic_upload(array $upload, string $nonce): array
{
    $paths = [];
    foreach (deploy_atomic_upload_plan($upload, $nonce) as $item) {
        $paths[] = $item['staging'];
        $paths[] = $item['final'];
    }

    return deploy_required_directories($paths);
}

/**
 * Decide whether this release is incremental and which base SHA to use.
 *
 * @return array{mode: 'full'|'incremental', from: ?string, reason: string}
 */
function deploy_resolve_mode(?string $remoteRevision, ?string $explicitFrom, bool $forceFull, string $toSha): array
{
    if ($forceFull) {
        return ['mode' => 'full', 'from' => null, 'reason' => 'forced full deploy'];
    }

    $from = null;
    $reason = '';
    if (is_string($explicitFrom) && deploy_is_valid_git_sha($explicitFrom)) {
        $from = strtolower(trim($explicitFrom));
        $reason = 'explicit DEPLOY_FROM';
    } elseif (is_string($remoteRevision) && deploy_is_valid_git_sha($remoteRevision)) {
        $from = strtolower(trim($remoteRevision));
        $reason = 'remote ' . DEPLOY_REVISION_FILE;
    }

    if ($from === null) {
        return ['mode' => 'full', 'from' => null, 'reason' => 'no previous revision'];
    }

    if (strtolower(trim($toSha)) === $from) {
        return ['mode' => 'incremental', 'from' => $from, 'reason' => 'already at target (' . $reason . ')'];
    }

    return ['mode' => 'incremental', 'from' => $from, 'reason' => $reason];
}
