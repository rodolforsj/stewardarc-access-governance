# ADR-002 — Technology Stack Baseline

- **Status:** Accepted
- **Date:** 2026-09-15

## Context

[ADR-001](0001-backend-architecture-baseline.md) defined the backend architecture baseline as a modular monolith with a shared consistency boundary for the Access Governance domain. It deliberately left the technology stack open.

Before the application can be scaffolded, the project needs a concrete stack. The choice must be:

- proportional to the MVP scope: three actors, two approval flows and two lifecycles;
- coherent with the architectural drivers, in particular consistency across decisions, state transitions, grant confirmation, Granted Access and functional history, under failures and concurrency (`RNF-008`, `RNF-009`);
- useful as an engineering case study, making domain rules, authorization, transactions, consistency, APIs and tests visible.

The stack must not anticipate decisions that still depend on modeling and implementation. See the [architecture overview](../overview.md).

## Decision

Adopt the following technology stack baseline:

| Element | Choice | Role |
| --- | --- | --- |
| Backend | PHP + Laravel | Implements the modular monolith: domain rules, authorization, transactions and the application API. |
| Application persistence | Eloquent | Default persistence mechanism of the application. |
| Relational database | PostgreSQL | Central relational database for the MVP core. |
| Frontend | React + TypeScript + Vite | Web client application. |
| Application contract | REST/JSON | Communication style between the web client and the backend. |
| Local environment | Docker Compose | Reproducible local execution of the application, the database and only the auxiliary services that prove necessary. |

## Rationale

The rationale is specific to StewardArc; it is not a general comparison between technologies.

- **PHP + Laravel** is proportional to the scope and suitable for demonstrating domain rules, authorization, transactions, consistency, APIs and tests inside the approved modular monolith, without introducing artificial architectural complexity.
- **PostgreSQL** fits the highly relational StewardArc domain and supports demonstrating integrity, constraints, concurrency and consistency at the database level.
- **Eloquent** provides the default persistence mechanism within the Laravel backend. Its role is bounded, as described below.
- **React + TypeScript + Vite** allows a modern, typed web client without shifting the project's focus to visual complexity.
- **REST/JSON** is a straightforward communication style for a web client consuming the backend.
- **Docker Compose** allows the application and its database to be run locally in a reproducible way.

## Persistence and consistency boundary

- Eloquent is the default persistence mechanism of the application.
- Critical invariants do not depend exclusively on the ORM. Duplicate prevention (`RN03`), lifecycle integrity, consistency under failures (`RNF-008`) and consistency under concurrency and repetition (`RNF-009`) may require a combination of domain rules, transactions, PostgreSQL constraints and concrete concurrency or idempotency mechanisms.
- The concrete atomicity, concurrency and idempotency mechanisms are not chosen by this ADR and will be decided later.

## Consequences

Positive:

- The implementation can use a modern full-stack ecosystem without changing the architecture baseline of ADR-001.
- Backend and frontend remain conceptually separable.
- The MVP core has a central relational database.
- The future environment can be reproduced locally through Docker Compose.

Trade-offs:

- The repository will contain more technologies, which requires discipline so that the frontend does not dominate the scope.
- Later decisions on security, consistency, observability and deployment are still required; this stack does not settle them.

## Deferred decisions

The following are not decided by this ADR:

- concrete authentication mechanism;
- session policy and mechanism;
- concrete source of the Governance authority;
- whether `S3` is persisted or derived;
- whether `A2` is persisted, derived or materialized;
- time-based mechanism for expiration;
- concrete atomicity mechanism;
- concrete concurrency strategy;
- retry and idempotency;
- observability, logging, metrics and tracing;
- detailed API contracts;
- testing strategy;
- CI;
- deployment;
- cloud and hosting;
- concrete versions of the stack.

## Relationship to ADR-001

ADR-002 complements [ADR-001](0001-backend-architecture-baseline.md). ADR-001 addresses the architectural form of the backend; ADR-002 defines the base technologies that will materialize it.

ADR-002 does not replace or change the decision for a modular monolith with a shared consistency boundary for the Access Governance domain.

## Version policy

This ADR selects products and technologies, not versions. Concrete versions of PHP, Laravel, PostgreSQL, React, TypeScript, Vite and any other tools will be chosen at the scaffold checkpoint, based on the versions supported at that time.

## Out of scope

This ADR does not define:

- authentication;
- session;
- endpoints;
- physical schema;
- definitive logical data model;
- concrete locking strategy;
- idempotency keys;
- queues;
- cache;
- observability;
- CI/CD;
- hosting or cloud;
- deployment strategy.
