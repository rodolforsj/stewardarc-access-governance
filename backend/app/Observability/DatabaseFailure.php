<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Database\QueryException;

/**
 * The sanitized operational record of a database failure
 * ([ADR-012](docs/architecture/adr/0012-operational-observability-baseline.md)).
 * The raw report is refused: the message of a `QueryException` carries the
 * statement with its binding values interpolated, the driver's own message —
 * which in PostgreSQL can include the value of the offending column — and the
 * connection host, port and database. None of that is needed to know which
 * operation failed and why, so only the exception class, the SQLSTATE and the
 * application frame are kept. The `execution_id` and the `operation` come from
 * the context of the execution and are never duplicated here.
 */
final class DatabaseFailure
{
    public const MESSAGE = 'Unexpected database failure.';

    /** Five characters, digits and upper-case letters (SQL:2011, part 2). */
    private const SQLSTATE = '/^[0-9A-Z]{5}$/';

    /**
     * @return array<string, string>
     */
    public static function context(QueryException $failure): array
    {
        return array_filter([
            'exception_class' => $failure::class,
            'sqlstate' => self::sqlstate($failure),
            'code_reference' => self::codeReference($failure),
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * Only the structural code the driver reports. The driver's message is
     * never read, and a value that is not a SQLSTATE is left out.
     */
    private static function sqlstate(QueryException $failure): ?string
    {
        $state = is_array($failure->errorInfo) ? ($failure->errorInfo[0] ?? null) : null;

        return is_string($state) && preg_match(self::SQLSTATE, $state) === 1 ? $state : null;
    }

    /**
     * The first application frame, as a path relative to the project and a
     * line, such as `app/AccessGovernance/Actions/CreateAccessRequest.php:84`.
     * Frame arguments are never read and the trace is never serialized; with
     * no application frame, the field is simply left out.
     */
    private static function codeReference(QueryException $failure): ?string
    {
        foreach ($failure->getTrace() as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;

            if (! is_string($file) || ! is_int($line)) {
                continue;
            }

            if (! str_starts_with($file, app_path().DIRECTORY_SEPARATOR)) {
                continue;
            }

            return str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).':'.$line;
        }

        return null;
    }
}
