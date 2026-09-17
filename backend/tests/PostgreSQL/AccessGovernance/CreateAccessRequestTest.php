<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Actions\CreateAccessRequest;
use App\AccessGovernance\Exceptions\AccessRequestRuleViolation;
use App\Models\AccessRequest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\PostgreSQL\PostgresTestCase;

final class CreateAccessRequestTest extends PostgresTestCase
{
    private function action(): CreateAccessRequest
    {
        return new CreateAccessRequest();
    }

    /** CA-002: a valid standard request is registered and enters its lifecycle. */
    public function test_registers_a_standard_request_in_s1(): void
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');

        $request = $this->action()->execute($requester->id, $profile->id, 'I need read access.');

        $this->assertSame('S1', $request->current_state);
        $this->assertSame('standard', $request->approval_flow);
        $this->assertNull($request->requested_duration_seconds);
        $this->assertSame($requester->id, $request->requester_actor_reference_id);
        $this->assertSame($profile->id, $request->access_profile_id);
        $this->assertSame('I need read access.', $request->justification);
        $this->assertNotNull($request->requested_at);

        $persisted = AccessRequest::query()->findOrFail($request->id);
        $this->assertSame('S1', $persisted->current_state);
        $this->assertSame('I need read access.', $persisted->justification);
        $this->assertSame(1, AccessRequest::query()->count());
    }

    /** CA-002 and CA-007: privileged requests persist the validated duration. */
    #[DataProvider('validPrivilegedDurations')]
    public function test_registers_a_privileged_request_with_a_valid_duration(int $duration): void
    {
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), 'privileged');

        $request = $this->action()->execute($requester->id, $profile->id, 'Temporary elevated access.', $duration);

        $this->assertSame('S1', $request->current_state);
        $this->assertSame('privileged', $request->approval_flow);
        $this->assertSame($duration, $request->requested_duration_seconds);
        $this->assertSame($duration, AccessRequest::query()->findOrFail($request->id)->requested_duration_seconds);
    }

    /** @return array<string, array{int}> */
    public static function validPrivilegedDurations(): array
    {
        return [
            'lower bound' => [1],
            'one hour' => [3600],
            'upper bound' => [7776000],
        ];
    }

    /** CA-003 / RN01. */
    public function test_rejects_an_unavailable_profile(): void
    {
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), 'standard', false);

        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($requester->id, $profile->id, 'Please.')
        );

        $this->assertSame('RN01', $violation->ruleId);
        $this->assertSame(0, AccessRequest::query()->count());
    }

    /** CA-006 / RN11. */
    public function test_rejects_a_resource_owner_requesting_their_own_resource_profile(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');

        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($owner->id, $profile->id, 'Mine.')
        );

        $this->assertSame('RN11', $violation->ruleId);
        $this->assertSame(0, AccessRequest::query()->count());
    }

    /** CA-007 / RN07. */
    #[DataProvider('invalidPrivilegedDurations')]
    public function test_rejects_invalid_privileged_durations(?int $duration): void
    {
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), 'privileged');

        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($requester->id, $profile->id, 'Elevated.', $duration)
        );

        $this->assertSame('RN07', $violation->ruleId);
        $this->assertSame(0, AccessRequest::query()->count());
    }

    /** @return array<string, array{int|null}> */
    public static function invalidPrivilegedDurations(): array
    {
        return [
            'missing' => [null],
            'zero' => [0],
            'negative' => [-1],
            'above the maximum' => [7776001],
        ];
    }

    /** A duration for a standard profile breaks the Action contract, not a business rule. */
    public function test_rejects_a_duration_for_a_standard_profile_as_a_contract_violation(): void
    {
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), 'standard');

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->action()->execute($requester->id, $profile->id, 'Standard with duration.', 60);
        } finally {
            $this->assertSame(0, AccessRequest::query()->count());
        }
    }

    /** CA-005 / RN03: an equivalent request still in processing blocks a new one. */
    #[DataProvider('processingStates')]
    public function test_rejects_an_equivalent_request_in_processing(string $state): void
    {
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), 'standard');
        $this->makeAccessRequest($requester, $profile, $state);

        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($requester->id, $profile->id, 'Again.')
        );

        $this->assertSame('RN03', $violation->ruleId);
        $this->assertSame(1, AccessRequest::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function processingStates(): array
    {
        return ['S1' => ['S1'], 'S2' => ['S2'], 'S3' => ['S3']];
    }

    /** CA-005 / RN03: an equivalent Granted Access in A1 blocks a new request. */
    #[DataProvider('activeAccessValidity')]
    public function test_rejects_an_equivalent_active_granted_access(?string $validUntil): void
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $previous = $this->makeAccessRequest($requester, $profile, 'S4');
        $this->makeGrantedAccess($previous, $owner, $validUntil === null ? null : Carbon::now()->addSeconds(3600));

        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($requester->id, $profile->id, 'Again.')
        );

        $this->assertSame('RN03', $violation->ruleId);
        $this->assertSame(1, AccessRequest::query()->count());
    }

    /** @return array<string, array{string|null}> */
    public static function activeAccessValidity(): array
    {
        return ['without a calculable end' => [null], 'still within validity' => ['future']];
    }

    /** An expired access is A2 and does not block a new request. */
    public function test_allows_a_new_request_when_the_equivalent_access_expired(): void
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $previous = $this->makeAccessRequest($requester, $profile, 'S4');
        $this->makeGrantedAccess($previous, $owner, Carbon::now()->subSeconds(60));

        $request = $this->action()->execute($requester->id, $profile->id, 'After expiry.');

        $this->assertSame('S1', $request->current_state);
        $this->assertSame(2, AccessRequest::query()->count());
    }

    /** A revoked access is A3 and does not block a new request. */
    public function test_allows_a_new_request_when_the_equivalent_access_was_revoked(): void
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $previous = $this->makeAccessRequest($requester, $profile, 'S4');
        $access = $this->makeGrantedAccess($previous, $owner, Carbon::now()->addSeconds(3600));
        $this->makeRevocationConfirmation($access, $owner);

        $request = $this->action()->execute($requester->id, $profile->id, 'After revocation.');

        $this->assertSame('S1', $request->current_state);
        $this->assertSame(2, AccessRequest::query()->count());
    }

    /**
     * CA-004 / RN02: the operation has no target-actor parameter, so a request
     * can only be registered for the requester that was passed in.
     */
    public function test_the_operation_only_registers_requests_for_the_requester_itself(): void
    {
        $parameters = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod(CreateAccessRequest::class, 'execute'))->getParameters()
        );

        $this->assertSame(
            ['requesterActorReferenceId', 'accessProfileId', 'justification', 'requestedDurationSeconds'],
            $parameters
        );

        $requester = $this->makeActor('Requester');
        $other = $this->makeActor('Somebody else');
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), 'standard');

        $request = $this->action()->execute($requester->id, $profile->id, 'For myself.');

        $this->assertSame($requester->id, AccessRequest::query()->findOrFail($request->id)->requester_actor_reference_id);
        $this->assertSame(0, AccessRequest::query()->where('requester_actor_reference_id', $other->id)->count());
    }

    /** CA-022 / RN12: a blank justification is refused and nothing is registered. */
    #[DataProvider('blankJustifications')]
    public function test_rejects_a_blank_justification(string $justification, string $classification, ?int $duration): void
    {
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), $classification);

        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($requester->id, $profile->id, $justification, $duration)
        );

        $this->assertSame('RN12', $violation->ruleId);
        $this->assertSame(0, AccessRequest::query()->count());
    }

    /** @return array<string, array{string, string, int|null}> */
    public static function blankJustifications(): array
    {
        return [
            'empty' => ['', 'standard', null],
            'one space' => [' ', 'standard', null],
            'several spaces' => ['    ', 'standard', null],
            'tabs and newlines' => ["\t\n \r\n\t", 'standard', null],
            'empty on a privileged profile' => ['', 'privileged', 3600],
            'whitespace on a privileged profile' => ["  \n  ", 'privileged', 3600],
        ];
    }

    /** CA-002 / RN12: any non-blank justification is accepted and persisted exactly as received. */
    #[DataProvider('nonBlankJustifications')]
    public function test_persists_a_non_blank_justification_without_trimming_it(string $justification, string $classification, ?int $duration): void
    {
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), $classification);

        $request = $this->action()->execute($requester->id, $profile->id, $justification, $duration);

        $persisted = AccessRequest::query()->findOrFail($request->id);
        $this->assertSame($justification, $request->justification);
        $this->assertSame($justification, $persisted->justification);
        $this->assertSame('S1', $persisted->current_state);
        $this->assertSame($classification, $persisted->approval_flow);
        $this->assertSame($duration, $persisted->requested_duration_seconds);
    }

    /** @return array<string, array{string, string, int|null}> */
    public static function nonBlankJustifications(): array
    {
        return [
            'single character' => ['A', 'standard', null],
            'sentence' => ['Necessário para executar minha função.', 'standard', null],
            'surrounding spaces' => [' acesso necessário ', 'standard', null],
            'surrounding whitespace on a privileged profile' => ["\n\t acesso necessário \t\n", 'privileged', 3600],
        ];
    }

    /** RN12 is checked on the input before any database access, lock included. */
    public function test_a_blank_justification_is_refused_before_touching_the_database(): void
    {
        $requester = $this->makeActor('Requester');
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        // The profile does not even exist: RN12 is decided first.
        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($requester->id, (string) Str::uuid7(), '   ')
        );

        $this->assertSame('RN12', $violation->ruleId);
        $this->assertSame([], $queries);
        $this->assertSame(0, DB::transactionLevel());
    }

    private function assertRuleViolation(callable $operation): AccessRequestRuleViolation
    {
        try {
            $operation();
        } catch (AccessRequestRuleViolation $violation) {
            return $violation;
        }

        $this->fail('Expected an AccessRequestRuleViolation.');
    }
}
