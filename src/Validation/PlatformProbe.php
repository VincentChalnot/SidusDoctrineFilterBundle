<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Validation;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Sidus\DoctrineFilterBundle\Platform\Dialect;
use Sidus\FilterBundle\Exception\ConfigurationException;

/**
 * What the connection of an entity manager can do, discovered lazily and once. Every answer is "no refusal" when
 * the database cannot be reached: the checks are skipped, not failed.
 */
final class PlatformProbe
{
    private bool $loaded = false;

    private ?Dialect $dialect = null;

    private ?string $unsupported = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function entityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    /** The dialect of the connection, null when it is unreachable or unsupported. */
    public function dialect(): ?Dialect
    {
        $this->load();

        return $this->dialect;
    }

    /** @return string|null why `Matches` cannot work on this connection */
    public function regexpRefusal(): ?string
    {
        $this->load();
        if (null !== $this->unsupported) {
            return $this->unsupported;
        }
        if (null === $this->dialect || !$this->dialect->isSqlite()) {
            return null;
        }
        try {
            $this->entityManager->getConnection()->executeQuery("SELECT 'a' REGEXP 'a'")->fetchOne();
        } catch (DbalException) {
            return 'the SQLite connection has no REGEXP function; register Sidus\DoctrineFilterBundle\Platform\SqliteRegexp::register() on it when it is opened.';
        }

        return null;
    }

    /** @return string|null why `ILIKE` cannot work on this connection */
    public function ilikeRefusal(): ?string
    {
        $this->load();
        if (null !== $this->unsupported) {
            return $this->unsupported;
        }

        return null === $this->dialect || $this->dialect->isPostgreSql() ? null : \sprintf('ILIKE is available on PostgreSQL only, the connection is %s.', $this->dialect->name());
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        try {
            $this->dialect = Dialect::of($this->entityManager->getConnection()->getDatabasePlatform());
        } catch (ConfigurationException $e) {
            $this->unsupported = $e->getMessage();
        } catch (DbalException|\PDOException) {
            // the database is not reachable (build without a database): platform checks are skipped
        }
    }
}
