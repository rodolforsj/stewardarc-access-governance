# ADR-005 — Concurrency and Invariant Enforcement Baseline

- **Status:** Accepted
- **Date:** 2026-09-15

## Context

[ADR-001](0001-backend-architecture-baseline.md) established a shared consistency boundary for the Access Governance domain. [ADR-003](0003-lifecycle-state-and-persistence-baseline.md) decided that a functional fact and the related change of the Access Request current state belong to the same PostgreSQL transaction, and that the Granted Access state (`A1`/`A2`/`A3`) is derived from preserved facts, the effective validity period and time rather than materialized. [ADR-004](0004-initial-data-model-baseline.md) modeled `RN03` only partially, through a unique partial index over the requester and the access profile restricted to requests in `S1`, `S2` and `S3`. That schema is now materialized by the backend migrations.

`RN03` spans two entities: it forbids a new request when an equivalent request is still in processing **or** when an equivalent Granted Access is currently active. The second half has no single row to constrain, because `A1` is derived.

Under `READ COMMITTED`, reading before writing does not prevent two concurrent transactions from each observing a state in which the new request looks admissible, and then both committing — a logical write skew. The window is widest exactly when a request leaves the set protected by the partial index and the Granted Access starts to exist:

`S3` → Grant Confirmation → `S4` → Granted Access.

`RNF-008` requires consistency under failures and `RNF-009` requires that concurrent or repeated operations produce no duplicate or conflicting functional effects. Those requirements cannot be met for `RN03` by the current structural defenses alone.

## Decision

### Isolation level

PostgreSQL stays at `READ COMMITTED`. The baseline does not raise the system globally to `SERIALIZABLE`, and does not adopt `REPEATABLE READ` as the new baseline.

This is contextual to StewardArc and works in combination with the transactions already required by ADR-003, row-level locking on the relevant mutable entities, and advisory locking for the one invariant that has no natural single row to lock. It is not a claim that `READ COMMITTED` is universally superior.

### Row locking — Access Request

An operation that changes the lifecycle of an existing Access Request acquires a pessimistic row lock on that request — conceptually `SELECT ... FOR UPDATE` — before it:

- validates the current state that authorizes the operation;
- records the corresponding functional facts;
- changes `current_state`.

The lock lives inside the same PostgreSQL transaction as the operation. It applies, as relevant, to future operations such as recording a Decision and confirming a Grant, and it preserves the ADR-003 rule that the functional fact and the state change of one functional result are atomic.

### Row locking — Granted Access

The future revocation confirmation acquires a pessimistic row lock on the corresponding Granted Access before it re-evaluates the derived state, validates the operation and records the Revocation Confirmation. The lock is transactional. `A1`/`A2`/`A3` are not materialized and no state column is added.

### RN03 advisory lock

`RN03` is serialized by a PostgreSQL **transaction-level advisory lock** whose key is derived deterministically, at the conceptual level, from the pair (`requester_actor_reference_id`, `access_profile_id`).

The lock is transaction-scoped: acquired inside the transaction, held until `COMMIT` or `ROLLBACK`, and never dependent on a manual release afterwards.

This ADR does not choose the hash algorithm, the key encoding, a specific Laravel helper or any PHP signature. The architectural requirement is only that the same logical pair produces the same lock domain, deterministically.

### Operations covered

The advisory lock is required only for operations that can change whether a new equivalent request is admissible:

1. creation of an Access Request;
2. confirmation of a Grant;
3. confirmation of a Revocation.

Not every command in the system uses it. A Decision does not need this lock merely for being a Decision; it stays protected by the row lock on its Access Request.

### Invariant recheck

`RN03` is re-evaluated **after** entering the serialized region. Within one transaction, the future creation operation:

1. acquires the advisory lock for the requester/profile pair;
2. re-evaluates `RN03` inside that lock;
3. checks whether an equivalent request exists in `S1`, `S2` or `S3`;
4. checks whether an equivalent Granted Access is currently `A1`;
5. only then creates the new request.

### The grant confirmation window

The future grant confirmation holds the advisory lock for the same pair throughout the transactional result that includes validating the Access Request in `S3`, recording the Grant Confirmation, moving `S3` → `S4` and creating the Granted Access. This prevents a new equivalent request from crossing the window in which the request has left `S3` but the Granted Access is not yet visible as a confirmed fact.

**Approval ≠ grant** is preserved: the lock protects the window, it does not merge the two concepts.

### Revocation confirmation

The future revocation confirmation uses the same advisory lock for the pair, while the Granted Access row is also row-locked, its derived state re-evaluated and the Revocation Confirmation recorded. A concurrent creation of an equivalent request waits for that transaction to finish before re-evaluating `RN03`, so it then sees the state already updated by the facts.

### Expiration

The passage of time that takes an access from `A1` to `A2` requires no advisory lock, no row lock, no job, no scheduler and no persisted expiration event. Expiration stays derived from the effective validity period and time, per ADR-003. A creation that checks `RN03` evaluates the current state of the Granted Access according to the `A1`/`A2`/`A3` rules. `A1` is not materialized to make locking easier.

### Structural defenses remain

The unique partial index over (`requester_actor_reference_id`, `access_profile_id`) for `current_state IN ('S1','S2','S3')` remains mandatory and is not replaced by the advisory lock. The existing `UNIQUE`, `CHECK` and foreign key constraints continue to apply.

The architecture is therefore defense in depth:

- the advisory lock serializes the logical invariant per requester and access profile;
- row locks protect transitions over existing records;
- constraints structurally prevent duplicate facts.

This is not a distributed lock. It is a PostgreSQL mechanism inside the same transactional database that already forms the consistency boundary.

### Retry and transport idempotency

Both are explicitly deferred. This baseline adopts no automatic retry strategy and chooses no transport idempotency keys.

### Not part of this baseline

The baseline does not introduce global `SERIALIZABLE` isolation, a lock table, a Redis or cache distributed lock, an optimistic version column, a materialized `is_active` flag, materialized `A1`/`A2`/`A3`, a trigger for `RN03` or for expiration, a scheduler that maintains state, an artificial exclusion constraint, a new invariants table or event sourcing. None of these is forbidden forever; they simply do not belong to the current baseline.

## Rationale

The reasoning is specific to StewardArc, not a general claim that advisory locks are the best tool.

- `READ COMMITTED` plus check-before-write does not close `RN03`: two concurrent transactions can each read an admissible state and both commit.
- The partial unique index solves the "request already in processing" half, but not the cut between `S3`, `S4` and the new Granted Access, because the request leaves the indexed set at the same moment the access starts to exist.
- During the creation of a request there is no natural single row to lock: the conflicting state may live in another request, in a Granted Access, or not exist yet.
- A transaction-level advisory lock provides a logical unit of serialization for exactly that pair, without inventing a physical row to represent it.
- Row locks are the appropriate tool for existing records whose state will change, and they keep concurrent commands from deciding on stale state.
- Keeping `READ COMMITTED` avoids raising the whole system to a stronger isolation level because of one localized invariant.
- Not materializing `A1` preserves ADR-003 and avoids a redundant state that would need to be kept in sync.

## Consequences

Benefits:

- `RN03` can be protected atomically across access requests and granted accesses.
- The `S3` → `S4` window is serialized.
- Concurrent commands over the same request do not decide on stale state.
- No materialization of `A1` is required.
- The database remains the same consistency boundary; no external coordination service is introduced.

Trade-offs:

- Operations over the same requester and access profile may wait for each other.
- Advisory locks require consistent discipline in the application: a command that forgets the lock silently loses the guarantee.
- Diagnosing contention will require observability that does not exist yet.
- Deadlocks still have to be handled correctly when more than one lock is acquired.
- The retry policy remains pending.
- The physical advisory lock key will have to be implemented in a stable and testable way.

## Lock ordering

Operations that combine an advisory lock and row locks must follow **one** consistent acquisition order across the whole application, to avoid lock-order inversion.

The concrete physical order is not frozen here. It must be defined and tested when the application commands are implemented.

## Deferred decisions

The following remain open:

- the physical algorithm and encoding of the advisory lock key;
- the Laravel helper or component that will encapsulate advisory locking;
- lock timeout and wait policy;
- retry policy, including deadlock retry;
- transport idempotency keys: format, header, persistence, scope, time window and replay semantics;
- authentication;
- session;
- concrete source of the Governance authority;
- detailed API contracts;
- HTTP error mapping;
- a clock abstraction for tests, if one proves necessary;
- observability of lock contention;
- testing strategy;
- CI;
- deployment.

The absence of a transport idempotency key does not mean duplicate effects are acceptable. The current guarantees remain the persisted current state, row locks, the advisory lock, the `UNIQUE` constraints and the preserved facts. What a repeated successful HTTP call should mean is simply not decided yet.

The following are **not** reopened by later work: `READ COMMITTED` as the isolation level, the conceptual use of row locks, the conceptual use of the transactional advisory lock for `RN03`, and which operations take part in that serialization.

## Relationship to previous ADRs

- [ADR-001](0001-backend-architecture-baseline.md) defines the shared consistency boundary this baseline operates inside.
- [ADR-003](0003-lifecycle-state-and-persistence-baseline.md) defines the atomicity of a functional result; this ADR says how concurrent results are kept from conflicting.
- [ADR-004](0004-initial-data-model-baseline.md) defines the schema and the partial unique index; this ADR closes the concurrency strategy those ADRs deliberately left open.

ADR-005 complements them and rewrites none of them.

## Out of scope

This ADR does not define:

- application code;
- services;
- repositories;
- controllers;
- API;
- authentication;
- policies;
- the SQL implementation of the advisory lock;
- retries;
- idempotency middleware;
- concurrency tests;
- schema changes;
- new migrations;
- triggers;
- schedulers;
- queues.
