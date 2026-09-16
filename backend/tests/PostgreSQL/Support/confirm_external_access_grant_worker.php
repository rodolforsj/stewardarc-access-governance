<?php

declare(strict_types=1);

/**
 * Test-only worker: runs the real ConfirmExternalAccessGrant Action in an
 * independent process/connection and reports the outcome as JSON on stdout.
 *
 * Usage: php confirm_external_access_grant_worker.php <requestId> <actorId> [applicationName]
 */

use App\AccessGovernance\Actions\ConfirmExternalAccessGrant;
use App\AccessGovernance\Exceptions\GrantConfirmationViolation;
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

if (($argv[3] ?? '') !== '') {
    // Identifies this worker's backend in pg_stat_activity.
    DB::select("select set_config('application_name', ?, false)", [(string) $argv[3]]);
}

try {
    $confirmation = (new ConfirmExternalAccessGrant())->execute(
        (string) ($argv[1] ?? ''),
        (string) ($argv[2] ?? ''),
    );

    echo json_encode(['status' => 'confirmed', 'id' => $confirmation->id]), PHP_EOL;
} catch (GrantConfirmationViolation $violation) {
    echo json_encode(['status' => 'violation', 'constraintId' => $violation->constraintId]), PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['status' => 'error', 'class' => $error::class, 'message' => $error->getMessage()]), PHP_EOL;
}
