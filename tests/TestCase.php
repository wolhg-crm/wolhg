<?php

declare(strict_types=1);

final class TestCase
{
    private int $assertions = 0;

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->fail($message ?: 'Failed asserting that two values are identical.', $expected, $actual);
        }
    }

    public function assertTrue(bool $actual, string $message = ''): void
    {
        $this->assertSame(true, $actual, $message ?: 'Failed asserting true.');
    }

    public function assertFalse(bool $actual, string $message = ''): void
    {
        $this->assertSame(false, $actual, $message ?: 'Failed asserting false.');
    }

    public function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertSame(null, $actual, $message ?: 'Failed asserting null.');
    }

    public function assertNotNull(mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($actual === null) {
            throw new RuntimeException($message ?: 'Failed asserting that value is not null.');
        }
    }

    public function assertCount(int $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if (!is_countable($actual)) {
            throw new RuntimeException($message ?: 'Failed asserting count: value is not countable.');
        }
        $count = count($actual);
        if ($count !== $expected) {
            $this->fail($message ?: 'Failed asserting count.', $expected, $count);
        }
    }

    public function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException($message ?: sprintf('Failed asserting that string contains "%s".', $needle));
        }
    }

    public function assertMatches(string $pattern, string $subject, string $message = ''): void
    {
        $this->assertions++;
        if (preg_match($pattern, $subject) !== 1) {
            throw new RuntimeException($message ?: sprintf('Failed asserting that string matches "%s".', $pattern));
        }
    }

    public function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            throw new RuntimeException($message ?: sprintf('Failed asserting that string does not contain "%s".', $needle));
        }
    }

    public function assertFileExists(string $path, string $message = ''): void
    {
        $this->assertions++;
        if (!is_file($path)) {
            throw new RuntimeException($message ?: sprintf('Expected file to exist: %s', $path));
        }
    }

    public function assertions(): int
    {
        return $this->assertions;
    }

    private function fail(string $message, mixed $expected, mixed $actual): never
    {
        throw new RuntimeException($message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual:   ' . var_export($actual, true));
    }
}
