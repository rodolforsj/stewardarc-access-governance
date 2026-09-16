<?php

declare(strict_types=1);

namespace Tests\PostgreSQL;

use App\AccessGovernance\Actions\DecideAccessRequest;
use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\ActorReference;
use App\Models\Decision;
use App\Models\GrantConfirmation;
use App\Models\GovernanceMembership;
use App\Models\GrantedAccess;
use App\Models\Resource;
use App\Models\RevocationConfirmation;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Base class for the application tests that require real PostgreSQL semantics
 * (ADR-006). It refuses to run against anything but a dedicated test database.
 */
abstract class PostgresTestCase extends TestCase
{
    /** Child tables first; truncation is restricted to the nine domain tables. */
    protected const DOMAIN_TABLES = [
        'revocation_confirmations',
        'granted_accesses',
        'grant_confirmations',
        'decisions',
        'access_requests',
        'access_profiles',
        'resources',
        'governance_memberships',
        'actor_references',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        self::guardTestDatabase();
        $this->truncateDomainTables();
    }

    /**
     * Never clean or write to a database that is not an explicit test database.
     */
    public static function guardTestDatabase(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver !== 'pgsql') {
            throw new RuntimeException("These tests require the pgsql driver, got [{$driver}].");
        }

        $database = (string) $connection->getDatabaseName();

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run against database [{$database}]: its name must end with _test.");
        }
    }

    protected function truncateDomainTables(): void
    {
        $tables = implode(', ', array_map(static fn (string $table): string => '"'.$table.'"', self::DOMAIN_TABLES));

        DB::statement("truncate table {$tables}");
    }

    protected function makeActor(string $displayName = 'Actor'): ActorReference
    {
        $actor = new ActorReference();
        $actor->external_identity_key = 'ext-'.bin2hex(random_bytes(8));
        $actor->display_name = $displayName;
        $actor->save();

        return $actor;
    }

    protected function makeGovernanceMembership(ActorReference $actor): GovernanceMembership
    {
        $membership = new GovernanceMembership();
        $membership->actor_reference_id = $actor->id;
        $membership->save();

        return $membership;
    }

    protected function makeResource(ActorReference $owner, string $name = 'Resource'): Resource
    {
        $resource = new Resource();
        $resource->name = $name;
        $resource->resource_owner_actor_reference_id = $owner->id;
        $resource->save();

        return $resource;
    }

    protected function makeProfile(
        Resource $resource,
        string $classification = 'standard',
        bool $isAvailable = true,
        string $name = 'Profile',
    ): AccessProfile {
        $profile = new AccessProfile();
        $profile->resource_id = $resource->id;
        $profile->name = $name;
        $profile->classification = $classification;
        $profile->is_available = $isAvailable;
        $profile->save();

        return $profile;
    }

    protected function makeAccessRequest(
        ActorReference $requester,
        AccessProfile $profile,
        string $currentState,
        ?int $durationSeconds = null,
    ): AccessRequest {
        $request = new AccessRequest();
        $request->requester_actor_reference_id = $requester->id;
        $request->access_profile_id = $profile->id;
        $request->justification = 'fixture';
        $request->approval_flow = (string) $profile->classification;
        $request->requested_duration_seconds = $profile->classification === 'privileged'
            ? ($durationSeconds ?? 3600)
            : null;
        $request->current_state = $currentState;
        $request->requested_at = Carbon::now();
        $request->save();

        return $request;
    }

    /**
     * Records a Decision fact directly, for fixtures that need a specific
     * authorship or moment in time.
     */
    protected function makeDecision(
        AccessRequest $request,
        ActorReference $actor,
        string $stage,
        string $outcome = 'approved',
        ?string $justification = null,
        ?DateTimeInterface $decidedAt = null,
    ): Decision {
        $decision = new Decision();
        $decision->access_request_id = $request->id;
        $decision->stage = $stage;
        $decision->outcome = $outcome;
        $decision->actor_reference_id = $actor->id;
        $decision->justification = $justification;
        $decision->decided_at = $decidedAt ?? Carbon::now();
        $decision->save();

        return $decision;
    }

    /**
     * Drives an existing S1 request to S3 through the real decision Action.
     */
    protected function approveUntilAwaitingGrant(
        AccessRequest $request,
        ActorReference $resourceOwner,
        ?ActorReference $governance = null,
    ): AccessRequest {
        (new DecideAccessRequest())->execute($request->id, $resourceOwner->id, 'approved');

        if ($governance !== null) {
            (new DecideAccessRequest())->execute($request->id, $governance->id, 'approved');
        }

        return $request->fresh();
    }

    /**
     * Creates the confirmed grant and the Granted Access for an existing request.
     */
    protected function makeGrantedAccess(
        AccessRequest $request,
        ActorReference $recordedBy,
        ?DateTimeInterface $validUntil = null,
    ): GrantedAccess {
        $confirmation = new GrantConfirmation();
        $confirmation->access_request_id = $request->id;
        $confirmation->actor_reference_id = $recordedBy->id;
        $confirmation->recorded_at = Carbon::now();
        $confirmation->save();

        $access = new GrantedAccess();
        $access->grant_confirmation_id = $confirmation->id;
        $access->valid_until_at = $validUntil;
        $access->save();

        return $access;
    }

    /**
     * Test-only assertion tool: applies the derived `A1`/`A2`/`A3` definition
     * of the baseline literally. No state is materialized by the product.
     */
    protected function derivedGrantedAccessState(GrantedAccess $access, ?DateTimeInterface $now = null): string
    {
        $moment = $now === null ? Carbon::now() : Carbon::instance(Carbon::parse($now));
        $validUntil = $access->fresh()->valid_until_at;
        $revocation = RevocationConfirmation::query()
            ->where('granted_access_id', $access->id)
            ->first();

        if ($revocation !== null && ($validUntil === null || $revocation->recorded_at->lessThan($validUntil))) {
            return 'A3';
        }

        if ($validUntil !== null && ! $moment->lessThan($validUntil)) {
            return 'A2';
        }

        return 'A1';
    }

    protected function makeRevocationConfirmation(GrantedAccess $access, ActorReference $recordedBy): RevocationConfirmation
    {
        $confirmation = new RevocationConfirmation();
        $confirmation->granted_access_id = $access->id;
        $confirmation->actor_reference_id = $recordedBy->id;
        $confirmation->recorded_at = Carbon::now();
        $confirmation->save();

        return $confirmation;
    }
}
