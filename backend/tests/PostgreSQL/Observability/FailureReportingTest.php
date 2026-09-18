<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\Observability;

use App\AccessGovernance\Actions\CreateAccessRequest;
use App\AccessGovernance\Actions\DecideAccessRequest;
use App\AccessGovernance\Exceptions\AccessDecisionRuleViolation;
use App\AccessGovernance\Exceptions\AccessRequestRuleViolation;
use App\AccessGovernance\Exceptions\GrantConfirmationViolation;
use App\AccessGovernance\Exceptions\RevocationConfirmationViolation;
use App\Models\ActorReference;
use App\Observability\DatabaseFailure;
use App\Observability\OperationContext;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Monolog\Level;
use RuntimeException;
use Tests\PostgreSQL\PostgresTestCase;
use Throwable;

/**
 * ADR-012 / RNF-010 and RNF-006: an unexpected failure produces one record
 * naming its execution, its operation and its condition, and nothing of the
 * payload; an expected failure produces no operational error.
 */
final class FailureReportingTest extends PostgresTestCase
{
    use CapturesLogRecords;

    private const IDENTIFIER_SENTINEL = 'not-a-uuid-IDENTIFIER-SENTINEL';

    private const JUSTIFICATION_SENTINEL = 'JUSTIFICATION-SENTINEL';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureLogRecords();
    }

    // ------------------------------------------------------------------
    // A critical Action invoked directly

    public function test_a_critical_action_invoked_directly_reports_its_failure_as_its_own_execution(): void
    {
        $requester = $this->makeActor();

        $thrown = $this->failureOf(fn () => (new CreateAccessRequest())->execute($requester->id, 'not-a-uuid', 'Needed.'));

        // An unexpected failure: the advisory lock refuses a malformed key.
        $this->assertInstanceOf(InvalidArgumentException::class, $thrown);

        $record = $this->soleRecord(Level::Error);
        $this->assertExecutionId($record);
        $this->assertSame('access_request.create', $record->extra['operation']);
        // Reported before the context was given back: the record carries the
        // Action's execution and operation, and the very instance rethrown.
        $this->assertSame($thrown, $record->context['exception']);
        $this->assertSame([], Context::all());
    }

    public function test_the_trace_of_a_reported_failure_carries_no_argument_values(): void
    {
        $this->assertSame('1', ini_get('zend.exception_ignore_args'));

        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner));

        // A duration for a standard profile breaks the Action's contract: an
        // unexpected failure, recorded by the default report with its trace.
        $thrown = $this->failureOf(fn () => (new CreateAccessRequest())->execute(
            $this->makeActor()->id,
            $profile->id,
            self::JUSTIFICATION_SENTINEL.' with the rest of the text',
            60,
        ));

        $this->assertInstanceOf(InvalidArgumentException::class, $thrown);
        $this->assertSame('access_request.create', $this->soleRecord(Level::Error)->extra['operation']);

        // The trace still locates the failure, without the values of the call.
        $rendered = $this->renderedRecords();
        $this->assertStringContainsString('[stacktrace]', $rendered);
        $this->assertStringContainsString('CreateAccessRequest->execute()', $rendered);
        $this->assertRecordsDoNotContain(
            substr(self::JUSTIFICATION_SENTINEL, 0, 15),
            substr($profile->id, 0, 15),
            substr($owner->id, 0, 15),
        );
    }

    public function test_the_same_failure_is_reported_once_when_an_outer_boundary_meets_it_again(): void
    {
        $thrown = $this->failureOf(
            fn () => (new DecideAccessRequest())->execute(self::IDENTIFIER_SENTINEL, $this->makeActor()->id, 'approved')
        );

        // An outer boundary reporting the same instance adds nothing.
        report($thrown);
        app(ExceptionHandler::class)->report($thrown);

        $this->assertCount(1, $this->records(Level::Error));
    }

    public function test_a_failing_action_inside_a_request_is_reported_once_under_its_own_operation(): void
    {
        $requester = $this->makeActor();

        Route::middleware('web')
            ->get('/_test/observability/failing-action', fn () => (new CreateAccessRequest())->execute($requester->id, 'not-a-uuid', 'Needed.'))
            ->name('test.observability.failing-action');

        $this->get('/_test/observability/failing-action')->assertStatus(500);

        // The Action reported it first; the HTTP boundary met the same
        // instance and recorded nothing more.
        $record = $this->soleRecord(Level::Error);
        $this->assertExecutionId($record);
        $this->assertSame('access_request.create', $record->extra['operation']);
        $this->assertSame([], Context::all());
    }

    public function test_an_unexpected_failure_of_a_request_is_reported_once_under_its_route(): void
    {
        Route::middleware('web')
            ->get('/_test/observability/failing', fn () => throw new RuntimeException('Unexpected test failure.'))
            ->name('test.observability.failing');

        $this->get('/_test/observability/failing')->assertStatus(500);

        $record = $this->soleRecord(Level::Error);
        $this->assertExecutionId($record);
        $this->assertSame('test.observability.failing', $record->extra['operation']);
        $this->assertInstanceOf(RuntimeException::class, $record->context['exception']);
        // The record's own instant is the operational instant.
        $this->assertEqualsWithDelta(time(), $record->datetime->getTimestamp(), 60);
    }

    // ------------------------------------------------------------------
    // Expected failures

    public function test_the_functional_refusals_of_the_critical_actions_are_not_reported(): void
    {
        $handler = app(ExceptionHandler::class);

        foreach ([
            new AccessRequestRuleViolation('RN01', 'refused'),
            new AccessDecisionRuleViolation('RN04', 'refused'),
            new GrantConfirmationViolation('RN10', 'refused'),
            new RevocationConfirmationViolation('RF-007', 'refused'),
        ] as $refusal) {
            $this->assertFalse($handler->shouldReport($refusal), $refusal::class.' must not be reported.');
        }

        // Unexpected failures of the same shape still are.
        $this->assertTrue($handler->shouldReport(new InvalidArgumentException('defect')));
        $this->assertTrue($handler->shouldReport(new RuntimeException('defect')));
    }

    public function test_a_business_rule_violation_inside_a_critical_action_is_not_an_operational_error(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), isAvailable: false);

        $thrown = $this->failureOf(fn () => (new CreateAccessRequest())->execute($this->makeActor()->id, $profile->id, 'Needed.'));

        $this->assertInstanceOf(AccessRequestRuleViolation::class, $thrown);
        $this->assertSame('RN01', $thrown->ruleId);
        $this->assertSame([], $this->records());
    }

    public function test_a_guest_request_to_the_product_api_is_not_an_operational_error(): void
    {
        $this->getJson('/api/me/requests-and-accesses')->assertUnauthorized();

        $this->assertNoOperationalError();
    }

    /**
     * Laravel skips the CSRF check while running tests, so the middleware is
     * replaced, for this test only, by one that does not.
     */
    public function test_a_state_change_without_csrf_protection_is_not_an_operational_error(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $this->post('/auth/logout')->assertStatus(419);

        $this->assertNoOperationalError();
    }

    // ------------------------------------------------------------------
    // Database failures

    public function test_binding_values_are_masked_in_database_exception_messages(): void
    {
        $this->assertTrue(config('database.connections.pgsql.mask_bindings_in_exception_messages'));

        $thrown = $this->failureOf(
            fn () => (new DecideAccessRequest())->execute(self::IDENTIFIER_SENTINEL, $this->makeActor()->id, 'approved')
        );

        $this->assertInstanceOf(QueryException::class, $thrown);
        $statement = substr($thrown->getMessage(), (int) strpos($thrown->getMessage(), 'SQL: '));
        $this->assertStringContainsString('?', $statement);
        $this->assertStringNotContainsString(self::IDENTIFIER_SENTINEL, $statement);
    }

    public function test_a_database_failure_is_recorded_sanitized_and_only_once(): void
    {
        $thrown = $this->failureOf(fn () => (new DecideAccessRequest())->execute(
            self::IDENTIFIER_SENTINEL,
            $this->makeActor()->id,
            'rejected',
            self::JUSTIFICATION_SENTINEL,
        ));

        // The driver itself puts the value in its message: masking the
        // bindings alone would not be enough.
        $this->assertInstanceOf(QueryException::class, $thrown);
        $this->assertStringContainsString(self::IDENTIFIER_SENTINEL, $thrown->getMessage());

        $record = $this->soleRecord();
        $this->assertSame(Level::Error, $record->level);
        $this->assertSame(DatabaseFailure::MESSAGE, $record->message);
        $this->assertSame(['exception_class', 'sqlstate', 'code_reference'], array_keys($record->context));
        $this->assertSame(QueryException::class, $record->context['exception_class']);
        $this->assertSame('22P02', $record->context['sqlstate']);
        $this->assertMatchesRegularExpression(
            '#^app/AccessGovernance/Actions/DecideAccessRequest\.php:\d+$#',
            $record->context['code_reference']
        );
        $this->assertExecutionId($record);
        $this->assertSame('access_request.decide', $record->extra['operation']);

        $this->assertRecordsDoNotContain(
            self::IDENTIFIER_SENTINEL,
            self::JUSTIFICATION_SENTINEL,
            'SQLSTATE[',
            'invalid input syntax',
            'select ',
            'access_requests',
            'Connection:',
            'Host:',
            'Port:',
            'Database:',
            (string) config('database.connections.pgsql.database'),
            '[object]',
            '[stacktrace]',
            '[previous exception]',
            base_path(),
        );
    }

    public function test_a_constraint_violation_detail_never_reaches_the_record(): void
    {
        $keySentinel = 'oidc:v1:EXTERNAL-IDENTITY-KEY-SENTINEL';
        $this->makeActorWithKey($keySentinel);

        $thrown = $this->failureOf(fn () => OperationContext::run(
            'test.duplicate-identity',
            fn () => $this->makeActorWithKey($keySentinel),
        ));

        // PostgreSQL names the duplicate value in the driver's DETAIL line.
        $this->assertInstanceOf(UniqueConstraintViolationException::class, $thrown);
        $this->assertStringContainsString('DETAIL:', $thrown->getMessage());
        $this->assertStringContainsString($keySentinel, $thrown->getMessage());

        $record = $this->soleRecord(Level::Error);
        $this->assertSame(DatabaseFailure::MESSAGE, $record->message);
        $this->assertSame(UniqueConstraintViolationException::class, $record->context['exception_class']);
        $this->assertSame('23505', $record->context['sqlstate']);
        $this->assertSame('test.duplicate-identity', $record->extra['operation']);

        $this->assertRecordsDoNotContain($keySentinel, 'EXTERNAL-IDENTITY-KEY-SENTINEL', 'DETAIL', 'Key (', 'duplicate key');
    }

    public function test_a_database_failure_outside_the_application_code_omits_the_code_reference(): void
    {
        $failure = new QueryException('pgsql', 'select 1', [], new RuntimeException('driver'));

        $this->assertArrayNotHasKey('code_reference', DatabaseFailure::context($failure));
        $this->assertArrayNotHasKey('sqlstate', DatabaseFailure::context($failure));
    }

    private function makeActorWithKey(string $key): ActorReference
    {
        $actor = new ActorReference();
        $actor->external_identity_key = $key;
        $actor->display_name = 'Duplicate';
        $actor->save();

        return $actor;
    }

    private function failureOf(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        $this->fail('The operation was expected to fail.');
    }
}
