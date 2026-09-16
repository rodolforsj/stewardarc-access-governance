<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\ActorReference;
use App\Models\Decision;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * Two independent processes decide the same access request at the same time.
 * The row lock must serialize them: exactly one decision is recorded, and the
 * second attempt finds no pending step (RN09).
 */
final class DecideAccessRequestConcurrencyTest extends PostgresTestCase
{
    private const WAITER_TIMEOUT_SECONDS = 20;

    private string $applicationNamePrefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationNamePrefix = 'stewardarc-decide-'.bin2hex(random_bytes(6));
    }

    public function test_two_concurrent_decisions_record_exactly_one_decision(): void
    {
        [$request, $owner] = $this->committedFixtures();

        // The parent holds the row lock so both workers block on the same row.
        DB::beginTransaction();
        AccessRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

        $workers = [
            $this->startWorker($request->id, $owner->id, $this->applicationNamePrefix.'-one'),
            $this->startWorker($request->id, $owner->id, $this->applicationNamePrefix.'-two'),
        ];

        try {
            $this->waitForWorkersWaitingOnLock(2);
        } catch (RuntimeException $error) {
            DB::rollBack();
            $output = array_map(fn (array $worker): array => $this->collectWorker($worker), $workers);

            throw new RuntimeException($error->getMessage().' Worker output: '.json_encode($output), 0, $error);
        }

        // Releasing the parent lock lets exactly one worker decide first.
        DB::commit();

        $results = array_map(fn (array $worker): array => $this->collectWorker($worker), $workers);
        $statuses = array_column($results, 'status');
        sort($statuses);

        $this->assertSame(['decided', 'rule-violation'], $statuses, 'Unexpected worker outcomes: '.json_encode($results));

        $violation = $results[array_search('rule-violation', array_column($results, 'status'), true)];
        $this->assertSame('RN09', $violation['ruleId'] ?? null);

        $decisions = Decision::query()->where('access_request_id', $request->id)->get();
        $this->assertCount(1, $decisions);
        $this->assertSame('resource_owner', $decisions->first()->stage);
        $this->assertSame('approved', $decisions->first()->outcome);
        $this->assertSame($owner->id, $decisions->first()->actor_reference_id);
        $this->assertSame('S3', $request->fresh()->current_state);
    }

    /** @return array{0: AccessRequest, 1: ActorReference} */
    private function committedFixtures(): array
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($requester, $profile, 'S1');

        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed before the workers start.');
        $this->assertInstanceOf(AccessProfile::class, $profile);

        return [$request, $owner];
    }

    /** @return array{process: resource, stdout: resource, stderr: resource} */
    private function startWorker(string $requestId, string $actorId, string $applicationName): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $command = [
            PHP_BINARY,
            base_path('tests/PostgreSQL/Support/decide_access_request_worker.php'),
            $requestId,
            $actorId,
            'approved',
            $applicationName,
        ];

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

        $process = proc_open($command, $descriptors, $pipes, base_path(), $environment);

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the decision worker process.');
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
     * Waits until both workers are blocked on a lock, identifying them by their
     * own application_name instead of counting waiters server-wide.
     */
    private function waitForWorkersWaitingOnLock(int $expected): void
    {
        $deadline = microtime(true) + self::WAITER_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            // Statistics views are cached for the duration of a transaction,
            // and this observation runs inside the transaction that holds the
            // row lock, so the snapshot has to be discarded on every poll.
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

        throw new RuntimeException("Timed out waiting for {$expected} workers blocked on the row lock.");
    }
}
