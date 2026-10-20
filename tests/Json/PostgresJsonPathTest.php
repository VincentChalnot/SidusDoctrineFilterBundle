<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Json;

final class PostgresJsonPathTest extends AbstractJsonPathTestCase
{
    protected static function platform(): string
    {
        return 'pgsql';
    }

    protected static function supportsJson(): bool
    {
        return true;
    }

    protected static function expectedScoreFragment(): string
    {
        return "JSON_GET_FIELD_AS_INTEGER(JSON_GET_FIELD(root.meta, 'stats'), 'score')";
    }
}
