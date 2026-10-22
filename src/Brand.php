<?php

declare(strict_types=1);

function brand_builtin_mark_asset(): string
{
    return '/assets/logo.svg';
}

/**
 * Platform-wide default church logo from app_settings (https URL or uploaded file).
 */
function brand_platform_default_mark_asset(): ?string
{
    if (!function_exists('app_setting')) {
        return null;
    }
    $raw = trim((string) app_setting('default_church_logo', ''));
    if ($raw === '') {
        return null;
    }
    if (function_exists('church_landing_normalize_media_src')) {
        return church_landing_normalize_media_src($raw);
    }
    if (preg_match('#^https?://#i', $raw) === 1) {
        return $raw;
    }
    if (str_starts_with($raw, '/uploads/church/')) {
        return $raw;
    }

    return null;
}

/**
 * Per-church logo from church_landing, if any.
 *
 * @param array<string, mixed>|null $church
 */
function brand_church_mark_asset(?array $church): ?string
{
    if (!$church || !function_exists('church_landing_logo_for_church')) {
        return null;
    }

    return church_landing_logo_for_church($church);
}

/**
 * Resolved mark path: explicit → church → platform default → built-in SVG.
 *
 * @param array<string, mixed>|null $church
 */
function brand_mark_asset(?array $church = null): string
{
    if ($church === null && function_exists('current_church')) {
        $church = current_church();
    }
    $churchLogo = brand_church_mark_asset($church);
    if ($churchLogo !== null && $churchLogo !== '') {
        return $churchLogo;
    }
    $platform = brand_platform_default_mark_asset();
    if ($platform !== null && $platform !== '') {
        return $platform;
    }

    return brand_builtin_mark_asset();
}

/**
 * <img src> value with cache-bust for local assets.
 *
 * @param array<string, mixed>|null $church
 */
function brand_mark_img_src(?array $church = null, ?string $explicit = null): string
{
    $path = $explicit !== null && trim($explicit) !== '' ? trim($explicit) : brand_mark_asset($church);
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }
    if (function_exists('asset_url')) {
        return asset_url($path);
    }

    return $path;
}

function brand_crm_label(): string
{
    return 'CRM';
}

/**
 * @return list<string>
 */
function brand_title_lines(): array
{
    return ['Домашние', 'группы'];
}

function brand_accessible_label(?string $subtitle = null): string
{
    $label = app_name();
    $subtitle = trim((string) $subtitle);
    if ($subtitle !== '') {
        $label .= ', ' . $subtitle;
    }

    return $label;
}

function render_brand_markup(array $options = []): string
{
    $variant = (string) ($options['variant'] ?? 'default');
    $subtitle = array_key_exists('subtitle', $options) ? $options['subtitle'] : null;
    $href = $options['href'] ?? null;
    $church = is_array($options['church'] ?? null) ? $options['church'] : null;
    $explicitMark = isset($options['mark']) ? trim((string) $options['mark']) : null;

    $classes = ['brand'];
    if ($variant === 'hero') {
        $classes[] = 'brand--hero';
    }

    $tag = is_string($href) && $href !== '' ? 'a' : 'div';
    $accessibleLabel = brand_accessible_label(is_string($subtitle) ? $subtitle : null);

    $html = '<' . $tag;
    if ($tag === 'a') {
        $html .= ' href="' . e($href) . '"';
    }
    $html .= ' class="' . e(implode(' ', $classes)) . '" aria-label="' . e($accessibleLabel) . '">';
    $html .= '<span class="brand-lockup">';
    $html .= '<img class="brand-mark" src="' . e(brand_mark_img_src($church, $explicitMark !== '' ? $explicitMark : null)) . '" alt="" decoding="async" aria-hidden="true">';
    $html .= '<span class="brand-wordmark__crm" aria-hidden="true">' . e(brand_crm_label()) . '</span>';
    $html .= '<span class="brand-wordmark__title" aria-hidden="true">';
    foreach (brand_title_lines() as $line) {
        $html .= '<span class="brand-wordmark__title-line">' . e($line) . '</span>';
    }
    $html .= '</span>';
    $html .= '</span>';

    if (is_string($subtitle) && $subtitle !== '') {
        $html .= '<span class="brand-subtitle"><small>' . e($subtitle) . '</small></span>';
    }

    $html .= '</' . $tag . '>';

    return $html;
}
