<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Sidus\DoctrineFilterBundle\Metadata\FieldCapabilities;
use Sidus\DoctrineFilterBundle\Metadata\ResolvedField;

/**
 * Binds the values of criteria as typed parameters of the terminal field: numeric strings become numbers, dates
 * (DateTimeInterface or ISO strings) are converted to the PHP default timezone as DBAL does when storing, so that
 * an instant means the same thing in the query as in the row.
 */
final class ValueBinder
{
    /** Binds one value, returns its placeholder. */
    public static function bind(DoctrineQueryContext $context, ResolvedField $field, mixed $value): string
    {
        [$value, $type] = self::prepare($field->valueType(), $value);

        return $context->parameter($value, $type);
    }

    /**
     * Binds a list for an IN: values are converted to their database form first, since DBAL cannot convert array parameters.
     *
     * @param list<mixed> $values
     */
    public static function bindList(DoctrineQueryContext $context, ResolvedField $field, array $values): string
    {
        $platform = $context->entityManager()->getConnection()->getDatabasePlatform();
        $converted = [];
        $binding = ParameterType::STRING;
        foreach ($values as $value) {
            [$prepared, $type] = self::prepare($field->valueType(), $value);
            if (\is_string($type) && Type::hasType($type)) {
                $dbalType = Type::getType($type);
                $prepared = $dbalType->convertToDatabaseValue($prepared, $platform);
                $binding = $dbalType->getBindingType();
            }
            $converted[] = $prepared;
        }

        return $context->parameter($converted, match ($binding) {
            ParameterType::INTEGER => ArrayParameterType::INTEGER,
            ParameterType::BINARY => ArrayParameterType::BINARY,
            default => ArrayParameterType::STRING,
        });
    }

    /**
     * @return array{0: mixed, 1: string|null} the value and its DBAL type name
     */
    private static function prepare(?string $type, mixed $value): array
    {
        if (null === $type) {
            return [$value, null];
        }
        if (\in_array($type, FieldCapabilities::DATE_TYPES, true)) {
            return [self::date($value, $type), str_ends_with($type, '_immutable') ? $type : $type.'_immutable'];
        }
        if (\is_string($value) && is_numeric($value) && \in_array($type, ['integer', 'smallint', 'bigint'], true)) {
            $value = $value + 0;
        } elseif (\is_string($value) && is_numeric($value) && \in_array($type, ['float', 'smallfloat'], true)) {
            $value = (float) $value;
        } elseif (\in_array($type, FieldCapabilities::TEXT_TYPES, true) && (\is_int($value) || \is_float($value))) {
            $value = (string) $value;
        }

        return [$value, $type];
    }

    private static function date(mixed $value, string $type): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            $date = \DateTimeImmutable::createFromInterface($value);
        } else {
            try {
                $date = new \DateTimeImmutable((string) $value);
            } catch (\Exception) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a valid date for a %s field.', \is_scalar($value) ? (string) $value : get_debug_type($value), $type));
            }
        }

        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
