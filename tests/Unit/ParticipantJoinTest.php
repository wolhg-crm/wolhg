<?php

declare(strict_types=1);

return [
    'public join page uses required participant fields and consent' => function (TestCase $test): void {
        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');

        $test->assertStringContains('name="first_name"', $catalog);
        $test->assertStringContains('name="last_name"', $catalog);
        $test->assertStringContains('phone_input(', $catalog);
        $test->assertStringContains('telegram_input(', $catalog);
        $test->assertStringContains('name="group_id"', $catalog);
        $test->assertStringContains('name="email"', $catalog);
        $test->assertStringContains('name="consent"', $catalog);
        $test->assertStringContains('render_social_join_choice(', $catalog);
        $test->assertStringContains('manual-join-fields', $catalog);
        $test->assertStringNotContains('Заполнить вручную', $catalog);
        $social = file_get_contents(__DIR__ . '/../../src/Social.php');
        $test->assertStringContains("render_social_provider_button(\$provider, \$href, 'link')", $social);
        $test->assertStringContains('social_join_profile()', $catalog);
        $test->assertStringContains('source_provider', $catalog);
        $test->assertStringContains('source_external_id', $catalog);
        $test->assertStringContains('target_group_id', $catalog);
        $test->assertStringContains('consent_given', $catalog);
    },

    'public homegroup routes are available before login' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');

        $homegroupPos = strpos($index, "if (\$path === '/homegroup')");
        $joinPos = strpos($index, "if (\$path === '/homegroup/join')");
        $loginPos = strpos($index, '$user = require_login();');

        $test->assertTrue($homegroupPos !== false && $joinPos !== false && $loginPos !== false);
        $test->assertTrue($homegroupPos < $loginPos);
        $test->assertTrue($joinPos < $loginPos);
        $test->assertStringContains("redirect('/homegroup/join'", $index);
    },

    'participant requests schema includes join workflow columns' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/013_home_group_catalog.sql');

        $test->assertStringContains('first_name VARCHAR(100)', $schema);
        $test->assertStringContains('last_name VARCHAR(100)', $schema);
        $test->assertStringContains('target_group_id INT UNSIGNED', $schema);
        $test->assertStringContains('consent_given TINYINT(1)', $schema);
        $test->assertStringContains("source_provider ENUM('site','yandex','vk','max','telegram')", $schema);
        $test->assertStringContains("mode ENUM('login','link','join')", $schema);
        $test->assertStringContains('personal_data_consent_text TEXT', $schema);
        $test->assertStringContains('ALTER TABLE participant_requests', $migration);
        $socialJoinMigration = file_get_contents(__DIR__ . '/../../database/migrations/015_social_join_homegroup.sql');
        $test->assertStringContains("MODIFY mode ENUM('login','link','join')", $socialJoinMigration);
        $test->assertStringContains("MODIFY source_provider ENUM('site','yandex','vk','max')", $socialJoinMigration);
        $telegramBotMigration = file_get_contents(__DIR__ . '/../../database/migrations/039_bot_telegram_provider.sql');
        $test->assertStringContains("MODIFY source_provider ENUM('site','yandex','vk','max','telegram')", $telegramBotMigration);
    },

    'homegroup map assets include cluster initialization' => function (TestCase $test): void {
        $js = file_get_contents(__DIR__ . '/../../public/assets/app.js');
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');
        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');

        $test->assertStringContains('initHomegroupMap', $js);
        $test->assertStringContains('Clusterer', $js);
        $test->assertStringContains('[data-homegroup-map]', $js);
        $test->assertStringContains('data-homegroup-map', $catalog);
        $test->assertStringContains('.homegroup-map', $css);
    },
];
