<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Concurrency\Rn03AdvisoryLock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\PostgreSQL\PostgresTestCase;

final class Rn03AdvisoryLockTest extends PostgresTestCase
{
    private const VECTOR_REQUESTER = '018f0000-0000-7000-8000-000000000001';

    private const VECTOR_PROFILE = '018f0000-0000-7000-8000-000000000002';

    private const VECTOR_CANONICAL = 'stewardarc:rn03:v1|018f0000-0000-7000-8000-000000000001|018f0000-0000-7000-8000-000000000002';

    private const VECTOR_SHA256 = '38d51a93899e851670b0f6f83fa735a12629795e081e5bd7599df34f9efcd28e';

    /** Expected values come from the ADR-006 vector, not from the helper. */
    private const VECTOR_KEY1 = 953490067;

    private const VECTOR_KEY2 = -1986099946;

    public function test_canonical_input_is_namespaced_and_versioned(): void
    {
        $this->assertSame(
            self::VECTOR_CANONICAL,
            Rn03AdvisoryLock::canonicalInput(self::VECTOR_REQUESTER, self::VECTOR_PROFILE)
        );
    }

    public function test_known_vector_produces_the_expected_signed_int32_pair(): void
    {
        $this->assertSame(self::VECTOR_SHA256, hash('sha256', self::VECTOR_CANONICAL));

        $this->assertSame(
            [self::VECTOR_KEY1, self::VECTOR_KEY2],
            Rn03AdvisoryLock::keysFor(self::VECTOR_REQUESTER, self::VECTOR_PROFILE)
        );
    }

    public function test_uuids_are_normalized_to_the_canonical_lowercase_form(): void
    {
        $this->assertSame(
            Rn03AdvisoryLock::keysFor(self::VECTOR_REQUESTER, self::VECTOR_PROFILE),
            Rn03AdvisoryLock::keysFor(strtoupper(self::VECTOR_REQUESTER), strtoupper(self::VECTOR_PROFILE))
        );
    }

    public function test_non_canonical_identifiers_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Rn03AdvisoryLock::keysFor(str_replace('-', '', self::VECTOR_REQUESTER), self::VECTOR_PROFILE);
    }

    public function test_acquire_requires_an_active_transaction(): void
    {
        $this->expectException(LogicException::class);

        (new Rn03AdvisoryLock())->acquire(self::VECTOR_REQUESTER, self::VECTOR_PROFILE);
    }

    public function test_acquire_registers_the_expected_advisory_lock_in_postgresql(): void
    {
        DB::beginTransaction();

        try {
            (new Rn03AdvisoryLock())->acquire(self::VECTOR_REQUESTER, self::VECTOR_PROFILE);

            $held = DB::selectOne(
                "select count(*) as total from pg_locks
                 where locktype = 'advisory'
                   and granted
                   and classid = cast(? as bigint)
                   and objid = cast(? as bigint)
                   and pid = pg_backend_pid()",
                [self::VECTOR_KEY1, self::VECTOR_KEY2 + 4294967296]
            );

            $this->assertSame(1, (int) $held->total);
        } finally {
            DB::rollBack();
        }
    }
}
