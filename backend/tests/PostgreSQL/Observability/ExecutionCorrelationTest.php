<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\Observability;

use App\AccessGovernance\Actions\CreateAccessRequest;
use App\AccessGovernance\Actions\DecideAccessRequest;
use App\Http\Middleware\TrackExecution;
use App\Observability\OperationContext;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Level;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * ADR-012 / RNF-011: the records of one execution share one server-generated
 * identifier, other executions get other identifiers, and operations nest
 * without leaking into what runs next.
 */
final class ExecutionCorrelationTest extends PostgresTestCase
{
    use CapturesLogRecords;

    /** A route that exists only in this test, inside the web group. */
    private const PROBE_PATH = '/_test/observability/probe';

    private const PROBE_ROUTE = 'test.observability.probe';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureLogRecords();

        Route::middleware('web')->get(self::PROBE_PATH, function (): string {
            Log::info('First record of the execution.');
            Log::info('Second record of the execution.');

            return 'ok';
        })->name(self::PROBE_ROUTE);
    }

    public function test_the_records_of_one_request_share_one_execution_named_by_the_route(): void
    {
        $this->get(self::PROBE_PATH)->assertOk();

        [$first, $second] = $this->records();
        $executionId = $this->assertExecutionId($first);

        $this->assertSame($executionId, $second->extra['execution_id']);
        $this->assertSame(self::PROBE_ROUTE, $first->extra['operation']);
        $this->assertSame(self::PROBE_ROUTE, $second->extra['operation']);
        // The execution context travels with the record, not in its own context.
        $this->assertArrayNotHasKey('execution_id', $first->context);
    }

    public function test_sequential_requests_are_distinct_executions(): void
    {
        $this->get(self::PROBE_PATH)->assertOk();
        $this->get(self::PROBE_PATH)->assertOk();

        $identifiers = array_map(fn ($record): string => $this->assertExecutionId($record), $this->records());

        $this->assertCount(4, $identifiers);
        $this->assertSame($identifiers[0], $identifiers[1]);
        $this->assertSame($identifiers[2], $identifiers[3]);
        $this->assertNotSame($identifiers[0], $identifiers[2]);
    }

    public function test_nothing_of_the_execution_remains_after_the_request(): void
    {
        $this->get(self::PROBE_PATH)->assertOk();

        $this->assertSame([], Context::all());
    }

    public function test_client_supplied_correlation_identifiers_are_never_used_or_returned(): void
    {
        $sentinels = [
            'X-Request-ID' => 'client-request-id-SENTINEL',
            'X-Correlation-ID' => 'client-correlation-id-SENTINEL',
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ];

        $response = $this->get(self::PROBE_PATH, $sentinels)->assertOk();

        $executionId = $this->assertExecutionId($this->records()[0]);
        $this->assertNotContains($executionId, $sentinels);
        $this->assertRecordsDoNotContain(...array_values($sentinels));

        // Nothing about the execution goes back to the client.
        foreach ([...array_values($sentinels), $executionId] as $value) {
            $this->assertStringNotContainsString($value, (string) $response->headers);
            $this->assertStringNotContainsString($value, (string) $response->getContent());
        }

        foreach (['X-Request-ID', 'X-Correlation-ID', 'traceparent', 'X-Execution-ID'] as $header) {
            $this->assertFalse($response->headers->has($header));
        }
    }

    public function test_the_tracked_surface_covers_the_login_and_product_routes_and_every_one_is_named(): void
    {
        $tracked = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array(TrackExecution::class, $this->middlewareOf($route), true)) {
                // A route with no name would leave its operation unnamed.
                $this->assertNotNull($route->getName(), "Route [{$route->uri()}] is tracked but has no name.");
                $tracked[] = $route->getName();
            }
        }

        foreach (['auth.login', 'auth.callback', 'auth.logout', 'api.me.requests-and-accesses'] as $name) {
            $this->assertContains($name, $tracked);
        }

        // It is the first middleware of the group: sessions, authentication
        // and CSRF protection all run inside the execution.
        $apiRoute = Route::getRoutes()->getByName('api.me.requests-and-accesses');
        $this->assertSame(TrackExecution::class, $this->middlewareOf($apiRoute)[0]);
    }

    public function test_the_health_check_stays_outside_the_tracked_surface(): void
    {
        $health = Route::getRoutes()->match(request()->create('/up'));

        $this->assertNotContains(TrackExecution::class, $this->middlewareOf($health));

        $this->get('/up')->assertOk();

        $this->assertSame([], $this->records());
    }

    public function test_a_nested_operation_names_its_records_only_while_it_runs(): void
    {
        OperationContext::runNewExecution('test.outer', function (): void {
            Log::info('Before the inner operation.');

            OperationContext::run('test.inner', function (): void {
                Log::info('Inside the inner operation.');
            });

            Log::info('After the inner operation.');
        });

        [$before, $inside, $after] = $this->records();
        $executionId = $this->assertExecutionId($before);

        $this->assertSame($executionId, $inside->extra['execution_id']);
        $this->assertSame($executionId, $after->extra['execution_id']);
        $this->assertSame(['test.outer', 'test.inner', 'test.outer'], [
            $before->extra['operation'],
            $inside->extra['operation'],
            $after->extra['operation'],
        ]);
        $this->assertSame([], Context::all());
    }

    public function test_a_critical_action_joins_the_current_execution_and_gives_the_operation_back(): void
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner));

        OperationContext::runNewExecution('test.outer', function () use ($requester, $profile): void {
            $outerExecutionId = Context::get(OperationContext::EXECUTION_ID);

            // Success: the Action's operation ends with it.
            (new CreateAccessRequest())->execute($requester->id, $profile->id, 'Needed for the quarterly audit.');

            $this->assertSame('test.outer', Context::get(OperationContext::OPERATION));
            $this->assertSame($outerExecutionId, Context::get(OperationContext::EXECUTION_ID));

            // Failure: reported under the Action's operation, in the same
            // execution, and the outer operation is given back afterwards.
            try {
                (new DecideAccessRequest())->execute('not-a-uuid', $requester->id, 'approved');
                $this->fail('The Action was expected to fail.');
            } catch (QueryException) {
            }

            $record = $this->soleRecord(Level::Error);
            $this->assertSame($outerExecutionId, $record->extra['execution_id']);
            $this->assertSame('access_request.decide', $record->extra['operation']);
            $this->assertSame('test.outer', Context::get(OperationContext::OPERATION));
            $this->assertSame($outerExecutionId, Context::get(OperationContext::EXECUTION_ID));
        });

        $this->assertSame([], Context::all());
    }

    /**
     * @return list<string>
     */
    private function middlewareOf(RoutingRoute $route): array
    {
        // The HTTP kernel gives the router its middleware groups.
        $this->app->make(HttpKernel::class);

        return array_values(app(Router::class)->gatherRouteMiddleware($route));
    }
}
