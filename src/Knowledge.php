<?php

declare(strict_types=1);

/**
 * Knowledge base / training materials: image upload and safe body rendering.
 */

function knowledge_upload_web_prefix(): string
{
    return '/uploads/knowledge';
}

function knowledge_upload_absolute_dir(): string
{
    return dirname(__DIR__) . '/public/uploads/knowledge';
}

/**
 * @return list<string>
 */
function knowledge_allowed_image_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'];
}

/**
 * @return array<string, string> mime => extension
 */
function knowledge_allowed_image_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
}

function knowledge_max_image_bytes(): int
{
    return 2 * 1024 * 1024;
}

function knowledge_ensure_upload_dir(): string
{
    $dir = knowledge_upload_absolute_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог для загрузок знаний.');
    }

    return $dir;
}

function knowledge_normalize_image_path(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }

    $path = str_replace('\\', '/', $path);
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    return $path;
}

function knowledge_is_safe_image_path(?string $path): bool
{
    $path = knowledge_normalize_image_path($path);
    if ($path === null) {
        return false;
    }

    $prefix = knowledge_upload_web_prefix() . '/';
    if (!str_starts_with($path, $prefix)) {
        return false;
    }

    $relative = substr($path, strlen($prefix));
    if ($relative === '' || str_contains($relative, '/') || str_contains($relative, '..')) {
        return false;
    }

    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,180}\.([a-zA-Z0-9]{2,8})$/', $relative, $matches)) {
        return false;
    }

    $ext = strtolower($matches[1]);
    return in_array($ext, knowledge_allowed_image_extensions(), true);
}

function knowledge_absolute_path_from_web(?string $webPath): ?string
{
    if (!knowledge_is_safe_image_path($webPath)) {
        return null;
    }

    $webPath = knowledge_normalize_image_path($webPath);
    $relative = substr((string) $webPath, strlen(knowledge_upload_web_prefix() . '/'));

    return knowledge_upload_absolute_dir() . '/' . $relative;
}

/**
 * Detect MIME of an uploaded image file. Returns null if unknown.
 */
function knowledge_detect_image_mime(string $absolutePath): ?string
{
    if (!is_readable($absolutePath)) {
        return null;
    }

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($absolutePath);
        if (is_string($mime) && $mime !== '') {
            return strtolower($mime);
        }
    }

    $info = @getimagesize($absolutePath);
    if (is_array($info) && isset($info['mime']) && is_string($info['mime']) && $info['mime'] !== '') {
        return strtolower($info['mime']);
    }

    return null;
}

/**
 * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
 * @return array{ok:true,path:string}|array{ok:false,error:string}
 */
function knowledge_store_uploaded_image(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Файл не выбран'];
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'Файл слишком большой'];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Не удалось загрузить файл'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Некорректный файл загрузки'];
    }
    if ($size <= 0 || $size > knowledge_max_image_bytes()) {
        return ['ok' => false, 'error' => 'Размер картинки не должен превышать 2 МБ'];
    }

    $imageInfo = @getimagesize($tmp);
    if (!is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1])) {
        return ['ok' => false, 'error' => 'Файл не является изображением'];
    }

    $mime = knowledge_detect_image_mime($tmp);
    $allowed = knowledge_allowed_image_mimes();
    if ($mime === null || !isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Допустимы только JPG, PNG, GIF и WebP'];
    }

    $ext = $allowed[$mime];
    $originalName = (string) ($file['name'] ?? '');
    $originalExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($originalExt === 'jpeg') {
        $originalExt = 'jpg';
    }
    if ($originalExt !== '' && !in_array($originalExt, knowledge_allowed_image_extensions(), true)) {
        return ['ok' => false, 'error' => 'Недопустимое расширение файла'];
    }

    try {
        $dir = knowledge_ensure_upload_dir();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $destination)) {
        return ['ok' => false, 'error' => 'Не удалось сохранить файл на сервере'];
    }
    @chmod($destination, 0644);

    $webPath = knowledge_upload_web_prefix() . '/' . $filename;
    if (!knowledge_is_safe_image_path($webPath)) {
        @unlink($destination);
        return ['ok' => false, 'error' => 'Внутренняя ошибка пути изображения'];
    }

    return ['ok' => true, 'path' => $webPath];
}

/**
 * Store an image from a local filesystem path (for tests). Still validates like an upload.
 *
 * @return array{ok:true,path:string}|array{ok:false,error:string}
 */
function knowledge_store_image_from_path(string $sourcePath): array
{
    if (!is_readable($sourcePath)) {
        return ['ok' => false, 'error' => 'Файл недоступен'];
    }

    $size = (int) filesize($sourcePath);
    if ($size <= 0 || $size > knowledge_max_image_bytes()) {
        return ['ok' => false, 'error' => 'Размер картинки не должен превышать 2 МБ'];
    }

    $imageInfo = @getimagesize($sourcePath);
    if (!is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1])) {
        return ['ok' => false, 'error' => 'Файл не является изображением'];
    }

    $mime = knowledge_detect_image_mime($sourcePath);
    $allowed = knowledge_allowed_image_mimes();
    if ($mime === null || !isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Допустимы только JPG, PNG, GIF и WebP'];
    }

    $ext = $allowed[$mime];
    try {
        $dir = knowledge_ensure_upload_dir();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $dir . '/' . $filename;
    if (!copy($sourcePath, $destination)) {
        return ['ok' => false, 'error' => 'Не удалось сохранить файл на сервере'];
    }
    @chmod($destination, 0644);

    return ['ok' => true, 'path' => knowledge_upload_web_prefix() . '/' . $filename];
}

function knowledge_delete_image(?string $webPath): void
{
    $absolute = knowledge_absolute_path_from_web($webPath);
    if ($absolute === null || !is_file($absolute)) {
        return;
    }
    @unlink($absolute);
}

/**
 * Resolve image for knowledge-save: keep existing, replace, or remove.
 *
 * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int}|null $uploaded
 * @return array{ok:true,path:?string,replaced:bool}|array{ok:false,error:string}
 */
function knowledge_resolve_item_image(?string $currentPath, ?array $uploaded, bool $removeRequested): array
{
    $current = knowledge_is_safe_image_path($currentPath) ? knowledge_normalize_image_path($currentPath) : null;

    $error = (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE);
    $hasUpload = $uploaded !== null && $error !== UPLOAD_ERR_NO_FILE;

    if ($hasUpload) {
        $stored = knowledge_store_uploaded_image($uploaded);
        if (!$stored['ok']) {
            return ['ok' => false, 'error' => $stored['error']];
        }

        if ($current !== null && $current !== $stored['path']) {
            knowledge_delete_image($current);
        }

        return ['ok' => true, 'path' => $stored['path'], 'replaced' => true];
    }

    if ($removeRequested) {
        if ($current !== null) {
            knowledge_delete_image($current);
        }
        return ['ok' => true, 'path' => null, 'replaced' => $current !== null];
    }

    return ['ok' => true, 'path' => $current, 'replaced' => false];
}

function knowledge_render_video_iframes(string $text): ?string
{
    if (preg_match('/^\s*https:\/\/vkvideo\.ru\/video(-?\d+)_(\d+)\/?\s*$/', $text, $matches)) {
        $oid = urlencode($matches[1]);
        $id = urlencode($matches[2]);
        return '<div class="knowledge-video"><iframe src="https://vk.com/video_ext.php?oid=' . $oid . '&amp;id=' . $id . '&amp;hd=2&amp;autoplay=0&amp;rel=0" allow="autoplay; encrypted-media; fullscreen; picture-in-picture;" frameborder="0" allowfullscreen></iframe></div>';
    }
    if (preg_match('/^\s*https:\/\/rutube\.ru\/video\/([a-zA-Z0-9_-]+)\/?\s*$/', $text, $matches)) {
        $id = urlencode($matches[1]);
        return '<div class="knowledge-video"><iframe src="https://rutube.ru/play/embed/' . $id . '/?rel=0" frameBorder="0" allow="clipboard-write; autoplay" webkitAllowFullScreen mozallowfullscreen allowFullScreen></iframe></div>';
    }
    return null;
}

function knowledge_embed_videos_in_text(string $text): string
{
    $lines = explode("\n", $text);
    foreach ($lines as &$line) {
        $videoHtml = knowledge_render_video_iframes($line);
        if ($videoHtml !== null) {
            $line = $videoHtml;
        } else {
            $line = e($line);
        }
    }
    unset($line);
    return implode("<br />\n", $lines);
}

/**
 * Render knowledge body: HTML-escape text, preserve newlines, allow safe local images.
 *
 * Inline syntax: ![описание](/uploads/knowledge/filename.ext)
 */
function knowledge_render_body(string $body): string
{
    $pattern = '/!\[([^\]]*)\]\(([^)]+)\)/';
    $parts = preg_split($pattern, $body, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return knowledge_embed_videos_in_text($body);
    }

    $html = '';
    $count = count($parts);
    for ($i = 0; $i < $count; $i++) {
        if ($i % 3 === 0) {
            $html .= knowledge_embed_videos_in_text($parts[$i]);
            continue;
        }

        $alt = $parts[$i];
        $url = $parts[$i + 1] ?? '';
        $i++;

        $normalized = knowledge_normalize_image_path($url);
        if (knowledge_is_safe_image_path($normalized)) {
            $html .= '<img class="knowledge-inline-image" src="' . e($normalized) . '" alt="' . e($alt) . '" loading="lazy">';
        } else {
            $html .= nl2br(e('![' . $alt . '](' . $url . ')'));
        }
    }

    return $html;
}

function knowledge_render_card_media(?string $imagePath, string $title = ''): string
{
    if (!knowledge_is_safe_image_path($imagePath)) {
        return '';
    }

    $path = knowledge_normalize_image_path($imagePath);
    $alt = $title !== '' ? $title : '';

    return '<figure class="knowledge-card__media"><img src="' . e($path) . '" alt="' . e($alt) . '" loading="lazy"></figure>';
}

function knowledge_markdown_for_path(string $webPath, string $alt = 'картинка'): string
{
    $path = knowledge_normalize_image_path($webPath) ?? '';
    $alt = trim($alt) !== '' ? trim($alt) : 'картинка';

    return '![' . $alt . '](' . $path . ')';
}

/**
 * Markup for cover image field in knowledge edit forms.
 */
function knowledge_image_field_html(?string $currentPath = null, string $inputName = 'image'): string
{
    $html = '<div class="knowledge-image-field">';
    $html .= '<label class="knowledge-image-field__file">Картинка (JPG, PNG, GIF, WebP до 2 МБ)';
    $html .= '<input type="file" name="' . e($inputName) . '" accept="image/jpeg,image/png,image/gif,image/webp" data-knowledge-image-input>';
    $html .= '</label>';
    $html .= '<div class="knowledge-image-field__preview" data-knowledge-image-preview hidden></div>';

    if (knowledge_is_safe_image_path($currentPath)) {
        $path = knowledge_normalize_image_path($currentPath);
        $html .= '<div class="knowledge-image-field__current">';
        $html .= '<img src="' . e($path) . '" alt="Текущая картинка" loading="lazy">';
        $html .= '<label class="checkbox-row"><input type="checkbox" name="remove_image" value="1"> Удалить картинку</label>';
        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * Controls to upload and insert an inline image into a nearby textarea.
 */
function knowledge_inline_image_controls_html(): string
{
    return '<div class="knowledge-inline-tools" data-knowledge-inline-tools>'
        . '<button type="button" class="ghost" data-knowledge-insert-image>Вставить картинку в текст</button>'
        . '<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden data-knowledge-insert-file>'
        . '<p class="muted knowledge-inline-tools__hint">Можно вставить несколько картинок в текст материала.</p>'
        . '</div>';
}
