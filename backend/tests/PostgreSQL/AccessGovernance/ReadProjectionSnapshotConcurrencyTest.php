<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Actions\ConfirmExternalAccessGrant;
use App\AccessGovernance\Actions\CreateAccessRequest;
use App\AccessGovernance\Actions\FollowMyRequestsAndAccesses;
use App\Models\GrantConfirmation;
use App\Models\GrantedAccess;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\PostgreSQL\PostgresTestCase;
use Throwable;

/**
 * ADR-008: a read projection keeps one snapshot while a grant commits in the
 * middle of it, and a later projection observes the new facts.
 */
final class ReadProjectionSnapshotConcurrencyTest extends PostgresTestCase
{
    private const WAIT_TIMEOUT_SECONDS = 20;

    public function test_a_grant_committed_during_the_read_does_not_produce_a_hybrid_projection(): void
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = (new CreateAccessRequest())->execute($requester->id, $profile->id, 'Needed.');
        $this->approveUntilAwaitingGrant($request, $owner);

        $this->assertSame('S3', $request->fresh()->current_state);
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed before the worker starts.');

        $applicationName = 'stewardarc-read-'.bin2hex(random_bytes(6));
        $worker = $this->startWorker($request->id, $applicationName);

        try {
            $ready = $this->readLine($worker, 'the worker to establish its snapshot');
            $this->assertSame('ready', $ready['phase'] ?? null, 'Worker: '.json_encode($ready));

            // The worker is parked inside its read transaction, holding no lock
            // that the grant needs.
            $this->assertSame('idle in transaction', $this->sessionState($applicationName));

            $confirmation = (new ConfirmExternalAccessGrant())->execute($request->id, $owner->id);

            // The grant is committed and visible to any new snapshot.
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('S4', $request->fresh()->current_state);
            $this->assertSame(1, GrantedAccess::query()->count());

            fwrite($worker['stdin'], "continue\n");
            fflush($worker['stdin']);

            $done = $this->readLine($worker, 'the worker to finish its second read');
        } catch (Throwable $error) {
            $output = $this->stopWorker($worker);

            if ($error instanceof RuntimeException) {
                throw new RuntimeException($error->getMessage().' Worker output: '.json_encode($output), 0, $error);
            }

            throw $error;
        }

        $this->stopWorker($worker);

        $this->assertSame('done', $done['phase'] ?? null, 'Worker: '.json_encode($done));
        $this->assertSame('continue', $done['signal']);

        $expectedSnapshot = [
            'current_state' => 'S3',
            'grant_confirmation_id' => null,
            'granted_access_id' => null,
        ];

        // Both reads belong to the old snapshot: still S3, no grant facts.
        foreach (['before', 'after'] as $read) {
            $this->assertSame(
                $expectedSnapshot,
                array_intersect_key($done[$read], $expectedSnapshot),
                "Hybrid or fresh view in the [{$read}] read: ".json_encode($done)
            );
        }

        $this->assertSame($done['before']['transaction_timestamp'], $done['after']['transaction_timestamp']);
        $this->assertSame('repeatable read', $ready['isolation']);
        $this->assertSame('on', $ready['read_only']);

        // A subsequent projection observes the committed grant.
        $projection = (new FollowMyRequestsAndAccesses())->execute($requester->id);

        $this->assertCount(1, $projection['requests']);
        $projected = $projection['requests'][0];
        $grantedAccess = GrantedAccess::query()->where('grant_confirmation_id', $confirmation->id)->firstOrFail();

        $this->assertSame('S4', $projected['current_state']);
        $this->assertSame($grantedAccess->id, $projected['granted_access']['id']);
        $this->assertSame('A1', $projected['granted_access']['state']);
        $this->assertContains($confirmation->id, array_column($projected['history'], 'fact_id'));
        $this->assertTrue($projection['projection_reference_at']->greaterThan(new DateTimeImmutable($done['reference_at'])));
        $this->assertSame(1, GrantConfirmation::query()->count());
    }

    private function sessionState(string $applicationName): ?string
    {
        DB::select('select pg_stat_clear_snapshot()');

        $row = DB::selectOne('select state from pg_stat_activity where application_name = ?', [$applicationName]);

        return $row?->state;
    }

    /** @return array{process: resource, stdin: resource, stdout: resource, stderr: resource, buffer: string} */
    private function startWorker(string $requestId, string $applicationName): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

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

        $process = proc_open(
            [PHP_BINARY, base_path('tests/PostgreSQL/Support/read_projection_snapshot_worker.php'), $requestId, $applicationName],
            $descriptors,
            $pipes,
            base_path(),
            $environment
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the read projection worker process.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'buffer' => ''];
    }

    /**
     * Reads one JSON line from the worker, failing after a safety timeout.
     *
     * @param  array{process: resource, stdin: resource, stdout: resource, stderr: resource, buffer: string}  $worker
     * @return array<string, mixed>
     */
    private function readLine(array &$worker, string $description): array
    {
        $deadline = microtime(true) + self::WAIT_TIMEOUT_SECONDS;

        while (($newline = strpos($worker['buffer'], "\n")) === false) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw new RuntimeException("Timed out waiting for {$description}.");
            }

            $read = [$worker['stdout']];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 0, (int) min($remaining * 1000000, 200000)) > 0) {
                $chunk = fread($worker['stdout'], 8192);

                if ($chunk === '' && feof($worker['stdout'])) {
                    throw new RuntimeException("The worker exited while waiting for {$description}.");
                }

                $worker['buffer'] .= (string) $chunk;
            }
        }

        $line = substr($worker['buffer'], 0, $newline);
        $worker['buffer'] = substr($worker['buffer'], $newline + 1);

        $decoded = json_decode($line, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Unparsable worker output while waiting for {$description}: {$line}");
        }

        return $decoded;
    }

    /**
     * @param  array{process: resource, stdin: resource, stdout: resource, stderr: resource, buffer: string}  $worker
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function stopWorker(array $worker): array
    {
        if (is_resource($worker['stdin'])) {
            fclose($worker['stdin']);
        }

        $status = proc_get_status($worker['process']);

        if ($status['running']) {
            $deadline = microtime(true) + 5;

            while ($status['running'] && microtime(true) < $deadline) {
                usleep(20000);
                $status = proc_get_status($worker['process']);
            }

            if ($status['running']) {
                proc_terminate($worker['process']);
            }
        }

        $output = [
            'stdout' => $worker['buffer'].(string) stream_get_contents($worker['stdout']),
            'stderr' => (string) stream_get_contents($worker['stderr']),
            'exit' => $status['running'] ? -1 : $status['exitcode'],
        ];

        fclose($worker['stdout']);
        fclose($worker['stderr']);
        proc_close($worker['process']);

        return $output;
    }
}
