<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * The DBAL logging middleware writes every statement here (`logger` service of the kernel with `record_queries`).
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    private static array $statements = [];

    public function log($level, $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            self::$statements[] = (string) $context['sql'];
        }
    }

    public static function reset(): void
    {
        self::$statements = [];
    }

    /**
     * @return list<string> the SQL statements executed since the last reset
     */
    public static function statements(): array
    {
        return self::$statements;
    }
}
