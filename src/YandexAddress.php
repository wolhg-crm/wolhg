<?php

declare(strict_types=1);

function yandex_address_trim_text(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    $text = trim((string) $value);
    return preg_replace('/\s+/u', ' ', $text) ?: '';
}

/**
 * @param array<string, mixed> $source
 * @param list<string> $keys
 */
function yandex_address_first_text(array $source, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $source) || !is_scalar($source[$key])) {
            continue;
        }
        $text = yandex_address_trim_text($source[$key]);
        if ($text !== '') {
            return $text;
        }
    }

    return '';
}

/**
 * @return list<array<string, mixed>>
 */
function yandex_address_collect_candidates(mixed $value): array
{
    if (is_scalar($value)) {
        $formatted = yandex_address_trim_text($value);
        return $formatted !== '' ? [['formatted' => $formatted]] : [];
    }

    if (!is_array($value)) {
        return [];
    }

    if (yandex_address_is_list($value)) {
        $items = [];
        foreach ($value as $item) {
            $items = array_merge($items, yandex_address_collect_candidates($item));
        }
        return $items;
    }

    foreach (['items', 'list', 'values'] as $key) {
        if (isset($value[$key]) && is_array($value[$key])) {
            $items = yandex_address_collect_candidates($value[$key]);
            if ($items !== []) {
                return $items;
            }
        }
    }

    return [$value];
}

/**
 * @param array<int|string, mixed> $value
 */
function yandex_address_is_list(array $value): bool
{
    if ($value === []) {
        return true;
    }

    return array_keys($value) === range(0, count($value) - 1);
}

function yandex_address_build_line(string $city, string $street, string $house, string $building, string $formatted): string
{
    if ($city === '' && $street === '' && $house === '') {
        return $formatted;
    }

    $housePart = $house;
    if ($building !== '') {
        $housePart = trim($housePart . ' к. ' . $building);
    }

    $parts = array_values(array_filter([$city, $street, $housePart], static fn (string $part): bool => $part !== ''));
    return $parts !== [] ? implode(', ', $parts) : $formatted;
}

function yandex_address_build_label(string $line, string $apartment, string $formatted): string
{
    $label = $line !== '' ? $line : $formatted;
    if ($label !== '' && $apartment !== '') {
        $label .= ', кв. ' . $apartment;
    }

    return $label;
}

/**
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function yandex_address_normalize_one(array $source): array
{
    foreach (['address', 'delivery_address', 'shipping_address'] as $wrapper) {
        if (isset($source[$wrapper]) && is_array($source[$wrapper])) {
            $source = array_merge($source, $source[$wrapper]);
        }
    }

    $city = yandex_address_first_text($source, ['city', 'locality', 'settlement', 'town']);
    $street = yandex_address_first_text($source, ['street', 'street_name', 'thoroughfare']);
    $house = yandex_address_first_text($source, ['house', 'house_number', 'home', 'building_number', 'premise']);
    $building = yandex_address_first_text($source, ['building', 'block', 'corpus', 'housing', 'structure']);
    $formatted = yandex_address_first_text($source, ['formatted', 'address_1', 'address_line', 'full_address', 'full', 'display_name', 'value']);
    $line = yandex_address_build_line($city, $street, $house, $building, $formatted);
    $apartment = yandex_address_first_text($source, ['apartment', 'flat', 'room', 'office', 'kv']);
    $district = yandex_address_first_text($source, ['district', 'area', 'region', 'state', 'province', 'administrative_area']);

    if ($line === '' && $formatted === '' && $apartment === '' && $district === '') {
        return [];
    }

    $address = [
        'label' => yandex_address_build_label($line, $apartment, $formatted),
        'formatted' => $line !== '' ? $line : $formatted,
        'district' => $district,
        'apartment' => $apartment,
    ];

    foreach ($address as $key => $value) {
        if ($value === '') {
            unset($address[$key]);
        }
    }

    return $address;
}

/**
 * @param list<array<string, mixed>> $addresses
 * @return list<array<string, mixed>>
 */
function yandex_address_sanitize_list(array $addresses): array
{
    $result = [];
    $seen = [];

    foreach ($addresses as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $address = yandex_address_normalize_one($candidate);
        if ($address === []) {
            continue;
        }

        $dedupeKey = implode('|', [
            (string) ($address['formatted'] ?? ''),
            (string) ($address['apartment'] ?? ''),
            (string) ($address['district'] ?? ''),
        ]);
        if (isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;

        if (($address['label'] ?? '') === '') {
            $address['label'] = 'Адрес ' . (count($result) + 1);
        }
        $result[] = $address;
    }

    return $result;
}

/**
 * @param array<string, mixed> $data
 * @return list<array<string, mixed>>
 */
function yandex_address_normalize_profile(array $data): array
{
    $raw = [];
    foreach (['addresses', 'address', 'default_address', 'delivery_addresses', 'delivery_address', 'shipping_addresses', 'shipping_address'] as $key) {
        if (array_key_exists($key, $data)) {
            $raw = array_merge($raw, yandex_address_collect_candidates($data[$key]));
        }
    }

    return yandex_address_sanitize_list($raw);
}

/**
 * @param array<string, mixed> $address
 */
function yandex_address_formatted_line(array $address): string
{
    $line = yandex_address_trim_text($address['formatted'] ?? '');
    if ($line === '') {
        $line = yandex_address_trim_text($address['label'] ?? '');
    }

    $apartment = yandex_address_trim_text($address['apartment'] ?? '');
    if ($line !== '' && $apartment !== '' && !str_contains(mb_strtolower($line), 'кв')) {
        $line .= ', кв. ' . $apartment;
    }

    return mb_substr($line, 0, 255);
}

/**
 * @param list<array<string, mixed>>|null $addresses
 * @return list<array<string, mixed>>
 */
function yandex_address_decode_stored(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    return yandex_address_sanitize_list($decoded);
}
