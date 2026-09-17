<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\Authentication;

use App\Authentication\ActorReferenceResolver;
use App\Authentication\Exceptions\UnresolvedExternalIdentity;
use App\Authentication\ExternalIdentityKey;
use App\Models\ActorReference;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * ADR-010 and ADR-011: an authenticated external identity resolves to exactly
 * one pre-provisioned Actor Reference, or fails closed.
 */
final class ActorReferenceResolverTest extends PostgresTestCase
{
    private const ISSUER = 'https://id.example.test';

    public function test_a_known_identity_resolves_to_its_actor_reference(): void
    {
        $this->makeActorWithIdentity('Someone else', 'other-subject');
        $actor = $this->makeActorWithIdentity('Requester', '248289761001');

        $resolved = $this->resolver()->resolve(ExternalIdentityKey::fromOidc(self::ISSUER, '248289761001'));

        $this->assertInstanceOf(ActorReference::class, $resolved);
        $this->assertTrue($resolved->is($actor));
        $this->assertSame($actor->id, $resolved->id);
    }

    public function test_an_unknown_identity_fails_closed_without_creating_anything(): void
    {
        $this->makeActorWithIdentity('Requester', '248289761001');
        $before = DB::table('actor_references')->orderBy('id')->get()->all();

        try {
            $this->resolver()->resolve(ExternalIdentityKey::fromOidc(self::ISSUER, 'unknown-subject'));
            $this->fail('An unknown identity must not resolve.');
        } catch (UnresolvedExternalIdentity $error) {
            // The identity itself is not echoed back.
            $this->assertStringNotContainsString('unknown-subject', $error->getMessage());
            $this->assertStringNotContainsString('oidc:v1:', $error->getMessage());
        }

        $this->assertEquals($before, DB::table('actor_references')->orderBy('id')->get()->all());
        $this->assertSame(0, DB::table('governance_memberships')->count());
    }

    public function test_the_lookup_uses_only_the_exact_external_identity_key(): void
    {
        $actor = $this->makeActorWithIdentity('Requester', '248289761001');
        $key = ExternalIdentityKey::fromOidc(self::ISSUER, '248289761001');

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $this->assertTrue($this->resolver()->resolve($key)->is($actor));

        $this->assertCount(1, $queries);
        $this->assertMatchesRegularExpression('/^select \* from "actor_references" where "external_identity_key" = \? limit 1$/', $queries[0]['sql']);
        $this->assertSame([$key->value()], $queries[0]['bindings']);

        // Any other representation of the same claims does not resolve.
        foreach ([
            ExternalIdentityKey::fromOidc(self::ISSUER.'/', '248289761001'),
            ExternalIdentityKey::fromOidc(strtoupper(self::ISSUER), '248289761001'),
            ExternalIdentityKey::fromOidc(self::ISSUER, ' 248289761001'),
            ExternalIdentityKey::fromOidc('https://other.example.test', '248289761001'),
        ] as $variant) {
            $this->assertUnresolved($variant);
        }
    }

    /** Display names and e-mail-like values never take part in the resolution. */
    public function test_display_name_and_email_do_not_participate(): void
    {
        $email = 'requester@example.test';
        $keyByEmail = ExternalIdentityKey::fromOidc(self::ISSUER, $email);

        // An actor whose display name is the e-mail, or even the would-be key.
        $this->makeActor($email);
        $this->makeActor($keyByEmail->value());
        $this->makeActorWithIdentity($email, '248289761001');

        $this->assertUnresolved($keyByEmail);
        $this->assertUnresolved(ExternalIdentityKey::fromOidc(self::ISSUER, 'Requester'));
    }

    private function resolver(): ActorReferenceResolver
    {
        return new ActorReferenceResolver();
    }

    private function makeActorWithIdentity(string $displayName, string $subject): ActorReference
    {
        $actor = new ActorReference();
        $actor->external_identity_key = ExternalIdentityKey::fromOidc(self::ISSUER, $subject)->value();
        $actor->display_name = $displayName;
        $actor->save();

        return $actor;
    }

    private function assertUnresolved(ExternalIdentityKey $key): void
    {
        try {
            $this->resolver()->resolve($key);
            $this->fail('The identity must not resolve: '.$key->value());
        } catch (UnresolvedExternalIdentity) {
            $this->addToAssertionCount(1);
        }
    }
}
