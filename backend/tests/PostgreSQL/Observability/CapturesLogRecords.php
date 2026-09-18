<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\Observability;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Captures what the application really writes: the default channel becomes an
 * in-memory Monolog handler, so the records pass through the same logger and
 * the same processors as in production — including the one that injects the
 * execution context — and can be inspected as records instead of as a file.
 */
trait CapturesLogRecords
{
    private TestHandler $capturedRecords;

    protected function captureLogRecords(): void
    {
        config(['logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);

        $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $handler);

        Log::setDefaultDriver('capture');

        $this->capturedRecords = $handler;
    }

    /**
     * @return list<LogRecord>
     */
    protected function records(?Level $level = null): array
    {
        return array_values(array_filter(
            $this->capturedRecords->getRecords(),
            static fn (LogRecord $record): bool => $level === null || $record->level === $level,
        ));
    }

    protected function soleRecord(?Level $level = null): LogRecord
    {
        $records = $this->records($level);
        $this->assertCount(1, $records, 'Exactly one record was expected.');

        return $records[0];
    }

    protected function assertNoOperationalError(): void
    {
        $this->assertSame(
            [],
            array_map(static fn (LogRecord $record): string => $record->message, $this->records(Level::Error)),
            'An expected failure must not become an operational error.'
        );
    }

    /**
     * The records as the application's own log format writes them: the whole
     * line, including the context, the execution context and any exception.
     */
    protected function renderedRecords(): string
    {
        $formatter = Log::channel('single')->getLogger()->getHandlers()[0]->getFormatter();

        return implode('', array_map(
            static fn (LogRecord $record): string => (string) $formatter->format($record),
            $this->records(),
        ));
    }

    protected function assertRecordsDoNotContain(string ...$values): void
    {
        $rendered = $this->renderedRecords();

        foreach ($values as $value) {
            $this->assertStringNotContainsString($value, $rendered);
        }
    }

    protected function assertExecutionId(LogRecord $record): string
    {
        $executionId = $record->extra['execution_id'] ?? null;
        $this->assertIsString($executionId);
        // A server-generated UUIDv4 (RFC 9562): version 4, variant 10xx.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $executionId
        );

        return $executionId;
    }
}
