<?php

declare(strict_types=1);

/**
 * Test-only worker: runs the real DecideAccessRequest Action in an independent
 * process/connection and reports the outcome as JSON on stdout.
 *
 * Usage: php decide_access_request_worker.php <requestId> <actorId> <outcome> <applicationName>
 */

use App\AccessGovernance\Actions\DecideAccessRequest;
use App\AccessGovernance\Exceptions\AccessDecisionRuleViolation;
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
    echo json_encode(['status' => 'guard-failed', 'database' => $database]), PHP_EOL;
    exit(1);
}

// Identifies this worker's backend in pg_stat_activity so the test can observe
// exactly these two sessions waiting for the row lock.
DB::select('select set_config(?, ?, false)', ['application_name', (string) ($argv[4] ?? 'stewardarc-decide-worker')]);

try {
    $decision = (new DecideAccessRequest())->execute(
        (string) ($argv[1] ?? ''),
        (string) ($argv[2] ?? ''),
        (string) ($argv[3] ?? 'approved'),
    );

    echo json_encode(['status' => 'decided', 'id' => $decision->id, 'stage' => $decision->stage]), PHP_EOL;
} catch (AccessDecisionRuleViolation $violation) {
    echo json_encode(['status' => 'rule-violation', 'ruleId' => $violation->ruleId]), PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['status' => 'error', 'class' => $error::class, 'message' => $error->getMessage()]), PHP_EOL;
}
