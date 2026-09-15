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

## Known conceptual boundaries

- **Access Governance** is a cohesive core: access requests, decisions, grant confirmation, Granted Access, effective validity, revocation confirmation, expiration and functional history.
- **Catalog** (resources and access profiles) is only a **candidate reference boundary**. It is not a separate service.
- **Authenticated identity** is external to the domain; **authorization** belongs to the product.
- **Target systems**, where grants and revocations actually happen, are outside StewardArc.
- **Operational telemetry** is a concern separate from the functional history.

Internal module organization, packages, namespaces, layers and code directories are not defined yet.

## Deliberately open decisions

The following are intentionally not decided:

- version update and maintenance policy for the stack;
- deployment, including separate or joint deployment of frontend and backend, and cloud or hosting;
- concrete authentication and session mechanism;
- concrete source of the Governance authority;
- whether a resource can have more than one Resource Owner;
- any future proactive or operational time-based processing (`A2` is already derived from the effective validity period and time; see ADR-003);
- concrete isolation level, locking, concurrency, retry and idempotency strategy (the conceptual transaction boundary is decided in ADR-003);
- detailed API contracts;
- logical and physical data model;
- concrete observability;
- testing strategy;
- CI.

Each should be decided when a real need arises and recorded as an ADR when it is architecturally significant.
