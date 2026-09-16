# ADR-009 — Functional Time Source Baseline

- **Status:** Accepted
- **Date:** 2026-09-16

## Context

`UC-005` (Follow my requests and accesses) materialized [ADR-008](0008-read-projection-consistency-baseline.md). Its compound read projection runs in one PostgreSQL `REPEATABLE READ READ ONLY` transaction and takes its single `projection_reference_at` from PostgreSQL `transaction_timestamp()`.

The mutations that create the preserved functional facts take their instants from another clock. `CreateAccessRequest`, `DecideAccessRequest`, `ConfirmExternalAccessGrant` and `RecordExternalAccessRevocation` assign `requested_at`, `decided_at` and `recorded_at` from `Carbon::now()`, that is, from the clock of the PHP process.

The product therefore uses two time sources for functional time: the application clock for the facts it writes, and the PostgreSQL clock for the moment at which a projection derives state. Under normal conditions both clocks are synchronized, but the architecture depends on that synchronization implicitly.

With enough skew, a conceptually invalid combination can appear. A Revocation Confirmation may already be visible in a projection's PostgreSQL snapshot while its `recorded_at`, taken from the PHP clock, is later than that projection's own `projection_reference_at`. A fact that has already been committed would then appear to happen after the instant at which it is observed. The `A1`/`A2`/`A3` rules of [ADR-003](0003-lifecycle-state-and-persistence-baseline.md) and ADR-008 do not cover that combination, so their completeness would rest on the clocks agreeing.

`RNF-004` requires the functional sequence to be reconstructable without contradiction. The driver is to give functional time one source, shared by writers and read projections, without changing the schema.

## Decision

### Canonical functional time source

**PostgreSQL** is the canonical time source for the preserved functional timestamps that StewardArc generates inside its transactional operations. Writers and read projections belong to the same PostgreSQL timeline.

The following are not the functional time source of these operations: `Carbon::now()`, PHP `now()`, the clock of the host running PHP, `clock_timestamp()`, `statement_timestamp()`, `CURRENT_TIMESTAMP` used in a way that hides this decision, a database default or a trigger.

### Functional transaction instant

The approved function is PostgreSQL **`transaction_timestamp()`**.

Its semantics are accepted exactly as PostgreSQL defines them:

- it is the instant at which the **current transaction started**;
- it stays **stable for the whole transaction**: every call inside the same transaction returns the same value;
- it is **not** the commit time;
- it is **not** a wall-clock reading taken after a lock wait: even when the application asks for it later in the transaction, for example after acquiring its locks, the value is still the transaction start;
- it is **not** the time of the current statement (`statement_timestamp()`) and **not** the current wall clock (`clock_timestamp()`).

Each functional operation uses **one** value of `transaction_timestamp()` for every temporal effect it produces in its transaction. The value may be obtained once and reused; it is not queried again for each fact of the same operation.

### Covered functional timestamps

The baseline applies exactly to:

| Column | Operation | Meaning |
| --- | --- | --- |
| `access_requests.requested_at` | Request creation | The functional instant of the request registration. |
| `decisions.decided_at` | Decision | The functional instant of the Decision. |
| `grant_confirmations.recorded_at` | Grant confirmation | The functional instant of the Grant Confirmation. |
| `revocation_confirmations.recorded_at` | Revocation confirmation | The functional instant of the Revocation Confirmation. |

These are the preserved functional timestamps that StewardArc currently generates during the lifecycle operations. The decision is not extended, without a driver, to any other present or future timestamp.

### Grant validity

In a grant confirmation, the single transaction instant is both `grant_confirmations.recorded_at` and the start of the effective validity period. For a Privileged request:

```text
valid_until_at = recorded_at + requested_duration_seconds
```

using that same value. The validity is not based on `requested_at`, `decided_at`, the moment the request entered `S3`, the PHP clock or a separate temporal SELECT. `RN07` is unchanged, and **approval ≠ grant** is preserved: the validity stays tied to the recorded grant confirmation.

### Read projection alignment

ADR-008 already takes `projection_reference_at` from `transaction_timestamp()` in the read transaction of compound projections. ADR-009 complements it on the write side: once materialized, writers and compound read projections both use the PostgreSQL clock as their functional time source.

ADR-008 is not changed and is not reopened: `REPEATABLE READ READ ONLY`, the single snapshot, `projection_reference_at` and the absence of read locks stay as decided.

### Explicit application assignment

PostgreSQL provides the instant; the application obtains the value and assigns it explicitly to the functional fact it records.

The baseline does not use `DEFAULT now()`, `DEFAULT transaction_timestamp()`, a `BEFORE INSERT` trigger, a generated column or a mandatory stored procedure. The operation stays readable in the Action, and lifecycle semantics are not hidden in the schema.

No generic clock abstraction — `Clock`, `SystemClock`, `DomainClock`, `TimeProvider`, `ClockInterface` or similar — is introduced; there is no driver for one. The later materialization may add a small, PostgreSQL-specific helper that requires an active transaction, obtains `transaction_timestamp()`, converts it to an immutable temporal value and lets the Actions reuse it. Its name, signature, namespace, injection and concrete API are not decided here. What this ADR fixes is the source and its semantics, not the shape of a class.

### Existing data

The baseline applies prospectively, from its materialization onward. Facts already persisted remain exactly as recorded. There is no data migration, backfill, correction script, timestamp normalization or rewrite of historical rows.

### Scope boundaries

The following are outside this baseline:

- **UUIDv7.** Identifiers keep being generated by the application under [ADR-004](0004-initial-data-model-baseline.md). The time component inside a UUIDv7 is not `requested_at`, `decided_at`, `recorded_at` or `projection_reference_at`, must not be used to reconstruct the Functional History and does not need to be synchronized with this baseline. `HasUuids` and identifier generation are unchanged.
- migration filename timestamps;
- log, telemetry and observability timestamps;
- HTTP timestamps;
- framework timestamps, which the schema does not have;
- infrastructure monitoring and CI timestamps.

### Current implementation gap

At the time ADR-009 is published, the read side is already aligned: `ReadProjectionTransaction` takes `projection_reference_at` from `transaction_timestamp()`.

The write side is **not** yet aligned. `CreateAccessRequest`, `DecideAccessRequest`, `ConfirmExternalAccessGrant` and `RecordExternalAccessRevocation` still assign the covered timestamps from the PHP clock. The decision is accepted and documented; its write-side materialization is pending.

### Testing baseline

No test is written by this ADR. When the write side is materialized, tests run against real PostgreSQL, following [ADR-006](0006-application-implementation-baseline.md); SQLite does not provide the approved time source. They should demonstrate at least:

- the four covered production timestamps come from PostgreSQL;
- `Carbon::setTestNow()` and the PHP clock do not change them;
- a single instant is reused within each operation;
- the grant's `recorded_at` and the base of `valid_until_at` are the same instant;
- `projection_reference_at` and the committed facts share the same PostgreSQL source;
- the schema is unchanged;
- historical data is not rewritten.

## Rationale

The reasoning is specific to StewardArc and is not a general claim about clocks.

- A single source removes the implicit dependency between the PHP clock and the database clock, so the derived state no longer relies on their synchronization.
- The database is already the consistency boundary of ADR-001 and the source of `projection_reference_at` in ADR-008; placing functional time there keeps facts and projections on one timeline.
- `transaction_timestamp()` is stable for the whole transaction, so one operation has one instant, and every effect of one functional result shares it, as ADR-003 already requires for atomicity.
- A common source makes the temporal reconstruction required by `RNF-004` coherent across writes and reads.
- Explicit assignment keeps the lifecycle semantics visible in the Actions instead of hiding them in defaults or triggers.
- The schema stays intact: the columns are already `timestamptz`.
- The problem is the source of time, not the need to replace time in tests or across implementations, so no generic clock abstraction is justified.

## Consequences

Benefits:

- Functional time has one source.
- Writers and read projections are aligned on PostgreSQL.
- The derived `A1`/`A2`/`A3` state no longer depends on perfect PHP ↔ PostgreSQL synchronization.
- Each functional operation has one stable instant.
- A grant confirmation reuses exactly the same instant for `recorded_at` and for its validity.

Trade-offs:

- `transaction_timestamp()` represents the start of the transaction, not its commit.
- Any lock wait happens after the functional instant: a transaction that stays blocked for a while still records the instant at which it started, not the instant at which it committed.
- It is not a wall-clock reading taken after the locks are acquired.
- Rows recorded before the materialization keep the values already written from the PHP clock.
- The current Actions still need a later refactor.

These trade-offs are accepted by this baseline and are not resolved here by choosing another temporal function.

## Deferred decisions

The following remain open:

- the concrete PHP helper that obtains the transaction instant;
- its name and API;
- any future policy if a requirement for commit time or for the time of an external event appears;
- a technical timestamp of the external execution of a grant or revocation, should a requirement arise;
- clock concerns across other, distributed systems;
- observability timestamps;
- API representation of instants;
- authentication;
- caching;
- CI and deployment.

The following are **not** reopened by later work: PostgreSQL as the functional time source, `transaction_timestamp()` as the approved transaction instant, the covered timestamps, one instant per operation, explicit assignment by the application and the absence of any historical rewrite.

## Relationship to previous ADRs

- [ADR-003](0003-lifecycle-state-and-persistence-baseline.md): functional facts remain preserved, the Functional History remains reconstructable from them and the lifecycles do not change.
- [ADR-004](0004-initial-data-model-baseline.md): `timestamptz` and the schema stay the same, no migration is created, and UUIDv7 remains application-generated and independent of this decision.
- [ADR-005](0005-concurrency-and-invariant-enforcement-baseline.md): the isolation and locking of mutations do not change; obtaining `transaction_timestamp()` does not change the lock order.
- [ADR-006](0006-application-implementation-baseline.md): Actions keep owning their transactions, and the future reading of the instant happens inside them; the concrete helper is a materialization choice, not a generic architecture.
- [ADR-007](0007-governance-authority-baseline.md): no impact on the Governance authority.
- [ADR-008](0008-read-projection-consistency-baseline.md): `projection_reference_at` already comes from `transaction_timestamp()`; ADR-009 aligns the timestamps produced by the write side with the same PostgreSQL source, and the read transaction semantics remain intact.

ADR-009 complements the previous ADRs and rewrites none of them.

## Out of scope

This ADR does not create:

- the refactor of the Actions;
- a PHP helper;
- the `UC-006` implementation;
- API;
- authentication;
- frontend;
- migrations;
- model changes;
- schema changes;
- database defaults;
- triggers;
- backfill;
- rewriting of existing timestamps;
- event sourcing;
- a scheduler;
- a generic clock abstraction;
- an observability clock.
