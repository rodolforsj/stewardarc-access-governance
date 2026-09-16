<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Concurrency\Rn03AdvisoryLock;
use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\ActorReference;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * Two independent processes create the same equivalent request at the same
 * time. Exactly one must succeed; the other must be rejected by RN03.
 */
final class CreateAccessRequestConcurrencyTest extends PostgresTestCase
{
    private const WAITER_TIMEOUT_SECONDS = 20;

    public function test_two_concurrent_creations_produce_exactly_one_request(): void
    {
        [$requester, $profile] = $this->committedFixtures();

        // The parent holds the RN03 advisory lock so both workers block on it.
        DB::beginTransaction();
        (new Rn03AdvisoryLock())->acquire($requester->id, $profile->id);

        $workers = [
            $this->startWorker($requester->id, $profile->id, 'worker one'),
            $this->startWorker($requester->id, $profile->id, 'worker two'),
        ];

        try {
            $this->waitForAdvisoryWaiters(2);
        } catch (RuntimeException $error) {
            DB::rollBack();
            foreach ($workers as $worker) {
                $this->collectWorker($worker);
            }

            throw $error;
        }

        // Releasing the parent lock lets exactly one worker enter first.
        DB::commit();

        $results = array_map(fn (array $worker): array => $this->collectWorker($worker), $workers);
        $statuses = array_column($results, 'status');
        sort($statuses);

        $this->assertSame(['created', 'rule-violation'], $statuses, 'Unexpected worker outcomes: '.json_encode($results));

        $violation = $results[array_search('rule-violation', array_column($results, 'status'), true)];
        $this->assertSame('RN03', $violation['ruleId'] ?? null);

        $requests = AccessRequest::query()
            ->where('requester_actor_reference_id', $requester->id)
            ->where('access_profile_id', $profile->id)
            ->get();

        $this->assertCount(1, $requests);
        $this->assertSame('S1', $requests->first()->current_state);
        $this->assertContains($requests->first()->id, array_column($results, 'id'));
    }

    /** @return array{0: ActorReference, 1: AccessProfile} */
    private function committedFixtures(): array
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');

        // Fixtures are written outside any transaction, so they are already
        // committed and visible to the independent worker connections.
        $this->assertSame(0, DB::transactionLevel());

        return [$requester, $profile];
    }

    /** @return array{process: resource, stdout: resource, stderr: resource} */
    private function startWorker(string $requesterId, string $profileId, string $justification): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $command = [
            PHP_BINARY,
            base_path('tests/PostgreSQL/Support/create_access_request_worker.php'),
            $requesterId,
            $profileId,
            $justification,
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
     * Waits until both workers are actually blocked on the advisory lock, so the
     * test proves contention instead of relying on timing.
     */
    private function waitForAdvisoryWaiters(int $expected): void
    {
        $deadline = microtime(true) + self::WAITER_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            $waiters = (int) DB::selectOne(
                "select count(*) as total from pg_locks where locktype = 'advisory' and not granted"
            )->total;

            if ($waiters >= $expected) {
                return;
            }

            usleep(50000);
        }

        throw new RuntimeException("Timed out waiting for {$expected} advisory lock waiters.");
    }
}
