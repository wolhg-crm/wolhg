<?php

declare(strict_types=1);

/**
 * Public church mini-landing (/l/{slug}) and CRM constructor (/church-page).
 */

function church_landing_upload_web_prefix(): string
{
    return '/uploads/church';
}

function church_landing_upload_absolute_dir(): string
{
    return dirname(__DIR__) . '/public/uploads/church';
}

/**
 * @return list<string>
 */
function church_landing_allowed_image_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
}

/**
 * @return array<string, string>
 */
function church_landing_allowed_image_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];
}

function church_landing_max_image_bytes(): int
{
    return 2 * 1024 * 1024;
}

function church_landing_gallery_max_items(): int
{
    return 6;
}

/**
 * @return list<string>
 */
function church_landing_section_ids(): array
{
    return [
        'hero',
        'gallery',
        'description',
        'buttons',
        'pastors',
        'contacts',
        'address',
        'schedule',
        'events',
        'ministries',
        'faq',
        'donation',
    ];
}

/**
 * @return array<string, string>
 */
function church_landing_section_labels(): array
{
    return [
        'hero' => 'Название',
        'gallery' => 'Галерея',
        'description' => 'Описание',
        'buttons' => 'Кнопки и ссылки',
        'pastors' => 'Пасторы',
        'contacts' => 'Контакты',
        'address' => 'Адрес и карта',
        'schedule' => 'Расписание',
        'events' => 'События',
        'ministries' => 'Служения и направления',
        'faq' => 'Вопрос — ответ',
        'donation' => 'Пожертвования',
    ];
}

/**
 * Short explanations shown in the constructor next to each public section.
 *
 * @return array<string, string>
 */
function church_landing_section_hints(): array
{
    return [
        'hero' => 'Логотип и название церкви вверху публичной страницы. Название берётся из настроек церкви.',
        'gallery' => 'Сетка фотографий. Пустая галерея на лендинге не показывается.',
        'description' => 'Текст «О церкви» под кнопками или в выбранном порядке секций.',
        'buttons' => 'Крупные кнопки: домашние группы, молитва, свидетельство, соцсети и свои ссылки.',
        'pastors' => 'Блок с фото и описанием пасторов.',
        'contacts' => 'Телефон, email, Telegram, Max, WhatsApp — только заполненные поля.',
        'address' => 'Адрес, карта, маршрут в Яндекс.Навигаторе и подсказка «как добраться».',
        'schedule' => 'Регулярное расписание встреч по дням недели.',
        'events' => 'Ближайшие разовые события (прошедшие скрываются автоматически).',
        'ministries' => 'Служения и направления: название, описание и необязательная ссылка.',
        'faq' => 'Вопрос — ответ: раскрывающиеся блоки для гостей.',
        'donation' => 'Кнопка пожертвований со ссылкой наружу.',
    ];
}

/**
 * @return list<int>
 */
function church_landing_weekdays(): array
{
    return [1, 2, 3, 4, 5, 6, 7];
}

/**
 * @return array<int, string>
 */
function church_landing_weekday_labels(): array
{
    return [
        1 => 'Понедельник',
        2 => 'Вторник',
        3 => 'Среда',
        4 => 'Четверг',
        5 => 'Пятница',
        6 => 'Суббота',
        7 => 'Воскресенье',
    ];
}

/**
 * Social + meeting networks with brand colors (readable contrast).
 *
 * @return array<string, array{label:string,bg:string,fg:string,host_hint:string}>
 */
function church_landing_social_networks(): array
{
    return [
        'vk' => ['label' => 'VK', 'bg' => '#0077ff', 'fg' => '#ffffff', 'host_hint' => 'vk.com'],
        'max' => ['label' => 'Max', 'bg' => '#000000', 'fg' => '#ffffff', 'host_hint' => 'max.ru'],
        'telegram' => ['label' => 'Telegram', 'bg' => '#26a5e4', 'fg' => '#ffffff', 'host_hint' => 't.me'],
        'yandex_music' => ['label' => 'Яндекс.Музыка', 'bg' => '#ffcc00', 'fg' => '#111111', 'host_hint' => 'music.yandex'],
        'dzen' => ['label' => 'Дзен', 'bg' => '#111111', 'fg' => '#ffffff', 'host_hint' => 'dzen.ru'],
        'youtube' => ['label' => 'YouTube', 'bg' => '#ff0000', 'fg' => '#ffffff', 'host_hint' => 'youtube.com'],
        'rutube' => ['label' => 'Rutube', 'bg' => '#1c1c1c', 'fg' => '#ffffff', 'host_hint' => 'rutube.ru'],
        'whatsapp' => ['label' => 'WhatsApp', 'bg' => '#25d366', 'fg' => '#111111', 'host_hint' => 'wa.me'],
        'tiktok' => ['label' => 'TikTok', 'bg' => '#111111', 'fg' => '#ffffff', 'host_hint' => 'tiktok.com'],
        'apple_podcasts' => ['label' => 'Apple Podcasts', 'bg' => '#9933cc', 'fg' => '#ffffff', 'host_hint' => 'podcasts.apple.com'],
        'instagram' => ['label' => 'Instagram', 'bg' => '#e4405f', 'fg' => '#ffffff', 'host_hint' => 'instagram.com'],
        'odnoklassniki' => ['label' => 'Одноклассники', 'bg' => '#ee8208', 'fg' => '#ffffff', 'host_hint' => 'ok.ru'],
        'yandex_maps' => ['label' => 'Яндекс.Карты', 'bg' => '#fc3f1d', 'fg' => '#ffffff', 'host_hint' => 'yandex.ru/maps'],
        'telemost' => ['label' => 'Телемост', 'bg' => '#111111', 'fg' => '#ffffff', 'host_hint' => 'telemost.yandex'],
        'zoom' => ['label' => 'Zoom', 'bg' => '#2d8cff', 'fg' => '#ffffff', 'host_hint' => 'zoom.us'],
    ];
}

/**
 * @return array<string, string>
 */
function church_landing_builtin_button_kinds(): array
{
    return [
        'homegroups' => 'Домашние группы',
        'prayer' => 'Молитвенная нужда',
        'miracle' => 'Свидетельство',
    ];
}

/**
 * @return list<array{id:string,visible:bool}>
 */
function church_landing_default_sections(): array
{
    $sections = [];
    foreach (church_landing_section_ids() as $id) {
        $sections[] = ['id' => $id, 'visible' => true];
    }

    return $sections;
}

/**
 * @return list<array{kind:string,network:string,label:string,url:string,icon:string,sort:int}>
 */
function church_landing_default_buttons(): array
{
    $buttons = [];
    $sort = 0;
    foreach (church_landing_builtin_button_kinds() as $network => $label) {
        $buttons[] = [
            'kind' => 'builtin',
            'network' => $network,
            'label' => $label,
            'url' => '',
            'icon' => '',
            'sort' => $sort++,
        ];
    }

    return $buttons;
}

/**
 * @return array{
 *   church_id:int,
 *   seo_title:?string,
 *   seo_description:?string,
 *   og_image:?string,
 *   logo:?string,
 *   description:?string,
 *   how_to_get:?string,
 *   address_text:?string,
 *   address_lat:?float,
 *   address_lng:?float,
 *   donation_label:?string,
 *   donation_url:?string,
 *   contacts:array{phone:string,email:string,max:string,telegram:string,whatsapp:string},
 *   gallery:list<array{src:string,alt:string,sort:int}>,
 *   pastors:list<array{name:string,photo:string,description:string,sort:int}>,
 *   schedule:list<array{weekday:int,time_from:string,time_to:string,title:string,note:string,sort:int}>,
 *   events:list<array{date:string,time_from:string,time_to:string,title:string,note:string,sort:int}>,
 *   ministries:list<array{title:string,description:string,url:string,sort:int}>,
 *   faq:list<array{question:string,answer:string,sort:int}>,
 *   buttons:list<array{kind:string,network:string,label:string,url:string,icon:string,sort:int}>,
 *   sections:list<array{id:string,visible:bool}>
 * }
 */
function church_landing_empty(int $churchId = 0): array
{
    return [
        'church_id' => $churchId,
        'seo_title' => null,
        'seo_description' => null,
        'og_image' => null,
        'logo' => null,
        'description' => null,
        'how_to_get' => null,
        'address_text' => null,
        'address_lat' => null,
        'address_lng' => null,
        'donation_label' => null,
        'donation_url' => null,
        'contacts' => [
            'phone' => '',
            'email' => '',
            'max' => '',
            'telegram' => '',
            'whatsapp' => '',
        ],
        'gallery' => [],
        'pastors' => [],
        'schedule' => [],
        'events' => [],
        'ministries' => [],
        'faq' => [],
        'buttons' => church_landing_default_buttons(),
        'sections' => church_landing_default_sections(),
    ];
}

function church_landing_decode_json(?string $json): mixed
{
    if ($json === null || trim($json) === '') {
        return null;
    }
    try {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
}

/**
 * @param mixed $value
 * @return list<mixed>
 */
function church_landing_as_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    if ($value === []) {
        return [];
    }
    $isList = array_keys($value) === range(0, count($value) - 1);

    return $isList ? array_values($value) : [];
}

function church_landing_sanitize_http_url(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (preg_match('#^www\.#i', $value) === 1) {
        $value = 'https://' . $value;
    }
    if (!preg_match('#^https?://#i', $value)) {
        return null;
    }
    if (preg_match('#^(javascript|data|vbscript):#i', $value)) {
        return null;
    }
    $parts = parse_url($value);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    return $value;
}

/**
 * Optional link for ministries/custom fields: https URL, www.…, or @telegram handle.
 */
function church_landing_normalize_optional_link(?string $raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    if (str_starts_with($raw, '@')) {
        $href = church_landing_normalize_network_href('telegram', $raw);

        return $href ?? '';
    }
    if (preg_match('#^(t\.me|telegram\.me)/#i', $raw) === 1) {
        $raw = 'https://' . $raw;
    }

    return church_landing_sanitize_http_url($raw) ?? '';
}

function church_landing_strip_at(string $value): string
{
    $value = trim($value);
    if (str_starts_with($value, '@')) {
        return trim(substr($value, 1));
    }

    return $value;
}

function church_landing_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

/**
 * Normalize social / contact target into a safe http(s) URL.
 */
function church_landing_normalize_network_href(string $network, ?string $raw): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }

    $asUrl = church_landing_sanitize_http_url($raw);
    if ($asUrl !== null) {
        return $asUrl;
    }

    $handle = church_landing_strip_at($raw);
    if ($handle === '') {
        return null;
    }
    if (preg_match('#^(javascript|data|vbscript):#i', $handle) === 1 || str_contains($handle, ':') || str_contains($handle, '//')) {
        return null;
    }

    return match ($network) {
        'vk' => 'https://vk.com/' . rawurlencode($handle),
        'max' => 'https://max.ru/' . rawurlencode($handle),
        'telegram' => 'https://t.me/' . rawurlencode($handle),
        'yandex_music' => 'https://music.yandex.ru/' . ltrim($handle, '/'),
        'dzen' => 'https://dzen.ru/' . ltrim($handle, '/'),
        'youtube' => str_starts_with($handle, 'c/') || str_starts_with($handle, '@') || str_starts_with($handle, 'channel/')
            ? 'https://www.youtube.com/' . ltrim($handle, '/')
            : 'https://www.youtube.com/@' . rawurlencode($handle),
        'rutube' => 'https://rutube.ru/' . ltrim($handle, '/'),
        'whatsapp' => (static function () use ($raw, $handle): ?string {
            $digits = church_landing_digits($raw !== '' ? $raw : $handle);
            if ($digits === '') {
                return null;
            }
            if (str_starts_with($digits, '8') && strlen($digits) === 11) {
                $digits = '7' . substr($digits, 1);
            }

            return 'https://wa.me/' . $digits;
        })(),
        'tiktok' => 'https://www.tiktok.com/@' . rawurlencode($handle),
        'apple_podcasts' => null,
        'instagram' => 'https://www.instagram.com/' . rawurlencode($handle) . '/',
        'odnoklassniki' => 'https://ok.ru/' . rawurlencode($handle),
        'yandex_maps' => null,
        'telemost' => null,
        'zoom' => null,
        default => null,
    };
}

function church_landing_normalize_media_src(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $http = church_landing_sanitize_http_url($value);
    if ($http !== null) {
        return $http;
    }

    $path = str_replace('\\', '/', $value);
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    if (church_landing_is_safe_image_path($path)) {
        return $path;
    }

    return null;
}

function church_landing_ensure_upload_dir(): string
{
    $dir = church_landing_upload_absolute_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог для загрузок страницы церкви.');
    }

    return $dir;
}

function church_landing_normalize_image_path(?string $path): ?string
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

function church_landing_is_safe_image_path(?string $path): bool
{
    $path = church_landing_normalize_image_path($path);
    if ($path === null) {
        return false;
    }

    $prefix = church_landing_upload_web_prefix() . '/';
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

    return in_array($ext, church_landing_allowed_image_extensions(), true);
}

function church_landing_detect_image_mime(string $absolutePath): ?string
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
 * True when the file body looks like an SVG document (getimagesize cannot detect SVG).
 */
function church_landing_content_is_svg(string $contents): bool
{
    if ($contents === '') {
        return false;
    }
    // Strip UTF-8 BOM and leading whitespace / XML declaration before sniffing.
    $probe = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
    $probe = ltrim($probe);
    if (str_starts_with($probe, '<?xml')) {
        $end = strpos($probe, '?>');
        if ($end !== false) {
            $probe = ltrim(substr($probe, $end + 2));
        }
    }

    return (bool) preg_match('/^<svg(\s|>)/i', $probe) || (bool) preg_match('/<svg(\s|>)/i', substr($probe, 0, 8192));
}

/**
 * Reject SVG payloads that are unsafe to store under a public upload URL.
 */
function church_landing_svg_looks_safe(string $contents): bool
{
    if ($contents === '' || !church_landing_content_is_svg($contents)) {
        return false;
    }
    if (preg_match('/<\?(?:php|=)|<%/i', $contents)) {
        return false;
    }
    if (preg_match('/<!ENTITY/i', $contents)) {
        return false;
    }
    if (preg_match('/<(?:script|foreignObject|iframe|object|embed|link|meta|base)[\s>\/]/i', $contents)) {
        return false;
    }
    if (preg_match('/\bon[a-z]+\s*=/i', $contents)) {
        return false;
    }
    if (preg_match('/(?:xlink:)?href\s*=\s*(["\']?)\s*javascript:/i', $contents)) {
        return false;
    }

    return true;
}

/**
 * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
 * @return array{ok:true,path:string}|array{ok:false,error:string}
 */
function church_landing_store_uploaded_image(array $file): array
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
    if ($size <= 0 || $size > church_landing_max_image_bytes()) {
        return ['ok' => false, 'error' => 'Размер картинки не должен превышать 2 МБ'];
    }

    $contents = @file_get_contents($tmp);
    if ($contents === false || $contents === '') {
        return ['ok' => false, 'error' => 'Не удалось прочитать файл'];
    }

    $originalName = (string) ($file['name'] ?? '');
    $originalExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($originalExt === 'jpeg') {
        $originalExt = 'jpg';
    }
    if ($originalExt !== '' && !in_array($originalExt, church_landing_allowed_image_extensions(), true)) {
        return ['ok' => false, 'error' => 'Недопустимое расширение файла'];
    }

    $isSvg = church_landing_content_is_svg($contents)
        || $originalExt === 'svg'
        || church_landing_detect_image_mime($tmp) === 'image/svg+xml';

    if ($isSvg) {
        if (!church_landing_svg_looks_safe($contents)) {
            return ['ok' => false, 'error' => 'SVG содержит недопустимые элементы'];
        }
        $ext = 'svg';
    } else {
        $imageInfo = @getimagesize($tmp);
        if (!is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1])) {
            return ['ok' => false, 'error' => 'Файл не является изображением'];
        }

        $mime = church_landing_detect_image_mime($tmp);
        $allowed = church_landing_allowed_image_mimes();
        if ($mime === null || !isset($allowed[$mime]) || $allowed[$mime] === 'svg') {
            return ['ok' => false, 'error' => 'Допустимы только JPG, PNG, GIF, WebP и SVG'];
        }
        $ext = $allowed[$mime];
    }

    try {
        $dir = church_landing_ensure_upload_dir();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if ($isSvg) {
        if (@file_put_contents($dest, $contents) === false) {
            return ['ok' => false, 'error' => 'Не удалось сохранить файл'];
        }
        @unlink($tmp);
    } elseif (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'Не удалось сохранить файл'];
    }
    @chmod($dest, 0644);

    return ['ok' => true, 'path' => church_landing_upload_web_prefix() . '/' . $filename];
}

/**
 * @param mixed $raw
 * @return array{phone:string,email:string,max:string,telegram:string,whatsapp:string}
 */
function church_landing_normalize_contacts(mixed $raw): array
{
    $row = is_array($raw) ? $raw : [];

    return [
        'phone' => trim((string) ($row['phone'] ?? '')),
        'email' => trim((string) ($row['email'] ?? '')),
        'max' => trim((string) ($row['max'] ?? '')),
        'telegram' => trim((string) ($row['telegram'] ?? '')),
        'whatsapp' => trim((string) ($row['whatsapp'] ?? '')),
    ];
}

/**
 * @param mixed $raw
 * @return list<array{src:string,alt:string,sort:int}>
 */
function church_landing_normalize_gallery(mixed $raw): array
{
    $items = [];
    foreach (church_landing_as_list($raw) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $src = church_landing_normalize_media_src($row['src'] ?? null);
        if ($src === null) {
            continue;
        }
        $items[] = [
            'src' => $src,
            'alt' => mb_substr(trim((string) ($row['alt'] ?? '')), 0, 190),
            'sort' => (int) ($row['sort'] ?? $index),
        ];
        if (count($items) >= church_landing_gallery_max_items()) {
            break;
        }
    }
    usort($items, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
    foreach ($items as $i => &$item) {
        $item['sort'] = $i;
    }
    unset($item);

    return $items;
}

/**
 * @param mixed $raw
 * @return list<array{name:string,photo:string,description:string,sort:int}>
 */
function church_landing_normalize_pastors(mixed $raw): array
{
    $items = [];
    foreach (church_landing_as_list($raw) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = mb_substr(trim((string) ($row['name'] ?? '')), 0, 160);
        if ($name === '') {
            continue;
        }
        $photo = church_landing_normalize_media_src($row['photo'] ?? null) ?? '';
        $items[] = [
            'name' => $name,
            'photo' => $photo,
            'description' => mb_substr(trim((string) ($row['description'] ?? '')), 0, 4000),
            'sort' => (int) ($row['sort'] ?? $index),
        ];
    }
    usort($items, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
    foreach ($items as $i => &$item) {
        $item['sort'] = $i;
    }
    unset($item);

    return $items;
}

function church_landing_normalize_time(?string $value): string
{
    $value = trim((string) $value);
    if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
        return $value;
    }
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) === 1) {
        return substr($value, 0, 5);
    }

    return '';
}

/**
 * @param mixed $raw
 * @return list<array{weekday:int,time_from:string,time_to:string,title:string,note:string,sort:int}>
 */
function church_landing_normalize_schedule(mixed $raw): array
{
    $items = [];
    $labels = church_landing_weekday_labels();
    foreach (church_landing_as_list($raw) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $weekday = (int) ($row['weekday'] ?? 0);
        $title = mb_substr(trim((string) ($row['title'] ?? '')), 0, 190);
        if ($title === '' || !isset($labels[$weekday])) {
            continue;
        }
        $items[] = [
            'weekday' => $weekday,
            'time_from' => church_landing_normalize_time($row['time_from'] ?? null),
            'time_to' => church_landing_normalize_time($row['time_to'] ?? null),
            'title' => $title,
            'note' => mb_substr(trim((string) ($row['note'] ?? '')), 0, 500),
            'sort' => (int) ($row['sort'] ?? $index),
        ];
    }
    usort($items, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
    foreach ($items as $i => &$item) {
        $item['sort'] = $i;
    }
    unset($item);

    return $items;
}

/**
 * @param mixed $raw
 * @return list<array{date:string,time_from:string,time_to:string,title:string,note:string,sort:int}>
 */
function church_landing_normalize_events(mixed $raw): array
{
    $items = [];
    foreach (church_landing_as_list($raw) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $date = trim((string) ($row['date'] ?? ''));
        $title = mb_substr(trim((string) ($row['title'] ?? '')), 0, 190);
        if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            continue;
        }
        $items[] = [
            'date' => $date,
            'time_from' => church_landing_normalize_time($row['time_from'] ?? null),
            'time_to' => church_landing_normalize_time($row['time_to'] ?? null),
            'title' => $title,
            'note' => mb_substr(trim((string) ($row['note'] ?? '')), 0, 500),
            'sort' => (int) ($row['sort'] ?? $index),
        ];
    }
    usort($items, static function (array $a, array $b): int {
        $byDate = strcmp($a['date'], $b['date']);
        if ($byDate !== 0) {
            return $byDate;
        }

        return $a['sort'] <=> $b['sort'];
    });
    foreach ($items as $i => &$item) {
        $item['sort'] = $i;
    }
    unset($item);

    return $items;
}

/**
 * @param mixed $raw
 * @return list<array{title:string,description:string,url:string,sort:int}>
 */
function church_landing_normalize_ministries(mixed $raw): array
{
    $items = [];
    foreach (church_landing_as_list($raw) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $title = mb_substr(trim((string) ($row['title'] ?? '')), 0, 190);
        if ($title === '') {
            continue;
        }
        $urlRaw = trim((string) ($row['url'] ?? $row['link'] ?? ''));
        $items[] = [
            'title' => $title,
            'description' => mb_substr(trim((string) ($row['description'] ?? '')), 0, 4000),
            'url' => church_landing_normalize_optional_link($urlRaw),
            'sort' => (int) ($row['sort'] ?? $index),
        ];
    }
    usort($items, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
    foreach ($items as $i => &$item) {
        $item['sort'] = $i;
    }
    unset($item);

    return $items;
}

/**
 * @param mixed $raw
 * @return list<array{question:string,answer:string,sort:int}>
 */
function church_landing_normalize_faq(mixed $raw): array
{
    $items = [];
    foreach (church_landing_as_list($raw) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $question = mb_substr(trim((string) ($row['question'] ?? '')), 0, 300);
        $answer = mb_substr(trim((string) ($row['answer'] ?? '')), 0, 4000);
        if ($question === '' || $answer === '') {
            continue;
        }
        $items[] = [
            'question' => $question,
            'answer' => $answer,
            'sort' => (int) ($row['sort'] ?? $index),
        ];
    }
    usort($items, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
    foreach ($items as $i => &$item) {
        $item['sort'] = $i;
    }
    unset($item);

    return $items;
}

/**
 * @param mixed $raw
 * @return list<array{kind:string,network:string,label:string,url:string,icon:string,sort:int}>
 */
function church_landing_normalize_buttons(mixed $raw): array
{
    $social = church_landing_social_networks();
    $builtin = church_landing_builtin_button_kinds();
    $items = [];
    foreach (church_landing_as_list($raw) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $kind = (string) ($row['kind'] ?? '');
        if (!in_array($kind, ['social', 'custom', 'builtin'], true)) {
            continue;
        }
        $network = trim((string) ($row['network'] ?? ''));
        $label = mb_substr(trim((string) ($row['label'] ?? '')), 0, 120);
        $urlRaw = trim((string) ($row['url'] ?? ''));
        $icon = church_landing_normalize_media_src($row['icon'] ?? null) ?? '';

        if ($kind === 'builtin') {
            if (!isset($builtin[$network])) {
                continue;
            }
            if ($label === '') {
                $label = $builtin[$network];
            }
            $urlRaw = '';
            $icon = '';
        } elseif ($kind === 'social') {
            if (!isset($social[$network])) {
                continue;
            }
            if ($label === '') {
                $label = $social[$network]['label'];
            }
            if (church_landing_normalize_network_href($network, $urlRaw) === null) {
                continue;
            }
            $icon = '';
        } else {
            if ($label === '' || church_landing_sanitize_http_url($urlRaw) === null) {
                continue;
            }
            $network = '';
        }

        $items[] = [
            'kind' => $kind,
            'network' => $network,
            'label' => $label,
            'url' => $urlRaw,
            'icon' => $icon,
            'sort' => (int) ($row['sort'] ?? $index),
        ];
    }
    usort($items, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
    foreach ($items as $i => &$item) {
        $item['sort'] = $i;
    }
    unset($item);

    return $items;
}

/**
 * @param mixed $raw
 * @return list<array{id:string,visible:bool}>
 */
function church_landing_normalize_sections(mixed $raw): array
{
    $known = church_landing_section_ids();
    $seen = [];
    $items = [];
    // Legacy standalone «howto» merges into «address» (keep address visible if either was).
    $legacyHowtoVisible = null;
    foreach (church_landing_as_list($raw) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $visibleRaw = $row['visible'] ?? true;
        if (is_bool($visibleRaw)) {
            $visible = $visibleRaw;
        } elseif (is_int($visibleRaw) || is_float($visibleRaw)) {
            $visible = (int) $visibleRaw === 1;
        } else {
            $visible = !in_array(strtolower(trim((string) $visibleRaw)), ['0', 'false', 'off', 'no', ''], true);
        }
        if ($id === 'howto') {
            $legacyHowtoVisible = $visible;
            continue;
        }
        if (!in_array($id, $known, true) || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $items[] = ['id' => $id, 'visible' => $visible];
    }
    if ($legacyHowtoVisible !== null && isset($seen['address'])) {
        foreach ($items as &$item) {
            if ($item['id'] === 'address') {
                $item['visible'] = $item['visible'] || $legacyHowtoVisible;
                break;
            }
        }
        unset($item);
    }
    foreach ($known as $id) {
        if (!isset($seen[$id])) {
            $visible = true;
            if ($id === 'address' && $legacyHowtoVisible !== null) {
                $visible = $legacyHowtoVisible;
            }
            $items[] = ['id' => $id, 'visible' => $visible];
        }
    }

    return $items;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function church_landing_hydrate_row(array $row): array
{
    $landing = church_landing_empty((int) ($row['church_id'] ?? 0));
    $landing['seo_title'] = trim((string) ($row['seo_title'] ?? '')) ?: null;
    $landing['seo_description'] = trim((string) ($row['seo_description'] ?? '')) ?: null;
    $landing['og_image'] = church_landing_normalize_media_src($row['og_image'] ?? null);
    $landing['logo'] = church_landing_normalize_media_src($row['logo'] ?? null);
    $landing['description'] = trim((string) ($row['description'] ?? '')) ?: null;
    $landing['how_to_get'] = trim((string) ($row['how_to_get'] ?? '')) ?: null;
    $landing['address_text'] = trim((string) ($row['address_text'] ?? '')) ?: null;
    $lat = $row['address_lat'] ?? null;
    $lng = $row['address_lng'] ?? null;
    $landing['address_lat'] = $lat !== null && $lat !== '' ? (float) $lat : null;
    $landing['address_lng'] = $lng !== null && $lng !== '' ? (float) $lng : null;
    $landing['donation_label'] = trim((string) ($row['donation_label'] ?? '')) ?: null;
    $donationUrl = church_landing_sanitize_http_url($row['donation_url'] ?? null);
    $landing['donation_url'] = $donationUrl;
    $landing['contacts'] = church_landing_normalize_contacts(church_landing_decode_json($row['contacts_json'] ?? null) ?? ($row['contacts'] ?? null));
    $landing['gallery'] = church_landing_normalize_gallery(church_landing_decode_json($row['gallery_json'] ?? null) ?? ($row['gallery'] ?? null));
    $landing['pastors'] = church_landing_normalize_pastors(church_landing_decode_json($row['pastors_json'] ?? null) ?? ($row['pastors'] ?? null));
    $landing['schedule'] = church_landing_normalize_schedule(church_landing_decode_json($row['schedule_json'] ?? null) ?? ($row['schedule'] ?? null));
    $landing['events'] = church_landing_normalize_events(church_landing_decode_json($row['events_json'] ?? null) ?? ($row['events'] ?? null));
    $landing['ministries'] = church_landing_normalize_ministries(church_landing_decode_json($row['ministries_json'] ?? null) ?? ($row['ministries'] ?? null));
    $landing['faq'] = church_landing_normalize_faq(church_landing_decode_json($row['faq_json'] ?? null) ?? ($row['faq'] ?? null));
    $buttonsRaw = church_landing_decode_json($row['buttons_json'] ?? null);
    $landing['buttons'] = $buttonsRaw === null && !isset($row['buttons'])
        ? church_landing_default_buttons()
        : church_landing_normalize_buttons($buttonsRaw ?? ($row['buttons'] ?? null));
    if ($landing['buttons'] === []) {
        $landing['buttons'] = church_landing_default_buttons();
    }
    $sectionsRaw = church_landing_decode_json($row['sections_json'] ?? null);
    $landing['sections'] = $sectionsRaw === null && !isset($row['sections'])
        ? church_landing_default_sections()
        : church_landing_normalize_sections($sectionsRaw ?? ($row['sections'] ?? null));

    return $landing;
}

function church_landing_load(int $churchId): array
{
    if ($churchId <= 0) {
        return church_landing_empty(0);
    }
    $row = fetch_one('SELECT * FROM church_landing WHERE church_id = ? LIMIT 1', [$churchId]);
    if (!$row) {
        return church_landing_empty($churchId);
    }

    return church_landing_hydrate_row($row);
}

/**
 * @param array<string, mixed> $church
 */
function church_landing_logo_for_church(array $church): ?string
{
    $churchId = (int) ($church['id'] ?? 0);
    if ($churchId <= 0) {
        return null;
    }
    try {
        $row = fetch_one('SELECT logo FROM church_landing WHERE church_id = ? LIMIT 1', [$churchId]);
    } catch (Throwable) {
        return null;
    }
    if (!$row) {
        return null;
    }

    return church_landing_normalize_media_src($row['logo'] ?? null);
}

/**
 * @param array<string, mixed> $landing
 */
function church_landing_save(int $churchId, array $landing): void
{
    $payload = church_landing_hydrate_row(array_merge($landing, ['church_id' => $churchId]));
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    db()->prepare(
        'INSERT INTO church_landing (
            church_id, seo_title, seo_description, og_image, logo, description, how_to_get,
            address_text, address_lat, address_lng, donation_label, donation_url,
            contacts_json, gallery_json, pastors_json, schedule_json, events_json,
            ministries_json, faq_json, buttons_json, sections_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            seo_title = VALUES(seo_title),
            seo_description = VALUES(seo_description),
            og_image = VALUES(og_image),
            logo = VALUES(logo),
            description = VALUES(description),
            how_to_get = VALUES(how_to_get),
            address_text = VALUES(address_text),
            address_lat = VALUES(address_lat),
            address_lng = VALUES(address_lng),
            donation_label = VALUES(donation_label),
            donation_url = VALUES(donation_url),
            contacts_json = VALUES(contacts_json),
            gallery_json = VALUES(gallery_json),
            pastors_json = VALUES(pastors_json),
            schedule_json = VALUES(schedule_json),
            events_json = VALUES(events_json),
            ministries_json = VALUES(ministries_json),
            faq_json = VALUES(faq_json),
            buttons_json = VALUES(buttons_json),
            sections_json = VALUES(sections_json)'
    )->execute([
        $churchId,
        $payload['seo_title'],
        $payload['seo_description'],
        $payload['og_image'],
        $payload['logo'],
        $payload['description'],
        $payload['how_to_get'],
        $payload['address_text'],
        $payload['address_lat'],
        $payload['address_lng'],
        $payload['donation_label'],
        $payload['donation_url'],
        json_encode($payload['contacts'], $jsonFlags),
        json_encode($payload['gallery'], $jsonFlags),
        json_encode($payload['pastors'], $jsonFlags),
        json_encode($payload['schedule'], $jsonFlags),
        json_encode($payload['events'], $jsonFlags),
        json_encode($payload['ministries'], $jsonFlags),
        json_encode($payload['faq'], $jsonFlags),
        json_encode($payload['buttons'], $jsonFlags),
        json_encode($payload['sections'], $jsonFlags),
    ]);
}

/**
 * Upcoming events only (date >= today in church timezone when available).
 *
 * @param list<array{date:string,time_from:string,time_to:string,title:string,note:string,sort:int}> $events
 * @return list<array{date:string,time_from:string,time_to:string,title:string,note:string,sort:int}>
 */
function church_landing_upcoming_events(array $events, ?string $timezone = null): array
{
    try {
        $tz = new DateTimeZone($timezone && $timezone !== '' ? $timezone : 'Europe/Moscow');
    } catch (Throwable) {
        $tz = new DateTimeZone('Europe/Moscow');
    }
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $out = [];
    foreach ($events as $event) {
        if (($event['date'] ?? '') >= $today) {
            $out[] = $event;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $landing
 * @return array<string, bool>
 */
function church_landing_section_visibility_map(array $landing): array
{
    $map = [];
    foreach ($landing['sections'] as $section) {
        $map[$section['id']] = (bool) $section['visible'];
    }

    return $map;
}

/**
 * @param array<string, mixed> $landing
 * @param array<string, mixed> $church
 */
function church_landing_section_has_content(string $sectionId, array $landing, array $church): bool
{
    return match ($sectionId) {
        'hero' => trim((string) ($church['name'] ?? '')) !== '',
        'gallery' => $landing['gallery'] !== [],
        'description' => trim((string) ($landing['description'] ?? '')) !== '',
        'buttons' => $landing['buttons'] !== [],
        'pastors' => $landing['pastors'] !== [],
        'contacts' => array_filter($landing['contacts'], static fn(string $v): bool => trim($v) !== '') !== [],
        'address' => trim((string) ($landing['address_text'] ?? '')) !== ''
            || trim((string) ($landing['how_to_get'] ?? '')) !== '',
        'schedule' => $landing['schedule'] !== [],
        'events' => church_landing_upcoming_events($landing['events'], $church['timezone'] ?? null) !== [],
        'ministries' => $landing['ministries'] !== [],
        'faq' => $landing['faq'] !== [],
        'donation' => ($landing['donation_url'] ?? null) !== null,
        default => false,
    };
}

function church_landing_media_public_url(?string $src): string
{
    $src = trim((string) $src);
    if ($src === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $src) === 1) {
        return $src;
    }
    if (function_exists('asset_url')) {
        return asset_url($src);
    }

    return $src;
}

function church_landing_navi_route_url(?float $lat, ?float $lng, ?string $address = null): string
{
    if ($lat !== null && $lng !== null) {
        return 'https://yandex.ru/maps/?rtext=~' . rawurlencode($lat . ',' . $lng) . '&rtt=auto';
    }
    $address = trim((string) $address);
    if ($address !== '') {
        return 'https://yandex.ru/maps/?text=' . rawurlencode($address);
    }

    return '';
}

function church_landing_social_icon_svg(string $network): string
{
    // Official monochrome brand marks; color comes from button background via currentColor.
    static $icons = null;
    if ($icons === null) {
        $icons = array (
  'vk' => 
  array (
    'd' => 'm9.489.004.729-.003h3.564l.73.003.914.01.433.007.418.011.403.014.388.016.374.021.36.025.345.03.333.033c1.74.196 2.933.616 3.833 1.516.9.9 1.32 2.092 1.516 3.833l.034.333.029.346.025.36.02.373.025.588.012.41.013.644.009.915.004.98-.001 3.313-.003.73-.01.914-.007.433-.011.418-.014.403-.016.388-.021.374-.025.36-.03.345-.033.333c-.196 1.74-.616 2.933-1.516 3.833-.9.9-2.092 1.32-3.833 1.516l-.333.034-.346.029-.36.025-.373.02-.588.025-.41.012-.644.013-.915.009-.98.004-3.313-.001-.73-.003-.914-.01-.433-.007-.418-.011-.403-.014-.388-.016-.374-.021-.36-.025-.345-.03-.333-.033c-1.74-.196-2.933-.616-3.833-1.516-.9-.9-1.32-2.092-1.516-3.833l-.034-.333-.029-.346-.025-.36-.02-.373-.025-.588-.012-.41-.013-.644-.009-.915-.004-.98.001-3.313.003-.73.01-.914.007-.433.011-.418.014-.403.016-.388.021-.374.025-.36.03-.345.033-.333c.196-1.74.616-2.933 1.516-3.833.9-.9 2.092-1.32 3.833-1.516l.333-.034.346-.029.36-.025.373-.02.588-.025.41-.012.644-.013.915-.009ZM6.79 7.3H4.05c.13 6.24 3.25 9.99 8.72 9.99h.31v-3.57c2.01.2 3.53 1.67 4.14 3.57h2.84c-.78-2.84-2.83-4.41-4.11-5.01 1.28-.74 3.08-2.54 3.51-4.98h-2.58c-.56 1.98-2.22 3.78-3.8 3.95V7.3H10.5v6.92c-1.6-.4-3.62-2.34-3.71-6.92Z',
    'viewBox' => '0 0 24 24',
  ),
  'telegram' => 
  array (
    'd' => 'M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z',
    'viewBox' => '0 0 24 24',
  ),
  'youtube' => 
  array (
    'd' => 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z',
    'viewBox' => '0 0 24 24',
  ),
  'whatsapp' => 
  array (
    'd' => 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z',
    'viewBox' => '0 0 24 24',
  ),
  'tiktok' => 
  array (
    'd' => 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z',
    'viewBox' => '0 0 24 24',
  ),
  'apple_podcasts' => 
  array (
    'd' => 'M5.34 0A5.328 5.328 0 000 5.34v13.32A5.328 5.328 0 005.34 24h13.32A5.328 5.328 0 0024 18.66V5.34A5.328 5.328 0 0018.66 0zm6.525 2.568c2.336 0 4.448.902 6.056 2.587 1.224 1.272 1.912 2.619 2.264 4.392.12.59.12 2.2.007 2.864a8.506 8.506 0 01-3.24 5.296c-.608.46-2.096 1.261-2.336 1.261-.088 0-.096-.091-.056-.46.072-.592.144-.715.48-.856.536-.224 1.448-.874 2.008-1.435a7.644 7.644 0 002.008-3.536c.208-.824.184-2.656-.048-3.504-.728-2.696-2.928-4.792-5.624-5.352-.784-.16-2.208-.16-3 0-2.728.56-4.984 2.76-5.672 5.528-.184.752-.184 2.584 0 3.336.456 1.832 1.64 3.512 3.192 4.512.304.2.672.408.824.472.336.144.408.264.472.856.04.36.03.464-.056.464-.056 0-.464-.176-.896-.384l-.04-.03c-2.472-1.216-4.056-3.274-4.632-6.012-.144-.706-.168-2.392-.03-3.04.36-1.74 1.048-3.1 2.192-4.304 1.648-1.737 3.768-2.656 6.128-2.656zm.134 2.81c.409.004.803.04 1.106.106 2.784.62 4.76 3.408 4.376 6.174-.152 1.114-.536 2.03-1.216 2.88-.336.43-1.152 1.15-1.296 1.15-.023 0-.048-.272-.048-.603v-.605l.416-.496c1.568-1.878 1.456-4.502-.256-6.224-.664-.67-1.432-1.064-2.424-1.246-.64-.118-.776-.118-1.448-.008-1.02.167-1.81.562-2.512 1.256-1.72 1.704-1.832 4.342-.264 6.222l.413.496v.608c0 .336-.027.608-.06.608-.03 0-.264-.16-.512-.36l-.034-.011c-.832-.664-1.568-1.842-1.872-2.997-.184-.698-.184-2.024.008-2.72.504-1.878 1.888-3.335 3.808-4.019.41-.145 1.133-.22 1.814-.211zm-.13 2.99c.31 0 .62.06.844.178.488.253.888.745 1.04 1.259.464 1.578-1.208 2.96-2.72 2.254h-.015c-.712-.331-1.096-.956-1.104-1.77 0-.733.408-1.371 1.112-1.745.224-.117.534-.176.844-.176zm-.011 4.728c.988-.004 1.706.349 1.97.97.198.464.124 1.932-.218 4.302-.232 1.656-.36 2.074-.68 2.356-.44.39-1.064.498-1.656.288h-.003c-.716-.257-.87-.605-1.164-2.644-.341-2.37-.416-3.838-.218-4.302.262-.616.974-.966 1.97-.97z',
    'viewBox' => '0 0 24 24',
  ),
  'instagram' => 
  array (
    'd' => 'M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077',
    'viewBox' => '0 0 24 24',
  ),
  'odnoklassniki' => 
  array (
    'd' => 'M12 0a6.2 6.2 0 0 0-6.194 6.195 6.2 6.2 0 0 0 6.195 6.192 6.2 6.2 0 0 0 6.193-6.192A6.2 6.2 0 0 0 12.001 0zm0 3.63a2.567 2.567 0 0 1 2.565 2.565 2.568 2.568 0 0 1-2.564 2.564 2.568 2.568 0 0 1-2.565-2.564 2.567 2.567 0 0 1 2.565-2.564zM6.807 12.6a1.814 1.814 0 0 0-.91 3.35 11.611 11.611 0 0 0 3.597 1.49l-3.462 3.463a1.815 1.815 0 0 0 2.567 2.566L12 20.066l3.405 3.403a1.813 1.813 0 0 0 2.564 0c.71-.709.71-1.858 0-2.566l-3.462-3.462a11.593 11.593 0 0 0 3.596-1.49 1.814 1.814 0 1 0-1.932-3.073 7.867 7.867 0 0 1-8.34 0c-.318-.2-.674-.29-1.024-.278z',
    'viewBox' => '0 0 24 24',
  ),
  'zoom' => 
  array (
    'd' => 'M5.033 14.649H.743a.74.74 0 0 1-.686-.458.74.74 0 0 1 .16-.808L3.19 10.41H1.06A1.06 1.06 0 0 1 0 9.35h3.957c.301 0 .57.18.686.458a.74.74 0 0 1-.161.808L1.51 13.59h2.464c.585 0 1.06.475 1.06 1.06zM24 11.338c0-1.14-.927-2.066-2.066-2.066-.61 0-1.158.265-1.537.686a2.061 2.061 0 0 0-1.536-.686c-1.14 0-2.066.926-2.066 2.066v3.311a1.06 1.06 0 0 0 1.06-1.06v-2.251a1.004 1.004 0 0 1 2.013 0v2.251c0 .586.474 1.06 1.06 1.06v-3.311a1.004 1.004 0 0 1 2.012 0v2.251c0 .586.475 1.06 1.06 1.06zM16.265 12a2.728 2.728 0 1 1-5.457 0 2.728 2.728 0 0 1 5.457 0zm-1.06 0a1.669 1.669 0 1 0-3.338 0 1.669 1.669 0 0 0 3.338 0zm-4.82 0a2.728 2.728 0 1 1-5.458 0 2.728 2.728 0 0 1 5.457 0zm-1.06 0a1.669 1.669 0 1 0-3.338 0 1.669 1.669 0 0 0 3.338 0z',
    'viewBox' => '0 0 24 24',
  ),
  'yandex_music' => 
  array (
    'd' => 'm130.863 57.739-.468-2.327-19.788-3.457 11.498-15.557-1.337-1.462-16.913 8.11 2.139-21.54-1.738-.997-10.295 17.418L82.395 12H80.39l2.74 25.064-29.08-23.269-2.474.732 22.396 28.122-44.323-14.76-2.006 2.261L67.22 52.686l-54.618 4.521-.602 3.39 56.757 6.184-47.33 39.157 2.005 2.726 56.356-30.648-11.164 53.983h3.41l21.592-50.792 13.17 39.756 2.34-1.795-5.415-40.42 20.524 23.268 1.337-2.128-15.711-28.853 21.928 8.111.201-2.46-19.655-14.493 18.518-4.454Z',
    'viewBox' => '0 0 144 144',
  ),
  'max' => 
  array (
    'd' => 'M50.7571 0.261719C78.2929 0.261719 99.8857 22.5974 99.8857 50.1474C99.8857 77.6974 77.6071 99.4903 51.0214 99.4903C41.5857 99.4903 37.0143 98.1617 29.65 92.9474C29.1429 92.5903 28.45 92.6831 28.0214 93.1403C22.3571 99.1831 7.85 103.426 7.18571 95.176C7.18571 80.7903 0 71.4474 0 49.876C0 21.5546 23.2214 0.261719 50.7571 0.261719ZM51.5286 24.8117C38.4643 24.126 28.2643 33.1974 26.0143 47.3831C24.15 59.1332 27.45 73.4546 30.2786 74.176C31.4786 74.4832 34.3571 72.276 36.4571 70.2974C36.85 69.926 37.45 69.8617 37.9071 70.1474C41.1786 72.1474 44.8786 73.6474 48.9571 73.8617C62.3714 74.5617 74.2571 64.0617 74.9643 50.6474C75.6643 37.2331 64.9429 25.5046 51.5286 24.8046V24.8117Z',
    'viewBox' => '0 0 100 100',
    'fill_rule' => 'evenodd',
  ),
  'rutube' => 
  array (
    'd' => 'M81.536 62.987H42.54V47.555h38.997c2.278 0 3.862.397 4.657 1.09s1.287 1.98 1.287 3.858v5.541c0 1.98-.492 3.265-1.287 3.959-.795.693-2.379.99-4.657.99zm2.675-29.981H26V99h16.539V77.53h30.479L87.48 99H106L90.055 77.429c5.878-.871 8.518-2.673 10.695-5.642s3.269-7.716 3.269-14.051v-4.948c0-3.758-.398-6.727-1.092-9.002s-1.88-4.255-3.565-6.033c-1.78-1.683-3.76-2.868-6.14-3.663-2.378-.693-5.35-1.09-9.01-1.09z',
    'viewBox' => '24 30 86 72',
  ),
  'dzen' => 
  array (
    'd' => 'M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm0 3.2a6.8 6.8 0 0 1 0 13.6c-1.76 0-3.08-.52-3.08-2.08 0-.96.56-1.52 1.36-2.08.72-.48 1.52-1.04 1.52-1.92 0-1.36-1.2-2.16-2.96-2.16-1.04 0-2.08.32-2.88.8l.72-2.48A8.3 8.3 0 0 1 12 5.2z',
    'viewBox' => '0 0 24 24',
  ),
  'yandex_maps' => 
  array (
    'd' => 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z',
    'viewBox' => '0 0 24 24',
  ),
  'telemost' => 
  array (
    'd' => 'M17 10.5V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3.5l4 4v-11l-4 4z',
    'viewBox' => '0 0 24 24',
  ),
);
    }
    $icon = $icons[$network] ?? null;
    if ($icon === null) {
        return '<svg class="church-landing-btn__icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8"/></svg>';
    }
    $viewBox = (string) ($icon['viewBox'] ?? '0 0 24 24');
    $fillRule = isset($icon['fill_rule']) ? ' fill-rule="' . e((string) $icon['fill_rule']) . '"' : '';
    $d = (string) ($icon['d'] ?? '');

    return '<svg class="church-landing-btn__icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="' . e($viewBox) . '" fill="currentColor" aria-hidden="true" focusable="false"><path' . $fillRule . ' d="' . e($d) . '"/></svg>';
}

/**
 * @param array<string, mixed> $button
 * @param array<string, mixed> $church
 * @return array{href:string,label:string,class:string,style:string,icon_html:string}|null
 */
function church_landing_resolve_button(array $button, array $church): ?array
{
    $kind = (string) ($button['kind'] ?? '');
    $label = trim((string) ($button['label'] ?? ''));
    if ($label === '') {
        return null;
    }

    if ($kind === 'builtin') {
        $network = (string) ($button['network'] ?? '');
        $href = match ($network) {
            'homegroups' => public_homegroup_catalog_path($church),
            'prayer' => public_prayer_request_path($church),
            'miracle' => public_miracle_share_path($church),
            default => '',
        };
        if ($href === '') {
            return null;
        }

        return [
            'href' => $href,
            'label' => $label,
            'class' => 'church-landing-btn church-landing-btn--primary',
            'style' => '',
            'icon_html' => '',
        ];
    }

    if ($kind === 'social') {
        $network = (string) ($button['network'] ?? '');
        $meta = church_landing_social_networks()[$network] ?? null;
        $href = church_landing_normalize_network_href($network, $button['url'] ?? null);
        if ($meta === null || $href === null) {
            return null;
        }

        return [
            'href' => $href,
            'label' => $label,
            'class' => 'church-landing-btn church-landing-btn--social church-landing-btn--' . $network,
            'style' => '--landing-btn-bg:' . $meta['bg'] . ';--landing-btn-fg:' . $meta['fg'] . ';',
            'icon_html' => church_landing_social_icon_svg($network),
        ];
    }

    if ($kind === 'custom') {
        $href = church_landing_sanitize_http_url($button['url'] ?? null);
        if ($href === null) {
            return null;
        }
        $icon = church_landing_normalize_media_src($button['icon'] ?? null);
        $iconHtml = $icon
            ? '<img class="church-landing-btn__icon-img" src="' . e(church_landing_media_public_url($icon)) . '" alt="" width="22" height="22" loading="lazy">'
            : '';

        return [
            'href' => $href,
            'label' => $label,
            'class' => 'church-landing-btn church-landing-btn--custom',
            'style' => '',
            'icon_html' => $iconHtml,
        ];
    }

    return null;
}

/**
 * @param array<string, mixed> $contacts
 * @return list<array{label:string,href:string,display:string}>
 */
function church_landing_resolve_contacts(array $contacts): array
{
    $out = [];
    $phone = trim((string) ($contacts['phone'] ?? ''));
    if ($phone !== '') {
        $hrefPhone = function_exists('phone_href') ? phone_href($phone) : church_landing_digits($phone);
        if ($hrefPhone !== '') {
            $out[] = [
                'label' => 'Телефон',
                'href' => 'tel:' . $hrefPhone,
                'display' => function_exists('ChurchCare\\format_phone') ? (ChurchCare\format_phone($phone) ?: $phone) : $phone,
            ];
        }
    }
    $email = trim((string) ($contacts['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $out[] = ['label' => 'Email', 'href' => 'mailto:' . $email, 'display' => $email];
    }
    $telegram = trim((string) ($contacts['telegram'] ?? ''));
    $tgHref = church_landing_normalize_network_href('telegram', $telegram);
    if ($tgHref !== null) {
        $out[] = ['label' => 'Telegram', 'href' => $tgHref, 'display' => $telegram];
    }
    $max = trim((string) ($contacts['max'] ?? ''));
    $maxHref = church_landing_normalize_network_href('max', $max);
    if ($maxHref !== null) {
        $out[] = ['label' => 'Max', 'href' => $maxHref, 'display' => $max];
    }
    $wa = trim((string) ($contacts['whatsapp'] ?? ''));
    $waHref = church_landing_normalize_network_href('whatsapp', $wa);
    if ($waHref !== null) {
        $out[] = ['label' => 'WhatsApp', 'href' => $waHref, 'display' => $wa];
    }

    return $out;
}

/**
 * @param array<string, mixed> $landing
 * @param array<string, mixed> $church
 */
function church_landing_seo_title(array $landing, array $church): string
{
    $custom = trim((string) ($landing['seo_title'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }
    $name = trim((string) ($church['name'] ?? 'Церковь'));
    $city = trim((string) ($church['city'] ?? ''));

    return $city !== '' && $city !== $name ? $name . ' · ' . $city : $name;
}

/**
 * @param array<string, mixed> $landing
 * @param array<string, mixed> $church
 */
function church_landing_seo_description(array $landing, array $church): string
{
    $custom = trim((string) ($landing['seo_description'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }
    $description = trim((string) ($landing['description'] ?? ''));
    if ($description !== '') {
        return mb_substr(preg_replace('/\s+/u', ' ', $description) ?? $description, 0, 200);
    }

    return 'Страница церкви ' . trim((string) ($church['name'] ?? ''));
}

/**
 * @param array<string, mixed> $landing
 */
function church_landing_og_image(array $landing): string
{
    $og = church_landing_normalize_media_src($landing['og_image'] ?? null);
    if ($og !== null) {
        return church_landing_media_public_url($og);
    }

    // Prefer the church logo over gallery/pastor photos. Skip SVG: messengers
    // (Max, Telegram, …) usually ignore vector og:image and scrape the first JPEG.
    $logo = church_landing_normalize_media_src($landing['logo'] ?? null);
    if ($logo !== null && !preg_match('/\.svg(?:$|[?#])/i', $logo)) {
        return church_landing_media_public_url($logo);
    }

    if ($landing['gallery'] !== []) {
        return church_landing_media_public_url($landing['gallery'][0]['src']);
    }

    return '';
}

/**
 * @param array<string, mixed> $landing
 * @param array<string, mixed> $church
 */
function church_landing_og_head_html(array $landing, array $church, string $canonicalUrl): string
{
    $title = church_landing_seo_title($landing, $church);
    $description = church_landing_seo_description($landing, $church);
    $image = church_landing_og_image($landing);
    $html = '<meta name="description" content="' . e($description) . '">';
    $html .= '<meta property="og:type" content="website">';
    $html .= '<meta property="og:locale" content="ru_RU">';
    $html .= '<meta property="og:site_name" content="' . e($title) . '">';
    $html .= '<meta property="og:title" content="' . e($title) . '">';
    $html .= '<meta property="og:description" content="' . e($description) . '">';
    $html .= '<meta property="og:url" content="' . e($canonicalUrl) . '">';
    $html .= '<meta name="twitter:card" content="summary_large_image">';
    $html .= '<meta name="twitter:title" content="' . e($title) . '">';
    $html .= '<meta name="twitter:description" content="' . e($description) . '">';
    if ($image !== '') {
        $absoluteImage = preg_match('#^https?://#i', $image) === 1
            ? $image
            : rtrim((string) (function_exists('public_url') ? public_url('') : ''), '/') . $image;
        if (function_exists('public_url') && preg_match('#^https?://#i', $image) !== 1) {
            $absoluteImage = public_url(parse_url($image, PHP_URL_PATH) ?: $image);
        }
        $html .= '<meta property="og:image" content="' . e($absoluteImage) . '">';
        $html .= '<meta property="og:image:alt" content="' . e($title) . '">';
        $html .= '<meta name="twitter:image" content="' . e($absoluteImage) . '">';
        $html .= '<meta name="twitter:image:alt" content="' . e($title) . '">';
    }
    $html .= '<link rel="canonical" href="' . e($canonicalUrl) . '">';

    return $html;
}

function church_about_page(): void
{
    $church = current_church();
    if (!$church || !empty($church['deleted_at'])) {
        http_response_code(404);
        exit('Страница не найдена');
    }

    $landing = church_landing_load((int) $church['id']);
    $title = church_landing_seo_title($landing, $church);
    $aboutPath = public_church_about_path($church);
    $canonical = function_exists('public_url') ? public_url($aboutPath) : $aboutPath;
    $visibility = church_landing_section_visibility_map($landing);
    $mapKey = function_exists('homegroup_yandex_maps_api_key') ? homegroup_yandex_maps_api_key() : (string) (app_config('yandex.maps_api_key') ?: '');
    $hasCoords = $landing['address_lat'] !== null && $landing['address_lng'] !== null;
    $mapReady = $mapKey !== '' && $hasCoords;

    public_page_header($title, [
        'extra_head' => church_landing_og_head_html($landing, $church, $canonical),
    ]);

    echo '<main class="public-shell church-landing" data-church-landing>';
    foreach ($landing['sections'] as $section) {
        $id = $section['id'];
        if (!$section['visible'] || !church_landing_section_has_content($id, $landing, $church)) {
            continue;
        }
        church_landing_render_section($id, $landing, $church, [
            'map_key' => $mapKey,
            'map_ready' => $mapReady,
        ]);
    }

    echo '<p class="muted church-landing__crm-link"><a href="' . e('/' . rawurlencode((string) $church['slug']) . '/login') . '">Войти в CRM</a></p>';
    echo '</main>';

    $extraScripts = '';
    if ($mapReady) {
        $payload = json_encode([
            'lat' => $landing['address_lat'],
            'lng' => $landing['address_lng'],
            'title' => (string) ($church['name'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $extraScripts = '<div hidden data-church-landing-map data-map-key="' . e($mapKey) . '" data-point="' . e((string) $payload) . '"></div>';
    }
    public_page_footer(['extra_scripts' => $extraScripts]);
}

/**
 * @param array<string, mixed> $landing
 * @param array<string, mixed> $church
 * @param array{map_key?:string,map_ready?:bool} $options
 */
function church_landing_render_section(string $id, array $landing, array $church, array $options = []): void
{
    $labels = church_landing_section_labels();
    echo '<section class="church-landing__section church-landing__section--' . e($id) . '" id="landing-' . e($id) . '">';

    if ($id === 'hero') {
        $mark = trim((string) ($landing['logo'] ?? ''));
        if ($mark === '' && function_exists('brand_platform_default_mark_asset')) {
            $mark = (string) (brand_platform_default_mark_asset() ?? '');
        }
        if ($mark !== '') {
            $src = function_exists('brand_mark_img_src')
                ? brand_mark_img_src($church, $mark)
                : $mark;
            echo '<p class="church-landing__logo"><img class="church-landing__logo-img" src="' . e($src) . '" alt="" width="200" height="200" decoding="async"></p>';
        }
        echo '<h1 class="church-landing__title">' . e((string) $church['name']) . '</h1>';
        $city = trim((string) ($church['city'] ?? ''));
        if ($city !== '' && $city !== (string) $church['name']) {
            echo '<p class="muted church-landing__city">' . e($city) . '</p>';
        }
        echo '</section>';

        return;
    }

    if ($id !== 'buttons' && $id !== 'donation') {
        echo '<h2>' . e($labels[$id] ?? $id) . '</h2>';
    }

    if ($id === 'gallery') {
        echo '<div class="church-landing__gallery">';
        foreach ($landing['gallery'] as $item) {
            echo '<figure class="church-landing__gallery-item"><img src="' . e(church_landing_media_public_url($item['src'])) . '" alt="' . e($item['alt']) . '" loading="lazy"></figure>';
        }
        echo '</div></section>';

        return;
    }

    if ($id === 'description') {
        echo '<div class="church-landing__prose">' . nl2br(e((string) $landing['description'])) . '</div></section>';

        return;
    }

    if ($id === 'buttons') {
        echo '<div class="church-landing__buttons">';
        foreach ($landing['buttons'] as $button) {
            $resolved = church_landing_resolve_button($button, $church);
            if ($resolved === null) {
                continue;
            }
            $style = $resolved['style'] !== '' ? ' style="' . e($resolved['style']) . '"' : '';
            $rel = str_starts_with($resolved['href'], 'http') ? ' target="_blank" rel="noopener"' : '';
            echo '<a class="' . e($resolved['class']) . '" href="' . e($resolved['href']) . '"' . $style . $rel . '>'
                . $resolved['icon_html']
                . '<span>' . e($resolved['label']) . '</span></a>';
        }
        echo '</div></section>';

        return;
    }

    if ($id === 'pastors') {
        echo '<div class="church-landing__pastors">';
        foreach ($landing['pastors'] as $pastor) {
            echo '<article class="church-landing__pastor">';
            if ($pastor['photo'] !== '') {
                echo '<img class="church-landing__pastor-photo" src="' . e(church_landing_media_public_url($pastor['photo'])) . '" alt="' . e($pastor['name']) . '" loading="lazy">';
            }
            echo '<div><h3>' . e($pastor['name']) . '</h3>';
            if ($pastor['description'] !== '') {
                echo '<div class="church-landing__prose">' . nl2br(e($pastor['description'])) . '</div>';
            }
            echo '</div></article>';
        }
        echo '</div></section>';

        return;
    }

    if ($id === 'contacts') {
        echo '<ul class="church-landing__contacts">';
        foreach (church_landing_resolve_contacts($landing['contacts']) as $contact) {
            echo '<li><span class="muted">' . e($contact['label']) . '</span> <a href="' . e($contact['href']) . '">' . e($contact['display']) . '</a></li>';
        }
        echo '</ul></section>';

        return;
    }

    if ($id === 'address') {
        $addressText = trim((string) ($landing['address_text'] ?? ''));
        if ($addressText !== '') {
            echo '<p class="church-landing__address">' . e($addressText) . '</p>';
        }
        if (!empty($options['map_ready']) && $addressText !== '') {
            echo '<div class="church-landing__map" data-church-landing-map-host></div>';
        }
        $route = church_landing_navi_route_url($landing['address_lat'], $landing['address_lng'], $landing['address_text']);
        if ($route !== '') {
            echo '<p><a class="primary church-landing-btn" href="' . e($route) . '" target="_blank" rel="noopener">Проложить маршрут в Яндекс.Навигаторе</a></p>';
        }
        $howToGet = trim((string) ($landing['how_to_get'] ?? ''));
        if ($howToGet !== '') {
            echo '<h3 class="church-landing__subheading">Как добраться</h3>';
            echo '<div class="church-landing__prose">' . nl2br(e($howToGet)) . '</div>';
        }
        echo '</section>';

        return;
    }

    if ($id === 'schedule') {
        $weekdayLabels = church_landing_weekday_labels();
        echo '<ul class="church-landing__list">';
        foreach ($landing['schedule'] as $item) {
            $time = trim($item['time_from'] . ($item['time_to'] !== '' ? '–' . $item['time_to'] : ''));
            echo '<li><strong>' . e($weekdayLabels[$item['weekday']] ?? '') . '</strong>';
            if ($time !== '') {
                echo ' <span class="muted">' . e($time) . '</span>';
            }
            echo '<div>' . e($item['title']) . '</div>';
            if ($item['note'] !== '') {
                echo '<p class="muted">' . e($item['note']) . '</p>';
            }
            echo '</li>';
        }
        echo '</ul></section>';

        return;
    }

    if ($id === 'events') {
        $events = church_landing_upcoming_events($landing['events'], $church['timezone'] ?? null);
        echo '<ul class="church-landing__list">';
        foreach ($events as $item) {
            $time = trim($item['time_from'] . ($item['time_to'] !== '' ? '–' . $item['time_to'] : ''));
            echo '<li><strong>' . e($item['date']) . '</strong>';
            if ($time !== '') {
                echo ' <span class="muted">' . e($time) . '</span>';
            }
            echo '<div>' . e($item['title']) . '</div>';
            if ($item['note'] !== '') {
                echo '<p class="muted">' . e($item['note']) . '</p>';
            }
            echo '</li>';
        }
        echo '</ul></section>';

        return;
    }

    if ($id === 'ministries') {
        echo '<ul class="church-landing__ministries">';
        foreach ($landing['ministries'] as $item) {
            echo '<li>';
            $url = trim((string) ($item['url'] ?? ''));
            if ($url !== '') {
                echo '<a class="church-landing__ministries-title" href="' . e($url) . '" target="_blank" rel="noopener">' . e($item['title']) . '</a>';
            } else {
                echo '<span class="church-landing__ministries-title">' . e($item['title']) . '</span>';
            }
            if ($item['description'] !== '') {
                echo '<div class="church-landing__prose">' . nl2br(e($item['description'])) . '</div>';
            }
            echo '</li>';
        }
        echo '</ul></section>';

        return;
    }

    if ($id === 'faq') {
        echo '<div class="church-landing__faq">';
        foreach ($landing['faq'] as $item) {
            echo '<details class="church-landing__faq-item"><summary>' . e($item['question']) . '</summary>';
            echo '<div class="church-landing__prose">' . nl2br(e($item['answer'])) . '</div></details>';
        }
        echo '</div></section>';

        return;
    }

    if ($id === 'donation') {
        $label = trim((string) ($landing['donation_label'] ?? '')) ?: 'Пожертвования';
        echo '<div class="church-landing__buttons"><a class="church-landing-btn church-landing-btn--primary" href="' . e((string) $landing['donation_url']) . '" target="_blank" rel="noopener">' . e($label) . '</a></div></section>';

        return;
    }

    echo '</section>';
}
