<?php

declare(strict_types=1);

/**
 * Test-only worker: runs the real CreateAccessRequest Action in an independent
 * process/connection and reports the outcome as JSON on stdout.
 *
 * Usage: php create_access_request_worker.php <requesterId> <profileId> <justification>
 */

use App\AccessGovernance\Actions\CreateAccessRequest;
use App\AccessGovernance\Exceptions\AccessRequestRuleViolation;
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

try {
    $request = (new CreateAccessRequest())->execute(
        (string) ($argv[1] ?? ''),
        (string) ($argv[2] ?? ''),
        (string) ($argv[3] ?? 'concurrent worker'),
    );

    echo json_encode(['status' => 'created', 'id' => $request->id]), PHP_EOL;
} catch (AccessRequestRuleViolation $violation) {
    echo json_encode(['status' => 'rule-violation', 'ruleId' => $violation->ruleId]), PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['status' => 'error', 'class' => $error::class, 'message' => $error->getMessage()]), PHP_EOL;
}
