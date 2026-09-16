<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Concurrency\FunctionalTransactionTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * ADR-009 — the functional instant of the active PostgreSQL transaction.
 */
final class FunctionalTransactionTimeTest extends PostgresTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_requires_an_active_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);

        (new FunctionalTransactionTime())->current();
    }

    public function test_it_returns_the_postgresql_transaction_timestamp(): void
    {
        [$instant, $raw] = DB::transaction(static fn (): array => [
            (new FunctionalTransactionTime())->current(),
            DB::selectOne('select transaction_timestamp() as value')->value,
        ]);

        $this->assertInstanceOf(CarbonImmutable::class, $instant);
        $this->assertSame((string) config('app.timezone'), $instant->getTimezone()->getName());
        $this->assertTrue($instant->equalTo(CarbonImmutable::parse($raw)));
        $this->assertSame(
            CarbonImmutable::parse($raw)->utc()->format('Y-m-d H:i:s.u'),
            $instant->utc()->format('Y-m-d H:i:s.u'),
            'The microseconds of the transaction timestamp must be preserved.'
        );
    }

    public function test_it_is_stable_within_one_transaction_and_differs_across_transactions(): void
    {
        $helper = new FunctionalTransactionTime();

        [$first, $second] = DB::transaction(static function () use ($helper): array {
            $first = $helper->current();
            // A statement taken later in the same transaction.
            DB::selectOne('select pg_sleep(0.02)');

            return [$first, $helper->current()];
        });

        $this->assertTrue($first->equalTo($second));

        $next = DB::transaction(static fn (): CarbonImmutable => $helper->current());

        $this->assertTrue($next->greaterThan($first));
    }

    public function test_it_ignores_the_php_clock(): void
    {
        $fakeNow = CarbonImmutable::parse('2001-01-01 00:00:00', 'UTC');
        Carbon::setTestNow($fakeNow);

        [$instant, $databaseNow] = DB::transaction(static fn (): array => [
            (new FunctionalTransactionTime())->current(),
            DB::selectOne('select transaction_timestamp() as value')->value,
        ]);

        $this->assertTrue(Carbon::now()->equalTo($fakeNow), 'The PHP clock is frozen by the test.');
        $this->assertTrue($instant->equalTo(CarbonImmutable::parse($databaseNow)));
        $this->assertGreaterThan(20 * 365 * 86400, $instant->getTimestamp() - $fakeNow->getTimestamp());
    }
}
