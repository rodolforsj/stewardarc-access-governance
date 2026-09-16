<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Actions\ConfirmExternalAccessGrant;
use App\AccessGovernance\Actions\CreateAccessRequest;
use App\AccessGovernance\Actions\DecideAccessRequest;
use App\AccessGovernance\Actions\FollowMyRequestsAndAccesses;
use App\AccessGovernance\Actions\RecordExternalAccessRevocation;
use App\AccessGovernance\Exceptions\AccessRequestRuleViolation;
use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\ActorReference;
use App\Models\Decision;
use App\Models\GrantConfirmation;
use App\Models\GrantedAccess;
use App\Models\RevocationConfirmation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * ADR-009 — the four mutation Actions take their functional instant from
 * PostgreSQL `transaction_timestamp()`, once per operation, and the RN03
 * active-access check of request creation uses that same instant.
 */
final class MutationFunctionalTimeSourceTest extends PostgresTestCase
{
    private const DURATION_SECONDS = 5400;

    private ActorReference $owner;

    private ActorReference $governance;

    private ActorReference $requester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeActor('Owner');
        $this->governance = $this->makeActor('Governance');
        $this->makeGovernanceMembership($this->governance);
        $this->requester = $this->makeActor('Requester');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_request_creation_ignores_the_php_clock(): void
    {
        $profile = $this->profile();

        [$request, $window] = $this->withinDatabaseWindow(
            fn (): AccessRequest => (new CreateAccessRequest())->execute($this->requester->id, $profile->id, 'Needed.'),
            CarbonImmutable::parse('1999-01-01 00:00:00', 'UTC'),
        );

        $this->assertInDatabaseWindow($window, AccessRequest::query()->findOrFail($request->id)->requested_at);
    }

    public function test_decision_ignores_the_php_clock(): void
    {
        $request = $this->makeAccessRequest($this->requester, $this->profile(), 'S1');

        [$decision, $window] = $this->withinDatabaseWindow(
            fn (): Decision => (new DecideAccessRequest())->execute($request->id, $this->owner->id, 'approved'),
            CarbonImmutable::parse('2090-06-01 00:00:00', 'UTC'),
        );

        $persisted = Decision::query()->findOrFail($decision->id);
        $this->assertSame('resource_owner', $persisted->stage);
        $this->assertSame('S3', $request->fresh()->current_state);
        $this->assertInDatabaseWindow($window, $persisted->decided_at);
    }

    public function test_standard_grant_confirmation_ignores_the_php_clock(): void
    {
        $request = $this->awaitingGrant('standard');

        [$confirmation, $window] = $this->withinDatabaseWindow(
            fn (): GrantConfirmation => (new ConfirmExternalAccessGrant())->execute($request->id, $this->owner->id),
            CarbonImmutable::parse('1999-01-01 00:00:00', 'UTC'),
        );

        $persisted = GrantConfirmation::query()->findOrFail($confirmation->id);
        $this->assertInDatabaseWindow($window, $persisted->recorded_at);
        $this->assertNull(GrantedAccess::query()->where('grant_confirmation_id', $persisted->id)->firstOrFail()->valid_until_at);
    }

    /** RN07: the validity starts at the very instant recorded by the grant. */
    public function test_privileged_grant_confirmation_uses_one_instant_for_recording_and_validity(): void
    {
        $request = $this->awaitingGrant('privileged');

        [$confirmation, $window] = $this->withinDatabaseWindow(
            fn (): GrantConfirmation => (new ConfirmExternalAccessGrant())->execute($request->id, $this->owner->id),
            CarbonImmutable::parse('2090-06-01 00:00:00', 'UTC'),
        );

        $persisted = GrantConfirmation::query()->findOrFail($confirmation->id);
        $access = GrantedAccess::query()->where('grant_confirmation_id', $persisted->id)->firstOrFail();

        $this->assertInDatabaseWindow($window, $persisted->recorded_at);
        $this->assertTrue($access->valid_until_at->equalTo($persisted->recorded_at->addSeconds(self::DURATION_SECONDS)));

        // Measured by PostgreSQL itself, to the microsecond.
        $difference = DB::selectOne(
            'select extract(epoch from (g.valid_until_at - c.recorded_at)) as seconds
             from granted_accesses g join grant_confirmations c on c.id = g.grant_confirmation_id
             where g.id = ?',
            [$access->id]
        )->seconds;
        $this->assertEquals(self::DURATION_SECONDS, (float) $difference);
    }

    public function test_revocation_confirmation_ignores_the_php_clock(): void
    {
        $request = $this->awaitingGrant('standard');
        $grant = (new ConfirmExternalAccessGrant())->execute($request->id, $this->owner->id);
        $access = GrantedAccess::query()->where('grant_confirmation_id', $grant->id)->firstOrFail();
        $grantBefore = GrantConfirmation::query()->findOrFail($grant->id)->getAttributes();
        $accessBefore = $access->fresh()->getAttributes();

        [$confirmation, $window] = $this->withinDatabaseWindow(
            fn (): RevocationConfirmation => (new RecordExternalAccessRevocation())->execute($access->id, $this->owner->id),
            CarbonImmutable::parse('1999-01-01 00:00:00', 'UTC'),
        );

        $this->assertInDatabaseWindow($window, RevocationConfirmation::query()->findOrFail($confirmation->id)->recorded_at);
        $this->assertSame($grantBefore, GrantConfirmation::query()->findOrFail($grant->id)->getAttributes());
        $this->assertSame($accessBefore, $access->fresh()->getAttributes());
        $this->assertSame('S4', $request->fresh()->current_state);
    }

    /**
     * Inside one outer transaction every Action shares its start instant, so
     * each persisted instant must be that very value at the persisted precision.
     * Test-only orchestration; the facts are rolled back.
     */
    public function test_every_covered_timestamp_is_the_transaction_instant_of_its_operation(): void
    {
        $profile = $this->profile('privileged');

        DB::beginTransaction();

        try {
            $instant = CarbonImmutable::parse(DB::selectOne('select transaction_timestamp() as value')->value);

            $request = (new CreateAccessRequest())->execute($this->requester->id, $profile->id, 'Needed.', self::DURATION_SECONDS);
            $ownerDecision = (new DecideAccessRequest())->execute($request->id, $this->owner->id, 'approved');
            $governanceDecision = (new DecideAccessRequest())->execute($request->id, $this->governance->id, 'approved');
            $grant = (new ConfirmExternalAccessGrant())->execute($request->id, $this->owner->id);
            $access = GrantedAccess::query()->where('grant_confirmation_id', $grant->id)->firstOrFail();
            $revocation = (new RecordExternalAccessRevocation())->execute($access->id, $this->owner->id);

            $persisted = [
                AccessRequest::query()->findOrFail($request->id)->requested_at,
                Decision::query()->findOrFail($ownerDecision->id)->decided_at,
                Decision::query()->findOrFail($governanceDecision->id)->decided_at,
                GrantConfirmation::query()->findOrFail($grant->id)->recorded_at,
                RevocationConfirmation::query()->findOrFail($revocation->id)->recorded_at,
            ];

            foreach ($persisted as $value) {
                $this->assertSameInstantAtPersistedPrecision($instant, $value);
            }

            $this->assertTrue($access->valid_until_at->equalTo($persisted[3]->addSeconds(self::DURATION_SECONDS)));
        } finally {
            DB::rollBack();
        }
    }

    /** One `transaction_timestamp()` read per successful mutation. */
    public function test_each_mutation_reads_the_functional_instant_exactly_once(): void
    {
        $profile = $this->profile('privileged');
        $counts = [];
        $current = null;

        DB::listen(static function (QueryExecuted $query) use (&$counts, &$current): void {
            if ($current !== null && stripos($query->sql, 'transaction_timestamp') !== false) {
                $counts[$current]++;
            }
        });

        $count = static function (string $label, callable $operation) use (&$counts, &$current): mixed {
            $counts[$label] = 0;
            $current = $label;

            try {
                return $operation();
            } finally {
                $current = null;
            }
        };

        $request = $count('create', fn () => (new CreateAccessRequest())->execute($this->requester->id, $profile->id, 'Needed.', self::DURATION_SECONDS));
        $count('owner decision', fn () => (new DecideAccessRequest())->execute($request->id, $this->owner->id, 'approved'));
        $count('governance decision', fn () => (new DecideAccessRequest())->execute($request->id, $this->governance->id, 'approved'));
        $grant = $count('grant', fn () => (new ConfirmExternalAccessGrant())->execute($request->id, $this->owner->id));
        $access = GrantedAccess::query()->where('grant_confirmation_id', $grant->id)->firstOrFail();
        $count('revocation', fn () => (new RecordExternalAccessRevocation())->execute($access->id, $this->owner->id));

        $this->assertSame([
            'create' => 1,
            'owner decision' => 1,
            'governance decision' => 1,
            'grant' => 1,
            'revocation' => 1,
        ], $counts);
    }

    /**
     * RN03, access still active for PostgreSQL while the PHP clock is far past
     * its validity end: the new request is still blocked.
     */
    public function test_rn03_blocks_an_access_active_by_postgresql_time_whatever_the_php_clock(): void
    {
        $profile = $this->profile('privileged');
        $access = $this->concludedPrivilegedAccess($profile, "clock_timestamp() + interval '1 hour'");

        Carbon::setTestNow($access->valid_until_at->addYears(10));

        try {
            (new CreateAccessRequest())->execute($this->requester->id, $profile->id, 'Again.', self::DURATION_SECONDS);
            $this->fail('RN03 should block an access that is still active.');
        } catch (AccessRequestRuleViolation $violation) {
            $this->assertSame('RN03', $violation->ruleId);
        }

        $this->assertSame(1, AccessRequest::query()->count());
    }

    /**
     * RN03, access already expired for PostgreSQL while the PHP clock is far
     * before its validity end: the new request is admitted.
     */
    public function test_rn03_admits_a_request_after_postgresql_expiration_whatever_the_php_clock(): void
    {
        $profile = $this->profile('privileged');
        $access = $this->concludedPrivilegedAccess($profile, "clock_timestamp() - interval '1 hour'");

        Carbon::setTestNow($access->valid_until_at->subYears(10));

        $request = (new CreateAccessRequest())->execute($this->requester->id, $profile->id, 'Again.', self::DURATION_SECONDS);

        $this->assertSame('S1', $request->current_state);
        $this->assertSame(2, AccessRequest::query()->count());
    }

    /**
     * RN03 around the transaction instant. The columns keep whole seconds, so
     * an exact equality with the instant is not deterministic: the last whole
     * second at or before it has ended, the next whole second is still active.
     * Test-only orchestration inside an outer transaction, rolled back.
     */
    public function test_rn03_uses_the_transaction_instant_as_the_validity_boundary(): void
    {
        $profile = $this->profile('privileged');
        $access = $this->concludedPrivilegedAccess($profile, "clock_timestamp() + interval '1 hour'");

        DB::beginTransaction();

        try {
            DB::update(
                "update granted_accesses set valid_until_at = date_trunc('second', transaction_timestamp()) + interval '1 second' where id = ?",
                [$access->id]
            );

            try {
                (new CreateAccessRequest())->execute($this->requester->id, $profile->id, 'Again.', self::DURATION_SECONDS);
                $this->fail('A validity end after the transaction instant is still active.');
            } catch (AccessRequestRuleViolation $violation) {
                $this->assertSame('RN03', $violation->ruleId);
            }

            DB::update(
                "update granted_accesses set valid_until_at = date_trunc('second', transaction_timestamp()) where id = ?",
                [$access->id]
            );

            $request = (new CreateAccessRequest())->execute($this->requester->id, $profile->id, 'Again.', self::DURATION_SECONDS);

            $this->assertSame('S1', $request->current_state);
        } finally {
            DB::rollBack();
        }
    }

    /** Facts written before the materialization keep their values; the schema has no default or trigger. */
    public function test_existing_facts_are_not_rewritten_and_the_schema_supplies_no_time(): void
    {
        $historical = $this->makeAccessRequest($this->requester, $this->profile(), 'S1');
        $historical->requested_at = CarbonImmutable::parse('2020-02-02 02:02:02', 'UTC');
        $historical->save();
        $before = $historical->fresh()->getAttributes();

        $request = $this->makeAccessRequest($this->makeActor('Other'), $this->profile(), 'S1');
        (new DecideAccessRequest())->execute($request->id, $this->owner->id, 'approved');
        (new CreateAccessRequest())->execute($this->makeActor('Third')->id, $this->profile()->id, 'Needed.');

        $this->assertSame($before, $historical->fresh()->getAttributes());

        $defaults = DB::select(
            "select table_name, column_name, column_default from information_schema.columns
             where table_schema = current_schema()
               and (table_name, column_name) in (
                   ('access_requests', 'requested_at'), ('decisions', 'decided_at'),
                   ('grant_confirmations', 'recorded_at'), ('revocation_confirmations', 'recorded_at'))"
        );
        $this->assertCount(4, $defaults);

        foreach ($defaults as $column) {
            $this->assertNull($column->column_default, "{$column->table_name}.{$column->column_name} must have no default.");
        }

        $triggers = DB::selectOne(
            'select count(*) as total from information_schema.triggers where trigger_schema = current_schema()'
        )->total;
        $this->assertSame(0, (int) $triggers);
        $this->assertCount(9, glob(database_path('migrations/*.php')));
    }

    /** ADR-008 + ADR-009: a later projection never sees a fact recorded after its reference instant. */
    public function test_committed_facts_are_not_later_than_a_subsequent_projection_reference(): void
    {
        $request = $this->awaitingGrant('privileged');
        $grant = (new ConfirmExternalAccessGrant())->execute($request->id, $this->owner->id);
        $access = GrantedAccess::query()->where('grant_confirmation_id', $grant->id)->firstOrFail();
        (new RecordExternalAccessRevocation())->execute($access->id, $this->owner->id);

        Carbon::setTestNow(CarbonImmutable::parse('1999-01-01 00:00:00', 'UTC'));

        $projection = (new FollowMyRequestsAndAccesses())->execute($this->requester->id);

        $this->assertSame('A3', $projection['requests'][0]['granted_access']['state']);

        foreach ($projection['requests'][0]['history'] as $entry) {
            $this->assertTrue(
                $entry['occurred_at']->lessThanOrEqualTo($projection['projection_reference_at']),
                "[{$entry['kind']}] occurred after the projection reference instant."
            );
        }
    }

    // ------------------------------------------------------------------

    private function profile(string $classification = 'standard'): AccessProfile
    {
        return $this->makeProfile($this->makeResource($this->owner), $classification);
    }

    private function awaitingGrant(string $classification): AccessRequest
    {
        $request = $this->makeAccessRequest(
            $this->requester,
            $this->profile($classification),
            'S1',
            $classification === 'privileged' ? self::DURATION_SECONDS : null,
        );

        return $this->approveUntilAwaitingGrant(
            $request,
            $this->owner,
            $classification === 'privileged' ? $this->governance : null,
        );
    }

    /**
     * A concluded Privileged request whose validity end is set by a PostgreSQL
     * expression, independent of the PHP clock.
     */
    private function concludedPrivilegedAccess(AccessProfile $profile, string $validUntilExpression): GrantedAccess
    {
        $request = $this->makeAccessRequest($this->requester, $profile, 'S4', self::DURATION_SECONDS);
        $access = $this->makeGrantedAccess($request, $this->owner, CarbonImmutable::parse('2000-01-01 00:00:00', 'UTC'));

        DB::update("update granted_accesses set valid_until_at = {$validUntilExpression} where id = ?", [$access->id]);

        return $access->fresh();
    }

    /**
     * Runs the operation with the PHP clock frozen at $fakeNow, bracketed by
     * PostgreSQL wall-clock readings taken before and after it.
     *
     * @return array{0: mixed, 1: array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable}}
     */
    private function withinDatabaseWindow(callable $operation, CarbonImmutable $fakeNow): array
    {
        $before = $this->databaseClock();
        Carbon::setTestNow($fakeNow);

        try {
            $result = $operation();
        } finally {
            Carbon::setTestNow();
        }

        return [$result, [$before, $this->databaseClock(), $fakeNow]];
    }

    private function databaseClock(): CarbonImmutable
    {
        return CarbonImmutable::parse(DB::selectOne('select clock_timestamp() as value')->value);
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable}  $window
     */
    private function assertInDatabaseWindow(array $window, DateTimeInterface $persisted): void
    {
        [$before, $after, $fakeNow] = $window;
        $value = CarbonImmutable::instance($persisted);

        // The persisted precision may drop the fraction of a second.
        $this->assertTrue(
            $value->greaterThanOrEqualTo($before->startOfSecond()) && $value->lessThanOrEqualTo($after),
            "Persisted instant {$value->format('Y-m-d H:i:s.u')} is outside the PostgreSQL window "
            ."[{$before->format('Y-m-d H:i:s.u')}, {$after->format('Y-m-d H:i:s.u')}]."
        );
        $this->assertGreaterThan(86400, abs($value->getTimestamp() - $fakeNow->getTimestamp()));
    }

    private function assertSameInstantAtPersistedPrecision(CarbonImmutable $instant, DateTimeInterface $persisted): void
    {
        $value = CarbonImmutable::instance($persisted);

        $this->assertTrue(
            $value->equalTo($instant) || $value->equalTo($instant->startOfSecond()),
            "Persisted {$value->format('Y-m-d H:i:s.u')} is not the transaction instant {$instant->format('Y-m-d H:i:s.u')}."
        );
    }
}
