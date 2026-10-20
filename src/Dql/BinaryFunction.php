<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Dql;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * `SIDUS_BINARY(subject)`: the subject compared byte-wise (`COLLATE "C"` on PostgreSQL; SQLite's default collation
 * is already binary). For `ORDER BY` only.
 */
class BinaryFunction extends FunctionNode
{
    public Node $subject;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->subject = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $sql = $this->subject->dispatch($sqlWalker);

        return $sqlWalker->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform ? $sql.' COLLATE "C"' : $sql;
    }
}
