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

This baseline does not define language, framework, database, ORM, cloud, authentication protocol, session mechanism, frontend, or whether frontend and backend are deployed separately or together. It does not adopt full DDD, formal Clean Architecture, formal Hexagonal Architecture, CQRS, event sourcing or microservices.

## Known conceptual boundaries

- **Access Governance** is a cohesive core: access requests, decisions, grant confirmation, Granted Access, effective validity, revocation confirmation, expiration and functional history.
- **Catalog** (resources and access profiles) is only a **candidate reference boundary**. It is not a separate service.
- **Authenticated identity** is external to the domain; **authorization** belongs to the product.
- **Target systems**, where grants and revocations actually happen, are outside StewardArc.
- **Operational telemetry** is a concern separate from the functional history.

Internal module organization, packages, namespaces, layers and code directories are not defined yet.

## Deliberately open decisions

The following are intentionally not decided:

- stack, language and framework;
- database and ORM;
- frontend, and separate or joint deployment of frontend and backend;
- concrete authentication and session mechanism;
- concrete source of the Governance authority;
- whether a resource can have more than one Resource Owner;
- whether `S3` is persisted or derived;
- whether `A2` is persisted or derived/materialized;
- mechanism for time-based behavior (expiration);
- concrete atomicity mechanism;
- concrete concurrency and retry strategy;
- API contracts;
- logical and physical data model;
- concrete observability;
- CI.

Each should be decided when a real need arises and recorded as an ADR when it is architecturally significant.
