<?php

declare(strict_types=1);

function application_runs_in_docker(): bool
{
    return is_file('/.dockerenv');
}

function resolve_db_host(string $host): string
{
    if ($host !== 'db') {
        return $host;
    }

    return application_runs_in_docker() ? 'db' : 'localhost';
}
