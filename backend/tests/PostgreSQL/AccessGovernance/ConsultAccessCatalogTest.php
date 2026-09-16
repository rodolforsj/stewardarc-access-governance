<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Actions\ConfirmExternalAccessGrant;
use App\AccessGovernance\Actions\ConsultAccessCatalog;
use App\AccessGovernance\Actions\CreateAccessRequest;
use App\AccessGovernance\Exceptions\AccessRequestRuleViolation;
use App\Models\AccessProfile;
use App\Models\ActorReference;
use App\Models\Resource;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * RF-001 / CA-001 — consulting the catalog shows the Access Profiles available
 * for new requests (RN01). Result order is not part of the contract, so the
 * assertions compare sets.
 */
final class ConsultAccessCatalogTest extends PostgresTestCase
{
    private const ITEM_KEYS = [
        'access_profile_id',
        'access_profile_name',
        'classification',
        'resource_id',
        'resource_name',
    ];

    private ActorReference $owner;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeActor('Resource Owner');
        $this->resource = $this->makeResource($this->owner, 'Payroll');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_empty_catalog_returns_an_empty_list(): void
    {
        $this->assertSame([], $this->catalog());

        // Resources without profiles add nothing either.
        $this->makeResource($this->owner, 'Empty resource');

        $this->assertSame([], $this->catalog());
    }

    public function test_an_available_profile_is_listed_with_exactly_its_catalog_data(): void
    {
        $profile = $this->makeProfile($this->resource, name: 'Payroll viewer');

        $this->assertSame([[
            'access_profile_id' => $profile->id,
            'access_profile_name' => 'Payroll viewer',
            'classification' => 'standard',
            'resource_id' => $this->resource->id,
            'resource_name' => 'Payroll',
        ]], $this->catalog());
    }

    public function test_an_unavailable_profile_is_not_listed(): void
    {
        $this->makeProfile($this->resource, isAvailable: false);

        $this->assertSame([], $this->catalog());
    }

    public function test_only_available_profiles_are_listed_across_resources_and_classifications(): void
    {
        $otherOwner = $this->makeActor('Other owner');
        $ledger = $this->makeResource($otherOwner, 'Ledger');

        $available = [
            $this->makeProfile($this->resource, 'standard', name: 'Payroll viewer'),
            $this->makeProfile($this->resource, 'privileged', name: 'Payroll admin'),
            $this->makeProfile($ledger, 'standard', name: 'Ledger viewer'),
            $this->makeProfile($ledger, 'privileged', name: 'Ledger admin'),
        ];
        $unavailable = [
            $this->makeProfile($this->resource, 'standard', false, 'Payroll legacy'),
            $this->makeProfile($ledger, 'privileged', false, 'Ledger legacy admin'),
        ];

        $catalog = $this->catalog();

        $this->assertEqualsCanonicalizing(
            array_map(static fn (AccessProfile $profile): string => $profile->id, $available),
            array_column($catalog, 'access_profile_id')
        );
        $this->assertSame([], array_values(array_intersect(
            array_map(static fn (AccessProfile $profile): string => $profile->id, $unavailable),
            array_column($catalog, 'access_profile_id')
        )));

        $byId = array_column($catalog, null, 'access_profile_id');
        $resourceNames = [$this->resource->id => 'Payroll', $ledger->id => 'Ledger'];

        foreach ($available as $profile) {
            $item = $byId[$profile->id];

            $this->assertSame(self::ITEM_KEYS, array_keys($item));
            $this->assertSame($profile->name, $item['access_profile_name']);
            // The persisted classification, as is.
            $this->assertSame($profile->classification, $item['classification']);
            $this->assertSame($profile->resource_id, $item['resource_id']);
            $this->assertSame($resourceNames[$profile->resource_id], $item['resource_name']);
        }

        $this->assertEqualsCanonicalizing(
            ['standard', 'privileged', 'standard', 'privileged'],
            array_column($catalog, 'classification')
        );
    }

    /** RNF-005: no Resource Owner, Actor Reference or identity data is exposed. */
    public function test_no_owner_or_actor_data_is_exposed(): void
    {
        $this->makeProfile($this->resource);
        $this->makeProfile($this->makeResource($this->makeActor('Other owner'), 'Ledger'), 'privileged');

        $catalog = $this->catalog();
        $this->assertCount(2, $catalog);

        $actors = ActorReference::query()->get();
        $forbiddenValues = array_merge(
            $actors->pluck('id')->all(),
            $actors->pluck('external_identity_key')->all(),
            $actors->pluck('display_name')->all(),
        );

        foreach ($catalog as $item) {
            $this->assertSame(self::ITEM_KEYS, array_keys($item));
            $this->assertArrayNotHasKey('resource_owner_actor_reference_id', $item);
            $this->assertArrayNotHasKey('external_identity_key', $item);
            $this->assertArrayNotHasKey('is_available', $item);

            foreach ($item as $value) {
                $this->assertIsString($value);
                $this->assertNotContains($value, $forbiddenValues, 'Actor data leaked into the catalog.');
            }
        }
    }

    /** The catalog is current reference data: availability changes apply to the next consultation. */
    public function test_availability_changes_are_reflected_by_the_next_consultation_without_touching_facts(): void
    {
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->resource, isAvailable: false);
        $request = $this->makeAccessRequest($requester, $profile, 'S1');
        $factsBefore = $this->factsSnapshot();

        $this->assertSame([], $this->catalog());

        DB::table('access_profiles')->where('id', $profile->id)->update(['is_available' => true]);
        $this->assertSame([$profile->id], array_column($this->catalog(), 'access_profile_id'));

        DB::table('access_profiles')->where('id', $profile->id)->update(['is_available' => false]);
        $this->assertSame([], $this->catalog());

        $this->assertSame($factsBefore, $this->factsSnapshot());
        $this->assertSame('S1', $request->fresh()->current_state);
    }

    /**
     * Catalog availability is not final request eligibility: RN11 and RN03 are
     * evaluated by CreateAccessRequest, not by the catalog.
     */
    public function test_request_eligibility_rules_do_not_filter_the_catalog(): void
    {
        $requester = $this->makeActor('Requester');
        $inProcessing = $this->makeProfile($this->resource, name: 'In processing');
        $granted = $this->makeProfile($this->resource, name: 'Granted');
        $ownProfile = $this->makeProfile($this->makeResource($requester, 'Own resource'), name: 'Own resource profile');

        (new CreateAccessRequest())->execute($requester->id, $inProcessing->id, 'Needed.');
        $grantedRequest = (new CreateAccessRequest())->execute($requester->id, $granted->id, 'Needed.');
        $this->approveUntilAwaitingGrant($grantedRequest, $this->owner);
        (new ConfirmExternalAccessGrant())->execute($grantedRequest->id, $this->owner->id);

        $this->assertEqualsCanonicalizing(
            [$inProcessing->id, $granted->id, $ownProfile->id],
            array_column($this->catalog(), 'access_profile_id')
        );

        $this->assertRequestRefused('RN03', $requester, $inProcessing);
        $this->assertRequestRefused('RN03', $requester, $granted);
        $this->assertRequestRefused('RN11', $requester, $ownProfile);

        // The refusals changed nothing in the catalog.
        $this->assertEqualsCanonicalizing(
            [$inProcessing->id, $granted->id, $ownProfile->id],
            array_column($this->catalog(), 'access_profile_id')
        );
    }

    /** Read only: no lock, no write, no transaction or functional time, no PHP clock. */
    public function test_the_consultation_is_a_plain_read(): void
    {
        $this->makeProfile($this->resource);
        $this->makeProfile($this->resource, 'privileged');
        $this->makeProfile($this->resource, isAvailable: false);
        $before = $this->domainSnapshot();

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
        });

        Carbon::setTestNow(Carbon::create(1999, 1, 1));

        try {
            $first = $this->catalog();
            $second = $this->catalog();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertCount(2, $first);
        $this->assertSame($first, $second);
        // One statement per consultation, outside any explicit transaction.
        $this->assertCount(2, $queries);

        foreach ($queries as $query) {
            $this->assertSame(0, $query['level']);
            $this->assertMatchesRegularExpression('/^select\b/i', $query['sql']);
            $this->assertDoesNotMatchRegularExpression('/\bfor\s+(no\s+key\s+)?(update|share|key\s+share)\b/i', $query['sql']);
            $this->assertStringNotContainsStringIgnoringCase('pg_advisory', $query['sql']);
            $this->assertStringNotContainsStringIgnoringCase('transaction_timestamp', $query['sql']);
            $this->assertStringNotContainsStringIgnoringCase('now()', $query['sql']);
        }

        $this->assertSame($before, $this->domainSnapshot());
    }

    // ------------------------------------------------------------------

    private function catalog(): array
    {
        return (new ConsultAccessCatalog())->execute();
    }

    private function assertRequestRefused(string $ruleId, ActorReference $requester, AccessProfile $profile): void
    {
        try {
            (new CreateAccessRequest())->execute($requester->id, $profile->id, 'Needed again.');
            $this->fail("The request should have been refused by {$ruleId}.");
        } catch (AccessRequestRuleViolation $violation) {
            $this->assertSame($ruleId, $violation->ruleId);
        }
    }

    /** @return array<string, string> */
    private function factsSnapshot(): array
    {
        return array_intersect_key(
            $this->domainSnapshot(),
            array_flip(['access_requests', 'decisions', 'grant_confirmations', 'granted_accesses', 'revocation_confirmations'])
        );
    }

    /** @return array<string, string> */
    private function domainSnapshot(): array
    {
        $snapshot = [];

        foreach (self::DOMAIN_TABLES as $table) {
            $snapshot[$table] = json_encode(DB::table($table)->orderByRaw('1')->get()->all(), JSON_THROW_ON_ERROR);
        }

        return $snapshot;
    }
}
