<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Platform;

use PHPUnit\Framework\TestCase;
use Sidus\DoctrineFilterBundle\Platform\SqliteRegexp;

final class SqliteRegexpTest extends TestCase
{
    public function testItMatchesUnanchoredAndCaseSensitively(): void
    {
        self::assertSame(1, SqliteRegexp::match('o.*d$', 'Hello World'));
        self::assertSame(0, SqliteRegexp::match('^h', 'Hello World'));
        self::assertSame(1, SqliteRegexp::match('^[[:digit:]]+%', '100% sure'));
    }

    public function testALeadingInlineFlagMeansCaseInsensitive(): void
    {
        self::assertSame(1, SqliteRegexp::match('(?i)^h', 'Hello World'));
        self::assertSame(0, SqliteRegexp::match('(?i)^x', 'Hello World'));
    }

    public function testNullNeverMatches(): void
    {
        self::assertNull(SqliteRegexp::match('a', null));
        self::assertNull(SqliteRegexp::match(null, 'a'));
    }

    public function testItIsRegisteredAsTheRegexpOperator(): void
    {
        $pdo = \Pdo\Sqlite::connect('sqlite::memory:');
        SqliteRegexp::register($pdo);

        // SQLite calls REGEXP(pattern, subject) for `subject REGEXP pattern`.
        self::assertSame(1, (int) $pdo->query("SELECT 'abc' REGEXP 'b'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT 'abc' REGEXP '^b'")->fetchColumn());
    }
}
