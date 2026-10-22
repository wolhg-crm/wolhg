<?php

declare(strict_types=1);

/**
 * Church-scoped Web App Manifest for PWA installs.
 *
 * Each church gets its own start_url / shortcuts / id so an install from
 * /{slug}/… opens that church, not the platform default.
 */

/**
 * Whether a slug may appear in manifest URLs (normal churches + public /demo).
 */
function pwa_manifest_slug_is_allowed(string $slug): bool
{
    if ($slug === '' || strlen($slug) > 64) {
        return false;
    }
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        return false;
    }
    if (church_slug_is_valid($slug)) {
        return true;
    }
    // /demo is reserved for routing, but current_church_slug() exposes it for demo PWAs.
    return function_exists('demo_public_slug') && $slug === demo_public_slug();
}

/**
 * Resolve a safe church slug for the manifest (install church or platform default).
 */
function pwa_normalize_manifest_slug(?string $slug): string
{
    $candidate = normalize_church_slug((string) $slug);
    if (pwa_manifest_slug_is_allowed($candidate)) {
        return $candidate;
    }

    if (function_exists('current_church_slug')) {
        $current = current_church_slug();
        if (is_string($current) && $current !== '') {
            $current = normalize_church_slug($current);
            if (pwa_manifest_slug_is_allowed($current)) {
                return $current;
            }
        }
    }

    return legacy_default_slug();
}

/**
 * Public href for <link rel="manifest"> — always under /{slug}/.
 */
function pwa_manifest_href(?string $slug = null): string
{
    return '/' . pwa_normalize_manifest_slug($slug) . '/manifest.webmanifest';
}

/**
 * @return list<array{src: string, sizes: string, type: string, purpose: string}>
 */
function pwa_manifest_icons(): array
{
    return [
        [
            'src' => '/assets/icon.svg',
            'sizes' => 'any',
            'type' => 'image/svg+xml',
            'purpose' => 'any maskable',
        ],
        [
            'src' => '/assets/icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src' => '/assets/icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
    ];
}

/**
 * @return list<array{name: string, short_name: string, url: string, icons: list<array{src: string, sizes: string, type: string}>}>
 */
function pwa_manifest_shortcuts(string $slug): array
{
    $icon = [
        [
            'src' => '/assets/icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
        ],
    ];

    $base = '/' . $slug;

    return [
        [
            'name' => 'Домашние группы',
            'short_name' => 'Группы',
            'url' => $base . '/groups',
            'icons' => $icon,
        ],
        [
            'name' => 'Календарь',
            'short_name' => 'Календарь',
            'url' => $base . '/calendar',
            'icons' => $icon,
        ],
        [
            'name' => 'Профиль',
            'short_name' => 'Профиль',
            'url' => $base . '/profile',
            'icons' => $icon,
        ],
    ];
}

/**
 * Build the Web App Manifest payload for a church slug.
 *
 * @return array<string, mixed>
 */
function pwa_manifest_for_church(?string $slug = null): array
{
    $slug = pwa_normalize_manifest_slug($slug);
    $base = '/' . $slug;

    return [
        'name' => app_name(),
        'short_name' => app_short_name(),
        'start_url' => $base . '/?source=pwa',
        'display' => 'standalone',
        'background_color' => '#f7f4ee',
        'theme_color' => '#1d1d1b',
        'icons' => pwa_manifest_icons(),
        // Per-church id so installs from different churches stay distinct.
        'id' => $base . '/',
        // Whole origin so deep links and platform routes still work inside the PWA.
        'scope' => '/',
        'description' => app_description(),
        'display_override' => [
            'standalone',
            'minimal-ui',
        ],
        'orientation' => 'portrait-primary',
        'categories' => [
            'productivity',
            'lifestyle',
        ],
        'lang' => 'ru',
        'dir' => 'ltr',
        'shortcuts' => pwa_manifest_shortcuts($slug),
    ];
}

function pwa_manifest_json(?string $slug = null): string
{
    return json_encode(
        pwa_manifest_for_church($slug),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    );
}

/**
 * Emit the church-scoped manifest and terminate the request.
 */
function send_pwa_manifest_response(?string $slug = null): never
{
    $body = pwa_manifest_json($slug);
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string) strlen($body));
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
        echo $body;
    }
    exit;
}
