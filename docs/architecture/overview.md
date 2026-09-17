# Architecture overview

This document records the architectural drivers, constraints and approved baseline of StewardArc, and the decisions that remain deliberately open. The conceptual domain is described in [domain-model.md](domain-model.md).

## Architectural drivers

- **Consistency across the lifecycle.** A decision, the resulting state transition, the grant confirmation, the creation of the Granted Access and the functional history must remain coherent with each other.
- **Failure consistency.** A failure must not leave these facts inconsistent (`RNF-008`).
- **Concurrency and duplicate prevention.** Concurrent or repeated operations must not produce inconsistent states or duplicates (`RN03`, `RNF-009`).
- **Authority and sequence.** Authorization belongs to the product and must enforce who can decide and when (`RN04`, `RN09`, `RN11`, `RNF-001`).
- **Functional history integrity and attribution** (`RNF-003`, `RNF-004`).
- **Controlled MVP scope.** Three actors, two request flows, two lifecycles.

## Constraints

- **Approval ≠ grant.** StewardArc records decisions and confirmations; it does not provision or revoke access in target systems.
- **Identity is external.** The authenticated identity is provided outside the domain.
- **Accessibility.** User-facing interfaces meet the applicable WCAG 2.2 A and AA criteria (`RNF-007`).
- **Data minimization** for functional data and operational information (`RNF-005`, `RNF-006`).

## Guardrails

- Do not split the Access Governance domain across boundaries that would break its consistency.
- Do not treat operational telemetry as functional history, or the reverse.
- Do not introduce performance targets without a real driver.
- Do not select technology by convention; record technical decisions when a driver exists.
- Do not describe components, modules or infrastructure before they are decided.

## Approved baseline

The backend architecture baseline is a **modular monolith with a shared consistency boundary for the Access Governance domain**. See [ADR-001](adr/0001-backend-architecture-baseline.md).

This architecture baseline does not adopt full DDD, formal Clean Architecture, formal Hexagonal Architecture, CQRS, event sourcing or microservices.

The technology stack baseline (PHP + Laravel with Eloquent, PostgreSQL, React + TypeScript + Vite, REST/JSON and Docker Compose for the local environment) is recorded in [ADR-002](adr/0002-technology-stack-baseline.md), which complements ADR-001 without changing it and deliberately did not fix versions.

The executable scaffold later materialized concrete versions through Docker images and lockfiles ([compose.yaml](../../compose.yaml), [backend/Dockerfile](../../backend/Dockerfile), [backend/composer.lock](../../backend/composer.lock), [frontend/package-lock.json](../../frontend/package-lock.json)). These versions form the current executable baseline; those files remain the technical source for them.

The lifecycle state and persistence baseline is recorded in [ADR-003](adr/0003-lifecycle-state-and-persistence-baseline.md): the current Access Request state (`S1`–`S5`, including `S3`) is persisted; the Granted Access state (`A1`–`A3`), including expiration (`A2`), is determined from preserved functional facts, the effective validity period and time; and a functional fact and the related change of the Access Request current state are made in the same PostgreSQL transaction.

The initial data model baseline is recorded in [ADR-004](adr/0004-initial-data-model-baseline.md) and detailed in [data-model.md](data-model.md): eight tables covering the catalog, access requests and preserved functional facts, application-generated UUIDv7 identifiers, controlled values as text with CHECK constraints, restrictive foreign keys, no persisted state column for Granted Access and no table for Functional History.

The concurrency and invariant enforcement baseline is recorded in [ADR-005](adr/0005-concurrency-and-invariant-enforcement-baseline.md): mutations run at PostgreSQL `READ COMMITTED`; commands that change an existing Access Request or Granted Access take a pessimistic row lock on it; and `RN03` is serialized by a transaction-level PostgreSQL advisory lock, deterministic per requester and access profile, taken by request creation, grant confirmation and revocation confirmation, with the invariant re-checked inside it. The unique partial index remains as a structural second line of defense. Request creation materializes this advisory lock; decisions materialize the pessimistic row lock on the Access Request; grant confirmation materializes the advisory lock followed by the Access Request row lock; and revocation confirmation materializes the advisory lock followed by the Granted Access row lock. The three operations that take part in the `RN03` serialization — request creation, grant confirmation and revocation confirmation — are therefore materialized in the order decided by ADR-005 and ADR-006.

The application implementation baseline is recorded in [ADR-006](adr/0006-application-implementation-baseline.md): Eloquent models stay in `App\Models`, future functional operations live in `App\AccessGovernance\Actions` and PostgreSQL-specific concurrency mechanisms in `App\AccessGovernance\Concurrency`; each critical Action owns its own transaction; the advisory lock key is a namespaced SHA-256 derivation passed to `pg_advisory_xact_lock(integer, integer)`; locks are acquired advisory first, then row; and database-dependent tests run against real PostgreSQL. The slices implemented under these namespaces are Request access (UC-001 / RF-002, with the catalog consultation of RF-001 / CA-001 in `ConsultAccessCatalog`, a plain single-statement read of the profiles currently available for new requests, outside ADR-008 because it has no compound projection or temporal derivation), Decide access request (UC-002 / RF-004 and RF-005), Confirm external access grant (UC-003 / RF-006), Record external access revocation (UC-004 / RF-007), Follow my requests and accesses (UC-005 / RF-009, RF-010 and RF-011 in the Requester scope) and Consult accesses and history within responsibility scope (UC-006 / RF-010 and RF-011 in the Resource Owner and Governance scopes).

The governance authority baseline is recorded in [ADR-007](adr/0007-governance-authority-baseline.md): the current Governance authority is a minimal association, `governance_memberships`, whose single column `actor_reference_id` is at once primary key and foreign key to `actor_references`. Resource Owner authority keeps coming from the resource itself, the two authorities may overlap in the same actor, and `RN04` still forbids deciding one's own request. The association is materialized — the executable schema now has nine domain tables — and it is already the source of Governance authority used by the decision slice.

The read projection consistency baseline is recorded in [ADR-008](adr/0008-read-projection-consistency-baseline.md): the compound functional read projections of `UC-005` and `UC-006` run in one short PostgreSQL `REPEATABLE READ READ ONLY` transaction, so each projection — including its authorization scope reads — observes a single snapshot, and every temporal derivation in it uses one `projection_reference_at`, taken from `transaction_timestamp()`. Projections take no row or advisory locks and are fully materialized before their transaction ends. Functional History remains a projection over the preserved facts, with expiration as a derived milestone without an actor. Isolation is therefore scoped: mutations keep `READ COMMITTED` with the locking of ADR-005 and ADR-006, while compound read projections follow ADR-008; the global default is unchanged. `ReadProjectionTransaction`, in `App\AccessGovernance\Concurrency`, materializes this read transaction: it refuses to join an outer transaction, sets `REPEATABLE READ, READ ONLY` before any other query and takes `projection_reference_at` from `transaction_timestamp()`. `FollowMyRequestsAndAccesses` (`UC-005`) is the first projection built on it: it reads the Requester's own requests, Granted Accesses and Functional History in that one snapshot, derives `A1`/`A2`/`A3` against that single instant, takes no row or advisory lock and returns a fully materialized result. `ConsultAccessesAndHistoryWithinResponsibilityScope` (`UC-006`) is built on the same transaction: inside that snapshot it reads the actor's current Governance membership and returns the union, without duplicates, of the requests for resources the actor currently owns and, for Governance members, the requests whose `approval_flow` snapshot is `privileged`, in any state, with the same Granted Access derivation and Functional History as `UC-005` and each requester identified by id and display name.

The functional time source baseline is recorded in [ADR-009](adr/0009-functional-time-source-baseline.md): PostgreSQL is the canonical source of functional time. The preserved functional timestamps generated by the lifecycle operations — `requested_at`, `decided_at` and the `recorded_at` of grant and revocation confirmations — take one `transaction_timestamp()` value per operation (the stable start instant of its transaction, not its commit time), assigned explicitly by the application, without database defaults or triggers; a grant confirmation reuses that instant as the start of the effective validity. Both sides are now materialized. `FunctionalTransactionTime`, in `App\AccessGovernance\Concurrency`, reads `transaction_timestamp()` from the transaction already owned by the calling Action and refuses to run outside one; `CreateAccessRequest`, `DecideAccessRequest`, `ConfirmExternalAccessGrant` and `RecordExternalAccessRevocation` read it once per operation. `CreateAccessRequest` also evaluates the active-access half of `RN03` against that same instant, and `ConfirmExternalAccessGrant` uses it for both `recorded_at` and the validity end. On the read side, ADR-008 already takes `projection_reference_at` from the same function, so ADR-008 and ADR-009 are materialized for reads and writes alike, including both read projections (`UC-005` and `UC-006`).

A minimal continuous integration baseline automates the executable validations that already exist, following the testing baseline of ADR-006. The GitHub Actions workflow [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) runs on pushes and pull requests to `main` and on demand, with read-only repository permissions and no secrets. It reuses the local Docker Compose environment: it builds the backend, installs the locked dependencies, starts PostgreSQL and the backend, creates and migrates the dedicated `stewardarc_test` database, checks that `/up` returns 200, runs the scaffold's stock tests and the full PostgreSQL suite — including its concurrency tests — and lints and builds the frontend. It does not deploy, publish or release anything.

## Known conceptual boundaries

- **Access Governance** is a cohesive core: access requests, decisions, grant confirmation, Granted Access, effective validity, revocation confirmation, expiration and functional history.
- **Catalog** (resources and access profiles) is only a **candidate reference boundary**. It is not a separate service.
- **Authenticated identity** is external to the domain; **authorization** belongs to the product.
- **Target systems**, where grants and revocations actually happen, are outside StewardArc.
- **Operational telemetry** is a concern separate from the functional history.

The minimum implementation baseline defines only three code locations — `App\Models`, `App\AccessGovernance\Actions` and `App\AccessGovernance\Concurrency` (see [ADR-006](adr/0006-application-implementation-baseline.md)). Further module organization, packages, namespaces and layers remain open until a real need appears.

## Deliberately open decisions

The following are intentionally not decided:

- version update and maintenance policy for the stack;
- deployment, including separate or joint deployment of frontend and backend, and cloud or hosting;
- concrete authentication and session mechanism;
- the operational mechanism for provisioning and removing Governance memberships, and any temporal history of them (the authority source itself is decided in ADR-007);
- whether a resource can have more than one Resource Owner beyond the MVP, which models exactly one (see ADR-004);
- any future proactive or operational time-based processing (`A2` is already derived from the effective validity period and time; see ADR-003);
- retry policy, including deadlock retry, and transport idempotency keys (the concurrency baseline itself is decided in ADR-005);
- custom lock timeout and wait tuning (the baseline uses blocking advisory acquisition; see ADR-006);
- detailed API contracts, including pagination and the presentation order of projections;
- the representation of the read projections in a future HTTP/API contract (their isolation is decided in ADR-008, and `ReadProjectionTransaction` and the internal projection values are materialized);
- caching and materialized views;
- future schema evolution beyond the approved persistence baselines, including performance indexes beyond those motivated by integrity;
- concrete observability;
- test coverage policy and thresholds, and test organization beyond the baseline (which already distinguishes pure unit tests from PostgreSQL integration and concurrency tests; see ADR-006);
- CI beyond the minimal baseline, such as branch protection, required checks, build matrices, caching, dependency auditing, repeated concurrency runs and any release or deployment pipeline.

Each should be decided when a real need arises and recorded as an ADR when it is architecturally significant.
