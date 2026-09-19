# StewardArc

[![CI](https://github.com/rodolforsj/stewardarc-access-governance/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/rodolforsj/stewardarc-access-governance/actions/workflows/ci.yml)

**Govern access requests through accountable decisions and recorded outcomes.**

StewardArc is an engineering case study in **access request governance**. It defines a product that lets an organization record, in a traceable way, who requested which access and why, who authorized it and through which decision path, when the external grant was confirmed, how long the access is valid, whether it was revoked, and the history behind each outcome.

> **Status:** the backend implements the six application slices, OpenID Connect authentication and a first protected product API, `GET /api/me/requests-and-accesses`, all verified by continuous integration. There is no product user interface yet; the current environment is local, for development and demonstration only, and production deployment remains [deliberately open](docs/architecture/overview.md#deliberately-open-decisions).

## The problem

A fictional mid-sized organization receives access requests through decentralized channels. Decisions are taken, but the path from request to outcome is not recorded consistently, so the organization cannot reliably answer basic accountability questions about who asked for what, who decided, and what actually happened afterwards.

## Approval is not a grant

StewardArc is built around one invariant: **approval ≠ grant**.

- An approved request means the required decisions were made. It does not mean access was technically granted.
- The grant happens **outside** StewardArc and is later **confirmed** in the product.
- Only the grant confirmation creates a **Granted Access** and starts its effective validity period.

StewardArc governs the decision and the record of access. It is **not** an IAM platform and it does not provision or revoke access in target systems.

## Technology stack

- **Backend:** PHP 8.5, Laravel 13 and PostgreSQL 18, tested with PHPUnit 12.
- **Authentication:** OpenID Connect with `facile-it/php-openid-client`; Keycloak 26.7.4 only as an optional local identity provider for demonstration.
- **Frontend:** React 19, TypeScript 6 and Vite 8, as a scaffold with no product user interface yet.
- **Environment and CI:** Docker Compose for the local environment and GitHub Actions for continuous integration.

## Technical highlights

- **Traceable from requirements to verified code.** 11 functional requirements, 12 business rules, 11 non-functional requirements, 6 use cases and 22 acceptance criteria are linked in a [traceability matrix](docs/traceability.md), and 12 architecture decision records (ADRs) capture the decisions they drive. The implementation materializes those decisions under automated tests run in CI, and the architecture overview shows the [status of each non-functional requirement](docs/architecture/overview.md#non-functional-requirements-status).
- **Approval ≠ grant, with explicit lifecycles.** An Access Request moves through a persisted lifecycle (`S1`–`S5`); only a confirmed external grant creates a Granted Access, whose state (`A1`–`A3`) is derived from preserved facts, its effective validity and time, with no scheduler.
- **Consistency under concurrency in PostgreSQL.** A transaction-level advisory lock serializes the duplicate rule (`RN03`), row locks protect lifecycle transitions, a unique partial index is the structural second line of defense, and concurrency tests run against real PostgreSQL.
- **OpenID Connect with a server-side session.** The backend is a confidential client using PKCE, identity provider tokens are neither kept nor handed to the browser, and every `/api` route is protected by default and takes the actor from the session only.
- **Correlated, minimized observability.** Each execution carries an opaque identifier and an operation name, an unexpected failure is recorded once with a sanitized condition, and records keep to the allow-list of [ADR-012](docs/architecture/adr/0012-operational-observability-baseline.md).

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
| [Initial data model](docs/architecture/data-model.md) | Initial logical and physical persistence baseline. |
| [ADR-001](docs/architecture/adr/0001-backend-architecture-baseline.md) | Backend architecture baseline. |
| [ADR-002](docs/architecture/adr/0002-technology-stack-baseline.md) | Technology stack baseline. |
| [ADR-003](docs/architecture/adr/0003-lifecycle-state-and-persistence-baseline.md) | Lifecycle state and persistence baseline. |
| [ADR-004](docs/architecture/adr/0004-initial-data-model-baseline.md) | Initial data model baseline. |
| [ADR-005](docs/architecture/adr/0005-concurrency-and-invariant-enforcement-baseline.md) | Concurrency and invariant enforcement baseline. |
| [ADR-006](docs/architecture/adr/0006-application-implementation-baseline.md) | Application implementation baseline. |
| [ADR-007](docs/architecture/adr/0007-governance-authority-baseline.md) | Governance authority baseline. |
| [ADR-008](docs/architecture/adr/0008-read-projection-consistency-baseline.md) | Read projection consistency baseline. |
| [ADR-009](docs/architecture/adr/0009-functional-time-source-baseline.md) | Functional time source baseline. |
| [ADR-010](docs/architecture/adr/0010-application-entry-boundary-baseline.md) | Application entry boundary baseline. |
| [ADR-011](docs/architecture/adr/0011-authentication-baseline.md) | Authentication baseline. |
| [ADR-012](docs/architecture/adr/0012-operational-observability-baseline.md) | Operational observability baseline. |

## Running locally

The local environment runs with Docker Compose only; PHP, Composer and Node do not need to be installed on the host. It contains the backend, the frontend and PostgreSQL, and is intended for local development only.

The containers run as `${UID:-1000}:${GID:-1000}`, so that what they write into the bind-mounted `backend/` and `frontend/` directories belongs to your user. Shells usually do not export `UID` and `GID` to Docker Compose, so on Linux, when your user and group IDs are not `1000:1000`, first write them into a `.env` file at the repository root:

```bash
printf 'UID=%s\nGID=%s\n' "$(id -u)" "$(id -g)" > .env
```

This root `.env` is optional, is read by Docker Compose only, holds nothing but these container settings and is ignored by Git: it is not the application's configuration. The Laravel settings live in `backend/.env`, created below from `backend/.env.example` and ignored by `backend/.gitignore`. With the default IDs, and usually with Docker Desktop on Windows and macOS, the root `.env` is not needed.

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

Both are development servers, the backend in debug mode, so they are published on the host's loopback interface only: they answer at `localhost` on this machine and not from the rest of the network.

Stop the environment with `docker compose down`. Add `-v` to also remove the local database volume. If the optional local identity provider below was started, use `docker compose --profile idp down` instead: a plain `down` leaves it running.

No migrations are run automatically. To create the current schema described in [the data model](docs/architecture/data-model.md), run them manually:

```bash
docker compose exec backend php artisan migrate
```

Six application slices are implemented as Actions: **Request access** (UC-001 / RF-002, with the catalog consultation **Consult access catalog** for RF-001 / CA-001 as a separate read Action), **Decide access request** (UC-002 / RF-004 and RF-005), **Confirm external access grant** (UC-003 / RF-006), **Record external access revocation** (UC-004 / RF-007), **Follow my requests and accesses** (UC-005 / RF-009, RF-010 and RF-011 for the Requester) and **Consult accesses and history within responsibility scope** (UC-006 / RF-010 and RF-011 for the Resource Owner and Governance). The last two are read projections built on the ADR-008 read transaction; the catalog consultation is a plain single-statement read of the profiles currently available for new requests (RN01), with request eligibility (RN03, RN07, RN11, RN12) still checked only when a request is created. Follow my requests and accesses is also served over HTTP (below); the other slices have no endpoint yet and there is no user interface, so the PostgreSQL suite below is how the functional behavior is exercised and validated.

### Protected product API

The first protected product endpoint is `GET /api/me/requests-and-accesses` (UC-005). The `/api` surface is a stateful session API: `backend/routes/api.php` runs on Laravel's `web` middleware group plus `auth`, so every route in it requires the session opened by the OpenID Connect login — protected by default, with no Sanctum and no bearer token. Without a valid session the answer is `401` with `{"message":"Unauthenticated."}`, whatever the `Accept` header, and no CORS policy is emitted, because the browser surface is same-origin. The actor always comes from the session, never from the request.

The response is an explicit, minimal JSON contract: `{"requests": [...]}`, with each of the Requester's own requests, its access profile, resource name, justification, flow, states, Granted Access and Functional History. Instants are in UTC to the second (`2026-09-17T12:34:56Z`), and the collection is not paginated; a Requester without requests gets `{"requests":[]}`. This materializes `RNF-001` for this endpoint only, and `RNF-002` stays partial until deployment brings TLS, `Secure` cookies and the production session store. The [architecture overview](docs/architecture/overview.md#approved-baseline) has the details.

### Local OpenID Connect demonstration (optional)

The login can be tried end to end against a local [Keycloak](https://www.keycloak.org/) 26.7.4 that runs only under the `idp` Compose profile. It exists for development and demonstration only: it does not choose the production identity provider, it is not part of CI, and `docker compose up` without the profile still starts only PostgreSQL, the backend and the frontend. Its realm, [`keycloak/stewardarc-demo-realm.json`](keycloak/stewardarc-demo-realm.json), holds deliberately public, demo-only values — a client secret and passwords — for this disposable local environment; never reuse them anywhere else.

**1. Hosts entry.** The browser and the backend must reach Keycloak under the same issuer, `http://idp.stewardarc.test:8080/realms/stewardarc-demo`. The containers resolve `idp.stewardarc.test` through a Compose network alias, but the machine running the browser needs this line in its hosts file, because `.test` names are not resolved by public DNS:

```text
127.0.0.1 idp.stewardarc.test
```

- **Windows:** add it to `C:\Windows\System32\drivers\etc\hosts`, editing the file with administrator privileges (for example, Notepad run as administrator), and leave any entries added by Docker Desktop as they are.
- **Linux and macOS:** add it to `/etc/hosts`, for example with `sudo`.

Deleting the line undoes the change.

**2. Backend settings.** In `backend/.env`, replace the empty OpenID Connect values with the demo ones; the running backend reloads them. `demo-only-stewardarc-backend-client-secret` is public and only valid for this local demonstration. A `backend/.env` without these keys predates the login and its session settings: copy `backend/.env.example` again and run `php artisan key:generate` before editing it.

```text
OIDC_ISSUER=http://idp.stewardarc.test:8080/realms/stewardarc-demo
OIDC_CLIENT_ID=stewardarc-backend
OIDC_CLIENT_SECRET=demo-only-stewardarc-backend-client-secret
OIDC_REDIRECT_URI=http://localhost:8000/auth/callback
```

**3. Start and provision.**

```bash
docker compose --profile idp up -d --wait
docker compose exec backend php artisan migrate
docker compose exec backend php artisan db:seed --class=LocalOidcDemoSeeder
```

Keycloak is published on `127.0.0.1:8080` only and keeps its data inside its container, with no volume, so it imports the demo realm again whenever that container is recreated. The seeder runs only in the `local` environment with the demo issuer configured, and pre-provisions the Actor Reference of `demo-requester` alone: StewardArc never creates one on login, and nothing Keycloak asserts, roles included, grants any authority.

**4. Sign in.** Open http://localhost:8000/auth/login and sign in as `demo-requester` with the password `demo-only-password`. The login ends on http://localhost:8000/, which shows a 404 page because there is no user interface yet. Then open http://localhost:8000/api/me/requests-and-accesses: it answers 200 with `{"requests":[]}`, since the demonstration creates no requests.

**5. Sign out.** Without a user interface, send the logout from the browser's developer tools, in the console of a `http://localhost:8000` page such as http://localhost:8000/up:

```js
await fetch('/auth/logout', { method: 'POST', redirect: 'manual' });
```

As a same-origin request, it passes the CSRF check without the session cookie ever being copied, and the API answers 401 again. The logout is local only: Keycloak keeps its own session, so going back to `/auth/login` may sign you in without asking for the password; a private window, or closing the browser, shows the form again.

**6. An identity that is not provisioned.** In a new private window, sign in as `demo-unprovisioned` with `demo-only-password`. Keycloak accepts the credentials, but the callback answers `Authentication failed.`, the API stays at 401 and no Actor Reference is created: being authenticated by the identity provider gives nobody access to StewardArc.

**7. Reset and stop.**

- `docker compose --profile idp up -d --force-recreate --wait keycloak` recreates Keycloak with a freshly imported realm.
- `docker compose --profile idp rm --stop --force keycloak` stops and removes only Keycloak.
- `docker compose --profile idp down` stops the whole environment, Keycloak included; once the profile has been started, a plain `docker compose down` leaves Keycloak running. Adding `-v` to `down` also deletes StewardArc's PostgreSQL volume, so leave it out unless that is the intent.

### Application tests

The application tests run against real PostgreSQL, in a dedicated database, and are separate from the scaffold's stock tests:

```bash
docker compose exec postgres createdb -U stewardarc stewardarc_test
docker compose exec -e DB_DATABASE=stewardarc_test backend php artisan migrate --force
docker compose exec backend vendor/bin/phpunit -c phpunit.postgresql.xml
```

They refuse to run unless the driver is PostgreSQL and the database name ends with `_test`.

### Continuous integration

The [CI workflow](.github/workflows/ci.yml) runs on every push and pull request to `main`, and can be started manually. It uses the same Docker Compose environment and steps as above, with ephemeral placeholder credentials and read-only repository permissions, and checks that:

- `GET /up` returns 200;
- the scaffold's stock tests pass (`composer test`);
- the full PostgreSQL suite passes, including its concurrency tests;
- the frontend lints and builds (`npm run lint`, `npm run build`).

It does not deploy, publish or release anything.

## About this project

StewardArc is a portfolio project that treats a small, well-bounded product as a complete software engineering case study: requirements, behavior, traceability and architecture come before the implementation and drive it. Technical decisions are recorded as ADRs only when there is a real driver for them. The detailed architectural narrative is in the [architecture overview](docs/architecture/overview.md).

## License

This repository is public for viewing and evaluation. No open-source license is granted.
