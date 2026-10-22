<?php

declare(strict_types=1);

/**
 * Unit tests for church landing helpers (no DB).
 */

if (!function_exists('app_config')) {
    function app_config(?string $key = null, mixed $default = null): mixed
    {
        $config = [
            'name' => 'CRM Домашних групп',
            'legacy_default_slug' => 'pushkino',
            'default_church_id' => 1,
        ];
        if ($key === null) {
            return $config;
        }

        return $config[$key] ?? $default;
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

require_once __DIR__ . '/../../src/ChurchTenant.php';
require_once __DIR__ . '/../../src/ChurchLanding.php';

return [
    'about and l are reserved church slugs' => function (TestCase $test): void {
        $test->assertFalse(church_slug_is_valid('about'));
        $test->assertFalse(church_slug_is_valid('l'));
        $test->assertFalse(church_slug_is_valid('church-page'));
        $test->assertTrue(church_slug_is_valid('pushkino'));
        $test->assertTrue(in_array('l', church_slug_reserved_segments(), true));
        $test->assertTrue(is_platform_root_path('/l/korolev'));
    },

    'public_church_about_path builds /l/{slug} URL' => function (TestCase $test): void {
        $test->assertSame(
            '/l/pushkino',
            public_church_about_path(['slug' => 'pushkino', 'name' => 'СЖ Пушкино'])
        );
        $test->assertSame('/', public_church_about_path(['slug' => '', 'name' => 'X']));
        $test->assertSame('/', public_church_about_path(['slug' => 'about']));
        $test->assertSame('/', public_church_about_path(['slug' => 'l']));
    },

    'network href accepts full urls and usernames' => function (TestCase $test): void {
        $test->assertSame(
            'https://t.me/church',
            church_landing_normalize_network_href('telegram', '@church')
        );
        $test->assertSame(
            'https://t.me/church',
            church_landing_normalize_network_href('telegram', 'https://t.me/church')
        );
        $test->assertSame(
            'https://vk.com/wordoflife',
            church_landing_normalize_network_href('vk', 'wordoflife')
        );
        $test->assertSame(
            'https://wa.me/79001234567',
            church_landing_normalize_network_href('whatsapp', '+7 (900) 123-45-67')
        );
        $test->assertSame(
            'https://wa.me/79001234567',
            church_landing_normalize_network_href('whatsapp', '89001234567')
        );
        $test->assertNull(church_landing_normalize_network_href('whatsapp', '@channel'));
        $test->assertNull(church_landing_normalize_network_href('telegram', 'javascript:alert(1)'));
        $test->assertNull(church_landing_sanitize_http_url('javascript:alert(1)'));
        $test->assertNull(church_landing_sanitize_http_url('ftp://example.com'));
    },

    'default buttons include groups prayer and miracle' => function (TestCase $test): void {
        $buttons = church_landing_default_buttons();
        $test->assertCount(3, $buttons);
        $networks = array_column($buttons, 'network');
        $test->assertSame(['homegroups', 'prayer', 'miracle'], $networks);
    },

    'buttons and sections normalize safely' => function (TestCase $test): void {
        $buttons = church_landing_normalize_buttons([
            ['kind' => 'social', 'network' => 'telegram', 'label' => '', 'url' => '@church'],
            ['kind' => 'custom', 'network' => '', 'label' => 'Сайт', 'url' => 'https://example.com'],
            ['kind' => 'custom', 'network' => '', 'label' => 'Bad', 'url' => 'javascript:alert(1)'],
            ['kind' => 'builtin', 'network' => 'homegroups', 'label' => ''],
        ]);
        $test->assertCount(3, $buttons);
        $test->assertSame('Telegram', $buttons[0]['label']);
        $test->assertSame('Сайт', $buttons[1]['label']);
        $test->assertSame('Домашние группы', $buttons[2]['label']);

        $sections = church_landing_normalize_sections([
            ['id' => 'buttons', 'visible' => false],
            ['id' => 'hero', 'visible' => '1'],
            ['id' => 'unknown', 'visible' => true],
            ['id' => 'howto', 'visible' => true],
            ['id' => 'address', 'visible' => false],
        ]);
        $ids = array_column($sections, 'id');
        $test->assertTrue(in_array('hero', $ids, true));
        $test->assertTrue(in_array('faq', $ids, true));
        $test->assertTrue(in_array('address', $ids, true));
        $test->assertFalse(in_array('unknown', $ids, true));
        $test->assertFalse(in_array('howto', $ids, true));
        $map = [];
        foreach ($sections as $section) {
            $map[$section['id']] = $section['visible'];
        }
        $test->assertFalse($map['buttons']);
        $test->assertTrue($map['hero']);
        // Legacy howto visibility merges into address.
        $test->assertTrue($map['address']);
    },

    'gallery caps at six and rejects unsafe media' => function (TestCase $test): void {
        $items = [];
        for ($i = 0; $i < 8; $i++) {
            $items[] = ['src' => 'https://cdn.example/photo' . $i . '.jpg', 'alt' => 'a', 'sort' => $i];
        }
        $items[] = ['src' => 'javascript:alert(1)', 'alt' => 'x'];
        $gallery = church_landing_normalize_gallery($items);
        $test->assertCount(6, $gallery);
        $test->assertTrue(church_landing_is_safe_image_path('/uploads/church/abcdef0123456789.png'));
        $test->assertFalse(church_landing_is_safe_image_path('/uploads/church/../secret.png'));
        $test->assertFalse(church_landing_is_safe_image_path('/uploads/knowledge/abcdef0123456789.png'));
    },

    'schedule requires weekday and title' => function (TestCase $test): void {
        $schedule = church_landing_normalize_schedule([
            ['weekday' => 7, 'time_from' => '11:00', 'time_to' => '13:00', 'title' => 'Служение', 'note' => 'зал A'],
            ['weekday' => 9, 'time_from' => '11:00', 'title' => 'Bad'],
            ['weekday' => 1, 'title' => ''],
        ]);
        $test->assertCount(1, $schedule);
        $test->assertSame(7, $schedule[0]['weekday']);
        $test->assertSame('11:00', $schedule[0]['time_from']);
    },

    'upcoming events hide past dates' => function (TestCase $test): void {
        $events = church_landing_normalize_events([
            ['date' => '2000-01-01', 'title' => 'Old'],
            ['date' => '2099-12-31', 'title' => 'Future', 'time_from' => '18:00'],
        ]);
        $upcoming = church_landing_upcoming_events($events, 'Europe/Moscow');
        $test->assertCount(1, $upcoming);
        $test->assertSame('2099-12-31', $upcoming[0]['date']);
    },

    'resolve builtin buttons for church' => function (TestCase $test): void {
        $church = ['id' => 1, 'slug' => 'pushkino', 'name' => 'СЖ'];
        $resolved = church_landing_resolve_button([
            'kind' => 'builtin',
            'network' => 'homegroups',
            'label' => 'Домашние группы',
        ], $church);
        $test->assertNotNull($resolved);
        $test->assertSame('/pushkino/homegroup', $resolved['href']);

        $prayer = church_landing_resolve_button([
            'kind' => 'builtin',
            'network' => 'prayer',
            'label' => 'Молитва',
        ], $church);
        $test->assertSame('/pushkino/prayer-request', $prayer['href'] ?? null);

        $miracle = church_landing_resolve_button([
            'kind' => 'builtin',
            'network' => 'miracle',
            'label' => 'Свидетельство',
        ], $church);
        $test->assertSame('/pushkino/miracle-share', $miracle['href'] ?? null);
    },

    'empty landing still has default CTAs and sections' => function (TestCase $test): void {
        $landing = church_landing_empty(3);
        $test->assertSame(3, $landing['church_id']);
        $test->assertCount(3, $landing['buttons']);
        $test->assertSame(count(church_landing_section_ids()), count($landing['sections']));
        $test->assertTrue(church_landing_section_has_content('buttons', $landing, ['name' => 'СЖ']));
        $test->assertTrue(church_landing_section_has_content('hero', $landing, ['name' => 'СЖ']));
        $test->assertFalse(church_landing_section_has_content('gallery', $landing, ['name' => 'СЖ']));
    },

    'landing hydrate keeps logo media path' => function (TestCase $test): void {
        $landing = church_landing_hydrate_row([
            'church_id' => 1,
            'logo' => '/uploads/church/abcdef0123456789.webp',
            'buttons_json' => null,
            'sections_json' => null,
        ]);
        $test->assertSame('/uploads/church/abcdef0123456789.webp', $landing['logo']);
        $test->assertNull(church_landing_normalize_media_src('javascript:alert(1)'));
    },

    'public landing hero does not show CRM product wordmark' => function (TestCase $test): void {
        $module = file_get_contents(__DIR__ . '/../../src/ChurchLanding.php');
        $heroPos = strpos($module, "if (\$id === 'hero')");
        $test->assertTrue($heroPos !== false);
        $nextSection = strpos($module, "if (\$id !== 'buttons'", $heroPos);
        $test->assertTrue($nextSection !== false);
        $heroBlock = substr($module, $heroPos, $nextSection - $heroPos);
        $test->assertStringNotContains('render_brand_markup', $heroBlock);
        $test->assertStringNotContains('brand_crm_label', $heroBlock);
        $test->assertStringContains('church-landing__logo', $heroBlock);
        $test->assertStringContains('church-landing__title', $heroBlock);
        $test->assertStringContains('width="200"', $heroBlock);
    },

    'public landing stylesheet is responsive across viewports' => function (TestCase $test): void {
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');
        $blockStart = strpos($css, '/* Church public landing');
        $test->assertTrue($blockStart !== false);
        $editorStart = strpos($css, '/* Church landing constructor */', $blockStart);
        $test->assertTrue($editorStart !== false);
        $block = substr($css, $blockStart, $editorStart - $blockStart);

        $test->assertStringContains('clamp(9rem, 42vw, 12.5rem)', $block);
        $test->assertStringContains('safe-area-inset', $block);
        $test->assertStringContains('@media (min-width: 480px)', $block);
        $test->assertStringContains('@media (min-width: 640px)', $block);
        $test->assertStringContains('@media (min-width: 768px)', $block);
        $test->assertStringContains('@media (min-width: 960px)', $block);
        $test->assertStringContains('grid-template-columns: repeat(2, minmax(0, 1fr))', $block);
        $test->assertStringContains('overflow-wrap: anywhere', $block);
        $test->assertStringContains('.church-landing__section--hero', $block);
    },

    'church landing allows svg logos with safe content checks' => function (TestCase $test): void {
        $mimes = church_landing_allowed_image_mimes();
        $test->assertSame('svg', $mimes['image/svg+xml'] ?? null);
        $test->assertTrue(in_array('svg', church_landing_allowed_image_extensions(), true));
        $test->assertTrue(church_landing_is_safe_image_path('/uploads/church/abcdef0123456789.svg'));

        $clean = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';
        $test->assertTrue(church_landing_content_is_svg($clean));
        $test->assertTrue(church_landing_svg_looks_safe($clean));

        $withScript = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $test->assertTrue(church_landing_content_is_svg($withScript));
        $test->assertFalse(church_landing_svg_looks_safe($withScript));

        $withHandler = '<svg xmlns="http://www.w3.org/2000/svg" onclick="alert(1)"><rect width="1" height="1"/></svg>';
        $test->assertFalse(church_landing_svg_looks_safe($withHandler));

        $test->assertFalse(church_landing_content_is_svg('not an image'));
        $test->assertFalse(church_landing_svg_looks_safe('<html><body>no</body></html>'));
    },

    'editor media field offers URL or upload without path wording' => function (TestCase $test): void {
        require_once __DIR__ . '/../../src/ChurchLandingEditor.php';
        $html = church_landing_editor_media_field_html('logo', 'https://cdn.example/logo.png', 'logo', [
            'upload_label' => 'Загрузить',
            'hint' => 'Вставьте ссылку на картинку или загрузите файл с устройства.',
        ]);
        $test->assertStringContains('landing-media-field', $html);
        $test->assertStringContains('landing-media-field__url">URL', $html);
        $test->assertStringContains('placeholder="https://…"', $html);
        $test->assertStringContains('data-landing-media-field="logo"', $html);
        $test->assertStringContains('data-landing-upload="logo"', $html);
        $test->assertStringContains('>Загрузить</button>', $html);
        $test->assertStringContains('или загрузите файл', $html);
        $test->assertStringNotContains('путь', mb_strtolower($html));
        $test->assertStringNotContains('/uploads/church/…', $html);
    },

    'section hints cover every public landing section' => function (TestCase $test): void {
        $labels = church_landing_section_labels();
        $hints = church_landing_section_hints();
        foreach (church_landing_section_ids() as $id) {
            $test->assertTrue(isset($labels[$id]), $id);
            $test->assertTrue(isset($hints[$id]), $id);
            $test->assertTrue(trim($hints[$id]) !== '', $id);
        }
        $test->assertFalse(isset($labels['howto']));
        $test->assertFalse(in_array('howto', church_landing_section_ids(), true));
    },

    'address section includes how-to-get content' => function (TestCase $test): void {
        require_once __DIR__ . '/../../src/ChurchLandingEditor.php';
        $landing = church_landing_empty(1);
        $landing['how_to_get'] = 'Вход со двора';
        $test->assertTrue(church_landing_section_has_content('address', $landing, ['name' => 'СЖ']));
        $test->assertFalse(church_landing_section_has_content('howto', $landing, ['name' => 'СЖ']));

        $card = church_landing_editor_section_card_html(
            ['id' => 'address', 'visible' => true, 'sort' => 0],
            $landing,
            ['slug' => 'korolev', 'name' => 'СЖ'],
            0
        );
        $test->assertStringContains('name="how_to_get"', $card);
        $test->assertStringContains('Как добраться', $card);
        $test->assertStringContains('Вход со двора', $card);

        ob_start();
        church_landing_render_section('address', $landing, ['name' => 'СЖ'], []);
        $html = (string) ob_get_clean();
        $test->assertStringContains('Как добраться', $html);
        $test->assertStringContains('Вход со двора', $html);
    },

    'social icons cover every catalog network with brand marks' => function (TestCase $test): void {
        foreach (array_keys(church_landing_social_networks()) as $network) {
            $svg = church_landing_social_icon_svg($network);
            $test->assertStringContains('<svg', $svg, $network);
            $test->assertStringContains('<path', $svg, $network);
            $test->assertFalse(str_contains($svg, '<circle cx="12" cy="12" r="8"/>'), $network);
        }
        $test->assertStringContains('fill-rule="evenodd"', church_landing_social_icon_svg('max'));
        $test->assertSame('#000000', church_landing_social_networks()['max']['bg']);
    },

    'ministries render as a bullet list with optional link' => function (TestCase $test): void {
        $landing = church_landing_empty(1);
        $landing['ministries'] = church_landing_normalize_ministries([
            ['title' => 'Воскресная школа', 'description' => '', 'url' => ''],
            ['title' => 'Молодёжка', 'description' => 'для подростков', 'url' => '@vokrugiisusa'],
        ]);
        $test->assertSame('https://t.me/vokrugiisusa', $landing['ministries'][1]['url']);
        $test->assertSame(
            'https://www.sberbank.com/sms/pbpn?requisiteNumber=79152545576',
            church_landing_sanitize_http_url('www.sberbank.com/sms/pbpn?requisiteNumber=79152545576')
        );

        ob_start();
        church_landing_render_section('ministries', $landing, ['name' => 'СЖ'], []);
        $html = (string) ob_get_clean();
        $test->assertStringContains('<ul class="church-landing__ministries">', $html);
        $test->assertStringContains('<li>', $html);
        $test->assertStringContains('Воскресная школа', $html);
        $test->assertStringContains('href="https://t.me/vokrugiisusa"', $html);
        $test->assertStringNotContains('<article>', $html);
    },

    'landing editor bootstraps payload outside fragile HTML attributes' => function (TestCase $test): void {
        $editor = file_get_contents(__DIR__ . '/../../src/ChurchLandingEditor.php');
        $js = file_get_contents(__DIR__ . '/../../public/assets/app.js');
        $test->assertStringContains('data-landing-payload', $editor);
        $test->assertStringContains('JSON_HEX_TAG', $editor);
        $test->assertStringContains('data-section-hints', $editor);
        $test->assertStringContains('initChurchLandingEditors()', $js);
        $test->assertStringContains('swapDocument', $js);
        $swapPos = strpos($js, 'function swapDocument');
        $swapChunk = substr($js, $swapPos, 2500);
        $test->assertStringContains('initChurchLandingEditors()', $swapChunk);
        $test->assertStringContains('dataset.landingEditorBound', $js);
        $test->assertStringContains('data-landing-drag-handle', $js);
    },

    'landing editor combines section order with block settings' => function (TestCase $test): void {
        require_once __DIR__ . '/../../src/ChurchLandingEditor.php';
        $editor = file_get_contents(__DIR__ . '/../../src/ChurchLandingEditor.php');
        $js = file_get_contents(__DIR__ . '/../../public/assets/app.js');
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');

        $test->assertStringContains('function church_landing_editor_section_card_html', $editor);
        $test->assertStringContains('function church_landing_editor_panel_body_html', $editor);
        $test->assertStringContains('data-landing-section-workspace', $editor);
        $test->assertStringContains('data-landing-section-visible', $editor);
        $test->assertStringContains('data-landing-section-up', $editor);
        $test->assertStringNotContains('data-landing-panel-pool', $editor);
        $test->assertStringNotContains('Порядок и видимость на лендинге', $editor);
        $test->assertTrue(preg_match('/data-landing-sections(?!-json)/', $editor) !== 1);
        $test->assertStringContains('moveSection', $js);
        $test->assertStringContains('data-landing-section-visible', $js);
        $test->assertStringNotContains('stashContentPanels', $js);
        $test->assertStringContains('.landing-editor-workspace', $css);
        $test->assertStringContains('.landing-editor-block', $css);

        $card = church_landing_editor_section_card_html(
            ['id' => 'description', 'visible' => true, 'sort' => 0],
            ['description' => 'Текст'],
            ['slug' => 'korolev', 'name' => 'СЖ'],
            0
        );
        $test->assertStringContains('data-landing-section-id="description"', $card);
        $test->assertStringContains('data-landing-section-visible', $card);
        $test->assertStringContains('data-landing-drag-handle', $card);
        $test->assertStringContains('name="description"', $card);
        $test->assertStringContains('На лендинге', $card);
    },

    'pastor role default includes manage_church_page' => function (TestCase $test): void {
        require_once __DIR__ . '/../../src/Features.php';
        $test->assertTrue(default_permission('pastor', 'manage_church_page'));
        $test->assertTrue(default_permission('admin', 'manage_church_page'));
        $test->assertFalse(default_permission('leader', 'manage_church_page'));
        $test->assertTrue(isset(permission_actions()['manage_church_page']));
    },

    'faq section is labeled вопрос — ответ' => function (TestCase $test): void {
        $test->assertSame('Вопрос — ответ', church_landing_section_labels()['faq']);
    },

    'og image prefers explicit og then raster logo not svg' => function (TestCase $test): void {
        $landing = church_landing_empty(1);
        $landing['logo'] = '/uploads/church/logo.svg';
        $landing['gallery'] = [['src' => '/uploads/church/gallery.jpg', 'alt' => '', 'sort' => 0]];
        $test->assertStringContains('/uploads/church/gallery.jpg', church_landing_og_image($landing));

        $landing['logo'] = '/uploads/church/logo.png';
        $test->assertStringContains('/uploads/church/logo.png', church_landing_og_image($landing));

        $landing['og_image'] = '/uploads/church/share.png';
        $test->assertStringContains('/uploads/church/share.png', church_landing_og_image($landing));
    },

    'og head includes description twitter and image tags' => function (TestCase $test): void {
        $landing = church_landing_empty(1);
        $landing['seo_title'] = 'СЖ Пушкино';
        $landing['seo_description'] = 'Церковь в Пушкино. Богослужение в 11:00.';
        $landing['og_image'] = '/uploads/church/og-share.png';
        if (!function_exists('request_is_https')) {
            function request_is_https(): bool
            {
                return true;
            }
        }
        $html = church_landing_og_head_html($landing, ['name' => 'СЖ Пушкино'], 'https://wolhg.ru/l/pushkino');
        $test->assertStringContains('og:description', $html);
        $test->assertStringContains('Церковь в Пушкино. Богослужение в 11:00.', $html);
        $test->assertStringContains('twitter:description', $html);
        $test->assertStringContains('og:image', $html);
        $test->assertStringContains('twitter:image', $html);
        $test->assertStringContains('og-share.png', $html);
    },
];
