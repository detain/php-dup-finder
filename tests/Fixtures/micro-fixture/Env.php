<?php
declare(strict_types=1);

// tests/Fixtures/micro-fixture/Env.php — the canonical single source of
// two one-line environment helpers (improve_logic.md Appendix A). Five
// sibling files in this fixture re-implement both bodies inline.

final class MicroFixtureEnv
{
    public static function isDev(): bool
    {
        return getenv('APP_ENV') === 'dev';
    }

    public static function baseUrl(): string
    {
        return getenv('APP_URL') === false ? 'http://localhost' : (string) getenv('APP_URL');
    }
}
