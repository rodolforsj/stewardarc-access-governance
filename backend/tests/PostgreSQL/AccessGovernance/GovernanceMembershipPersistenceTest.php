<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\Models\ActorReference;
use App\Models\GovernanceMembership;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * Materialization of the Governance authority association (ADR-007). These
 * tests cover persistence and integrity only; RF-004 and RF-005 are not
 * implemented yet.
 */
final class GovernanceMembershipPersistenceTest extends PostgresTestCase
{
    private function makeMembership(ActorReference $actor): GovernanceMembership
    {
        $membership = new GovernanceMembership();
        $membership->actor_reference_id = $actor->id;
        $membership->save();

        return $membership;
    }

    public function test_the_table_has_exactly_one_uuid_column(): void
    {
        $columns = DB::select(
            "select column_name, data_type, is_nullable, column_default
             from information_schema.columns
             where table_schema = 'public' and table_name = 'governance_memberships'
             order by ordinal_position"
        );

        $this->assertCount(1, $columns);
        $this->assertSame('actor_reference_id', $columns[0]->column_name);
        $this->assertSame('uuid', $columns[0]->data_type);
        $this->assertSame('NO', $columns[0]->is_nullable);
        $this->assertNull($columns[0]->column_default);
    }

    public function test_the_primary_key_is_the_actor_reference_id(): void
    {
        $primaryKey = DB::selectOne(
            "select conname, pg_get_constraintdef(oid) as definition
             from pg_constraint
             where contype = 'p' and conrelid = 'governance_memberships'::regclass"
        );

        $this->assertNotNull($primaryKey);
        $this->assertSame('PRIMARY KEY (actor_reference_id)', $primaryKey->definition);
    }

    public function test_the_foreign_key_is_restrictive_on_update_and_delete(): void
    {
        $foreignKeys = DB::select(
            "select conname, pg_get_constraintdef(oid) as definition
             from pg_constraint
             where contype = 'f' and conrelid = 'governance_memberships'::regclass"
        );

        $this->assertCount(1, $foreignKeys);
        $this->assertSame(
            'FOREIGN KEY (actor_reference_id) REFERENCES actor_references(id) ON UPDATE RESTRICT ON DELETE RESTRICT',
            $foreignKeys[0]->definition
        );
    }

    public function test_the_table_has_no_extra_index_sequence_or_trigger(): void
    {
        $indexes = DB::select("select indexname from pg_indexes where schemaname = 'public' and tablename = 'governance_memberships'");
        $this->assertCount(1, $indexes, 'Only the primary key index is expected.');

        $triggers = DB::selectOne(
            "select count(*) as total from information_schema.triggers
             where trigger_schema = 'public' and event_object_table = 'governance_memberships'"
        );
        $this->assertSame(0, (int) $triggers->total);

        $sequence = DB::selectOne("select pg_get_serial_sequence('governance_memberships', 'actor_reference_id') as sequence");
        $this->assertNull($sequence->sequence);
    }

    public function test_the_model_is_persisted_with_the_actor_reference_as_its_key(): void
    {
        $actor = $this->makeActor('Governance member');

        $membership = $this->makeMembership($actor);

        $this->assertSame($actor->id, $membership->getKey());
        $this->assertSame('actor_reference_id', $membership->getKeyName());
        $this->assertSame('governance_memberships', $membership->getTable());
        $this->assertFalse($membership->getIncrementing());
        $this->assertFalse($membership->timestamps);
        $this->assertNotContains(HasUuids::class, class_uses_recursive(GovernanceMembership::class));

        $found = GovernanceMembership::query()->findOrFail($actor->id);
        $this->assertSame($actor->id, $found->actor_reference_id);
        $this->assertSame(1, GovernanceMembership::query()->count());
    }

    public function test_the_membership_resolves_its_actor_reference(): void
    {
        $actor = $this->makeActor('Governance member');
        $membership = $this->makeMembership($actor);

        $this->assertSame($actor->id, $membership->actorReference->id);
        $this->assertSame('Governance member', $membership->actorReference->display_name);
    }

    public function test_the_actor_reference_resolves_its_membership(): void
    {
        $actor = $this->makeActor('Governance member');
        $this->makeMembership($actor);

        $this->assertSame($actor->id, $actor->fresh()->governanceMembership->actor_reference_id);
        $this->assertNull($this->makeActor('Plain actor')->governanceMembership);
    }

    public function test_an_actor_reference_cannot_hold_two_memberships(): void
    {
        $actor = $this->makeActor('Governance member');
        $this->makeMembership($actor);

        $this->expectException(QueryException::class);

        try {
            $this->makeMembership($actor);
        } finally {
            $this->assertSame(1, GovernanceMembership::query()->count());
        }
    }

    public function test_a_membership_requires_an_existing_actor_reference(): void
    {
        $this->expectException(QueryException::class);

        try {
            $membership = new GovernanceMembership();
            $membership->actor_reference_id = (string) Str::uuid7();
            $membership->save();
        } finally {
            $this->assertSame(0, GovernanceMembership::query()->count());
        }
    }

    public function test_an_actor_reference_with_a_membership_cannot_be_deleted(): void
    {
        $actor = $this->makeActor('Governance member');
        $this->makeMembership($actor);

        try {
            $actor->delete();
            $this->fail('Expected the restrictive foreign key to prevent the deletion.');
        } catch (QueryException) {
            // The restrictive foreign key is the expected protection.
        }

        $this->assertSame(1, ActorReference::query()->where('id', $actor->id)->count());
        $this->assertSame(1, GovernanceMembership::query()->count());
    }

    public function test_a_membership_can_be_removed_without_removing_the_actor(): void
    {
        $actor = $this->makeActor('Governance member');
        $membership = $this->makeMembership($actor);

        $membership->delete();

        $this->assertSame(0, GovernanceMembership::query()->count());
        $this->assertSame(1, ActorReference::query()->where('id', $actor->id)->count());
        $this->assertNull($actor->fresh()->governanceMembership);
    }

    public function test_the_same_actor_can_be_resource_owner_and_governance_member(): void
    {
        $actor = $this->makeActor('Owner and Governance');
        $resource = $this->makeResource($actor);
        $this->makeMembership($actor);

        $fresh = $actor->fresh();

        $this->assertSame($resource->id, $fresh->ownedResources->first()->id);
        $this->assertSame($actor->id, $fresh->governanceMembership->actor_reference_id);
    }
}
