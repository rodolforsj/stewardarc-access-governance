<?php

declare(strict_types=1);

namespace App\AccessGovernance\Concurrency;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The functional instant of the active PostgreSQL transaction (ADR-009):
 * `transaction_timestamp()`, the stable start instant of the transaction owned
 * by the calling Action. It is not the commit time.
 */
final class FunctionalTransactionTime
{
    /**
     * Read once per operation and reuse the returned value for every temporal
     * effect of that operation.
     */
    public function current(): CarbonImmutable
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'FunctionalTransactionTime::current() must be called inside an active database transaction.'
            );
        }

        $row = DB::selectOne('select transaction_timestamp() as functional_transaction_at');

        return CarbonImmutable::parse((string) $row->functional_transaction_at)
            ->setTimezone((string) config('app.timezone'));
    }
}
