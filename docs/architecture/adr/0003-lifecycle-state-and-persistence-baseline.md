# ADR-003 — Lifecycle State and Persistence Baseline

- **Status:** Accepted
- **Date:** 2026-09-15

## Context

[ADR-001](0001-backend-architecture-baseline.md) established the backend as a modular monolith with a shared consistency boundary for the Access Governance domain. [ADR-002](0002-technology-stack-baseline.md) selected PostgreSQL as the relational database and Eloquent as the default persistence mechanism.

The functional specifications define two distinct lifecycles ([behavior](../../behavior.md)):

- the Access Request lifecycle, `S1`–`S5`;
- the Granted Access lifecycle, `A1`–`A3`, which starts only when a grant is confirmed.

**Approval ≠ grant** remains a central invariant: an approved request waits in `S3`, and a Granted Access exists only after grant confirmation.

It was still undecided which states are persisted and which can be derived, in particular `S3` and `A2`, and what the unit of atomicity is for operations that record facts and change state. The decision is shaped by:

- `RNF-003` — functional history integrity;
- `RNF-004` — attribution and temporal coherence;
- `RNF-008` — consistency under failures;
- `RNF-009` — consistency under concurrency and repetition.

## Decision

### Access Request current state

The current state of each Access Request is persisted explicitly, for every state:

| State | Name |
| --- | --- |
| `S1` | Awaiting Resource Owner approval |
| `S2` | Awaiting Governance approval |
| `S3` | Awaiting grant |
| `S4` | Grant confirmed |
| `S5` | Rejected |

`S3` is therefore persisted, not derived. The persisted current state serves queries and the control of lifecycle transitions.

### Preserved functional facts

Decisions (including rejection justifications), Grant Confirmations and Revocation Confirmations are preserved functional facts. The persisted current state does not replace them, and they are not mutable attributes of the Access Request.

Once recorded, these facts keep their authorship and temporal reference (`RNF-004`) and are not edited or deleted by the normal functional operations of the MVP (`RNF-003`). No administrative correction mechanism is defined.

### Granted Access lifecycle

The Granted Access state is determined from the preserved facts, the effective validity period and time. `A2` does not depend on a transition persisted by a job or scheduler merely to record that time has passed.

- **`A1` — Active:** the access was granted; no revocation confirmation was recorded while it was active; and, when a calculable validity end exists, that end has not been reached.
- **`A2` — Ended by expiration:** a calculable validity end exists and has been reached, without the access having already ended by a revocation confirmed while it was active.
- **`A3` — Ended by confirmed revocation:** an external revocation was confirmed in StewardArc while the access was still in `A1`.

Consequently:

- a Revocation Confirmation recorded while the access is in `A1` results in `A3`;
- a Revocation Confirmation recorded after the access has already reached `A2` by expiration leaves it in `A2`; the confirmation remains preserved as a historical fact;
- an access that moved `A1 → A3` remains ended by revocation; the later passage of time does not turn `A3` into `A2`.

This ADR defines no physical field or database enumeration for the Granted Access state. Whether an auxiliary technical representation of the calculated state will be used in the future for query optimization is not decided.

### Expiration

Expiration is a consequence of the calculable end of the Effective Validity Period and the passage of time. In this baseline it requires neither a persisted expiration event nor a scheduler that changes records solely to produce `A2`. The product must be able to determine expiration from the validity information.

Expiration is not external revocation.

This does not prevent operational mechanisms from being introduced later if a real requirement emerges; none is chosen now.

### Functional History

Functional History is a capability, a projection built over the preserved functional facts, which include, as applicable:

- the functional registration of the request;
- Decisions;
- rejection justifications;
- the Grant Confirmation;
- Revocation Confirmations;
- other functional facts defined by the functional baselines.

When the history presents expiration, that milestone can be derived from the Effective Validity Period and time. Expiration is not turned into a persisted fact merely to feed the history.

Functional History is not a generic technical audit log, not operational telemetry, not event sourcing, and not a generic event table chosen in advance.

### Transaction boundary

When a functional operation records a functional fact and changes the current state of the Access Request as part of the same functional result, both changes are made in the same PostgreSQL transaction. Conceptual examples:

- recording a Decision and changing the corresponding current state;
- recording a Grant Confirmation, concluding the request in `S4` and creating the Granted Access.

This follows from `RNF-008` and from the shared consistency boundary of ADR-001. It does not choose an isolation level, locking approach, concurrency mechanism, retry policy or idempotency mechanism.

## Rationale

The decision is deliberately asymmetric, and specific to StewardArc rather than a general architectural rule:

- `S1`–`S5` change only in response to explicit functional operations, so persisting the current state follows those operations directly.
- Persisting `S3` makes transition control and queries straightforward, instead of inferring "approved but not yet granted" from the combination of other facts.
- `A2` arises from the passage of time over a calculable validity period. Deriving it avoids introducing a scheduler or job only to synchronize something that time already determines.
- Preserved functional facts keep decisions and confirmations traceable without requiring event sourcing.

## Consequences

Positive:

- Commands and queries on Access Requests have an explicit current state.
- No job is needed solely to turn `A1` into `A2`.
- Traceability rests on preserved facts, consistent with `RNF-003` and `RNF-004`.
- The unit of atomicity for a fact and its state change is explicit.

Trade-offs:

- Queries on Granted Access must take time and the effective validity period into account to determine the state correctly.
- Functional History must combine persisted facts with, when applicable, derived temporal information.
- Critical operations must respect the transaction boundary.
- Complex queries over large numbers of expired accesses may eventually require optimization; no solution is anticipated now.

## Deferred decisions

The following remain open:

- physical schema;
- table and column names;
- key and identifier types;
- technical representation of the `RN07` duration;
- concrete indexes and constraints;
- concrete database implementation of `RN03`;
- whether a resource can have more than one Resource Owner;
- authentication and session mechanism;
- concrete source of the Governance authority;
- isolation level;
- optimistic or pessimistic locking;
- concrete concurrency mechanism;
- retry;
- idempotency;
- any proactive or operational time-related mechanism, should one ever be needed;
- detailed API contracts;
- observability;
- CI;
- deployment.

## Relationship to previous ADRs

- [ADR-001](0001-backend-architecture-baseline.md) defines the architectural form and the shared consistency boundary.
- [ADR-002](0002-technology-stack-baseline.md) defines the stack and selects PostgreSQL and Eloquent.
- ADR-003 resolves questions that ADR-002 deliberately left open: whether `S3` and `A2` are persisted or derived, and the conceptual unit of atomicity. The concrete concurrency, retry and idempotency mechanisms remain open.

ADR-003 complements the previous ADRs and does not rewrite them.

## Out of scope

This ADR does not define:

- migrations;
- Eloquent models;
- physical schema;
- API endpoints;
- controllers;
- repositories;
- services;
- aggregates;
- authentication;
- concrete authorization;
- locking;
- isolation;
- retries;
- idempotency keys;
- scheduler;
- queue;
- cron;
- observability;
- CI or deployment.
