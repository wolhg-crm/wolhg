<?php

declare(strict_types=1);

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

require_once __DIR__ . '/../../src/Features.php';

return [
    'person change labels cover registered card fields' => function (TestCase $test): void {
        $test->assertSame('Телефон', person_change_field_label('phone'));
        $test->assertSame('Дата рождения', person_change_field_label('birth_date'));
        $test->assertSame('Семейное положение', person_change_field_label('marital_status'));
        $test->assertSame('Первое посещение', person_change_field_label('first_visit_date'));
        $test->assertSame('Покаяние', person_change_field_label('repentance_date'));
        $test->assertSame('Членство', person_change_field_label('membership_date'));
        $test->assertSame('Особые заметки', person_change_field_label('sensitive_notes_encrypted'));
        $test->assertSame('Духовная воронка', person_change_field_label('spiritual_stage_id'));
    },

    'marital status options are fixed and select markup uses them' => function (TestCase $test): void {
        $options = marital_status_options();
        $test->assertSame(['женат', 'замужем', 'не женат', 'не замужем'], $options);

        $choices = marital_status_choices('в разводе');
        $test->assertTrue(in_array('в разводе', $choices, true));
        $test->assertSame($options, array_slice($choices, 0, count($options)));

        $select = marital_status_select_markup('marital_status', 'женат');
        $test->assertStringContains('<select name="marital_status">', $select);
        $test->assertStringContains('<option value="">—</option>', $select);
        $test->assertStringContains('<option value="женат" selected>женат</option>', $select);
        $test->assertStringContains('<option value="замужем">замужем</option>', $select);
        $test->assertStringContains('<option value="не женат">не женат</option>', $select);
        $test->assertStringContains('<option value="не замужем">не замужем</option>', $select);

        $legacy = marital_status_select_markup('marital_status', 'в разводе');
        $test->assertStringContains('<option value="в разводе" selected>в разводе</option>', $legacy);
    },

    'person pastoral dates parse and clear' => function (TestCase $test): void {
        $ok = parse_person_field_value('birth_date', '1990-03-15');
        $test->assertTrue($ok['ok']);
        $test->assertSame('1990-03-15', $ok['value']);

        $cleared = parse_person_field_value('membership_date', '');
        $test->assertTrue($cleared['ok']);
        $test->assertSame(null, $cleared['value']);

        $bad = parse_person_field_value('repentance_date', '31-12-2020');
        $test->assertFalse($bad['ok']);

        $status = parse_person_field_value('marital_status', 'женат');
        $test->assertTrue($status['ok']);
        $test->assertSame('женат', $status['value']);

        $clearedStatus = parse_person_field_value('marital_status', '');
        $test->assertTrue($clearedStatus['ok']);
        $test->assertSame(null, $clearedStatus['value']);

        $invalidStatus = parse_person_field_value('marital_status', 'в разводе');
        $test->assertFalse($invalidStatus['ok']);
        $test->assertStringContains('семейное положение', mb_strtolower($invalidStatus['error']));
    },

    'sensitive change values are masked in history' => function (TestCase $test): void {
        $test->assertSame('[обновлено]', format_person_change_value('sensitive_notes_encrypted', 'encrypted-payload'));
        $test->assertSame('—', format_person_change_value('sensitive_notes_encrypted', ''));
    },

    'spiritual stage names resolve in change history' => function (TestCase $test): void {
        $names = [3 => 'Членство'];
        $test->assertSame('Членство', format_person_change_value('spiritual_stage_id', '3', $names));
        $test->assertSame('—', format_person_change_value('spiritual_stage_id', '', $names));
    },
];
