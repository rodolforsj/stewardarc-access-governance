# ADR-010 — Application Entry Boundary Baseline

- **Status:** Accepted
- **Date:** 2026-09-16

## Context

The functional slices of the MVP are implemented as Actions in `App\AccessGovernance\Actions` ([ADR-006](0006-application-implementation-baseline.md)). They receive an `actor_reference_id` that is already resolved by the calling layer, own their transactions, apply the business rules `RN01`–`RN12` and derive authorization from the product's own data: the Resource Owner of the resource ([ADR-004](0004-initial-data-model-baseline.md)), the Governance membership ([ADR-007](0007-governance-authority-baseline.md)) and the requester of each Access Request. The compound read projections of `UC-005` and `UC-006` follow [ADR-008](0008-read-projection-consistency-baseline.md).

No calling layer exists yet. The backend exposes no product route, controller or API; the only HTTP endpoint is the framework health check, `/up`. No authentication middleware, session or token mechanism and no identity provider has been adopted.

The drivers for deciding the entry boundary already exist:

- [ADR-002](0002-technology-stack-baseline.md) selected REST/JSON as the contract style between the web client and the backend;
- `RNF-001` requires every protected functionality to have a valid authenticated identity and to enforce the authorization and scope rules, without executing the operation or exposing protected content otherwise;
- `RNF-002` requires the artifacts that keep the authenticated context, and the communications of protected functionality, to be protected;
- the functional baseline keeps the authenticated identity external to the domain while authorization belongs to the product;
- `actor_references.external_identity_key` already exists as an opaque, stable and unique key intended for the future mapping from the authenticated identity to an Actor Reference.

These drivers are enough to define where the entry boundary sits and what it is responsible for. They are not enough to choose a concrete authentication mechanism, identity provider or HTTP contract. This ADR closes only the former.

## Decision

### HTTP is an entry adapter

The future REST/JSON interface of StewardArc is an entry and transport layer. It:

- receives HTTP requests;
- establishes or requires the authenticated context;
- validates the shape of the transport input;
- resolves the external identity when the operation needs the current actor;
- translates the HTTP input into the arguments of the operation;
- calls the existing Actions and projections;
- translates results and known failures into HTTP.

It does not:

- reimplement the business rules `RN01`–`RN12`;
- reimplement the lifecycles;
- duplicate the invariants enforced by the Actions;
- create a second domain layer;
- change the transactional responsibility of the critical Actions.

The Actions remain the boundary of the functional operations, and the authenticated identity remains external to the domain.

### External identity to Actor Reference

The entry boundary obtains, from the future authentication mechanism, a trusted authenticated external identifier. That identifier is resolved to an Actor Reference through `actor_references.external_identity_key`:

```text
authenticated external identity
  → Actor Reference (by external_identity_key)
  → actor_reference_id
  → Action or projection
```

`external_identity_key` stays opaque to the domain. This baseline does not define its format, the issuer, the subject, any specific claim, an issuer-plus-subject composition, the use of e-mail as identity, the identity provider or the authentication protocol.

An authenticated external identity that cannot be resolved unambiguously to one Actor Reference fails closed for the product's functionality. No Actor Reference is created automatically on first authentication; provisioning and synchronization of Actor References are outside this decision.

### The current actor never comes from the client

For every operation performed by, or on behalf of, the authenticated actor, the HTTP client cannot choose or supply an `actor_reference_id` that replaces the authenticated identity. This covers, among others:

- the Requester of `UC-001`;
- the actor who decides in `UC-002`;
- the actor who records the grant confirmation in `UC-003`;
- the actor who records the revocation confirmation in `UC-004`;
- the Requester of `UC-005`;
- the consulting actor of `UC-006`.

The current actor is always the Actor Reference resolved from the authenticated identity. An `actor_reference_id` sent in a route, query string or body is never passed to an Action or projection as the current actor.

Identifiers of functional resources — such as an access profile, an access request or a granted access — may be part of the route or the body, as the future HTTP contract defines. That does not include the identity of the current actor.

### Authentication is not authorization

The entry boundary may reject a request that lacks authentication. That does not replace the product's authorization.

Authorization keeps being derived and enforced by the existing operations from the product's data:

- `resources.resource_owner_actor_reference_id`;
- `governance_memberships`;
- the requester of the Access Request;
- the rules `RN04`, `RN09` and `RN11`;
- the scopes of the `UC-005` and `UC-006` projections.

These rules are not moved into middleware, and no generic role carried by a token or session replaces the current authority sources. Authentication proves who the actor is; StewardArc keeps deciding what that actor may do or consult.

### Read projections

The `UC-005` and `UC-006` projections keep receiving the resolved Actor Reference. The client does not state its own scope, does not declare that it is a Resource Owner or a Governance member, and does not choose the resources that make up its authorization. Those scopes keep being derived from the current data inside the projection.

ADR-008 remains fully valid. HTTP serialization happens only after the projection is fully materialized and its `REPEATABLE READ READ ONLY` transaction has ended. No response streaming or lazy database access extends beyond that transaction.

### Access catalog

The catalog (`RF-001`) is product functionality, not an operational endpoint. In the MVP, its future HTTP surface belongs to the authenticated product surface.

`ConsultAccessCatalog` may keep taking no actor parameter, and no actor-specific authorization is introduced for it. Requiring an authenticated identity at the entry does not make the consultation actor-dependent: an authenticated entry is not actor-specific catalog filtering, and no personalized catalog filtering is created.

### Protected by default

Future business routes of StewardArc are protected by default. Any public or operational exception must be explicit.

The existing health endpoint, `/up`, is operational infrastructure and stays outside the protected functional surface of this baseline. It is not changed, and no dependency health, readiness or liveness check is defined here.

### Failing closed

The future entry boundary fails closed when:

- a protected functionality has no valid authenticated identity;
- the authenticated identity cannot be resolved to an Actor Reference;
- the resolution is ambiguous;
- the operation rejects the actor for its scope or authority.

Conceptually, the boundary distinguishes these failure categories:

- authentication failure;
- identity resolution failure;
- authorization or business-rule failure;
- malformed transport input;
- unexpected internal failure.

Exact HTTP status codes, error codes, the JSON error envelope, external messages and any transport exception hierarchy belong to the future HTTP contract and are not fixed here.

### Routing baseline

REST/JSON is already the approved contract style. This baseline sets only the minimum:

- the future product API uses Laravel's HTTP infrastructure;
- business routes live under an `/api` surface;
- functional responses from that surface are JSON.

No versioning is introduced — in particular, `/api/v1` is not decided — and no concrete endpoint name or path is chosen.

### Actions keep their responsibilities

ADR-006 is preserved:

- critical Actions keep controlling their own transaction;
- future controllers do not open transactions around Actions;
- future controllers do not read models to reproduce invariants before calling an Action;
- locks, `FunctionalTransactionTime` and `ReadProjectionTransaction` stay internal to their operations.

Purely transport validation may happen before the Action. A functional rule that already belongs to an Action stays in the Action.

### Minimal output

The HTTP boundary does not serialize Eloquent models indiscriminately. The future output contract must be explicit and minimal (`RNF-005`).

In particular, `actor_references.external_identity_key` is internal resolution data and is not exposed in product responses unless a future explicit functional decision requires it; no such driver exists today. The same applies to internal relations and columns that are not part of the functional contract.

No DTO framework, serializer package or concrete resource class is chosen here.

### Materialization status

This ADR is an architectural decision, not an implementation. `RNF-001` and `RNF-002` are not materialized by it: there is still no authentication, no identity resolution and no product API.

## Rationale

The reasoning is specific to StewardArc.

- The Actions already hold the rules, the transactions and the authorization, and they already expect a resolved actor. Making HTTP a thin adapter reuses them as they are and avoids a second place where invariants could diverge.
- Deriving the current actor only from the authenticated identity is what makes `RN02`, `RN04`, `RN09`, `RN11` and the consultation scopes meaningful; a client-supplied actor would let any caller impersonate anyone.
- `external_identity_key` was introduced precisely to keep the authenticated identity outside the domain. Resolving through it keeps the identity provider replaceable without touching the domain.
- The product's authority sources — the resource's owner, the Governance membership and the request's requester — are current, queryable data. Copying them into roles inside a token or session would create a second, possibly stale, source of authorization.
- Failing closed on missing or ambiguous identity is the only behavior compatible with `RNF-001`.
- Protecting routes by default makes a forgotten exception visible instead of silently public.
- Fixing only the `/api` surface and JSON responses follows from ADR-002 without anticipating a contract that has no driver yet.
- A minimal, explicit output contract follows `RNF-005` and prevents the internal identity key from leaking by accident.

## Consequences

Benefits:

- A client cannot impersonate another actor by sending an actor identifier.
- The future authentication mechanism stays decoupled from the domain.
- The current authorization sources remain canonical.
- The Actions remain reusable outside HTTP.
- The transport does not duplicate invariants.
- The external identity key does not leak by default.
- The choice of identity provider remains replaceable.

Trade-offs:

- Every protected request that needs the current actor requires an identity resolution.
- A valid authentication does not guarantee that a provisioned Actor Reference exists.
- The authentication configuration still needs a later decision.
- Concrete HTTP contracts still have to be defined.
- Identity mapping failures become a relevant operational failure category.

## Deferred decisions

The following remain open:

- identity provider;
- OAuth, OIDC, JWT or bearer tokens;
- cookies and Laravel session;
- Sanctum, Passport or any other authentication package;
- stateful or stateless authentication;
- CSRF strategy;
- CORS policy;
- refresh tokens;
- token and session lifetime;
- login and logout flows;
- credential storage;
- MFA;
- claim names;
- the concrete format of `external_identity_key`;
- provisioning of Actor References;
- provisioning of Governance memberships;
- detailed HTTP status mapping;
- error envelope;
- individual endpoint paths;
- payload schemas;
- pagination;
- sorting and presentation ordering;
- API versioning;
- frontend integration;
- deployment topology;
- TLS termination;
- reverse proxy;
- rate limiting;
- observability and correlation;
- transport idempotency keys.

The following are **not** reopened by later work: HTTP as an entry adapter over the existing Actions, the resolution of the current actor from the authenticated identity through `external_identity_key`, the prohibition of a client-supplied current actor, the separation between authentication and product authorization, failing closed on missing or unresolvable identity, protected-by-default business routes under an `/api` surface with JSON responses, `/up` as operational infrastructure outside that surface, and the explicit, minimal output contract that does not expose `external_identity_key`.

## Relationship to previous ADRs

- [ADR-001](0001-backend-architecture-baseline.md): the entry boundary is an adapter inside the same modular monolith; it creates no new service or domain boundary.
- [ADR-002](0002-technology-stack-baseline.md): REST/JSON over Laravel is the contract style this baseline starts from; authentication, session and detailed contracts, deferred there, remain deferred.
- [ADR-003](0003-lifecycle-state-and-persistence-baseline.md) and [ADR-004](0004-initial-data-model-baseline.md): lifecycles, preserved facts and the schema, including `external_identity_key`, are unchanged.
- [ADR-005](0005-concurrency-and-invariant-enforcement-baseline.md) and [ADR-006](0006-application-implementation-baseline.md): isolation, locking and Action-owned transactions are unchanged; controllers do not wrap Actions in transactions.
- [ADR-007](0007-governance-authority-baseline.md): Governance authority keeps coming from `governance_memberships`, as its authentication boundary section anticipated.
- [ADR-008](0008-read-projection-consistency-baseline.md): projections stay fully materialized inside their read transaction before any serialization.
- [ADR-009](0009-functional-time-source-baseline.md): functional time keeps coming from PostgreSQL; the transport adds no functional timestamp.

ADR-010 complements the previous ADRs and rewrites none of them.

## Out of scope

This ADR does not create:

- routes, including `routes/api.php`;
- controllers;
- middleware;
- form requests;
- resources or DTOs;
- an identity resolver;
- authentication guards or providers;
- `config/auth.php`;
- authentication packages;
- session, cookie, CSRF or CORS configuration;
- login or logout;
- endpoints;
- an exception renderer or error envelope;
- observability or request identifiers;
- changes to the schema, models or Actions;
- tests;
- CI changes;
- frontend changes.
