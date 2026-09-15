# StewardArc

**Govern access requests through accountable decisions and recorded outcomes.**

StewardArc is an engineering case study in **access request governance**. It defines a product that lets an organization record, in a traceable way, who requested which access and why, who authorized it and through which decision path, when the external grant was confirmed, how long the access is valid, whether it was revoked, and the history behind each outcome.

> **Status:** documentation baseline and executable project scaffold established. No product functionality has been implemented yet.

## The problem

A fictional mid-sized organization receives access requests through decentralized channels. Decisions are taken, but the path from request to outcome is not recorded consistently, so the organization cannot reliably answer basic accountability questions about who asked for what, who decided, and what actually happened afterwards.

## Approval is not a grant

StewardArc is built around one invariant: **approval ≠ grant**.

- An approved request means the required decisions were made. It does not mean access was technically granted.
- The grant happens **outside** StewardArc and is later **confirmed** in the product.
- Only the grant confirmation creates a **Granted Access** and starts its effective validity period.

StewardArc governs the decision and the record of access. It is **not** an IAM platform and it does not provision or revoke access in target systems.

## Scope at a glance

- A catalog of access profiles, each belonging to exactly one resource.
- Access requests made by users for themselves.
- Standard and Privileged approval flows, with decisions taken by the right authority, in sequence, without self-approval.
- Justified rejections.
- Recording, by the Resource Owner, of the confirmation of external grants, creating Granted Access records.
- Recording, by the Resource Owner, of the confirmation of external revocations.
- End of temporary access in governance when its validity end is reached.
- Queries over requests, accesses and request history, restricted to each actor's scope.

## Actors

| Actor | Role in the MVP |
| --- | --- |
| Requester | Requests access for themselves and consults their own requests, Granted Accesses and the corresponding functional history. |
| Resource Owner | For the resources under their responsibility: decides requests, records the confirmation of external grants and of external revocations, and consults the related accesses and request history. |
| Governance | Takes the additional decision step for Privileged profiles and consults accesses and request history for Privileged profiles. |

## Documentation

| Document | Purpose |
| --- | --- |
| [Product](docs/product.md) | Context, problem, objective, MVP scope and boundaries. |
| [Requirements](docs/requirements.md) | Functional requirements, business rules and non-functional requirements. |
| [Behavior](docs/behavior.md) | Lifecycles, states, flows, use cases and acceptance criteria. |
| [Traceability](docs/traceability.md) | Links from needs to requirements, rules, use cases, criteria and NFRs. |
| [Architecture overview](docs/architecture/overview.md) | Drivers, constraints, approved baseline and open decisions. |
| [Domain model](docs/architecture/domain-model.md) | Conceptual domain model. |
| [ADR-001](docs/architecture/adr/0001-backend-architecture-baseline.md) | Backend architecture baseline. |
| [ADR-002](docs/architecture/adr/0002-technology-stack-baseline.md) | Technology stack baseline. |

## Running locally

The local environment runs with Docker Compose only; PHP, Composer and Node do not need to be installed on the host. It contains the backend, the frontend and PostgreSQL, and is intended for local development only.

```bash
cp backend/.env.example backend/.env
docker compose build
docker compose run --rm --no-deps backend composer install
docker compose run --rm --no-deps backend php artisan key:generate
docker compose run --rm --no-deps frontend npm ci
docker compose up -d
```

- Backend health check: http://localhost:8000/up
- Frontend: http://localhost:5173

Stop the environment with `docker compose down`. Add `-v` to also remove the local database volume.

No migrations are run automatically, and no domain schema exists yet.

## About this project

StewardArc is a portfolio project that treats a small, well-bounded product as a complete software engineering case study: problem framing, requirements, behavior, traceability and architecture come before implementation.

Technical decisions are made deliberately and recorded when there is a real driver for them. So far, the backend architecture baseline ([ADR-001](docs/architecture/adr/0001-backend-architecture-baseline.md)) and the technology stack baseline ([ADR-002](docs/architecture/adr/0002-technology-stack-baseline.md)) have been decided. Concrete versions were later materialized by the executable scaffold through Docker images and lockfiles; the policy for updating them remains open. Authentication, consistency mechanisms, API contracts and deployment also remain open on purpose; see [open decisions](docs/architecture/overview.md#deliberately-open-decisions).
