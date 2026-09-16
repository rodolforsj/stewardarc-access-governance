<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Concurrency\Rn03AdvisoryLock;
use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\ActorReference;
use App\Models\GrantConfirmation;
use App\Models\GrantedAccess;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * The RN03 advisory lock closes the S3 → S4 → Granted Access window: two
 * concurrent confirmations, and a confirmation racing a new equivalent request.
 */
final class ConfirmExternalAccessGrantConcurrencyTest extends PostgresTestCase
{
    private const WAITER_TIMEOUT_SECONDS = 20;

    private string $applicationNamePrefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationNamePrefix = 'stewardarc-grant-'.bin2hex(random_bytes(6));
    }

    public function test_two_concurrent_confirmations_record_exactly_one_grant(): void
    {
        [$request, $owner] = $this->committedRequestAwaitingGrant();

        $this->holdRn03AdvisoryLock($request);

        $workers = [
            $this->startGrantWorker($request->id, $owner->id, $this->applicationNamePrefix.'-grant-one'),
            $this->startGrantWorker($request->id, $owner->id, $this->applicationNamePrefix.'-grant-two'),
        ];

        $results = $this->releaseAndCollect($workers);
        $statuses = array_column($results, 'status');
        sort($statuses);

        $this->assertSame(['confirmed', 'violation'], $statuses, 'Unexpected worker outcomes: '.json_encode($results));

        $violation = $results[array_search('violation', array_column($results, 'status'), true)];
        $this->assertSame('RN10', $violation['constraintId'] ?? null);

        $this->assertSame(1, GrantConfirmation::query()->count());
        $this->assertSame(1, GrantedAccess::query()->count());
        $this->assertSame('S4', $request->fresh()->current_state);
    }

    /**
     * The motivating window of ADR-005: whoever takes the lock first, the new
     * equivalent request is refused by RN03 and the grant still completes.
     */
    public function test_a_concurrent_new_request_is_refused_while_the_grant_is_confirmed(): void
    {
        [$request, $owner, $requester, $profile] = $this->committedRequestAwaitingGrant();

        $this->holdRn03AdvisoryLock($request);

        $workers = [
            $this->startGrantWorker($request->id, $owner->id, $this->applicationNamePrefix.'-grant'),
            $this->startCreateWorker($requester->id, $profile->id, $this->applicationNamePrefix.'-create'),
        ];

        $results = $this->releaseAndCollect($workers);

        $grantResult = $results[0];
        $createResult = $results[1];

        $this->assertSame('confirmed', $grantResult['status'] ?? null, 'Grant result: '.json_encode($results));
        $this->assertSame('rule-violation', $createResult['status'] ?? null, 'Create result: '.json_encode($results));
        $this->assertSame('RN03', $createResult['ruleId'] ?? null);

        $this->assertSame('S4', $request->fresh()->current_state);
        $this->assertSame(1, GrantConfirmation::query()->count());
        $this->assertSame(1, GrantedAccess::query()->count());
        $this->assertSame(1, AccessRequest::query()->count());
    }

    /** @return array{0: AccessRequest, 1: ActorReference, 2: ActorReference, 3: AccessProfile} */
    private function committedRequestAwaitingGrant(): array
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($requester, $profile, 'S1');
        $this->approveUntilAwaitingGrant($request, $owner);

        $this->assertSame('S3', $request->fresh()->current_state);
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed before the workers start.');

        return [$request, $owner, $requester, $profile];
    }

    /** The parent holds the production advisory lock so both workers block on it. */
    private function holdRn03AdvisoryLock(AccessRequest $request): void
    {
        DB::beginTransaction();

        (new Rn03AdvisoryLock())->acquire(
            (string) $request->requester_actor_reference_id,
            (string) $request->access_profile_id,
        );
    }

    /**
     * @param  array<int, array{process: resource, stdout: resource, stderr: resource}>  $workers
     * @return array<int, array<string, mixed>>
     */
    private function releaseAndCollect(array $workers): array
    {
        try {
            $this->waitForWorkersWaitingOnLock(count($workers));
        } catch (RuntimeException $error) {
            DB::rollBack();
            $output = array_map(fn (array $worker): array => $this->collectWorker($worker), $workers);

            throw new RuntimeException($error->getMessage().' Worker output: '.json_encode($output), 0, $error);
        }

        DB::commit();

        return array_map(fn (array $worker): array => $this->collectWorker($worker), $workers);
    }

    /** @return array{process: resource, stdout: resource, stderr: resource} */
    private function startGrantWorker(string $requestId, string $actorId, string $applicationName): array
    {
        return $this->startWorker([
            base_path('tests/PostgreSQL/Support/confirm_external_access_grant_worker.php'),
            $requestId,
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
            'concurrent equivalent request',
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

    /**
     * Waits until this test's own workers are blocked, identified by their
     * application_name. Statistics views are cached inside a transaction, so
     * the snapshot is discarded on every poll.
     */
    private function waitForWorkersWaitingOnLock(int $expected): void
    {
        $deadline = microtime(true) + self::WAITER_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            DB::select('select pg_stat_clear_snapshot()');

            $waiting = (int) DB::selectOne(
                "select count(*) as total from pg_stat_activity
                 where application_name like ? and wait_event_type = 'Lock'",
                [$this->applicationNamePrefix.'%']
            )->total;

            if ($waiting >= $expected) {
                return;
            }

            usleep(50000);
        }

        throw new RuntimeException("Timed out waiting for {$expected} workers blocked on the advisory lock.");
    }
}
