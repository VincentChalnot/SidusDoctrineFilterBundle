<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Dql;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Literal;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * `SIDUS_JSON(column, '$.a.b', 'cast')`: the scalar at a JSON path as text or cast (`string`, `integer`, `float`,
 * `boolean`), NULL when missing or JSON null. MariaDB `JSON_VALUE`, MySQL `JSON_UNQUOTE(JSON_EXTRACT(...))`.
 * The path and the cast are literals written by the compiler, never request input.
 */
class JsonValueFunction extends FunctionNode
{
    public Node $subject;

    public Node $path;

    public string $cast = 'string';

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->subject = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->path = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $cast = $parser->Literal();
        \assert($cast instanceof Literal && \is_string($cast->value));
        $this->cast = $cast->value;
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();
        $subject = $this->subject->dispatch($sqlWalker);
        $path = $this->path->dispatch($sqlWalker);
        $text = match (true) {
            $platform instanceof MariaDBPlatform => 'JSON_VALUE('.$subject.', '.$path.')',
            $platform instanceof AbstractMySQLPlatform => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT($subject, $path)), 'null')",
            default => throw QueryException::syntaxError('SIDUS_JSON supports MariaDB and MySQL only.'),
        };

        return match ($this->cast) {
            'string' => $text,
            'integer' => 'CAST('.$text.' AS SIGNED)',
            'float' => 'CAST('.$text.' AS DOUBLE)',
            // MariaDB's JSON_VALUE reads a JSON boolean as 1 / 0, MySQL's JSON_UNQUOTE as 'true' / 'false'.
            'boolean' => 'CASE '.$text." WHEN 'true' THEN 1 WHEN '1' THEN 1 WHEN 'false' THEN 0 WHEN '0' THEN 0 ELSE NULL END",
            default => throw QueryException::syntaxError(\sprintf('SIDUS_JSON cannot cast to "%s".', $this->cast)),
        };
    }
}
