# ADR-006 — Application Implementation Baseline

- **Status:** Accepted
- **Date:** 2026-09-16

## Context

[ADR-001](0001-backend-architecture-baseline.md) established a modular monolith with a shared consistency boundary. [ADR-002](0002-technology-stack-baseline.md) selected Laravel, Eloquent and PostgreSQL. [ADR-003](0003-lifecycle-state-and-persistence-baseline.md) decided that a functional fact and the related state change belong to the same transaction. [ADR-004](0004-initial-data-model-baseline.md) defined the persistence model, which is now materialized by the backend migrations and Eloquent models. [ADR-005](0005-concurrency-and-invariant-enforcement-baseline.md) decided the concurrency strategy: `READ COMMITTED`, row locks on existing records, and a transaction-level advisory lock per requester and access profile for `RN03`, leaving the physical key and the concrete lock order open.

The next step is the first functional slice. Implementing it without a baseline would settle several things incidentally and invisibly: where application code lives, who owns the transaction, how the advisory lock key is encoded, in which order locks are taken, and which tests may run without a real database.

This ADR fixes that minimum and nothing beyond it.

## Decision

### Application organization

| Namespace | Role |
| --- | --- |
| `App\Models` | Eloquent models, as they exist today. |
| `App\AccessGovernance\Actions` | Future functional operations of the application. |
| `App\AccessGovernance\Concurrency` | PostgreSQL-specific concurrency mechanisms. |

The organization is deliberately small. This baseline introduces no generic repository layer, no repository interfaces, no generic service layer, no command bus, query bus, mediator or event bus, no CQRS, no event sourcing, no formal aggregates, no formal Clean or Hexagonal Architecture, no artificial ports and adapters, and no abstraction created only to "prepare for the future".

ADR-001 remains valid. These namespaces are not services, independent bounded contexts or distributed layers; they are directories inside one modular monolith.

A critical functional operation is represented by an **Action** focused on the corresponding operation, such as, in the future, creating an Access Request, recording a Decision, confirming a Grant or confirming a Revocation. An Action:

- orchestrates the functional operation;
- applies the rules that operation requires;
- uses the Eloquent models directly where appropriate;
- owns its transaction boundary;
- uses components from `Concurrency` when the concurrency baseline requires them.

Eloquent models do not become domain services, and the functional logic does not move into the models. The PHP signatures of the future Actions are not decided here.

### Transaction ownership

Each Action that represents a critical operation opens and controls its own `DB::transaction()`. The transaction belongs to the functional operation.

Components in `App\AccessGovernance\Concurrency` take part in the transaction that already exists. They do not start their own transaction, do not commit, and do not perform a functional rollback on their own.

This preserves ADR-003: the facts, state changes and other effects of one functional result stay in the same PostgreSQL transaction.

### Advisory lock key encoding

ADR-005 decided the transaction-level advisory lock per (`requester`, `access_profile`). This ADR closes its physical representation.

The function is `pg_advisory_xact_lock(integer, integer)`, which exists in the PostgreSQL materialized by the project.

The logical key starts from the requester Actor Reference UUID and the Access Profile UUID, in canonical textual form: lowercase, hyphenated, 36 characters. The canonical string is exactly:

```text
stewardarc:rn03:v1|<requester_uuid>|<profile_uuid>
```

Purely illustrative example:

```text
stewardarc:rn03:v1|018f0000-0000-7000-8000-000000000001|018f0000-0000-7000-8000-000000000002
```

The namespace and the version are part of the key definition. The key does not use an email, the `external_identity_key`, a name, database row order, a PHP object hash, `crc32`, any process-dependent hash, a non-deterministic `hash()` call, or identifiers concatenated without namespace and version.

From that string:

1. compute **SHA-256** over its UTF-8 bytes;
2. use the **binary** digest, not its hexadecimal representation, to select bytes;
3. take the **first 8 bytes** of the 32-byte digest;
4. split them into bytes 0..3 and bytes 4..7;
5. read each group as a 32-bit integer in **big-endian** order;
6. reinterpret each 32-bit two's-complement pattern as a **signed int32**, in the range `-2147483648 .. 2147483647`.

The result is `key1` and `key2`, passed to `pg_advisory_xact_lock(key1, key2)`.

The implementation must not rely on 32-bit-machine-dependent arithmetic; it must behave correctly on the 64-bit PHP environment currently materialized. This ADR documents the algorithm; the helper itself is not written yet.

**Collision property.** The physical key carries 64 effective bits of the SHA-256 digest. A collision between two different pairs must never be treated as business equivalence: it would only serialize unrelated pairs unnecessarily. `RN03` is still validated with the real domain identifiers and facts after the lock is acquired. In other words, the advisory lock key is not a business identity. This baseline makes no claim that collisions are impossible and adds no extra mechanism to detect them.

### Lock acquisition order

The order is **advisory lock → row lock**, for every operation that needs both.

- **Request creation** takes the advisory lock. It normally has no existing Access Request of its own to row-lock. After the lock it re-evaluates `RN03` and creates the request only if it is eligible.
- **Grant Confirmation** safely obtains the requester and profile pair needed for the key, then acquires the advisory lock, then the row lock on the Access Request, and only then re-reads and re-validates the state and facts inside the transaction before producing the result. A read taken before the row lock is not sufficient to validate the lifecycle. The requester and the profile are structural facts of the request and are not changed by the functional operations of the MVP.
- **Revocation Confirmation** resolves the requester and profile pair associated with the Granted Access, acquires the advisory lock, then the row lock on the Granted Access, and then re-evaluates the derived state and validates the operation. The path from the Granted Access back to the original request and grant is preserved by the facts of the model.
- **Decisions** that do not take part in the `RN03` serialization do not acquire the advisory lock merely for being Decisions. They rely on the row lock of their Access Request, as decided in ADR-005.

As a textual pseudo-flow, an operation that needs both locks looks like:

```text
begin transaction
    acquire advisory lock (key1, key2) derived from (requester, access profile)
    lock the relevant row for update
    re-read and re-validate state and facts
    record the functional fact and the related state change
commit
```

### Lock wait behavior

The baseline uses the blocking acquisition `pg_advisory_xact_lock`. The try-lock variant is not the default behavior.

The application configures no custom `lock_timeout`, no advisory-lock timeout of its own, no polling, no busy loop and no "try again later" fallback. It uses the blocking behavior of PostgreSQL as configured in the environment. No number of seconds is fixed here and no waiting SLA is asserted. Timeout and wait tuning will be introduced only if a real driver appears.

There is **no** approved automatic retry: no deadlock retry, no lock-timeout retry, no exponential backoff, no maximum attempt count, no retry middleware and no transaction retry helper. `RNF-009` still requires the absence of duplicate or conflicting effects, but that does not imply an automatic retry policy now.

### Testing baseline

**Pure unit tests** do not depend on a database and do not require PostgreSQL. They cover logic that can genuinely be exercised without persistence. No abstraction is created only to increase the number of unit tests.

**PostgreSQL integration tests** are required for anything that depends on Eloquent persistence, migrations, CHECK constraints, UNIQUE constraints, foreign keys, the partial unique index, transaction semantics, `SELECT ... FOR UPDATE`, advisory locks, concurrent `RN03` or `timestamptz` behavior. SQLite is not an acceptable substitute for these, because the semantics under test are PostgreSQL semantics.

The scaffold's current stock tests are not retroactively declared the application testing strategy merely because they exist. They are left untouched by this baseline.

**Concurrency tests** must use PostgreSQL, use two or more independent connections or sessions, work with base data already committed before the concurrent transactions start, explicitly control the points needed to demonstrate blocking and serialization, and assert the final persisted state and the invariant rather than only waiting times. Concurrency is not simulated by calling two functions sequentially on the same connection, and locking is not validated through mocks alone.

The local PostgreSQL testing strategy is decided here. How PostgreSQL is provisioned in CI remains open until the CI checkpoint, and no workflow is created now.

### Clock

No clock abstraction is chosen. The `A1`/`A2` state depends on time, but there is not yet enough of a driver to freeze a `Clock` interface, a Carbon wrapper, a global fake clock or a specific provider. If the first temporal lifecycle tests demonstrate the need, it can be decided in that implementation.

### Relation to authentication and API

This ADR chooses no authentication, session, auth middleware, identity provider, Governance authority source, REST endpoint shape, controller, HTTP request DTO, status code, error response schema or transport idempotency key.

The future Actions must be able to receive an already-resolved identity or actor from the calling layer once that integration exists. No fictitious HTTP layer is created now.

## Rationale

The reasoning is specific to StewardArc, not a set of universal best practices.

- A minimal structure avoids abstractions that have no driver. Three namespaces are enough to start, and more structure can be added when real pressure appears.
- Actions give one explicit place where a functional operation and its transaction live, instead of spreading that responsibility across models and controllers.
- Keeping the PostgreSQL-specific mechanisms in their own namespace isolates them without pretending the product is database-agnostic: it depends on PostgreSQL, and that dependency stays visible.
- A namespaced SHA-256 key provides a deterministic, stable mapping from a logical pair to a lock domain, and the namespace and version make a future change of scheme explicit instead of silent.
- Using only 64 bits is sufficient for a lock namespace, because a collision can only add contention: the invariant is re-checked with the real identity after the lock is acquired.
- A single advisory → row order gives combined operations one unambiguous acquisition sequence, which is what prevents lock-order inversion.
- Blocking acquisition avoids inventing a timeout or a retry policy with no requirement behind it.
- Real PostgreSQL is necessary exactly where the tested semantics are PostgreSQL semantics; a SQLite pass would prove nothing about them.

## Consequences

Benefits:

- The implementation of `RF-002` does not have to invent structure, transaction ownership, lock encoding or test strategy along the way.
- The transaction has a clear owner.
- The advisory lock key becomes reproducible and testable, including from outside the application.
- The lock order stops being ambiguous.
- Concurrency tests exercise the real mechanism rather than a simulation of it.

Trade-offs:

- Actions depend directly on Eloquent and Laravel; there is no insulating layer.
- `App\AccessGovernance\Concurrency` carries an explicit PostgreSQL dependency.
- A truncated hash admits theoretical collisions, which can cause extra contention.
- PostgreSQL integration tests are heavier and slower than SQLite tests.
- Blocking acquisition can wait under contention.
- Timeout and retry policies remain pending.

## Deferred decisions

The following remain open:

- authentication;
- session;
- concrete source of the Governance authority;
- transport idempotency;
- retry policy, including deadlock retry;
- custom lock timeout and wait tuning;
- detailed API contracts;
- HTTP error mapping;
- clock abstraction;
- observability;
- logging and correlation implementation;
- CI provisioning of PostgreSQL for tests;
- deployment;
- future package or module evolution, if real pressure appears.

The following are **not** reopened by later work: the package locations of this baseline, the Action-owned transaction, the namespaced SHA-256 advisory key algorithm, the use of the first 8 digest bytes, their representation as two big-endian signed int32 values, the advisory → row lock order, blocking advisory acquisition as the current baseline, and the requirement of real PostgreSQL for database and concurrency integration tests.

## Relationship to previous ADRs

ADR-006 complements ADR-001 to ADR-005 and rewrites none of them. It does not change the lifecycles, does not change the schema and does not implement any lock. It makes the previous decisions concrete enough for the first application commands to be written without inventing structure.

## Phase 2 boundary

With ADR-006, the foundation baseline is considered sufficient to start functional implementation.

This does not mean every future architectural decision is closed. Authentication, API, observability, CI and the other deferred items remain deliberately postponed until their driver appears.

The next planned slice is **UC-001 / RF-002 — Request access**. It is not implemented.

## Out of scope

This ADR does not create:

- Action directories or classes;
- the advisory lock helper;
- any SQL implementation in code;
- use cases;
- tests;
- changes to `phpunit.xml`;
- authentication;
- API;
- frontend;
- CI;
- deployment.
