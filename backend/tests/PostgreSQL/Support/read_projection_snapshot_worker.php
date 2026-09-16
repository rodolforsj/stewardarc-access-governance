<?php

declare(strict_types=1);

/**
 * Test-only worker: opens the real ReadProjectionTransaction in an independent
 * process/connection, reads one request and its grant facts, reports "ready"
 * on stdout, waits for a line on stdin and reads the same facts again inside
 * the same transaction. The final result is written as JSON on stdout.
 *
 * Usage: php read_projection_snapshot_worker.php <accessRequestId> [applicationName]
 */

use App\AccessGovernance\Concurrency\ReadProjectionTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
$database = (string) $connection->getDatabaseName();

if ($connection->getDriverName() !== 'pgsql' || ! str_ends_with($database, '_test')) {
    echo json_encode(['phase' => 'guard-failed', 'database' => $database]), PHP_EOL;
    exit(1);
}

$requestId = (string) ($argv[1] ?? '');

if (($argv[2] ?? '') !== '') {
    // Identifies this worker's backend in pg_stat_activity; set before the
    // read transaction starts.
    DB::select("select set_config('application_name', ?, false)", [(string) $argv[2]]);
}

/** The request state and its grant facts, as seen by the current snapshot. */
$observe = static function () use ($requestId): array {
    $grantConfirmationId = DB::table('grant_confirmations')
        ->where('access_request_id', $requestId)
        ->value('id');

    $grantedAccessId = DB::table('granted_accesses')
        ->join('grant_confirmations', 'grant_confirmations.id', '=', 'granted_accesses.grant_confirmation_id')
        ->where('grant_confirmations.access_request_id', $requestId)
        ->value('granted_accesses.id');

    return [
        'current_state' => DB::table('access_requests')->where('id', $requestId)->value('current_state'),
        'grant_confirmation_id' => $grantConfirmationId,
        'granted_access_id' => $grantedAccessId,
        'transaction_timestamp' => DB::selectOne('select transaction_timestamp() as value')->value,
    ];
};

try {
    $result = (new ReadProjectionTransaction())->run(
        static function (CarbonImmutable $referenceAt) use ($observe): array {
            $before = $observe();

            echo json_encode([
                'phase' => 'ready',
                'isolation' => DB::selectOne("select current_setting('transaction_isolation') as value")->value,
                'read_only' => DB::selectOne("select current_setting('transaction_read_only') as value")->value,
                'before' => $before,
            ]), PHP_EOL;
            fflush(STDOUT);

            // Blocks until the parent has committed its write.
            $signal = fgets(STDIN);

            return [
                'reference_at' => $referenceAt->format('Y-m-d H:i:s.uP'),
                'signal' => $signal === false ? null : trim($signal),
                'before' => $before,
                'after' => $observe(),
            ];
        }
    );

    echo json_encode(['phase' => 'done'] + $result), PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['phase' => 'error', 'class' => $error::class, 'message' => $error->getMessage()]), PHP_EOL;
    exit(1);
}
