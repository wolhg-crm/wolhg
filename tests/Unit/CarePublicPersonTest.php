<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Care.php';

return [
    'care_strip_sensitive_person_fields drops ciphertext and unknown keys' => function (TestCase $test): void {
        $row = care_strip_sensitive_person_fields([
            'id' => 5,
            'full_name' => 'Иван Иванов',
            'phone' => '+79991234567',
            'notes' => 'обычная заметка',
            'sensitive_notes_encrypted' => 'CIPHERTEXT',
            'sensitive_notes' => 'секрет',
            'care_level' => 'red',
            'password_hash' => 'should-not-leak',
        ]);

        $test->assertSame(5, $row['id']);
        $test->assertSame('Иван Иванов', $row['full_name']);
        $test->assertSame('обычная заметка', $row['notes']);
        $test->assertSame('red', $row['care_level']);
        $test->assertFalse(array_key_exists('sensitive_notes_encrypted', $row));
        $test->assertFalse(array_key_exists('sensitive_notes', $row));
        $test->assertFalse(array_key_exists('password_hash', $row));
    },
];
