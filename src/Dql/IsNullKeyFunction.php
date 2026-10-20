<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * `SIDUS_IS_NULL(expression)`: 1 when the expression is NULL, else 0 (`CASE WHEN (...) IS NULL THEN 1 ELSE 0 END`).
 * The null-ordering key of computed sort values: DQL's `IS NULL` only accepts paths and function calls, not an
 * arbitrary expression or a subquery.
 */
class IsNullKeyFunction extends FunctionNode
{
    public Node $expression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expression = $parser->SimpleArithmeticExpression();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'CASE WHEN ('.$this->expression->dispatch($sqlWalker).') IS NULL THEN 1 ELSE 0 END';
    }
}
