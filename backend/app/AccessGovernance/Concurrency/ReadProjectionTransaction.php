<?php

declare(strict_types=1);

namespace App\AccessGovernance\Concurrency;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The read transaction of a compound functional read projection (ADR-008):
 * one PostgreSQL REPEATABLE READ, READ ONLY transaction, one snapshot and one
 * projection reference instant. It takes no row or advisory lock.
 */
final class ReadProjectionTransaction
{
    /**
     * Runs $projection inside its own read transaction and returns its result,
     * which must already be fully materialized when the transaction ends.
     *
     * @template TResult
     *
     * @param  callable(CarbonImmutable): TResult  $projection
     * @return TResult
     */
    public function run(callable $projection): mixed
    {
        // The isolation, the read-only mode and the moment the snapshot is taken
        // must belong to this transaction, so it never joins an outer one.
        if (DB::transactionLevel() !== 0) {
            throw new LogicException(
                'ReadProjectionTransaction::run() must not be called inside an active database transaction.'
            );
        }

        return DB::transaction(function () use ($projection): mixed {
            // Must precede every query of the transaction, including the one
            // that establishes the snapshot.
            DB::statement('set transaction isolation level repeatable read, read only');

            // First SELECT of the projection: it establishes the snapshot and the
            // single temporal reference of the whole projection.
            $row = DB::selectOne('select transaction_timestamp() as projection_reference_at');

            $projectionReferenceAt = CarbonImmutable::parse((string) $row->projection_reference_at)
                ->setTimezone((string) config('app.timezone'));

            return $projection($projectionReferenceAt);
        });
    }
}
