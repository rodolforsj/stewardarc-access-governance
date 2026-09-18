<?php

declare(strict_types=1);

namespace App\Observability;

use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * The operational execution of [ADR-012](docs/architecture/adr/0012-operational-observability-baseline.md):
 * the `execution_id` that correlates the records of one execution and the
 * `operation` that names what is running. This is not a logging façade: it
 * runs a callable as one operation, reports an unexpected failure while that
 * operation is still the active one, rethrows the very same exception and
 * gives the previous context back. It touches no database and persists
 * nothing.
 */
final class OperationContext
{
    /** The correlation key. It is always generated here, never read from a request. */
    public const EXECUTION_ID = 'execution_id';

    /** The functionality being executed: a route name, or a critical Action's operation. */
    public const OPERATION = 'operation';

    /**
     * Runs $callback as $operation inside the current execution, starting one
     * when there is none. An Action called from HTTP therefore keeps the
     * execution of the request and only replaces the operation while it runs.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws Throwable the same instance the callback threw
     */
    public static function run(string $operation, Closure $callback): mixed
    {
        $current = Context::get(self::EXECUTION_ID);

        return self::within(is_string($current) ? $current : self::newExecutionId(), $operation, $callback);
    }

    /**
     * Runs $callback as a new execution: one HTTP request is one execution,
     * whatever context the process happens to carry.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws Throwable the same instance the callback threw
     */
    public static function runNewExecution(?string $operation, Closure $callback): mixed
    {
        return self::within(self::newExecutionId(), $operation, $callback);
    }

    /**
     * A server-generated, opaque UUIDv4: no ordering, no embedded time and no
     * functional meaning, which also keeps it distinct from the domain's own
     * identifiers.
     */
    private static function newExecutionId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws Throwable
     */
    private static function within(string $executionId, ?string $operation, Closure $callback): mixed
    {
        $context = [self::EXECUTION_ID => $executionId];

        if ($operation !== null) {
            $context[self::OPERATION] = $operation;
        }

        // The scope restores the whole previous context when it ends, so a
        // nested operation cannot outlive itself and nothing leaks into the
        // next execution.
        return Context::scope(function () use ($callback): mixed {
            try {
                return $callback();
            } catch (Throwable $failure) {
                // Reported here, before the scope restores the previous
                // context, so the record names the operation that failed. The
                // handler keeps one record per exception instance.
                report($failure);

                throw $failure;
            }
        }, $context);
    }
}
