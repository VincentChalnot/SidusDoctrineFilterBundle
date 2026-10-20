<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Metadata;

/**
 * A join declared in the list options (`options.joins.<alias>`): an entity with no Doctrine association to the
 * root, joined on `<alias>.<onField> = <equals>` where `equals` is a path of the root (it may traverse to-one
 * associations, never a named join). The alias is usable as the first segment of any field path of the list.
 *
 *  - `one`: a shared LEFT JOIN, created once per request on first use;
 *  - `many`: behaves like a to-many association: one correlated EXISTS per criterion; with `shared: true` it is
 *    joined once instead (the same element satisfies every criterion) and the list switches to DISTINCT mode.
 */
final readonly class NamedJoin
{
    public const string ONE = 'one';
    public const string MANY = 'many';

    /**
     * @param class-string $entity
     */
    public function __construct(
        public string $alias,
        public string $entity,
        public string $onField,
        public string $equals,
        public string $cardinality = self::ONE,
        public bool $shared = false,
    ) {
    }

    /**
     * @param array<string, mixed> $config `entity`, `on: {field, equals}`, `cardinality`, `shared`
     */
    public static function fromConfig(string $alias, array $config): self
    {
        $on = $config['on'] ?? [];

        return new self($alias, $config['entity'], $on['field'], $on['equals'], $config['cardinality'] ?? self::ONE, $config['shared'] ?? false);
    }

    /**
     * Validates and normalises the `joins` option.
     *
     * @param array<array-key, mixed> $joins
     *
     * @return array<string, array{entity: string, on: array{field: string, equals: string}, cardinality: string, shared: bool}>
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(array $joins): array
    {
        $normalized = [];
        foreach ($joins as $alias => $config) {
            $alias = (string) $alias;
            if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
                throw new \InvalidArgumentException(\sprintf('Invalid join alias "%s": use letters, digits and underscores (it is the first segment of field paths).', $alias));
            }
            $where = \sprintf('joins.%s', $alias);
            if (!\is_array($config)) {
                throw new \InvalidArgumentException($where.' must be a map with "entity", "on" and "cardinality".');
            }
            $unknown = array_diff(array_keys($config), ['entity', 'on', 'cardinality', 'shared']);
            if ([] !== $unknown) {
                throw new \InvalidArgumentException(\sprintf('%s: unknown key(s) %s (allowed: entity, on, cardinality, shared).', $where, implode(', ', $unknown)));
            }
            if (!isset($config['entity']) || !\is_string($config['entity']) || '' === $config['entity']) {
                throw new \InvalidArgumentException($where.'.entity is required (an entity class).');
            }
            $on = $config['on'] ?? null;
            if (!\is_array($on) || !isset($on['field'], $on['equals']) || !\is_string($on['field']) || !\is_string($on['equals']) || [] !== array_diff(array_keys($on), ['field', 'equals'])) {
                throw new \InvalidArgumentException($where.'.on must be {field: <field of the joined entity>, equals: <path of the root>}.');
            }
            $cardinality = $config['cardinality'] ?? self::ONE;
            if (!\in_array($cardinality, [self::ONE, self::MANY], true)) {
                throw new \InvalidArgumentException($where.'.cardinality must be "one" or "many".');
            }
            $shared = $config['shared'] ?? false;
            if (!\is_bool($shared)) {
                throw new \InvalidArgumentException($where.'.shared must be a boolean.');
            }
            if ($shared && self::ONE === $cardinality) {
                throw new \InvalidArgumentException($where.'.shared only applies to cardinality "many" (a "one" join is always shared).');
            }
            $normalized[$alias] = ['entity' => $config['entity'], 'on' => ['field' => $on['field'], 'equals' => $on['equals']], 'cardinality' => $cardinality, 'shared' => $shared];
        }

        return $normalized;
    }

    public function isMany(): bool
    {
        return self::MANY === $this->cardinality;
    }
}
