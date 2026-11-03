<?php

declare(strict_types=1);

/**
 * A slug is taken when the database's unique index says so, and only then
 * (ADR 0161): a read before the write can race another tenant's, so the
 * stores write and map the index's refusal to SlugTaken, and any other
 * failure goes on as itself.
 */

namespace Tinvar\Tests\Tenancy\SelfService\SlugRaces;

use Tinvar\Orm\Connection;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Tenancy\SelfService\DatabaseTenants;
use Tinvar\Tenancy\SelfService\SlugTaken;

/**
 * A connection whose reads of who holds a slug come back empty, as they do
 * for a store that read just before another tenant's write committed; and
 * whose writes fail with $failWrite, when one is given.
 */
final class RacingConnection implements Connection, NamesDriver
{
    public ?\PDOException $failWrite = null;

    public function __construct(private readonly PdoConnection $inner) {}

    public function select(string $sql, array $bindings = []): array
    {
        return preg_match('/WHERE (\w+\.)?slug = \?/', $sql) === 1 ? [] : $this->inner->select($sql, $bindings);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        if ($this->failWrite !== null && preg_match('/^(UPDATE|INSERT)/', $sql) === 1) {
            throw $this->failWrite;
        }

        return $this->inner->execute($sql, $bindings);
    }

    public function statementCount(): int
    {
        return $this->inner->statementCount();
    }

    public function resetStatementCount(): void
    {
        $this->inner->resetStatementCount();
    }

    public function statementLog(): array
    {
        return $this->inner->statementLog();
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function transaction(\Closure $work): mixed
    {
        return $this->inner->transaction($work);
    }

    public function durableTransaction(\Closure $work): mixed
    {
        return $this->inner->durableTransaction($work);
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function readOnly(\Closure $work): mixed
    {
        return $this->inner->readOnly($work);
    }

    public function isReadOnly(): bool
    {
        return $this->inner->isReadOnly();
    }

    public function rollBackOpenTransaction(): bool
    {
        return $this->inner->rollBackOpenTransaction();
    }

    public function driver(): string
    {
        return $this->inner->driver();
    }
}

/** @return array{DatabaseTenants, RacingConnection} */
function tenants(): array
{
    $inner = new PdoConnection(new \PDO('sqlite::memory:'), 'slugs');
    DatabaseTenants::define(new Schema($inner, Dialect::for($inner)));
    new DatabaseTenants($inner)->create('Acme', 'acme', id: 'acme');
    new DatabaseTenants($inner)->create('Globex', 'globex', id: 'globex');
    $racing = new RacingConnection($inner);

    return [new DatabaseTenants($racing), $racing];
}

it('says a slug is taken when the index refuses a new tenant, though the read before it found none', function () {
    [$tenants, $racing] = tenants();

    expect(static fn () => $tenants->create('Acme again', 'acme'))->toThrow(SlugTaken::class);

    $racing->failWrite = new \PDOException('SQLSTATE[HY000]: General error: 10 disk I/O error');
    expect(static fn () => $tenants->create('Initech', 'initech'))->toThrow(\PDOException::class, 'disk I/O error');
});

it('says a slug is taken when the index refuses a change, and lets any other failure go on as itself', function () {
    [$tenants, $racing] = tenants();

    expect(static fn () => $tenants->changeSlug('globex', 'acme'))->toThrow(SlugTaken::class)
        ->and($tenants->slugOf('globex'))->toBe('globex');

    $racing->failWrite = new \PDOException('SQLSTATE[HY000]: General error: 10 disk I/O error');
    expect(static fn () => $tenants->changeSlug('globex', 'globex-co'))->toThrow(\PDOException::class, 'disk I/O error');
});
