<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Actions\ConfirmExternalAccessGrant;
use App\AccessGovernance\Concurrency\Rn03AdvisoryLock;
use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\ActorReference;
use App\Models\GrantedAccess;
use App\Models\RevocationConfirmation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * Revocation shares the RN03 serialization domain with request creation and
 * grant confirmation: two concurrent revocations, and a revocation racing a new
 * equivalent request.
 */
final class RecordExternalAccessRevocationConcurrencyTest extends PostgresTestCase
{
    private const WAIT_TIMEOUT_SECONDS = 20;

    private string $applicationNamePrefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationNamePrefix = 'stewardarc-revoke-'.bin2hex(random_bytes(6));
    }

    public function test_two_concurrent_revocations_record_exactly_one_confirmation(): void
    {
        [$grantedAccess, $owner] = $this->committedActiveAccess();

        // The parent holds the RN03 advisory lock so both workers block on it.
        DB::beginTransaction();
        (new Rn03AdvisoryLock())->acquire(
            (string) $this->originRequest($grantedAccess)->requester_actor_reference_id,
            (string) $this->originRequest($grantedAccess)->access_profile_id,
        );

        $workers = [
            $this->startRevocationWorker($grantedAccess->id, $owner->id, $this->applicationNamePrefix.'-one'),
            $this->startRevocationWorker($grantedAccess->id, $owner->id, $this->applicationNamePrefix.'-two'),
        ];

        try {
            $this->waitUntil(
                fn (): bool => $this->countWaitingOnLock($this->applicationNamePrefix.'%') >= 2,
                'two revocation workers blocked on the advisory lock'
            );
        } catch (RuntimeException $error) {
            DB::rollBack();

            throw $this->withWorkerOutput($error, $workers);
        }

        DB::commit();

        $results = array_map(fn (array $worker): array => $this->collectWorker($worker), $workers);
        $statuses = array_column($results, 'status');
        sort($statuses);

        $this->assertSame(['recorded', 'violation'], $statuses, 'Unexpected worker outcomes: '.json_encode($results));

        $violation = $results[array_search('violation', array_column($results, 'status'), true)];
        $this->assertSame('RF-007', $violation['constraintId'] ?? null);

        $this->assertSame(1, RevocationConfirmation::query()->count());
        $this->assertSame('A3', $this->derivedGrantedAccessState($grantedAccess));
    }

    /**
     * A new equivalent request becomes admissible only after the revocation is
     * recorded, and both operations share the same advisory lock.
     */
    public function test_a_new_request_is_admitted_after_the_revocation_is_recorded(): void
    {
        [$grantedAccess, $owner, $requester, $profile, $request] = $this->committedActiveAccess();

        // Test-only orchestration: the parent holds ONLY the Granted Access row
        // lock, so the revocation worker takes the advisory lock and then waits.
        DB::beginTransaction();
        GrantedAccess::query()->whereKey($grantedAccess->id)->lockForUpdate()->firstOrFail();

        $revocationWorker = $this->startRevocationWorker(
            $grantedAccess->id,
            $owner->id,
            $this->applicationNamePrefix.'-revocation'
        );

        $createWorker = null;

        try {
            $this->waitUntil(
                fn (): bool => $this->holdsAdvisoryAndWaitsForLock($this->applicationNamePrefix.'-revocation'),
                'the revocation worker holding the advisory lock and waiting for the row lock'
            );

            $createWorker = $this->startCreateWorker(
                $requester->id,
                $profile->id,
                $this->applicationNamePrefix.'-create'
            );

            $this->waitUntil(
                fn (): bool => $this->waitsForAdvisoryLock($this->applicationNamePrefix.'-create'),
                'the create worker waiting for the advisory lock'
            );
        } catch (RuntimeException $error) {
            DB::rollBack();
            $workers = $createWorker === null ? [$revocationWorker] : [$revocationWorker, $createWorker];

            throw $this->withWorkerOutput($error, $workers);
        }

        // Releasing the row lock lets the revocation finish and, on commit,
        // release the advisory lock the create worker is waiting for.
        DB::commit();

        $revocationResult = $this->collectWorker($revocationWorker);
        $createResult = $this->collectWorker($createWorker);

        $this->assertSame('recorded', $revocationResult['status'] ?? null, 'Revocation: '.json_encode($revocationResult));
        $this->assertSame('created', $createResult['status'] ?? null, 'Create: '.json_encode($createResult));

        $this->assertSame(1, RevocationConfirmation::query()->count());
        $this->assertSame('A3', $this->derivedGrantedAccessState($grantedAccess));
        $this->assertSame('S4', $request->fresh()->current_state);

        $newRequest = AccessRequest::query()->whereKeyNot($request->id)->firstOrFail();
        $this->assertSame(2, AccessRequest::query()->count());
        $this->assertSame('S1', $newRequest->current_state);
        $this->assertSame($requester->id, $newRequest->requester_actor_reference_id);
        $this->assertSame($profile->id, $newRequest->access_profile_id);
        $this->assertSame(
            1,
            AccessRequest::query()->whereIn('current_state', ['S1', 'S2', 'S3'])->count(),
            'Only the new request may be in processing.'
        );
        $this->assertSame(1, GrantedAccess::query()->count());
    }

    /** @return array{0: GrantedAccess, 1: ActorReference, 2: ActorReference, 3: AccessProfile, 4: AccessRequest} */
    private function committedActiveAccess(): array
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($requester, $profile, 'S1');
        $this->approveUntilAwaitingGrant($request, $owner);
        (new ConfirmExternalAccessGrant())->execute($request->id, $owner->id);

        $grantedAccess = GrantedAccess::query()->firstOrFail();

        $this->assertSame('A1', $this->derivedGrantedAccessState($grantedAccess));
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed before the workers start.');

        return [$grantedAccess, $owner, $requester, $profile, $request->fresh()];
    }

    private function originRequest(GrantedAccess $grantedAccess): object
    {
        return DB::table('granted_accesses')
            ->join('grant_confirmations', 'grant_confirmations.id', '=', 'granted_accesses.grant_confirmation_id')
            ->join('access_requests', 'access_requests.id', '=', 'grant_confirmations.access_request_id')
            ->where('granted_accesses.id', $grantedAccess->id)
            ->select(['access_requests.requester_actor_reference_id', 'access_requests.access_profile_id'])
            ->firstOrFail();
    }

    /** Sessions of this test that are blocked waiting for any lock. */
    private function countWaitingOnLock(string $applicationNameLike): int
    {
        DB::select('select pg_stat_clear_snapshot()');

        return (int) DB::selectOne(
            "select count(*) as total from pg_stat_activity
             where application_name like ? and wait_event_type = 'Lock'",
            [$applicationNameLike]
        )->total;
    }

    /** The worker already holds an advisory lock and is now waiting for another lock. */
    private function holdsAdvisoryAndWaitsForLock(string $applicationName): bool
    {
        DB::select('select pg_stat_clear_snapshot()');

        return (int) DB::selectOne(
            "select count(*) as total from pg_stat_activity a
             where a.application_name = ?
               and a.wait_event_type = 'Lock'
               and exists (
                   select 1 from pg_locks l
                   where l.pid = a.pid and l.locktype = 'advisory' and l.granted
               )",
            [$applicationName]
        )->total > 0;
    }

    /** The worker is waiting for an advisory lock it has not been granted. */
    private function waitsForAdvisoryLock(string $applicationName): bool
    {
        DB::select('select pg_stat_clear_snapshot()');

        return (int) DB::selectOne(
            "select count(*) as total from pg_stat_activity a
             join pg_locks l on l.pid = a.pid
             where a.application_name = ? and l.locktype = 'advisory' and not l.granted",
            [$applicationName]
        )->total > 0;
    }

    private function waitUntil(callable $condition, string $description): void
    {
        $deadline = microtime(true) + self::WAIT_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            usleep(50000);
        }

        throw new RuntimeException("Timed out waiting for {$description}.");
    }

    /** @param array<int, array{process: resource, stdout: resource, stderr: resource}> $workers */
    private function withWorkerOutput(RuntimeException $error, array $workers): RuntimeException
    {
        $output = array_map(fn (array $worker): array => $this->collectWorker($worker), $workers);

        return new RuntimeException($error->getMessage().' Worker output: '.json_encode($output), 0, $error);
    }

    /** @return array{process: resource, stdout: resource, stderr: resource} */
    private function startRevocationWorker(string $grantedAccessId, string $actorId, string $applicationName): array
    {
        return $this->startWorker([
            base_path('tests/PostgreSQL/Support/record_external_access_revocation_worker.php'),
            $grantedAccessId,
            $actorId,
            $applicationName,
        ]);
    }

    /** @return array{process: resource, stdout: resource, stderr: resource} */
    private function startCreateWorker(string $requesterId, string $profileId, string $applicationName): array
    {
        return $this->startWorker([
            base_path('tests/PostgreSQL/Support/create_access_request_worker.php'),
            $requesterId,
            $profileId,
            'request after revocation',
            $applicationName,
        ]);
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array{process: resource, stdout: resource, stderr: resource}
     */
    private function startWorker(array $arguments): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $environment = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_HOST' => config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => config('database.connections.pgsql.password'),
        ];

        $process = proc_open(array_merge([PHP_BINARY], $arguments), $descriptors, $pipes, base_path(), $environment);

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the concurrency worker process.');
        }

        return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    /**
     * @param  array{process: resource, stdout: resource, stderr: resource}  $worker
     * @return array<string, mixed>
     */
    private function collectWorker(array $worker): array
    {
        $stdout = trim((string) stream_get_contents($worker['stdout']));
        $stderr = trim((string) stream_get_contents($worker['stderr']));

        fclose($worker['stdout']);
        fclose($worker['stderr']);
        proc_close($worker['process']);

        $decoded = json_decode($stdout, true);

        if (! is_array($decoded)) {
            return ['status' => 'unparsable', 'stdout' => $stdout, 'stderr' => $stderr];
        }

        return $decoded;
    }
}
