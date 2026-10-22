<?php

declare(strict_types=1);

/**
 * CRM constructor UI and POST handlers for church landing.
 */

/**
 * Media control: external image URL and/or file upload (no “path” wording in UI).
 *
 * @param array{hint?:string,upload_label?:string,preview_html?:string} $options
 */
function church_landing_editor_media_field_html(
    string $name,
    string $value,
    string $uploadKind,
    array $options = []
): string {
    $hint = trim((string) ($options['hint'] ?? 'Вставьте ссылку на картинку или загрузите файл с устройства.'));
    $uploadLabel = trim((string) ($options['upload_label'] ?? 'Загрузить'));
    if ($uploadLabel === '') {
        $uploadLabel = 'Загрузить';
    }
    $previewHtml = (string) ($options['preview_html'] ?? '');

    $html = '<div class="landing-media-field" data-landing-media-block="' . e($uploadKind) . '">';
    $html .= '<div class="landing-media-field__row">';
    $html .= '<label class="landing-media-field__url">URL';
    $html .= '<input name="' . e($name) . '" value="' . e($value) . '" placeholder="https://…" autocomplete="off" spellcheck="false" inputmode="url" data-landing-media-field="' . e($uploadKind) . '">';
    $html .= '</label>';
    $html .= '<button type="button" class="ghost landing-media-field__upload" data-landing-upload="' . e($uploadKind) . '">' . e($uploadLabel) . '</button>';
    $html .= '</div>';
    if ($hint !== '') {
        $html .= '<p class="muted landing-media-field__hint">' . e($hint) . '</p>';
    }
    if ($previewHtml !== '') {
        $html .= $previewHtml;
    }
    $html .= '</div>';

    return $html;
}

/**
 * @param array<string, mixed> $post
 * @return array<string, mixed>
 */
function church_landing_from_post(array $post, int $churchId): array
{
    $landing = church_landing_empty($churchId);
    $landing['seo_title'] = trim((string) ($post['seo_title'] ?? '')) ?: null;
    $landing['seo_description'] = trim((string) ($post['seo_description'] ?? '')) ?: null;
    $landing['og_image'] = church_landing_normalize_media_src($post['og_image'] ?? null);
    $landing['logo'] = church_landing_normalize_media_src($post['logo'] ?? null);
    $landing['description'] = trim((string) ($post['description'] ?? '')) ?: null;
    $landing['how_to_get'] = trim((string) ($post['how_to_get'] ?? '')) ?: null;
    $landing['address_text'] = trim((string) ($post['address_text'] ?? '')) ?: null;
    $landing['donation_label'] = trim((string) ($post['donation_label'] ?? '')) ?: null;
    $landing['donation_url'] = church_landing_sanitize_http_url($post['donation_url'] ?? null);
    $landing['contacts'] = church_landing_normalize_contacts([
        'phone' => $post['contact_phone'] ?? '',
        'email' => $post['contact_email'] ?? '',
        'max' => $post['contact_max'] ?? '',
        'telegram' => $post['contact_telegram'] ?? '',
        'whatsapp' => $post['contact_whatsapp'] ?? '',
    ]);

    $sectionsPayload = church_landing_decode_json((string) ($post['sections_json'] ?? ''));
    if ($sectionsPayload === null && isset($post['sections']) && is_array($post['sections'])) {
        $sectionsPayload = $post['sections'];
    }
    $landing['sections'] = church_landing_normalize_sections($sectionsPayload);

    foreach (['gallery', 'pastors', 'schedule', 'events', 'ministries', 'faq', 'buttons'] as $key) {
        $jsonKey = $key . '_json';
        $decoded = church_landing_decode_json((string) ($post[$jsonKey] ?? ''));
        if ($decoded === null && isset($post[$key]) && is_array($post[$key])) {
            $decoded = $post[$key];
        }
        $landing[$key] = match ($key) {
            'gallery' => church_landing_normalize_gallery($decoded),
            'pastors' => church_landing_normalize_pastors($decoded),
            'schedule' => church_landing_normalize_schedule($decoded),
            'events' => church_landing_normalize_events($decoded),
            'ministries' => church_landing_normalize_ministries($decoded),
            'faq' => church_landing_normalize_faq($decoded),
            'buttons' => church_landing_normalize_buttons($decoded),
            default => [],
        };
    }

    if ($landing['buttons'] === []) {
        $landing['buttons'] = church_landing_default_buttons();
    }

    $previousLat = isset($post['_address_lat']) ? (float) $post['_address_lat'] : null;
    $previousLng = isset($post['_address_lng']) ? (float) $post['_address_lng'] : null;
    $address = trim((string) ($landing['address_text'] ?? ''));
    if ($address === '') {
        $landing['address_lat'] = null;
        $landing['address_lng'] = null;
    } elseif (function_exists('yandex_geocode')) {
        $geo = yandex_geocode($address);
        if (is_array($geo)) {
            $lat = $geo['latitude'] ?? $geo['lat'] ?? null;
            $lng = $geo['longitude'] ?? $geo['lon'] ?? $geo['lng'] ?? null;
            if ($lat !== null && $lng !== null) {
                $landing['address_lat'] = (float) $lat;
                $landing['address_lng'] = (float) $lng;
            } else {
                $landing['address_lat'] = $previousLat;
                $landing['address_lng'] = $previousLng;
            }
        } else {
            $landing['address_lat'] = $previousLat;
            $landing['address_lng'] = $previousLng;
        }
    } else {
        $landing['address_lat'] = $previousLat;
        $landing['address_lng'] = $previousLng;
    }

    return $landing;
}

/**
 * @param array<string, mixed> $landing
 * @param array<string, mixed> $church
 */
function church_landing_editor_panel_body_html(string $sectionId, array $landing, array $church): string
{
    $hints = church_landing_section_hints();
    $hint = (string) ($hints[$sectionId] ?? '');
    $html = '<div class="landing-editor-panel" data-landing-content-panel="' . e($sectionId) . '">';

    if ($sectionId === 'hero') {
        $html .= '<p class="muted">' . e($hint) . '</p>';
        $html .= '<p class="muted">Логотип задаётся в блоке «Общие настройки · Логотип» выше. Название и город — в <a href="' . e(app_path('/church-settings')) . '">настройках церкви</a>.</p>';
        $html .= '</div>';

        return $html;
    }

    if ($sectionId === 'description') {
        $html .= '<p class="muted">' . e($hint) . '</p>';
        $html .= '<label>Текст о церкви<textarea name="description" rows="6">' . e((string) ($landing['description'] ?? '')) . '</textarea></label>';
        $html .= '</div>';

        return $html;
    }

    if ($sectionId === 'gallery') {
        $html .= '<p class="muted">' . e($hint) . ' До 6 фото.</p>';
        $html .= '<div data-landing-gallery></div>';
        $html .= '<button type="button" class="ghost" data-landing-add="gallery">Добавить фото</button>';
        $html .= '</div>';

        return $html;
    }

    if ($sectionId === 'buttons') {
        $html .= '<p class="muted">' . e($hint) . '</p>';
        $html .= '<div data-landing-buttons></div><div class="quick-actions">';
        $html .= '<button type="button" class="ghost" data-landing-add="button-builtin">Системная кнопка</button>';
        $html .= '<button type="button" class="ghost" data-landing-add="button-social">Соцсеть</button>';
        $html .= '<button type="button" class="ghost" data-landing-add="button-custom">Своя ссылка</button></div>';
        $html .= '</div>';

        return $html;
    }

    if ($sectionId === 'pastors') {
        $html .= '<p class="muted">' . e($hint) . '</p>';
        $html .= '<div data-landing-pastors></div>';
        $html .= '<button type="button" class="ghost" data-landing-add="pastor">Добавить пастора</button>';
        $html .= '</div>';

        return $html;
    }

    if ($sectionId === 'contacts') {
        $contacts = is_array($landing['contacts'] ?? null) ? $landing['contacts'] : church_landing_normalize_contacts([]);
        $html .= '<p class="muted">' . e($hint) . '</p>';
        $html .= '<div class="form-grid">';
        $html .= '<label>Телефон<input name="contact_phone" value="' . e((string) ($contacts['phone'] ?? '')) . '"></label>';
        $html .= '<label>Email<input name="contact_email" type="email" value="' . e((string) ($contacts['email'] ?? '')) . '"></label>';
        $html .= '<label>Telegram<input name="contact_telegram" value="' . e((string) ($contacts['telegram'] ?? '')) . '" placeholder="@username или ссылка"></label>';
        $html .= '<label>Max<input name="contact_max" value="' . e((string) ($contacts['max'] ?? '')) . '" placeholder="@username или ссылка"></label>';
        $html .= '<label>WhatsApp<input name="contact_whatsapp" value="' . e((string) ($contacts['whatsapp'] ?? '')) . '" placeholder="номер телефона"></label>';
        $html .= '</div></div>';

        return $html;
    }

    if ($sectionId === 'address') {
        $html .= '<p class="muted">' . e($hint) . '</p>';
        $html .= '<label>Адрес<textarea name="address_text" rows="2">' . e((string) ($landing['address_text'] ?? '')) . '</textarea></label>';
        if (($landing['address_lat'] ?? null) !== null && ($landing['address_lng'] ?? null) !== null) {
            $html .= '<input type="hidden" name="_address_lat" value="' . e((string) $landing['address_lat']) . '">';
            $html .= '<input type="hidden" name="_address_lng" value="' . e((string) $landing['address_lng']) . '">';
            $html .= '<p class="muted">Координаты сохранены — карта появится на лендинге.</p>';
        } else {
            $html .= '<p class="muted">При сохранении адрес геокодируется для карты и маршрута.</p>';
        }
        $html .= '<label>Как добраться<textarea name="how_to_get" rows="4" placeholder="Ориентиры, этаж, вход…">' . e((string) ($landing['how_to_get'] ?? '')) . '</textarea></label>';
        $html .= '</div>';

        return $html;
    }

    if ($sectionId === 'schedule') {
        $html .= '<p class="muted">' . e($hint) . '</p>';
        $html .= '<div data-landing-schedule></div>';
        $html .= '<button type="button" class="ghost" data-landing-add="schedule">Добавить пункт</button>';
        $html .= '</div>';

        return $html;
    }

    if ($sectionId === 'events') {
        $html .= '<p class="muted">' . e($hint) . '</p>';
        $html .= '<div data-landing-events></div>';
        $html .= '<button type="button" class="ghost" data-landing-add="event">Добавить событие</button>';
        $html .= '</div>';

        return $html;
    }

    if ($sectionId === 'ministries') {
        $html .= '<p class="muted">' . e($hint) . '</p>';
        $html .= '<div data-landing-ministries></div>';
        $html .= '<button type="button" class="ghost" data-landing-add="ministry">Добавить направление</button>';
        $html .= '</div>';

        return $html;
    }

    if ($sectionId === 'faq') {
        $html .= '<p class="muted">' . e($hint) . '</p>';
        $html .= '<div data-landing-faq></div>';
        $html .= '<button type="button" class="ghost" data-landing-add="faq">Добавить вопрос</button>';
        $html .= '</div>';

        return $html;
    }

    if ($sectionId === 'donation') {
        $html .= '<p class="muted">' . e($hint) . '</p>';
        $html .= '<div class="form-grid">';
        $html .= '<label>Текст кнопки<input name="donation_label" value="' . e((string) ($landing['donation_label'] ?? '')) . '" placeholder="Пожертвования"></label>';
        $html .= '<label>Ссылка<input name="donation_url" value="' . e((string) ($landing['donation_url'] ?? '')) . '" placeholder="https://..."></label>';
        $html .= '</div></div>';

        return $html;
    }

    $html .= '<p class="muted">Нет формы для этого блока.</p></div>';

    return $html;
}

/**
 * One landing block card: order + visibility + content editor together.
 *
 * @param array{id:string,visible:bool,sort?:int} $section
 * @param array<string, mixed> $landing
 * @param array<string, mixed> $church
 */
function church_landing_editor_section_card_html(array $section, array $landing, array $church, int $index): string
{
    $id = (string) ($section['id'] ?? '');
    $visible = !empty($section['visible']);
    $labels = church_landing_section_labels();
    $hints = church_landing_section_hints();
    $title = (string) ($labels[$id] ?? $id);
    $hint = (string) ($hints[$id] ?? '');
    $classes = 'landing-editor-block' . ($visible ? '' : ' is-hidden-on-landing');

    $html = '<article class="' . e($classes) . '" data-landing-section-id="' . e($id) . '" data-landing-drag-index="' . (int) $index . '">';
    $html .= '<div class="landing-editor-block__head">';
    $html .= '<button type="button" class="church-landing-editor__handle" data-landing-drag-handle="1" aria-label="Переместить" title="Перетащите, чтобы изменить порядок">⋮⋮</button>';
    $html .= '<div class="landing-editor-section-title"><strong>' . e($title) . '</strong>';
    if ($hint !== '') {
        $html .= '<p class="muted landing-editor-section-hint">' . e($hint) . '</p>';
    }
    $html .= '</div>';
    $html .= '<span class="landing-editor-badge ' . ($visible ? 'landing-editor-badge--on' : 'landing-editor-badge--off') . '" data-landing-section-badge>' . ($visible ? 'На лендинге' : 'Скрыта') . '</span>';
    $html .= '<label class="landing-editor-toggle"><input type="checkbox" data-landing-section-visible value="1"' . ($visible ? ' checked' : '') . '> Показывать</label>';
    $html .= '<div class="landing-editor-row-actions quick-actions">';
    $html .= '<button type="button" class="ghost" data-landing-section-up title="Выше" aria-label="Переместить выше">↑</button>';
    $html .= '<button type="button" class="ghost" data-landing-section-down title="Ниже" aria-label="Переместить ниже">↓</button>';
    $html .= '</div></div>';
    $html .= '<div class="landing-editor-block__body">';
    $html .= church_landing_editor_panel_body_html($id, $landing, $church);
    $html .= '</div></article>';

    return $html;
}

function church_landing_editor_page(array $user): void
{
    require_action($user, 'manage_church_page');
    $churchId = current_church_id() ?? (int) ($user['church_id'] ?? 0);
    $church = $churchId > 0 ? find_church_by_id($churchId) : null;
    if (!$church || !user_can_access_church($user, (int) $church['id'])) {
        http_response_code(404);
        exit('Церковь не найдена');
    }

    $landing = church_landing_load((int) $church['id']);
    $aboutPath = public_church_about_path($church);
    $uploadUrl = app_path('/church-page-image-upload');
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
    $socialJson = json_encode(church_landing_social_networks(), $jsonFlags);
    $builtinJson = json_encode(church_landing_builtin_button_kinds(), $jsonFlags);
    $weekdayJson = json_encode(church_landing_weekday_labels(), $jsonFlags);
    $landingJson = json_encode($landing, $jsonFlags);
    $sectionLabelsJson = json_encode(church_landing_section_labels(), $jsonFlags);
    $sectionHintsJson = json_encode(church_landing_section_hints(), $jsonFlags);

    page_header('Страница церкви', $user);
    echo '<section class="panel church-landing-editor" data-church-landing-editor>';
    echo '<div class="panel-head"><div><h2>Конструктор публичной страницы</h2>';
    echo '<p class="muted">Гости видят страницу по адресу <code>' . e($aboutPath) . '</code>. Название и город церкви меняются в настройках церкви.</p></div>';
    echo '<div class="quick-actions"><a class="ghost" href="' . e($aboutPath) . '" target="_blank" rel="noopener">Открыть лендинг</a>';
    echo '<a class="ghost" href="' . e(app_path('/church-settings')) . '">Настройки церкви</a></div></div>';

    echo '<div class="landing-editor-guide panel" data-landing-guide>';
    echo '<h3>Как пользоваться</h3>';
    echo '<ol class="landing-editor-guide__list">';
    echo '<li>Сверху — <strong>общие настройки</strong>: логотип и SEO (не секции лендинга).</li>';
    echo '<li>Ниже каждый <strong>блок лендинга</strong> — это одна карточка: содержимое, «Показывать» и порядок (⋮⋮ или ↑/↓).</li>';
    echo '<li>Отдельного списка «порядок и видимость» нет — всё на самих блоках.</li>';
    echo '<li>Пустые блоки гости не увидят. В конце нажмите <strong>Сохранить страницу</strong>.</li>';
    echo '</ol></div>';

    echo '<form method="post" action="' . e(app_path('/church-page-save')) . '" class="stack church-landing-editor__form" data-church-landing-form>';
    echo csrf_field();
    echo '<input type="hidden" name="sections_json" data-landing-sections-json value="">';
    echo '<input type="hidden" name="gallery_json" data-landing-gallery-json value="">';
    echo '<input type="hidden" name="pastors_json" data-landing-pastors-json value="">';
    echo '<input type="hidden" name="schedule_json" data-landing-schedule-json value="">';
    echo '<input type="hidden" name="events_json" data-landing-events-json value="">';
    echo '<input type="hidden" name="ministries_json" data-landing-ministries-json value="">';
    echo '<input type="hidden" name="faq_json" data-landing-faq-json value="">';
    echo '<input type="hidden" name="buttons_json" data-landing-buttons-json value="">';

    echo '<div hidden data-landing-bootstrap'
        . ' data-upload-url="' . e($uploadUrl) . '"'
        . ' data-social="' . e((string) $socialJson) . '"'
        . ' data-builtin="' . e((string) $builtinJson) . '"'
        . ' data-weekdays="' . e((string) $weekdayJson) . '"'
        . ' data-section-labels="' . e((string) $sectionLabelsJson) . '"'
        . ' data-section-hints="' . e((string) $sectionHintsJson) . '"></div>';
    echo '<script type="application/json" data-landing-payload>' . $landingJson . '</script>';

    echo '<article class="panel" id="landing-editor-logo"><h3>Общие настройки · Логотип</h3>';
    echo '<p class="muted">Показывается в шапке лендинга и в CRM этой церкви. Если не задан — логотип платформы по умолчанию.</p>';
    $logoPreview = '';
    if (!empty($landing['logo'])) {
        $logoPreview = '<p class="landing-media-field__preview"><img class="brand-mark" src="' . e(brand_mark_img_src($church, (string) $landing['logo'])) . '" alt="" width="64" height="64"></p>';
    }
    echo church_landing_editor_media_field_html('logo', (string) ($landing['logo'] ?? ''), 'logo', [
        'upload_label' => 'Загрузить',
        'preview_html' => $logoPreview,
    ]);
    echo '</article>';

    echo '<article class="panel" id="landing-editor-seo"><h3>Общие настройки · SEO и шаринг</h3><div class="form-grid">';
    echo '<label>Title<input name="seo_title" maxlength="190" value="' . e((string) ($landing['seo_title'] ?? '')) . '" placeholder="' . e(church_landing_seo_title($landing, $church)) . '"></label>';
    echo '</div>';
    echo church_landing_editor_media_field_html('og_image', (string) ($landing['og_image'] ?? ''), 'og_image', [
        'upload_label' => 'Загрузить',
        'hint' => 'Картинка для превью в мессенджерах и соцсетях: URL или загрузка файла.',
    ]);
    echo '<label>Description<textarea name="seo_description" rows="3">' . e((string) ($landing['seo_description'] ?? '')) . '</textarea></label>';
    echo '</article>';

    echo '<div class="landing-editor-workspace-head">';
    echo '<h3>Блоки лендинга</h3>';
    echo '<p class="muted">Редактируйте содержимое прямо в карточке. Порядок карточек = порядок на публичной странице.</p>';
    echo '</div>';
    echo '<div class="landing-editor-workspace" data-landing-section-workspace>';
    $sections = is_array($landing['sections'] ?? null) ? $landing['sections'] : church_landing_default_sections();
    foreach (array_values($sections) as $index => $section) {
        if (!is_array($section)) {
            continue;
        }
        echo church_landing_editor_section_card_html($section, $landing, $church, (int) $index);
    }
    echo '</div>';

    echo '<div class="church-landing-editor__sticky"><button class="primary" type="submit">Сохранить страницу</button>';
    echo '<a class="ghost" href="' . e($aboutPath) . '" target="_blank" rel="noopener">Открыть лендинг</a></div>';
    echo '</form>';
    echo '<input type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml,.svg" hidden data-landing-file-input>';
    echo '</section>';
    page_footer();
}

function handle_church_landing_post(string $path, array $user): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return false;
    }

    if ($path === '/church-page-image-upload') {
        verify_csrf();
        if (!can_action($user, 'manage_church_page')) {
            json_response(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }
        $file = $_FILES['image'] ?? null;
        if (!is_array($file)) {
            json_response(['ok' => false, 'error' => 'Файл не выбран'], 422);
        }
        $stored = church_landing_store_uploaded_image($file);
        if (!$stored['ok']) {
            json_response(['ok' => false, 'error' => $stored['error']], 422);
        }
        audit('church_landing_image_upload', 'church', current_church_id(), ['path' => $stored['path']]);
        json_response(['ok' => true, 'path' => $stored['path'], 'message' => 'Картинка загружена']);
    }

    if ($path === '/church-page-save') {
        verify_csrf();
        require_action($user, 'manage_church_page');
        $churchId = current_church_id() ?? (int) ($user['church_id'] ?? 0);
        $church = $churchId > 0 ? find_church_by_id($churchId) : null;
        if (!$church || !user_can_access_church($user, (int) $church['id'])) {
            ajax_redirect('/church-page', 'Церковь не найдена');
        }
        $landing = church_landing_from_post($_POST, (int) $church['id']);
        church_landing_save((int) $church['id'], $landing);
        audit('church_landing_save', 'church', (int) $church['id']);
        ajax_redirect('/church-page', 'Страница церкви сохранена');
    }

    return false;
}
