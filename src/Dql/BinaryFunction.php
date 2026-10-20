<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Dql;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * `SIDUS_BINARY(subject)`: the subject compared and ordered byte-wise, ignoring the column collation. MySQL and
 * MariaDB: `(BINARY subject)`, usable anywhere a string is (comparison, LIKE, LOCATE, ORDER BY). PostgreSQL:
 * `COLLATE "C"`, valid in ORDER BY only (the compilers use it nowhere else there). SQLite: its default collation is
 * already binary, nothing is emitted.
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
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform => $sql.' COLLATE "C"',
            $platform instanceof AbstractMySQLPlatform => '(BINARY '.$sql.')',
            default => $sql,
        };
    }
}
