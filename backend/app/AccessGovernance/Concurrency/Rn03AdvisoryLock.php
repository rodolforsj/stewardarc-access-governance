<?php

declare(strict_types=1);

namespace App\AccessGovernance\Concurrency;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Transaction-level PostgreSQL advisory lock that serializes RN03 per
 * (requester, access profile), as decided in ADR-005 and encoded in ADR-006.
 */
final class Rn03AdvisoryLock
{
    private const KEY_NAMESPACE = 'stewardarc:rn03:v1';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Acquire the lock inside the caller's transaction. It is released by
     * PostgreSQL on COMMIT or ROLLBACK; it is never released manually.
     */
    public function acquire(string $requesterUuid, string $profileUuid): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Rn03AdvisoryLock::acquire() must be called inside an active database transaction.'
            );
        }

        [$key1, $key2] = self::keysFor($requesterUuid, $profileUuid);

        DB::selectOne('select pg_advisory_xact_lock(cast(? as integer), cast(? as integer))', [$key1, $key2]);
    }

    /**
     * The canonical, namespaced and versioned input of the key.
     */
    public static function canonicalInput(string $requesterUuid, string $profileUuid): string
    {
        return self::KEY_NAMESPACE
            .'|'.self::canonicalUuid($requesterUuid)
            .'|'.self::canonicalUuid($profileUuid);
    }

    /**
     * SHA-256 of the canonical input, binary digest, first 8 bytes read as two
     * big-endian 32-bit blocks, each reinterpreted as a signed int32.
     *
     * @return array{0: int, 1: int}
     */
    public static function keysFor(string $requesterUuid, string $profileUuid): array
    {
        $digest = hash('sha256', self::canonicalInput($requesterUuid, $profileUuid), true);

        /** @var array{1: int, 2: int} $blocks */
        $blocks = unpack('N2', substr($digest, 0, 8));

        return [self::toSignedInt32($blocks[1]), self::toSignedInt32($blocks[2])];
    }

    private static function canonicalUuid(string $uuid): string
    {
        $canonical = strtolower($uuid);

        if (preg_match(self::UUID_PATTERN, $canonical) !== 1) {
            throw new InvalidArgumentException('Advisory lock keys require canonical hyphenated UUIDs.');
        }

        return $canonical;
    }

    private static function toSignedInt32(int $block): int
    {
        return $block > 2147483647 ? $block - 4294967296 : $block;
    }
}
