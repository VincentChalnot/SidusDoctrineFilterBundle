<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Query;

use Doctrine\ORM\QueryBuilder;
use Sidus\DoctrineFilterBundle\Metadata\FieldResolver;
use Sidus\DoctrineFilterBundle\Metadata\ResolvedField;
use Sidus\DoctrineFilterBundle\Metadata\ResolvedStep;

/**
 * The joins of one request (a member of {@see DoctrineQueryContext}, never a service).
 *
 * To-one path prefixes are shared: one LEFT JOIN per distinct prefix, with an alias derived from the path
 * (`author` becomes `author_`, `author.company` becomes `author_company_`, a numeric suffix on collision). That is
 * safe because a to-one join never changes the cardinality nor the meaning of other criteria. To-many paths never
 * go through here: every to-many criterion is its own correlated EXISTS subquery (see {@see FieldAccess}).
 *
 * Aliases are a pure function of the path (and of the order of first use for collisions, which follows the
 * definition order), never random: the DQL of two requests with the same active filters is byte-identical.
 */
final class JoinRegistry
{
    /** @var array<string, string> path key => alias */
    private array $aliases = [];

    private int $existsCount = 0;

    public function __construct(
        private readonly QueryBuilder $queryBuilder,
        private readonly string $rootAlias,
        private readonly FieldResolver $fields,
    ) {
    }

    public function rootAlias(): string
    {
        return $this->rootAlias;
    }

    /**
     * The alias of the entity at the end of a to-one association path of the root (`author.company`), joining
     * the missing prefixes. For customizers that want to reuse a shared join instead of adding their own.
     */
    public function aliasFor(string $path): string
    {
        $field = $this->fields->resolve($path);
        if (!$field->endsOnAssociation()) {
            throw new \LogicException(\sprintf('"%s" does not end on an association.', $path));
        }

        return $this->alias($field, \count($field->steps));
    }

    /**
     * The alias reached after the first `$depth` steps of the field (0 is the root), joining the missing to-one
     * prefixes.
     */
    public function alias(ResolvedField $field, int $depth): string
    {
        $alias = $this->rootAlias;
        $key = '';
        for ($index = 0; $index < $depth; ++$index) {
            $step = $field->steps[$index];
            if ($step->toMany) {
                throw new \LogicException(\sprintf('"%s": the to-many association "%s" cannot be joined as a shared prefix.', $field, $step->name));
            }
            $key .= ('' === $key ? '' : '.').$step->name;
            $alias = $this->aliases[$key] ??= $this->join($alias, $step, $key);
        }

        return $alias;
    }

    /**
     * Index of the next EXISTS subquery of the request (their aliases are `x<index>_...`, numbered in compilation order).
     */
    public function nextExistsIndex(): int
    {
        return ++$this->existsCount;
    }

    private function join(string $parentAlias, ResolvedStep $step, string $key): string
    {
        $alias = $this->generate($key);
        $this->queryBuilder->leftJoin($parentAlias.'.'.$step->name, $alias);

        return $alias;
    }

    private function generate(string $key): string
    {
        $base = str_replace('.', '_', $key);
        $taken = array_fill_keys($this->queryBuilder->getAllAliases(), true);
        $alias = $base.'_';
        for ($suffix = 2; isset($taken[$alias]); ++$suffix) {
            $alias = $base.'_'.$suffix.'_';
        }

        return $alias;
    }
}
