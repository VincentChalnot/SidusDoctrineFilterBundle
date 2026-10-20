<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

/**
 * The `provider_options.doctrine.expression` of a sort column: a DQL expression written in the configuration (hence
 * allow-listed by construction, never request input) that replaces the column's field as the sort value, for computed
 * sorts (ratios, flags). `{root}` stands for the root alias.
 *
 * A string is one order key in the direction of the column; a list gives several keys, each a string or
 * `{expression, direction: same|asc|desc}` (`same` follows the direction asked, `asc` / `desc` pin it), compiled to
 * hidden selects in that order.
 */
final class ExpressionSort
{
    public const string ROOT = '{root}';

    /**
     * @return list<array{expression: string, direction: 'same'|'asc'|'desc'}>
     *
     * @throws \InvalidArgumentException
     */
    public static function keys(mixed $expression): array
    {
        $items = \is_array($expression) && array_is_list($expression) ? $expression : [$expression];
        $keys = [];
        foreach ($items as $item) {
            $direction = 'same';
            if (\is_array($item)) {
                $unknown = array_diff(array_keys($item), ['expression', 'direction']);
                if ([] !== $unknown) {
                    throw new \InvalidArgumentException(\sprintf('unknown key(s) %s in an expression key (allowed: expression, direction).', implode(', ', $unknown)));
                }
                $direction = $item['direction'] ?? 'same';
                $item = $item['expression'] ?? null;
            }
            if (!\is_string($item) || '' === trim($item)) {
                throw new \InvalidArgumentException('an expression must be a non-empty DQL string.');
            }
            if (!\in_array($direction, ['same', 'asc', 'desc'], true)) {
                throw new \InvalidArgumentException(\sprintf('the direction of an expression key must be same, asc or desc, "%s" given.', \is_scalar($direction) ? $direction : get_debug_type($direction)));
            }
            $keys[] = ['expression' => $item, 'direction' => $direction];
        }
        if ([] === $keys) {
            throw new \InvalidArgumentException('an expression sort needs at least one key.');
        }

        return $keys;
    }

    public static function dql(string $expression, string $rootAlias): string
    {
        return str_replace(self::ROOT, $rootAlias, $expression);
    }
}
