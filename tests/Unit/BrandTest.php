<?php

declare(strict_types=1);

if (!function_exists('app_name')) {
    function app_name(): string
    {
        return 'CRM Домашних групп';
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $webPath): string
    {
        return $webPath . '?v=test';
    }
}

require_once __DIR__ . '/../../src/Brand.php';

return [
    'brand wordmark keeps CRM unified and title on two lines' => function (TestCase $test): void {
        $test->assertSame('CRM', brand_crm_label());
        $test->assertSame(['Домашние', 'группы'], brand_title_lines());
    },

    'render_brand_markup lays out icon CRM and title columns' => function (TestCase $test): void {
        $markup = render_brand_markup(['href' => '/', 'subtitle' => 'Пушкино']);

        $test->assertStringContains('class="brand-lockup"', $markup);
        $test->assertStringContains('class="brand-mark"', $markup);
        $test->assertStringContains('/assets/logo.svg?v=test', $markup);
        $test->assertStringContains('alt=""', $markup);
        $test->assertStringContains('class="brand-wordmark__crm" aria-hidden="true">CRM</span>', $markup);
        $test->assertStringContains('class="brand-wordmark__title"', $markup);
        $test->assertStringContains('class="brand-wordmark__title-line">Домашние</span>', $markup);
        $test->assertStringContains('class="brand-wordmark__title-line">группы</span>', $markup);
        $test->assertStringNotContains('brand-wordmark__crm-line', $markup);
        $test->assertStringNotContains('>CR<', $markup);
        $test->assertStringNotContains('>M<', $markup);
        $test->assertSame(1, substr_count($markup, 'class="brand-mark"'));
        $test->assertSame(1, substr_count($markup, '>CRM<'));
        $test->assertStringContains('class="brand-subtitle"', $markup);
        $test->assertStringContains('>Пушкино<', $markup);
        $test->assertStringContains('aria-label="CRM Домашних групп, Пушкино"', $markup);
    },

    'render_brand_markup hero variant adds presentation class' => function (TestCase $test): void {
        $markup = render_brand_markup(['variant' => 'hero']);

        $test->assertStringContains('class="brand brand--hero"', $markup);
        $test->assertStringContains('<div class="brand brand--hero"', $markup);
        $test->assertStringNotContains('class="brand-subtitle"', $markup);
    },

    'brand mark asset points to icon-only svg' => function (TestCase $test): void {
        $test->assertSame('/assets/logo.svg', brand_builtin_mark_asset());
        $test->assertSame('/assets/logo.svg', brand_mark_asset());

        $logo = file_get_contents(__DIR__ . '/../../public/assets/logo.svg');
        $test->assertStringContains('<svg', $logo);
        $test->assertStringContains('#FFBA0D', $logo);
        $test->assertStringContains('viewBox="120 78 272 302"', $logo);
        $test->assertStringNotContains('<text', $logo);
        $test->assertStringNotContains('CRM ДГ', $logo);
        $test->assertStringNotContains('scale(0.0703125)', $logo);
    },

    'brand mark prefers explicit then falls back to builtin' => function (TestCase $test): void {
        $markup = render_brand_markup(['mark' => '/uploads/church/abcdef0123456789.png']);
        $test->assertStringContains('/uploads/church/abcdef0123456789.png?v=test', $markup);

        $http = render_brand_markup(['mark' => 'https://cdn.example/logo.png']);
        $test->assertStringContains('src="https://cdn.example/logo.png"', $http);
        $test->assertStringNotContains('https://cdn.example/logo.png?v=', $http);
    },

    'runtime templates render brand through shared helper' => function (TestCase $test): void {
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');

        foreach ([$bootstrap, $index, $catalog] as $source) {
            $test->assertStringContains('render_brand_markup', $source);
            $test->assertStringNotContains('brand-logo', $source);
        }

        $test->assertStringContains('subtitle', $bootstrap);
        $test->assertStringContains('church_city', $bootstrap);
        $test->assertStringContains('church_picker_page', $index);
        $test->assertStringContains('app_path', $bootstrap);
        $test->assertStringContains("render_brand_markup(['variant' => 'hero'", $catalog);
    },

    'brand stylesheet uses hardcoded lockup sizes' => function (TestCase $test): void {
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');

        $test->assertStringContains('.brand-lockup', $css);
        $test->assertStringNotContains('--brand-lockup-height', $css);
        $test->assertStringNotContains('--brand-title-font-size', $css);
        $test->assertStringContains('display: flex', $css);
        $test->assertStringContains('.brand-mark {', $css);
        $test->assertStringContains('height: 26px', $css);
        $test->assertStringContains('margin-right: 3px', $css);
        $test->assertStringContains('font-size: 32px', $css);
        $test->assertStringContains('letter-spacing: -0.03em', $css);
        $test->assertStringContains('.brand-wordmark__crm', $css);
        $test->assertStringContains('.brand-wordmark__title-line', $css);
        $test->assertStringContains('.brand--hero', $css);
        $test->assertStringNotContains('.brand-wordmark__crm-line', $css);
        $test->assertStringNotContains('.brand-logo', $css);
        $test->assertStringNotContains('.login-brand', $css);
    },
];
