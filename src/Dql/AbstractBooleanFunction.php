<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * `NAME(subject, pattern)` returning 1 or 0, never null: `CASE WHEN (<test>) THEN 1 ELSE 0 END`. Used as
 * `NAME(...) = 1`, which is portable (PostgreSQL has real booleans) and null-safe under `NOT`.
 */
abstract class AbstractBooleanFunction extends FunctionNode
{
    public Node $subject;

    public Node $pattern;

    abstract protected function test(SqlWalker $sqlWalker, string $subject, string $pattern): string;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->subject = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->pattern = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'CASE WHEN ('.$this->test($sqlWalker, $this->subject->dispatch($sqlWalker), $this->pattern->dispatch($sqlWalker)).') THEN 1 ELSE 0 END';
    }
}
