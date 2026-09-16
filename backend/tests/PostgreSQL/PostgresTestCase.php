<?php

declare(strict_types=1);

namespace Tests\PostgreSQL;

use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\ActorReference;
use App\Models\GrantConfirmation;
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
    /** Child tables first; truncation is restricted to the eight domain tables. */
    protected const DOMAIN_TABLES = [
        'revocation_confirmations',
        'granted_accesses',
        'grant_confirmations',
        'decisions',
        'access_requests',
        'access_profiles',
        'resources',
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
