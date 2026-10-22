<?php

declare(strict_types=1);

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

require_once __DIR__ . '/../../src/Knowledge.php';

/**
 * @return string absolute path to a temporary 1x1 PNG
 */
function knowledge_test_make_png(): string
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    if ($png === false) {
        throw new RuntimeException('Failed to decode test PNG');
    }
    $path = sys_get_temp_dir() . '/wolhg-knowledge-' . bin2hex(random_bytes(8)) . '.png';
    if (file_put_contents($path, $png) === false) {
        throw new RuntimeException('Failed to write test PNG');
    }

    return $path;
}

return [
    'safe knowledge image paths accept only upload filenames' => function (TestCase $test): void {
        $test->assertTrue(knowledge_is_safe_image_path('/uploads/knowledge/abcdef0123456789.png'));
        $test->assertTrue(knowledge_is_safe_image_path('/uploads/knowledge/abc.JPEG'));
        $test->assertFalse(knowledge_is_safe_image_path('/uploads/knowledge/../secret.png'));
        $test->assertFalse(knowledge_is_safe_image_path('/uploads/knowledge/sub/dir.png'));
        $test->assertFalse(knowledge_is_safe_image_path('/assets/logo.svg'));
        $test->assertFalse(knowledge_is_safe_image_path('https://evil.example/x.png'));
        $test->assertFalse(knowledge_is_safe_image_path('/uploads/knowledge/file.php'));
        $test->assertFalse(knowledge_is_safe_image_path(null));
        $test->assertFalse(knowledge_is_safe_image_path(''));
    },

    'knowledge body escapes html and only renders safe local images' => function (TestCase $test): void {
        $body = "Строка 1\n<script>alert(1)</script>\n![ok](/uploads/knowledge/abcdef0123456789.png)\n![bad](javascript:alert(1))\n![traversal](/uploads/knowledge/../x.png)";
        $html = knowledge_render_body($body);

        $test->assertStringContains('Строка 1<br />', $html);
        $test->assertStringContains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $test->assertStringNotContains('<script>', $html);
        $test->assertStringContains('class="knowledge-inline-image"', $html);
        $test->assertStringContains('src="/uploads/knowledge/abcdef0123456789.png"', $html);
        $test->assertStringContains('alt="ok"', $html);
        $test->assertStringContains('![bad](javascript:alert(1))', $html);
        $test->assertStringNotContains('src="javascript:', $html);
        $test->assertStringContains('![traversal](/uploads/knowledge/../x.png)', $html);
    },

    'knowledge card media renders only safe paths' => function (TestCase $test): void {
        $safe = knowledge_render_card_media('/uploads/knowledge/abcdef0123456789.webp', 'Урок');
        $unsafe = knowledge_render_card_media('/tmp/evil.png', 'Урок');

        $test->assertStringContains('knowledge-card__media', $safe);
        $test->assertStringContains('alt="Урок"', $safe);
        $test->assertSame('', $unsafe);
    },

    'knowledge markdown helper builds insertable markup' => function (TestCase $test): void {
        $test->assertSame(
            '![картинка](/uploads/knowledge/abcdef0123456789.jpg)',
            knowledge_markdown_for_path('/uploads/knowledge/abcdef0123456789.jpg')
        );
        $test->assertSame(
            '![схема](/uploads/knowledge/abcdef0123456789.jpg)',
            knowledge_markdown_for_path('/uploads/knowledge/abcdef0123456789.jpg', 'схема')
        );
    },

    'knowledge image field markup includes upload and optional remove control' => function (TestCase $test): void {
        $empty = knowledge_image_field_html(null);
        $withImage = knowledge_image_field_html('/uploads/knowledge/abcdef0123456789.png');

        $test->assertStringContains('type="file"', $empty);
        $test->assertStringContains('data-knowledge-image-input', $empty);
        $test->assertStringNotContains('remove_image', $empty);
        $test->assertStringContains('name="remove_image"', $withImage);
        $test->assertStringContains('checkbox-row', $withImage);
        $test->assertStringContains('/uploads/knowledge/abcdef0123456789.png', $withImage);
        $test->assertStringContains('data-knowledge-insert-image', knowledge_inline_image_controls_html());
    },

    'storing a real png creates a safe web path under uploads' => function (TestCase $test): void {
        $source = knowledge_test_make_png();
        try {
            $result = knowledge_store_image_from_path($source);
            $test->assertTrue($result['ok'], $result['error'] ?? 'store failed');
            $test->assertTrue(knowledge_is_safe_image_path($result['path']));
            $absolute = knowledge_absolute_path_from_web($result['path']);
            $test->assertTrue(is_string($absolute) && is_file($absolute));
            knowledge_delete_image($result['path']);
            $test->assertFalse(is_file((string) $absolute));
        } finally {
            @unlink($source);
        }
    },

    'non-image files are rejected by knowledge store' => function (TestCase $test): void {
        $path = sys_get_temp_dir() . '/wolhg-knowledge-' . bin2hex(random_bytes(8)) . '.png';
        file_put_contents($path, 'not-an-image');
        try {
            $result = knowledge_store_image_from_path($path);
            $test->assertFalse($result['ok']);
            $test->assertStringContains('изображением', $result['error']);
        } finally {
            @unlink($path);
        }
    },

    'resolve item image keeps current path when no upload' => function (TestCase $test): void {
        $current = '/uploads/knowledge/abcdef0123456789.png';
        $result = knowledge_resolve_item_image($current, null, false);
        $test->assertTrue($result['ok']);
        $test->assertSame($current, $result['path']);
        $test->assertFalse($result['replaced']);
    },

    'resolve item image clears path when remove requested' => function (TestCase $test): void {
        $source = knowledge_test_make_png();
        try {
            $stored = knowledge_store_image_from_path($source);
            $test->assertTrue($stored['ok']);
            $absolute = knowledge_absolute_path_from_web($stored['path']);
            $test->assertTrue(is_file((string) $absolute));

            $result = knowledge_resolve_item_image($stored['path'], null, true);
            $test->assertTrue($result['ok']);
            $test->assertNull($result['path']);
            $test->assertTrue($result['replaced']);
            $test->assertFalse(is_file((string) $absolute));
        } finally {
            @unlink($source);
        }
    },

    'allowed mime map and size limit are conservative' => function (TestCase $test): void {
        $mimes = knowledge_allowed_image_mimes();
        $test->assertTrue(isset($mimes['image/jpeg']));
        $test->assertTrue(isset($mimes['image/png']));
        $test->assertTrue(isset($mimes['image/webp']));
        $test->assertFalse(isset($mimes['image/svg+xml']));
        $test->assertSame(2 * 1024 * 1024, knowledge_max_image_bytes());
    },

    'knowledge renders vkvideo.ru and rutube videos' => function (TestCase $test): void {
        $body = "https://vkvideo.ru/video-112148879_456239069\nhttps://rutube.ru/video/3e60223df1f04ef396781b4dc805c6d3/";
        $html = knowledge_render_body($body);

        $test->assertStringContains('<iframe src="https://vk.com/video_ext.php?oid=-112148879&amp;id=456239069&amp;hd=2&amp;autoplay=0&amp;rel=0"', $html);
        $test->assertStringContains('<iframe src="https://rutube.ru/play/embed/3e60223df1f04ef396781b4dc805c6d3/?rel=0"', $html);
    },
];
