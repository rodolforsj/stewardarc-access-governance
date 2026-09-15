# ADR-001: Backend architecture baseline

- **Status:** Accepted
- **Decision date:** 2026-09-14

## Context

StewardArc governs access requests from submission to recorded outcome. Its MVP scope is controlled: three actors (Requester, Resource Owner, Governance), a Standard and a Privileged approval flow, the Access Request lifecycle (`S1`–`S5`) and the Granted Access lifecycle (`A1`–`A3`). See [the architecture overview](../overview.md).

The domain requires strong coherence between:

- decisions and the state transitions they cause;
- grant confirmation and the creation of the Granted Access, including the start of its effective validity period;
- these facts and the functional history of the request.

These facts must not become inconsistent under failures (`RNF-008`) or under concurrent or repeated operations, including duplicate prevention (`RN03`, `RNF-009`).

There are currently no drivers, such as independent scaling, independent deployment or separate team ownership, that would justify distributing the domain.

## Decision

Adopt a **modular monolith with a shared consistency boundary for the Access Governance domain** as the backend architecture baseline.

## Consequences

- Favors consistency across decisions, state transitions, grant confirmation, Granted Access and functional history, and supports incremental evolution.
- Internal modules can be defined later, when there is enough understanding to draw them.
- Does not determine the stack: language, framework, database, ORM, cloud, authentication and deployment remain open.
- Does not prevent future evolution if real drivers emerge.
- Does not imply full DDD, formal Clean Architecture, formal Hexagonal Architecture, CQRS or event sourcing.
- Does not imply microservices.
- Does not turn the Catalog into an independent service; it remains a candidate reference boundary.
