<?php

declare(strict_types=1);

return [
    'preza presentation ships complete deck assets and sections' => function (TestCase $test): void {
        $root = __DIR__ . '/../../preza';
        $html = file_get_contents($root . '/index.html');
        $css = file_get_contents($root . '/assets/preza.css');
        $js = file_get_contents($root . '/assets/preza.mjs');

        $test->assertFileExists($root . '/index.html');
        $test->assertFileExists($root . '/assets/preza.css');
        $test->assertFileExists($root . '/assets/preza.mjs');

        $test->assertStringContains('name="viewport"', $html);
        $test->assertStringContains('<h1>CRM Домашние группы</h1>', $html);
        $test->assertStringContains('data-deck-print', $html);
        $test->assertStringContains('Печать / PDF', $html);
        $test->assertStringContains('+79778189773', $html);
        $test->assertStringContains('t.me/sadakov', $html);
        $test->assertStringNotContains('fonts.googleapis.com', $html);
        $test->assertStringNotContains('fonts.gstatic.com', $html);
        $test->assertStringContains('assets/fonts/montserrat-', $html);
        $test->assertStringContains('assets/logo.svg', $html);
        $test->assertStringContains('fonts/montserrat-', $css);
        $test->assertStringNotContains('url("/assets/fonts/', $css);

        foreach ([
            'fonts/montserrat-400.woff2',
            'fonts/montserrat-700.woff2',
            'fonts/montserrat-800.woff2',
            'logo.svg',
            'icon.svg',
        ] as $asset) {
            $test->assertFileExists($root . '/assets/' . $asset);
        }

        foreach (['title', 'why', 'pastor', 'district', 'leader', 'guest', 'landing', 'pwa', 'training', 'change', 'rollout', 'cta'] as $section) {
            $test->assertStringContains('data-section="' . $section . '"', $html);
        }

        $test->assertStringContains('Обучение внутри платформы', $html);
        $test->assertStringContains('Как пользоваться системой', $html);

        $test->assertStringContains('Своя страница церкви для гостей', $html);
        $test->assertStringContains('screen-frame--autoscroll', $html);
        $test->assertStringContains('landing-autoscroll', $css);
        $test->assertStringContains('Сайт становится приложением', $html);
        $test->assertStringContains('Добавить на экран', $html);
        $test->assertStringContains('@media print', $css);
        $test->assertStringContains('data-deck-prev', $html);
        $test->assertStringContains('export function nextIndex', $js);

        foreach ([
            'hero-homegroup.jpg',
        ] as $img) {
            $test->assertFileExists($root . '/assets/img/' . $img);
        }

        $test->assertStringNotContains('leader-care.jpg', $html);
        $test->assertStringNotContains('guest-path.jpg', $html);

        foreach ([
            'dashboard.png',
            'care.png',
            'person.png',
            'meeting.png',
            'homegroup.png',
            'regional.png',
            'analytics.png',
            'pwa.png',
            'knowledge.png',
            'landing-korolev.png',
        ] as $screen) {
            $path = $root . '/assets/screens/' . $screen;
            $test->assertFileExists($path);
            $test->assertTrue(filesize($path) > 1000, $screen . ' should be a real screenshot');
        }

        $test->assertStringContains('Радар заботы', $html);
        $test->assertStringContains('До внедрения', $html);
        $test->assertStringContains('После внедрения', $html);
        $test->assertStringContains('Как подключить церковь', $html);
    },
];
