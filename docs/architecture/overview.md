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

The concurrency and invariant enforcement baseline is recorded in [ADR-005](adr/0005-concurrency-and-invariant-enforcement-baseline.md): PostgreSQL stays at `READ COMMITTED`; commands that change an existing Access Request or Granted Access take a pessimistic row lock on it; and `RN03` is serialized by a transaction-level PostgreSQL advisory lock, deterministic per requester and access profile, taken by request creation, grant confirmation and revocation confirmation, with the invariant re-checked inside it. The unique partial index remains as a structural second line of defense. Request creation materializes this advisory lock, the decision slice materializes the pessimistic row lock on the Access Request, and grant confirmation materializes both in the approved order — advisory lock first, then the row lock — so that the grant confirmation, the Granted Access and the move to `S4` are one atomic result. The revocation confirmation mechanism comes with its own slice.

The application implementation baseline is recorded in [ADR-006](adr/0006-application-implementation-baseline.md): Eloquent models stay in `App\Models`, future functional operations live in `App\AccessGovernance\Actions` and PostgreSQL-specific concurrency mechanisms in `App\AccessGovernance\Concurrency`; each critical Action owns its own transaction; the advisory lock key is a namespaced SHA-256 derivation passed to `pg_advisory_xact_lock(integer, integer)`; locks are acquired advisory first, then row; and database-dependent tests run against real PostgreSQL. The slices implemented under these namespaces are Request access (UC-001 / RF-002), Decide access request (UC-002 / RF-004 and RF-005) and Confirm external access grant (UC-003 / RF-006).

The governance authority baseline is recorded in [ADR-007](adr/0007-governance-authority-baseline.md): the current Governance authority is a minimal association, `governance_memberships`, whose single column `actor_reference_id` is at once primary key and foreign key to `actor_references`. Resource Owner authority keeps coming from the resource itself, the two authorities may overlap in the same actor, and `RN04` still forbids deciding one's own request. The association is materialized — the executable schema now has nine domain tables — and it is already the source of Governance authority used by the decision slice.

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
- detailed API contracts;
- future schema evolution beyond the approved persistence baselines, including performance indexes beyond those motivated by integrity;
- concrete observability;
- test coverage policy, CI execution and provisioning of PostgreSQL for tests, and test organization beyond the baseline (which already distinguishes pure unit tests from PostgreSQL integration and concurrency tests; see ADR-006);
- CI.

Each should be decided when a real need arises and recorded as an ADR when it is architecturally significant.
