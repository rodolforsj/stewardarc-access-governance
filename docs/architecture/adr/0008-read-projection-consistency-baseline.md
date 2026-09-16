# ADR-008 — Read Projection Consistency Baseline

- **Status:** Accepted
- **Date:** 2026-09-16

## Context

Four functional slices are implemented: Request access (`UC-001`), Decide access request (`UC-002`), Confirm external access grant (`UC-003`) and Record external access revocation (`UC-004`). Their main operations are mutations, and their consistency is governed by [ADR-005](0005-concurrency-and-invariant-enforcement-baseline.md) and [ADR-006](0006-application-implementation-baseline.md): `READ COMMITTED`, Action-owned transactions, pessimistic row locks on the records being changed and the `RN03` advisory lock, always acquired advisory first, then row.

The next use cases, `UC-005` (Follow my requests and accesses) and `UC-006` (Consult accesses and history within responsibility scope), bring a different driver. They are compound reads: one functional answer is assembled from several persisted and derived elements:

- the Access Request and its current state;
- its Decisions;
- its Grant Confirmation;
- the Granted Access and its `valid_until_at`;
- the Revocation Confirmation, when one exists;
- the passage of time;
- when applicable, the current authority and scope of the actor who reads.

Functional History is, per [ADR-003](0003-lifecycle-state-and-persistence-baseline.md) and [ADR-004](0004-initial-data-model-baseline.md), a projection over several preserved facts, not a table. The Granted Access state (`A1`/`A2`/`A3`) is derived from facts, the effective validity period and time, not persisted.

Under PostgreSQL `READ COMMITTED`, every statement sees its own snapshot. A projection built with several SELECTs could load the Access Request before a concurrent operation commits and its Grant Confirmation or Revocation Confirmation after that commit. Each mutation is atomically correct on its own, yet the projection could combine information that never coexisted in that form at any single consistent point in time — a hybrid view.

That is unacceptable for the derived `A1`/`A2`/`A3` state and for the Functional History. The drivers are:

- `RNF-004` — attribution and temporal coherence: the functional sequence must be reconstructable without contradiction;
- `RF-008` — expiration, observed through the status of the accesses consulted;
- `RF-009`, `RF-010`, `RF-011` — consultation of requests, accesses and request history;
- `CA-017`, `CA-018`, `CA-020` and `CA-021` — expiration, revocation after expiration, consultation scopes and history content.

The read side needs a coherent view that does not block writers. This ADR closes only that.

## Decision

### Scope

This baseline applies to the **compound functional read projections** needed by:

- `UC-005` — Follow my requests and accesses;
- `UC-006` — Consult accesses and history within responsibility scope.

That includes the reads that compose `RF-009` (own requests), `RF-010` (accesses), `RF-011` (request history) and the observation of `RF-008` through the derived state of accesses.

It is not applied retroactively to every SELECT in the product. The catalog (`RF-001`) and pending approvals (`RF-004`) are not rewritten by this decision. A future simple query that acquires the same driver — a multi-fact projection that must be coherent — may adopt this baseline, but ADR-008 does not impose `REPEATABLE READ` globally.

### Read transaction isolation

Each compound functional read projection of `UC-005` and `UC-006` runs inside one PostgreSQL transaction that is:

- `REPEATABLE READ`;
- `READ ONLY`.

All queries needed for that projection — including authorization and scope reads — run inside that same transaction. The isolation level and the read-only mode are set before any domain SELECT that could establish the snapshot.

The transaction is short and bounded by the assembly of **one** functional response. It is not kept open beyond that assembly, and it is never tied to a session, a user interface or a subsequent request.

This decision does not change the isolation of mutations:

- **mutations / critical operations** keep `READ COMMITTED` with the locking defined by ADR-005 and ADR-006;
- **compound functional read projections** use `REPEATABLE READ READ ONLY`.

The global PostgreSQL default is not changed, the application as a whole is not raised to `REPEATABLE READ`, and `SERIALIZABLE` is not used. ADR-005 is not revoked: ADR-008 is a scope-specific evolution for the read side.

The concrete PHP mechanism that opens and configures the transaction is not frozen. Laravel, Eloquent or the `DB` facade may materialize it, provided the PostgreSQL effect is exactly the one decided here. This ADR does not choose a helper, class, transactional executor name, trait, middleware or repository abstraction.

### Projection reference instant

Every projection has exactly one temporal reference instant, `projection_reference_at`.

The approved and preferred source is PostgreSQL `transaction_timestamp()`, obtained inside the same `REPEATABLE READ READ ONLY` transaction by the first SELECT of the projection. That instant is reused for **every** temporal derivation in that projection.

Different Granted Accesses of the same response are not evaluated against repeated calls to `Carbon::now()`, `now()` or `clock_timestamp()`.

No clock abstraction and no PHP clock class is introduced. The idea is simply: one PostgreSQL snapshot plus one reference instant from the same transaction, for the whole projection.

### Derived Granted Access state

No new state definition is created. The approved baseline is preserved exactly, with the current time replaced by `projection_reference_at`:

- **`A3` — Ended by confirmed revocation:** a Revocation Confirmation exists, and either `valid_until_at` is NULL or the confirmation's `recorded_at` is before `valid_until_at`.
- **`A2` — Ended by expiration:** `valid_until_at` is not NULL, `projection_reference_at` is at or after `valid_until_at`, and either no Revocation Confirmation exists or its `recorded_at` is at or after `valid_until_at`.
- **`A1` — Active:** no Revocation Confirmation exists, and either `valid_until_at` is NULL or `projection_reference_at` is before `valid_until_at`.

The boundary is unchanged: before `valid_until_at` the access may be `A1`; at `valid_until_at` or later its validity has ended.

- A revocation recorded exactly at `valid_until_at` does not produce `A3`: the access is `A2`, and the Revocation Confirmation remains a preserved historical fact.
- An access that became `A3` through a revocation confirmed before the end of its validity does not become `A2` as time passes.

### Functional History projection

Functional History keeps no table of its own. No `functional_history`, `history_events` or `domain_events` table, event store, audit table, generic append-only event table or event sourcing is introduced.

The projection is composed, when applicable, of:

| Entry | Source | `occurred_at` | Actor |
| --- | --- | --- | --- |
| Request registered | `access_requests` | `requested_at` | The requester of the Access Request. |
| Decision | `decisions` | `decided_at` | `actor_reference_id`. Stage, outcome and, when present, the rejection justification are preserved. |
| Grant confirmation | `grant_confirmations` | `recorded_at` | `actor_reference_id`, the actor who recorded the confirmation in StewardArc. |
| Revocation confirmation | `revocation_confirmations` | `recorded_at` | `actor_reference_id`, the actor who recorded the confirmation in StewardArc. |
| Expiration milestone | Derived from `granted_accesses.valid_until_at` | `valid_until_at` | None. |

The history does not mirror state changes as artificial entries. No "S1 entered", "S1 left", "S2 entered", "S2 left", "S3 entered", "S4 entered" or "S5 entered" entry is projected when the change is already explained by the corresponding preserved fact:

- a Resource Owner or Governance approval is the Decision;
- a rejection is the Decision, with its outcome and justification;
- a confirmed grant is the Grant Confirmation;
- a confirmed revocation is the Revocation Confirmation.

The creation of the Granted Access is a consequence of the same functional operation as the Grant Confirmation. It does not become a second history entry — no `access_created`, `access_activated` or `granted_access_started`. The Grant Confirmation is the preserved functional event; the Granted Access serves validity, derived state, the revocation relationship and access queries.

Authorship follows `RNF-004`: facts with authorship point to the preserved Actor Reference. No fictitious actor such as `system`, `scheduler`, `StewardArc` or `automation` is invented, and no historical snapshot of `display_name` is introduced; how a later rename would be presented is not decided here.

### Expiration milestone

Expiration is still not a persisted fact. When it appears in the Functional History it is a derived milestone:

- its semantic kind is expiration;
- `occurred_at` is `valid_until_at`;
- it has no actor and no executor;
- it does not imply an external revocation;
- it creates no row, no persisted UUID, no scheduler and no job.

It is projected only when expiration is what actually ends the access at `projection_reference_at`, that is, under exactly the `A2` condition:

- `valid_until_at` is not NULL;
- `projection_reference_at` is at or after `valid_until_at`;
- no Revocation Confirmation exists, or its `recorded_at` is at or after `valid_until_at`.

Consequently:

- **Revocation before `valid_until_at`:** the access is `A3`. No "ended by expiration" milestone is later invented merely because the clock has passed `valid_until_at`. `A3` does not turn into `A2`.
- **Revocation at or after `valid_until_at`:** the access is `A2`. The expiration milestone at `valid_until_at` may compose the history, and the Revocation Confirmation remains a separate preserved historical fact (`CA-018`, scenario B).

### Authorization scope

The caller keeps supplying an already-resolved `actor_reference_id`; this ADR chooses no authentication.

When a projection needs authorization information from the database — Resource ownership, Governance Membership — those checks run inside the **same** `REPEATABLE READ` snapshot as the content. Authority is never read in one snapshot and content in another.

- **`UC-005`:** the Requester consults only their own Access Requests, their own Granted Accesses and the Functional History of those requests.
- **`UC-006`, when implemented:** the Resource Owner consults accesses, requests and history tied to resources under their responsibility; Governance consults Granted Accesses, requests and history of Privileged profiles, with its authority coming from `governance_memberships` ([ADR-007](0007-governance-authority-baseline.md)).

ADR-008 does not implement these scopes; it only defines the consistency of the future projections.

### Projection materialization boundary

The projection is **fully materialized while the `REPEATABLE READ READ ONLY` transaction is still active**.

What is returned must not trigger further database access after the transaction ends: no later Eloquent lazy loading, no relation loading outside the transaction and no additional query after the read transaction commits. Lazy database access after the transaction is incompatible with this baseline, because it would read outside the projection's snapshot.

The concrete return type is not frozen. Arrays, collections, small projection objects or other internal values may be evaluated per slice. No DTO framework is introduced.

The projection is not an API. This ADR does not decide REST endpoints, paths, JSON schema, HTTP DTOs, status codes, pagination, cursors, page size, UI ordering, frontend models, GraphQL, public API versioning, final query class names or a new namespace. The information needed for temporal reconstruction stays available through `occurred_at` and the kind or source of each entry. Temporal ties require no public visual order here; state semantics come from the approved rules, not from the order in which a list is presented.

### No read locking

Projections do not acquire:

- `FOR UPDATE` or `FOR SHARE`;
- the `RN03` advisory lock or any other advisory lock;
- a Redis or distributed lock;
- an optimistic-version lock.

The goal is snapshot consistency, not serialization against writers. Writers keep running normally while a projection reads its snapshot.

**Freshness is not consistency.** `REPEATABLE READ` gives a coherent view of the transaction's snapshot. It does not mean the answer contains every commit that happened after the projection started. If a writer commits after the projection's snapshot was established, the current projection may not see it — that is expected — and a new read execution may see it. The guarantee is point-in-time coherence, not "always the most recent commit during the query's own execution". What is excluded is a contradictory combination of different snapshots within the same projection.

### Testing baseline

No test is written by this ADR. When `UC-005` and `UC-006` are implemented, tests of snapshot semantics use real PostgreSQL, following ADR-006. SQLite is not an adequate substitute for PostgreSQL `REPEATABLE READ`, read-only transaction behavior or concurrent snapshots.

The future suite should demonstrate at least:

- the whole projection observes a single snapshot;
- a concurrent commit in the middle of the read does not produce a hybrid projection;
- a subsequent projection observes the new commit;
- a single `projection_reference_at` is used for every derived state;
- no write happens inside the projection transaction;
- the projection is fully materialized before the transaction ends.

## Rationale

The reasoning is specific to StewardArc and is not a claim that `REPEATABLE READ` is the right choice for every read.

- `REPEATABLE READ` gives all statements of a transaction the same snapshot, which removes the hybrid projection that `READ COMMITTED` allows across statements.
- `READ ONLY` states the nature of the operation and lets PostgreSQL reject an accidental write inside it.
- Keeping mutations at `READ COMMITTED` avoids widening the isolation policy where ADR-005 already provides the needed guarantees through explicit locking; the stronger level is used only where the multi-fact read driver exists.
- A read-only `REPEATABLE READ` transaction does not raise serialization failures by itself, so this baseline introduces no retry need on the read side.
- Row or advisory locks on reads would block writers only to obtain a view that the snapshot already provides.
- `transaction_timestamp()` is fixed for the whole transaction, so it gives one stable temporal reference per execution, taken from the same source and transaction as the snapshot.
- Projecting history from preserved facts avoids a duplicated persistence of what the facts already say, and avoids event sourcing without a driver.
- Deriving the expiration milestone with the same rule as `A2` keeps the history and the displayed status from contradicting each other.
- Materializing the result inside the transaction prevents lazy loads from silently reading outside the snapshot.

## Consequences

Benefits:

- One execution produces a coherent view.
- `A1`/`A2`/`A3` are derived against one common instant.
- History and status belong to the same snapshot.
- Readers do not block writers with explicit locks.
- No new persistence is introduced.
- Expiration stays derived.
- The command concurrency baseline stays intact.

Trade-offs:

- A read transaction may observe a state slightly earlier than a concurrent commit.
- `REPEATABLE READ` holds its snapshot for the whole transaction, so projections must be short.
- The projection must be fully materialized inside the transaction.
- Tests of these semantics depend on real PostgreSQL.
- The future implementation must control isolation and read-only mode explicitly.

## Deferred decisions

The following remain open:

- the concrete PHP class or helper for the read transaction;
- concrete return types;
- endpoints and API;
- JSON;
- pagination;
- presentation ordering;
- API error mapping;
- frontend;
- authentication and session;
- caching;
- materialized views;
- performance indexes;
- observability;
- CI;
- deployment.

The following are **not** reopened by later work: `REPEATABLE READ READ ONLY` for the compound projections of `UC-005` and `UC-006`, the single projection reference instant, PostgreSQL `transaction_timestamp()` as its approved and preferred source, the absence of read locks, Functional History as a projection, expiration as a derived milestone without an actor, and full materialization before the transaction ends.

## Relationship to previous ADRs

- [ADR-001](0001-backend-architecture-baseline.md): the projections live inside the same cohesive Access Governance boundary.
- [ADR-002](0002-technology-stack-baseline.md): PostgreSQL remains the database; no new technology is introduced.
- [ADR-003](0003-lifecycle-state-and-persistence-baseline.md): `A1`/`A2`/`A3` stay derived and Functional History stays a projection; ADR-008 defines how to read those elements coherently.
- [ADR-004](0004-initial-data-model-baseline.md): no change to the schema or the data model.
- [ADR-005](0005-concurrency-and-invariant-enforcement-baseline.md): `READ COMMITTED` remains the baseline for mutations and the command locks are unchanged; ADR-008 adds a specific isolation only for compound read projections.
- [ADR-006](0006-application-implementation-baseline.md): the Action organization and the concurrency baseline continue; ADR-008 does not yet choose the concrete PHP class for reads; PostgreSQL-dependent tests keep using real PostgreSQL.
- [ADR-007](0007-governance-authority-baseline.md): Governance authority keeps coming from `governance_memberships`; when `UC-006` reads that authority, it belongs to the same snapshot as the projection.

ADR-008 complements the previous ADRs and rewrites none of them. It does not change the `S1`–`S5` lifecycle, the derived `A1`–`A3` lifecycle, the business rules, the authority sources, the schema, the models, the constraints, the UUID policy, the Functional History and expiration persistence policies, the command locking and isolation, the authentication boundary or the Governance Membership semantics.

## Out of scope

This ADR does not create:

- the `UC-005` implementation;
- the `UC-006` implementation;
- query classes;
- DTOs;
- API;
- authentication;
- frontend;
- cache;
- migrations;
- models;
- schema changes;
- materialized views;
- a history table;
- event sourcing;
- a scheduler;
- an expiration job;
- performance tuning;
- CI.
