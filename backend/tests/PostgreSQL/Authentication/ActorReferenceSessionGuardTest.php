<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\Authentication;

use App\Models\ActorReference;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * ADR-011: the Actor Reference is the principal of Laravel's session guard,
 * without any password or remember token.
 */
final class ActorReferenceSessionGuardTest extends PostgresTestCase
{
    public function test_the_default_guard_is_a_session_guard_over_actor_references(): void
    {
        $this->assertSame('web', config('auth.defaults.guard'));
        $this->assertSame(['driver' => 'session', 'provider' => 'actor_references'], config('auth.guards.web'));
        $this->assertSame(['driver' => 'eloquent', 'model' => ActorReference::class], config('auth.providers.actor_references'));

        $guard = Auth::guard();
        $this->assertInstanceOf(SessionGuard::class, $guard);
        $this->assertInstanceOf(EloquentUserProvider::class, $guard->getProvider());
        $this->assertSame(ActorReference::class, $guard->getProvider()->getModel());

        // The automated suites keep the in-memory session.
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertTrue(config('session.http_only'));
    }

    public function test_an_actor_reference_can_be_logged_in_and_only_its_id_is_kept_in_the_session(): void
    {
        $actor = $this->makeActor('Requester');
        $writes = $this->recordWrites();

        Auth::login($actor, false);

        $this->assertTrue(Auth::check());
        $this->assertSame($actor->id, Auth::id());
        $this->assertTrue(Auth::user()->is($actor));
        $this->assertSame([], $writes());

        // The guard keeps nothing but the Actor Reference id; the regenerated
        // session also carries the framework's rotated CSRF token.
        $session = session()->all();
        $this->assertEqualsCanonicalizing([Auth::guard()->getName(), '_token'], array_keys($session));
        $this->assertSame($actor->id, $session[Auth::guard()->getName()]);
        $this->assertSame(session()->token(), $session['_token']);
        $this->assertNotContains($actor->external_identity_key, $session);
        $this->assertNotContains($actor->display_name, $session);
    }

    public function test_the_actor_is_rehydrated_from_the_session_id_alone(): void
    {
        $actor = $this->makeActor('Requester');
        Auth::login($actor, false);
        $sessionKey = Auth::guard()->getName();

        // A fresh guard only has the id stored in the session.
        Auth::forgetGuards();

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $rehydrated = Auth::user();

        $this->assertInstanceOf(ActorReference::class, $rehydrated);
        $this->assertNotSame($actor, $rehydrated);
        $this->assertTrue($rehydrated->is($actor));
        $this->assertSame($actor->id, session($sessionKey));
        $this->assertCount(1, $queries);
        $this->assertMatchesRegularExpression('/^select \* from "actor_references" where "id" = \? limit 1$/', $queries[0]['sql']);
        $this->assertSame([$actor->id], $queries[0]['bindings']);
    }

    public function test_an_id_without_actor_reference_leaves_the_request_unauthenticated(): void
    {
        $actor = $this->makeActor('Removed');
        Auth::login($actor, false);
        DB::table('actor_references')->where('id', $actor->id)->delete();
        Auth::forgetGuards();

        $this->assertNull(Auth::user());
        $this->assertFalse(Auth::check());

        session([Auth::guard()->getName() => (string) Str::uuid7()]);
        Auth::forgetGuards();

        $this->assertNull(Auth::user());
    }

    public function test_password_authentication_can_never_succeed(): void
    {
        $actor = $this->makeActor('Requester');
        Event::fake([Failed::class]);
        $writes = $this->recordWrites();

        $this->assertNull($actor->getAuthPassword());
        $this->assertFalse(Auth::attempt(['id' => $actor->id, 'password' => 'any password']));
        $this->assertFalse(Auth::attempt(['external_identity_key' => $actor->external_identity_key, 'password' => '']));
        $this->assertFalse(Auth::attempt(['id' => $actor->id, 'password' => null]));
        $this->assertFalse(Auth::validate(['id' => $actor->id, 'password' => 'any password']));
        $this->assertFalse(Auth::guard()->getProvider()->validateCredentials($actor, ['password' => 'any password']));

        $this->assertFalse(Auth::check());
        $this->assertSame([], session()->all());
        Event::assertDispatched(Failed::class, 3);

        // Rewriting a password hash is refused before anything is written.
        Auth::login($actor, false);

        try {
            Auth::logoutOtherDevices('any password');
            $this->fail('Logging out other devices requires a password that does not exist.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], $writes());
    }

    public function test_remember_me_is_never_part_of_the_flow(): void
    {
        $actor = $this->makeActor('Requester');
        $writes = $this->recordWrites();

        Auth::login($actor, false);

        $this->assertNull($actor->getRememberToken());
        $this->assertFalse(Cookie::hasQueued(Auth::guard()->getRecallerName()));
        $this->assertFalse(Auth::viaRemember());
        $this->assertSame([], $writes());

        $actor->setRememberToken('a token');
        $this->assertNull($actor->getRememberToken());
        $this->assertFalse($actor->isDirty());
    }

    public function test_logout_ends_the_authentication_without_writing_to_actor_references(): void
    {
        $actor = $this->makeActor('Requester');
        $before = DB::table('actor_references')->where('id', $actor->id)->first();
        Auth::login($actor, false);
        $writes = $this->recordWrites();

        Auth::logout();

        $this->assertFalse(Auth::check());
        $this->assertNull(Auth::user());
        $this->assertFalse(session()->has(Auth::guard()->getName()));
        $this->assertSame([], $writes());
        $this->assertEquals($before, DB::table('actor_references')->where('id', $actor->id)->first());

        // A fresh guard over the same session is not authenticated either.
        Auth::forgetGuards();
        $this->assertNull(Auth::user());
    }

    public function test_the_actor_reference_table_has_no_credential_columns(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'actor_references')
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->all();

        $this->assertSame(['id', 'external_identity_key', 'display_name'], $columns);
    }

    public function test_the_credential_attribute_names_are_refused(): void
    {
        $actor = new ActorReference();

        foreach (['getAuthPasswordName', 'getRememberTokenName'] as $method) {
            try {
                $actor->{$method}();
                $this->fail("{$method}() must refuse: there is no such attribute.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @return callable(): list<string> the write statements executed so far
     */
    private function recordWrites(): callable
    {
        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|truncate)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        return static function () use (&$writes): array {
            return $writes;
        };
    }
}
