# ADR-011 — Authentication Baseline

- **Status:** Accepted
- **Date:** 2026-09-16

## Context

[ADR-010](0010-application-entry-boundary-baseline.md) defined the application entry boundary: a future REST/JSON surface under `/api`, protected by default, that resolves the authenticated external identity to an Actor Reference through `actor_references.external_identity_key`, never takes the current actor from the client and fails closed. It deliberately left the authentication mechanism open.

`RNF-001` requires a valid authenticated identity for every protected functionality, and `RNF-002` requires the artifacts that keep the authenticated context, and the communications of protected functionality, to be protected. Neither is materialized: there is no authentication, no identity resolution and no product API.

The drivers for choosing the authentication baseline already exist:

- StewardArc is not an identity provider and does not manage authenticated identities ([product](../../product.md)); the authenticated identity is provided externally ([requirements](../../requirements.md)).
- Authorization belongs to the product. The Resource Owner comes from `resources.resource_owner_actor_reference_id`, Governance from `governance_memberships` ([ADR-007](0007-governance-authority-baseline.md)), and the requester and the consultation scopes are derived by the Actions and projections.
- The client is a first-party React application and the backend is the Laravel application that serves the API ([ADR-002](0002-technology-stack-baseline.md)). No public API, mobile application or machine-to-machine client has a driver.
- The web client and the API are accepted to be presented to the browser under one logical origin in the MVP, as recorded below.
- The MVP must remain reproducible by third parties and in CI without personal credentials, real secrets or an external identity provider.

The drivers do not yet justify an identity provider, a library, a session store, a physical deployment or the exact encoding of `external_identity_key`. This ADR closes only the authentication baseline.

## Decision

### MVP browser surface

The StewardArc web client and API are presented to the browser under the same logical origin, behind a common edge. Conceptually:

```text
https://stewardarc.example/...      web client
https://stewardarc.example/api/...  product API
```

This is a constraint on the MVP browser surface, not a physical topology. The static frontend and the backend may remain distinct components or processes, and the reverse proxy, CDN, ingress, load balancer, hostname, domain and platform stay open. The local Docker Compose environment stays local-only and is not changed: locally, the frontend and the backend may keep running on different ports, and any development proxy is an implementation detail.

A cross-site architecture is not the baseline of the MVP.

### Federated authentication with OpenID Connect

Authentication is federated through **OpenID Connect**. StewardArc does not store user credentials. The Laravel backend acts as the OpenID Connect Relying Party and is a confidential client.

No identity provider is chosen.

### Authorization Code Flow with PKCE

The baseline flow is the OpenID Connect **Authorization Code Flow with PKCE**, protected by `state` and `nonce`. PKCE is used even though the backend is a confidential client, as recommended by the OAuth 2.0 Security Best Current Practice.

The ID Token is processed by the backend to establish the authenticated identity. Its validation follows OpenID Connect, including at least the issuer, the audience, the expiry and issue time, and the `nonce`.

The method used by the client to authenticate to the token endpoint — client secret, `private_key_jwt` or another supported method — is not fixed here.

### No bearer tokens between the web client and the API

OpenID Connect authenticates the user. It is not the credential between the first-party web client and the StewardArc API.

After authentication:

- the backend keeps the authenticated context in an application session;
- the browser reaches `/api`, on the same origin, with the session cookie;
- the web client does not receive an access token from the identity provider to call `/api`;
- the web client stores neither access tokens nor refresh tokens;
- `localStorage` and `sessionStorage` are not part of the authentication baseline.

If the token endpoint returns an access token during the OpenID Connect flow, it is neither promoted to a credential of the web client nor persisted without a new driver. No refresh token is part of this baseline. Tokens issued by the identity provider are not domain facts.

This follows the single-domain pattern described in RFC 10017, in which a browser frontend and a server-side API on a common domain use OpenID Connect for federated authentication and keep the user's authentication state in a server-side cookie-based session, without OAuth access tokens between them. Keeping tokens out of JavaScript matches the security properties discussed there for the Backend for Frontend pattern. StewardArc does not, however, introduce a Backend for Frontend layer or proxy: the Laravel backend is the application and its API, and there is no external resource server that it calls with access tokens on behalf of the web client.

### External identity

The canonical external identity established by OpenID Connect is the pair **(`iss`, `sub`)**:

- `iss` identifies the issuer;
- `sub` is stable and unique within that issuer, and is not globally sufficient on its own;
- e-mail is not the canonical identity;
- display name is not an identity;
- a username or login is not assumed to be stable.

StewardArc resolves that identity as follows:

```text
(iss, sub)
  → actor_references.external_identity_key
  → actor_reference_id
  → Action or projection
```

`external_identity_key` represents the pair (`iss`, `sub`) deterministically and unambiguously, preserving both the issuer and the subject. Its physical encoding — separator, JSON, URI, hash, escaping or textual canonicalization — is not defined here and is left to the materialization.

ADR-010 applies unchanged: resolution produces exactly one Actor Reference or fails closed; a valid authentication does not imply a valid Actor Reference; no Actor Reference is created on first login; and an `actor_reference_id` from the client is never the current actor.

### Actor Reference provisioning

For the MVP demonstration, Actor References may be pre-provisioned as demonstration data, with keys that correspond to the external identities used in the demonstration. The concrete mechanism — seeder, fixture or otherwise — the `iss` and `sub` values and the accounts of any identity provider are not chosen here.

### Authentication is not authorization

After the identity is resolved:

- the Requester is the authenticated Actor Reference;
- the Resource Owner keeps coming from `resources.resource_owner_actor_reference_id`;
- Governance keeps coming from `governance_memberships`;
- `UC-005` derives its own scope and `UC-006` derives the responsibility scope;
- `RN04`, `RN09`, `RN11` and the other rules stay in the product's operations.

Claims issued by the identity provider are not functional authority. Claims such as a role, a Resource Owner flag, external groups or OAuth scopes do not replace the canonical sources of StewardArc. External groups may, if a driver appears, become an input to provisioning, but never a runtime authorization check in this baseline.

### Application session

After a successful OpenID Connect login, the backend:

- establishes the application session, regenerating its identifier;
- keeps only the minimal context needed;
- associates the session with the resolved external identity and Actor Reference.

Subsequent API calls use that session. No identity declared in a body, query string or header replaces it.

The application session is not Functional History, and its data are not functional facts.

The session driver, any Redis, database or file store, sticky sessions, a distributed store, the exact lifetime and the absolute and idle timeouts are not defined here; they depend on the materialization and the deployment.

### Session cookie

In any non-local deployment:

- HTTPS/TLS is mandatory;
- the session cookie is `Secure`;
- the session cookie is `HttpOnly`;
- a host-only cookie is preferred, consistent with the same-origin surface;
- the session identifier is never exposed to JavaScript.

`SameSite` is set restrictively and must remain compatible with the OpenID Connect redirect and callback, which return to the application through a top-level navigation from the identity provider. The concrete value is defined and validated in the implementation; the current framework default is not adopted merely because it is the default.

The local environment is not required to use TLS in order to exercise this baseline.

### CSRF

Cookie-based authentication requires CSRF protection. Every authenticated operation that changes state has an effective CSRF defense. That defense belongs to the HTTP and framework layer, does not replace functional authorization, and its concrete Laravel mechanism is chosen in the implementation.

### CORS

Because the MVP browser surface is same-origin, CORS is not a requirement of the MVP production surface, and no cross-origin CORS policy is part of this baseline. Local development may later use a proxy or a development-specific configuration.

### Session lifecycle

- A successful authentication regenerates the session.
- A local logout invalidates the authenticated session.
- Authenticated material from before an invalidation is no longer usable after it.

RP-initiated logout, back-channel logout, front-channel logout and global logout at the identity provider are deferred.

### Tokens without a driver

The application does not call external APIs on behalf of the user in the MVP. There is therefore no driver for:

- refresh tokens;
- persisting access tokens;
- delivering access tokens to the web client;
- token exchange;
- DPoP;
- an OAuth authorization server of StewardArc's own;
- personal access tokens.

### Laravel integration

Concrete Laravel integration mechanisms and packages remain implementation decisions. No package is adopted by this ADR. In particular, Laravel Sanctum is not an identity provider, is not a source of identity and is not this baseline; it could eventually help with session or API plumbing, but there is no driver to choose it now.

### Failing closed

A protected operation does not execute when:

- there is no valid authenticated session;
- the OpenID Connect authentication is invalid;
- the external identity cannot be derived;
- no Actor Reference corresponds to the identity;
- the resolution is inconsistent;
- the Action or projection rejects the actor for its scope or authority.

HTTP status codes and the error envelope are not decided here.

### Catalog and health check

ADR-010 applies unchanged: the access catalog (`RF-001`) belongs to the authenticated product surface, authentication creates no personalized catalog filtering, and `/up` stays an operational endpoint outside the authenticated surface.

### Testability

The baseline must be verifiable without depending on an external identity provider:

- the normal test suite can exercise an authenticated context without personal credentials;
- actor resolution can be exercised with synthetic identities;
- the OpenID Connect flow can be tested with fake HTTP responses and local, ephemeral cryptographic material;
- CI depends neither on Internet access nor on a real identity provider account;
- personal secrets never enter the repository or GitHub.

No mocking or faking library is chosen, and no identity provider is added to the local environment by this ADR.

### Relationship to RNF-001 and RNF-002

For `RNF-001`, this ADR decides the baseline for the authenticated identity, the protected surface, actor resolution, failing closed, protection against actor spoofing and the continuity of the existing authorization rules.

For `RNF-002`, it decides that identity provider tokens stay out of JavaScript, that the backend controls the session, that the session cookie is protected, that TLS is mandatory outside the local environment, that CSRF protection is mandatory, that authentication artifacts are minimized and that no refresh token is kept without a driver.

This ADR materializes neither requirement. Authentication, the entry plumbing, the identity resolver, the routes, the concrete configuration and the HTTP tests are still required.

### References

- RFC 10017, *OAuth 2.0 for Browser-Based Applications* (BCP 212, August 2026): Section 7.1 on single-domain browser-based applications that use OpenID Connect with a server-side cookie session instead of OAuth between frontend and backend; Section 6.1.3.2 on cookie security; Section 6.1.3.3 on CSRF protections.
- RFC 9700, *Best Current Practice for OAuth 2.0 Security* (BCP 240): Section 2.1.1 on the authorization code grant and on PKCE, required for public clients and recommended for confidential clients.
- OpenID Connect Core 1.0: Section 2 on the ID Token and the `sub` claim, unique within the issuer; Section 3.1 on the Authorization Code Flow; Section 3.1.3.7 on ID Token validation; Section 5.7 on the stability and uniqueness of claims.

## Rationale

The reasoning is specific to StewardArc.

- Federating authentication keeps StewardArc out of credential management, as the product scope requires, and OpenID Connect is the standard way for a web application to obtain an authenticated identity from an external provider.
- The Authorization Code Flow with PKCE, `state` and `nonce`, run by a confidential backend, is the current recommended flow and keeps the authorization code and the tokens away from the browser.
- A server-side application session with a protected cookie is the simplest way for a first-party, same-origin web client to call its own API: it keeps tokens out of JavaScript, which `RNF-002` favors, and avoids token storage, refresh logic and CORS in the web client.
- Presenting the web client and the API under one logical origin is what makes that session simple and safe; a cross-site surface would require either relaxed cookies or bearer tokens in the browser, and there is no driver for it.
- `sub` is unique only within its issuer, and e-mail, names and usernames can change or be reassigned; the pair (`iss`, `sub`) is the stable identity that `external_identity_key` was introduced to hold.
- Keeping claims out of authorization preserves the canonical sources decided in ADR-004 and ADR-007, keeps authority changes immediately effective and keeps the domain independent of the identity provider.
- Requiring no refresh token, no persisted access token and no package now avoids solving problems the MVP does not have, while leaving the provider and the integration replaceable.
- A test strategy based on synthetic identities and local cryptographic material keeps the baseline verifiable by anyone and in CI.

## Consequences

Benefits:

- StewardArc stores no user credentials and remains independent of any specific identity provider.
- Identity provider tokens are never exposed to the web client.
- The web client needs no OpenID Connect or token-handling logic.
- CORS is unnecessary on the production surface of the MVP.
- The external identity is stable and unambiguous, and the existing authorization sources stay canonical.
- Authentication can be tested deterministically, without external services or personal secrets.

Trade-offs:

- An external OpenID Connect provider is required to log in, including in any end-to-end demonstration.
- The backend becomes stateful for authentication and needs a session store suitable for its deployment.
- CSRF protection becomes mandatory for state-changing operations.
- The same-origin surface constrains future deployment; a cross-site client or a public API would require a new decision.
- Demonstration identities must be pre-provisioned with keys that match the provider's (`iss`, `sub`).
- Local development needs a proxy or a development-specific configuration to reach the API from the frontend.
- The session and the identity resolution add work to every protected request.

## Deferred decisions

The following remain open:

- the identity provider, for production and for demonstration;
- the Laravel library or package used for OpenID Connect and for sessions;
- the client authentication method at the token endpoint;
- the session store, lifetime and absolute and idle timeouts;
- the concrete `SameSite` value and cookie name;
- the exact encoding of `external_identity_key`;
- the concrete provisioning mechanism and the demonstration identities;
- whether a complete local login is demonstrated, and with which provider;
- the local development proxy or configuration;
- the login, callback and logout endpoints and the other endpoint details;
- the error envelope and HTTP status mapping;
- the physical deployment, reverse proxy and TLS termination;
- observability and correlation;
- rate limiting;
- RP-initiated, back-channel, front-channel and global logout;
- MFA and step-up authentication;
- SCIM or any other synchronization of identities or groups;
- HTTP idempotency.

The following are **not** reopened by later work: OpenID Connect as the federated authentication protocol, the backend as a confidential Relying Party, the Authorization Code Flow with PKCE, `state` and `nonce`, the server-side application session with a protected cookie as the credential between the web client and the API, the absence of bearer tokens and of token storage in the web client, the same-origin logical browser surface of the MVP, (`iss`, `sub`) as the canonical external identity represented by `external_identity_key`, provider claims never being functional authority, mandatory CSRF protection and TLS outside the local environment, and a test strategy that does not depend on an external provider.

## Relationship to previous ADRs

- [ADR-001](0001-backend-architecture-baseline.md): authentication stays inside the same modular monolith; no identity service is introduced.
- [ADR-002](0002-technology-stack-baseline.md): the Laravel backend and the React client are kept; ADR-011 settles the authentication mechanism and session policy that ADR-002 deferred, at the architectural level only.
- [ADR-003](0003-lifecycle-state-and-persistence-baseline.md): sessions and tokens are not functional facts and do not feed the Functional History.
- [ADR-004](0004-initial-data-model-baseline.md): `external_identity_key` keeps its role and constraints; the schema is not changed.
- [ADR-005](0005-concurrency-and-invariant-enforcement-baseline.md) and [ADR-006](0006-application-implementation-baseline.md): isolation, locking, Action-owned transactions and the testing baseline are unchanged; authentication tests follow the same no-external-dependency principle.
- [ADR-007](0007-governance-authority-baseline.md): Governance authority keeps coming from `governance_memberships`, never from provider claims.
- [ADR-008](0008-read-projection-consistency-baseline.md): projections keep receiving the resolved Actor Reference and deriving their scopes from the data.
- [ADR-009](0009-functional-time-source-baseline.md): authentication and session instants are not functional timestamps.
- [ADR-010](0010-application-entry-boundary-baseline.md): ADR-011 chooses the authentication baseline that the entry boundary requires, and keeps its identity resolution, fail-closed behavior, protected `/api` surface and `/up` exception unchanged.

ADR-011 complements the previous ADRs and rewrites none of them.

## Out of scope

This ADR does not create:

- authentication, login, callback or logout;
- an identity provider integration or a local identity provider;
- packages, including Sanctum, Passport, Socialite or any OpenID Connect or JWT library;
- a `User` model or a `users` table;
- changes to `actor_references` or to the schema;
- a session table, a session store or changes to the session configuration;
- guards, middleware, routes or controllers;
- CSRF or CORS configuration;
- seeders or demonstration identities;
- secrets or environment changes;
- changes to the local environment;
- frontend changes;
- tests;
- CI changes.
