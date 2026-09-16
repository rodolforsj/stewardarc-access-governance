<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Concurrency\ReadProjectionTransaction;
use App\Models\ActorReference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * ADR-008 — the read transaction of compound functional read projections.
 */
final class ReadProjectionTransactionTest extends PostgresTestCase
{
    private function transaction(): ReadProjectionTransaction
    {
        return new ReadProjectionTransaction();
    }

    public function test_the_projection_runs_in_a_repeatable_read_read_only_transaction(): void
    {
        $settings = $this->transaction()->run(static fn (): array => [
            'level' => DB::transactionLevel(),
            'isolation' => DB::selectOne("select current_setting('transaction_isolation') as value")->value,
            'read_only' => DB::selectOne("select current_setting('transaction_read_only') as value")->value,
        ]);

        $this->assertSame(1, $settings['level']);
        $this->assertSame('repeatable read', $settings['isolation']);
        $this->assertSame('on', $settings['read_only']);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_the_settings_do_not_leak_into_later_transactions_or_the_session(): void
    {
        $this->transaction()->run(static fn (): null => null);

        $afterwards = DB::transaction(static fn (): array => [
            'isolation' => DB::selectOne("select current_setting('transaction_isolation') as value")->value,
            'read_only' => DB::selectOne("select current_setting('transaction_read_only') as value")->value,
        ]);

        // Mutations keep the READ COMMITTED baseline of ADR-005.
        $this->assertSame('read committed', $afterwards['isolation']);
        $this->assertSame('off', $afterwards['read_only']);
        $this->assertSame(
            'read committed',
            DB::selectOne("select current_setting('default_transaction_isolation') as value")->value
        );
    }

    public function test_the_isolation_is_set_before_the_reference_instant_is_read(): void
    {
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->transaction()->run(static function (): void {
            DB::selectOne('select 1 as probe');
        });

        $this->assertSame('set transaction isolation level repeatable read, read only', $statements[0]);
        $this->assertSame('select transaction_timestamp() as projection_reference_at', $statements[1]);
        $this->assertSame('select 1 as probe', $statements[2]);
        $this->assertCount(3, $statements);
    }

    public function test_the_callback_receives_the_transaction_timestamp_as_the_single_reference_instant(): void
    {
        $observed = $this->transaction()->run(static function (CarbonImmutable $referenceAt): array {
            $first = DB::selectOne('select transaction_timestamp() as value')->value;
            usleep(20000);
            $second = DB::selectOne('select transaction_timestamp() as value')->value;
            $matches = DB::selectOne(
                'select transaction_timestamp() = cast(? as timestamptz) as value',
                [$referenceAt->format('Y-m-d H:i:s.uP')]
            )->value;

            return [$referenceAt, $first, $second, $matches];
        });

        [$referenceAt, $first, $second, $matches] = $observed;

        $this->assertInstanceOf(CarbonImmutable::class, $referenceAt);
        $this->assertSame($first, $second);
        $this->assertTrue($referenceAt->equalTo(CarbonImmutable::parse($first)));
        $this->assertSame(
            CarbonImmutable::parse($first)->format('Y-m-d H:i:s.u'),
            $referenceAt->utc()->format('Y-m-d H:i:s.u'),
            'The microseconds of the transaction timestamp must be preserved.'
        );
        $this->assertTrue($matches);
    }

    public function test_each_run_gets_its_own_reference_instant(): void
    {
        $first = $this->transaction()->run(static fn (CarbonImmutable $referenceAt): CarbonImmutable => $referenceAt);
        usleep(20000);
        $second = $this->transaction()->run(static fn (CarbonImmutable $referenceAt): CarbonImmutable => $referenceAt);

        $this->assertTrue($second->greaterThan($first));
    }

    public function test_the_callback_result_is_returned(): void
    {
        $this->assertSame(['value' => 42], $this->transaction()->run(static fn (): array => ['value' => 42]));
    }

    public function test_an_insert_is_rejected_by_postgresql_and_nothing_is_persisted(): void
    {
        $this->assertSame(0, ActorReference::query()->count());

        try {
            $this->transaction()->run(static function (): void {
                DB::insert(
                    'insert into actor_references (id, external_identity_key, display_name) values (?, ?, ?)',
                    ['018f0000-0000-7000-8000-000000000001', 'ext-read-only', 'Should not exist']
                );
            });
            $this->fail('The insert should have been rejected.');
        } catch (QueryException $error) {
            // 25006: read_only_sql_transaction.
            $this->assertSame('25006', $error->getCode());
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, ActorReference::query()->count());
    }

    public function test_an_update_is_rejected_by_postgresql_and_nothing_is_changed(): void
    {
        $actor = $this->makeActor('Original name');

        try {
            $this->transaction()->run(static function () use ($actor): void {
                DB::update('update actor_references set display_name = ? where id = ?', ['Changed', $actor->id]);
            });
            $this->fail('The update should have been rejected.');
        } catch (QueryException $error) {
            $this->assertSame('25006', $error->getCode());
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Original name', $actor->fresh()->display_name);
    }

    public function test_it_refuses_to_join_an_outer_transaction(): void
    {
        $called = false;

        DB::beginTransaction();

        try {
            $this->transaction()->run(static function () use (&$called): void {
                $called = true;
            });
            $this->fail('A LogicException was expected.');
        } catch (LogicException) {
            $this->assertFalse($called);
            $this->assertSame(1, DB::transactionLevel());
            // The outer transaction was not reconfigured by the helper.
            $this->assertSame(
                'off',
                DB::selectOne("select current_setting('transaction_read_only') as value")->value
            );
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, DB::transactionLevel());
    }
}
