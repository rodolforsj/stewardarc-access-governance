# ADR-007 — Governance Authority Baseline

- **Status:** Accepted
- **Date:** 2026-09-16

## Context

The MVP has three actors: Requester, Resource Owner and Governance. The Resource Owner authority already has a concrete source in the schema: `resources.resource_owner_actor_reference_id` says, for each resource, who is responsible for it. The Governance authority had no concrete source; [ADR-004](0004-initial-data-model-baseline.md), [ADR-005](0005-concurrency-and-invariant-enforcement-baseline.md) and [ADR-006](0006-application-implementation-baseline.md) all deliberately deferred it.

The next slices create a real driver for that decision: `UC-002` needs to know who may decide, `RF-004` needs to list what is pending for an actor, and `RF-005` needs to check the authority of the step being decided. None of them can be implemented while "who is Governance" is undefined.

Authorization belongs to the product, while authentication remains external and undecided. A concrete source of Governance authority is therefore required before pending approvals and decisions can be implemented.

## Decision

### Governance membership

The current Governance authority is represented by a minimal persisted association, `governance_memberships`. The presence of a row for an Actor Reference means that actor currently holds Governance authority in StewardArc; the absence of a row means it does not.

The baseline does not use a boolean `is_governance` on `actor_references`, a `role` column, a PostgreSQL enum of roles, a generic `roles` or `actor_roles` table, generic RBAC, a permission engine or a policy engine. There is a driver for exactly one explicit Governance association, and that is what is modeled.

### Persistence representation

The table has exactly one column:

| Column | Type | Null | Constraint / meaning |
| --- | --- | --- | --- |
| `actor_reference_id` | `uuid` | NOT NULL | PRIMARY KEY and FK → `actor_references.id`, restrictive (`RESTRICT`/`NO ACTION`). |

There is no `id` column, no UUID of its own, no sequence or serial, no timestamps, no `created_at` or `updated_at`, no `is_active`, `role`, `type`, `source`, `justification`, `expiry` or metadata, no soft delete and no historical-membership columns.

[ADR-004](0004-initial-data-model-baseline.md) requires application-generated UUIDv7 identifiers for entities and facts that have an identity of their own. A Governance Membership is not a new entity or functional fact: it is a current authorization association, `Actor Reference 0..1 → Governance Membership`, and the foreign key already identifies it uniquely. No new UUIDv7 is generated for this table. ADR-007 evolves only the relational model needed for the Governance authority; it does not revoke the ADR-004 policy for anything with its own identity.

### Authority overlap

The MVP allows zero or more Governance members at the same time. The same Actor Reference may hold a Governance membership and also be the Resource Owner of one or more resources: the two authorities are not mutually exclusive, and no constraint prevents the overlap.

### Pending approval authority

For `RF-004`, when it is implemented:

- an actor acts as **Resource Owner** when `resources.resource_owner_actor_reference_id` equals their Actor Reference; their pending items are requests in `S1` whose Access Profile belongs to a resource under their responsibility;
- an actor acts as **Governance** when a row exists in `governance_memberships` for their Actor Reference; their pending items are requests in `S2`, which by the approved lifecycle is the Governance step of the Privileged flow.

Using `approval_flow = 'privileged'` as a coherence guard in that future query is acceptable, but it creates no new functional rule: a request validly in `S2` already belongs to the Privileged flow.

When the same actor holds both authorities, the future query may return the union of what belongs to each one: their `S1` items as Resource Owner and the `S2` items as Governance. That does not authorize deciding outside the current step.

### Decision authority

For `RF-005`, when it is implemented:

- for a request in `S1`, the required authority is the Resource Owner of the resource associated with the request's Access Profile, and only that actor may record the `resource_owner` stage decision;
- for a request in `S2`, the required authority is any Actor Reference that currently has a row in `governance_memberships`, and only such an actor may record the `governance` stage decision.

This ADR creates no decision authority for any other state. The future operation follows the lifecycle and `RN09`.

The same association is the single source that recognizes an actor as Governance wherever the functional baseline refers to the Governance actor, including future queries within the applicable scope. That does not implement `RF-010`, `RF-011` or `UC-006`; it only prevents the architecture from having two different sources of Governance.

### Self-decision

Holding an authority does not remove `RN04`. An actor cannot decide their own Access Request: being the correct Resource Owner does not authorize it, and holding a Governance membership does not authorize it either. The authority check and the `RN04` check are independent conditions and both are necessary.

### Authentication boundary

StewardArc still has no authentication mechanism. A future calling layer resolves the authenticated identity into an Actor Reference, and the Actions work with an `actor_reference_id` that is already resolved. From there, functional authorization belongs to the product: the Resource Owner authority derives from the resource, and the Governance authority derives from `governance_memberships`.

No password, token, provider session, credential or authentication claim is stored in `governance_memberships`. The `external_identity_key` on `actor_references` remains only the opaque reference already approved.

### Provisioning and lifecycle

A membership may be added or removed as the actor's current authority changes. In the MVP this maintenance happens outside the product UI, as provisioning of controlled reference data.

This ADR chooses no administrative endpoint, administrative screen, definitive CLI command, definitive seeder, identity provider integration, SCIM, LDAP or SSO group synchronization.

### Historical decisions

The membership represents **current** authority. Removing a Governance membership removes that actor's prospective Governance authority, but it does not change any Decision already recorded, does not erase the `actor_reference_id` of a past Decision and does not rewrite the Functional History. Decisions remain preserved functional facts under ADR-003 and ADR-004, and the authorship and time of each Decision stay in the fact itself.

This baseline does not require reconstructing, from `governance_memberships`, whether a given actor was a Governance member at some past date. If an explicit requirement to prove the historical validity of a membership ever appears, it will need a new decision and new modeling. No `valid_from`, `valid_until`, membership history, governance membership events or audit table is invented now.

## Rationale

The reasoning is specific to StewardArc and is not a general solution for authorization.

- A boolean `is_governance` on `actor_references` would mix an identity reference with an authorization statement, in a table that was deliberately kept free of authentication and authorization concerns.
- Generic RBAC, a roles table or a policy engine would be abstraction without a driver: the product has exactly one authority that needs an explicit source.
- An explicit association table represents that single requirement directly and can be read with a trivial query.
- Using the foreign key as the primary key avoids inventing an artificial identity for a `0..1` association that the foreign key already identifies.
- Allowing multiple members without forbidding the overlap with Resource Owner matches the approved rules, which never state that the roles are exclusive.
- Keeping authentication separate preserves the boundary already approved: identity is external, authorization belongs to the product.
- Because Decisions preserve their own actor and time, a later change to the membership cannot distort what was decided in the past.

## Consequences

Benefits:

- Governance gains an explicit, queryable source.
- `RF-004` and `RF-005` can be implemented without inventing role semantics.
- Multiple simultaneous Governance members are supported.
- Resource Owner and Governance authorities can coexist in the same actor.
- No authentication data is stored.
- Recorded Decisions remain independent of the current state of the membership.

Trade-offs:

- The domain model gains a ninth table.
- Authority management still depends on provisioning outside the product UI.
- The membership expresses only current authority.
- The temporal history of a membership is not modeled.
- A future identity or group integration may require adaptation.
- Authorization keeps depending on queries against the application database.

## Deferred decisions

The following remain open:

- authentication mechanism;
- session;
- identity provider;
- the concrete mapping from an authenticated identity to an Actor Reference;
- the operational way to provision and remove Governance memberships;
- any administrative UI or API for it;
- any integration with external groups;
- a temporal history of memberships, should a requirement appear;
- API contracts;
- HTTP status and error mapping;
- transport idempotency;
- retry;
- observability;
- CI and deployment.

The following are **not** reopened: the `governance_memberships` table, `actor_reference_id` as both primary key and foreign key, the absence of an identifier of its own, support for multiple members, the overlap between Resource Owner and Governance, the membership as the source of current authority, and the applicability of `RN04` even to valid authorities.

## Relationship to previous ADRs

- [ADR-001](0001-backend-architecture-baseline.md): authorization remains inside the product and its consistency boundary.
- [ADR-002](0002-technology-stack-baseline.md): persistence continues in PostgreSQL with Eloquent.
- [ADR-003](0003-lifecycle-state-and-persistence-baseline.md): Decisions remain preserved functional facts.
- [ADR-004](0004-initial-data-model-baseline.md): defined the original eight-table model, which ADR-007 now evolves; ADR-004 itself stays as the historical record and is not rewritten.
- [ADR-005](0005-concurrency-and-invariant-enforcement-baseline.md) and [ADR-006](0006-application-implementation-baseline.md): the concurrency and application baselines are unchanged.

ADR-007 closes only the concrete source of the Governance authority.

The documented target model becomes nine domain tables, while the executable schema at this checkpoint still has eight: the migration and the Eloquent model for `governance_memberships` do not exist yet.

## Out of scope

This ADR does not create:

- a migration;
- an Eloquent model;
- seeders;
- an administrative UI;
- an administrative API;
- authentication;
- session;
- SSO;
- SCIM;
- LDAP;
- the Decision Action;
- the pending approval Action or query;
- the `UC-002` implementation;
- frontend;
- membership history.
