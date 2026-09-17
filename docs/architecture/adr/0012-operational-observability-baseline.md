# ADR-012 — Operational Observability Baseline

- **Status:** Accepted
- **Date:** 2026-09-17

## Context

StewardArc now has the surfaces where operational failures actually happen. [ADR-010](0010-application-entry-boundary-baseline.md) and [ADR-011](0011-authentication-baseline.md) are materialized for the OpenID Connect login (`/auth/login`, `/auth/callback`, `/auth/logout`) and for the first protected product endpoint, `GET /api/me/requests-and-accesses`. The four critical Actions — `CreateAccessRequest`, `DecideAccessRequest`, `ConfirmExternalAccessGrant` and `RecordExternalAccessRevocation` — own their transactions ([ADR-006](0006-application-implementation-baseline.md)) and are invoked directly, today by the test suites and tomorrow also by HTTP. The compound read projections run inside their own read transaction ([ADR-008](0008-read-projection-consistency-baseline.md)).

The application currently records nothing operational. `config/logging.php` is the framework scaffold, and no application code calls the logger, the exception reporter or the framework's log context: the OpenID Connect controller deliberately answers every failure with the same generic response and writes nothing. The only operational records that exist are the ones the framework writes by itself when an exception escapes.

The drivers are specific and already stated in [requirements.md](../../requirements.md):

- `RNF-006` requires operational records and telemetry to contain no credentials, authentication secrets or tokens, and no personal data beyond what diagnosis, security and operational correlation need;
- `RNF-010` requires an unexpected failure in protected functionality or in a critical operation to produce enough operational information to identify the affected functionality or operation, the time of occurrence and the failure condition, without violating `RNF-005` or `RNF-006`;
- `RNF-011` requires the records of one execution to be groupable unequivocally, without credentials, secrets or unnecessary personal content, and to stay distinguishable from simultaneous or later executions.

These three rules cross the HTTP entry adapter, the OpenID Connect login, the critical Actions, exception reporting and every future entry point. They cannot be settled inside one functional slice, and they are not a generic wish for "good practice": each one is a stated requirement with a stated verification. [ADR-006](0006-application-implementation-baseline.md), [ADR-008](0008-read-projection-consistency-baseline.md), [ADR-009](0009-functional-time-source-baseline.md), [ADR-010](0010-application-entry-boundary-baseline.md) and [ADR-011](0011-authentication-baseline.md) each left observability and correlation deliberately open, waiting for exactly this driver. Without a decision now, the first log line written by the first slice that needs one would settle invisibly what an execution is, what identifies it and what a record may contain.

The behavior of the stack that is already materialized is part of the context, because it is what this baseline has to constrain. In the versions the project locks:

- an unexpected exception is reported at error level on the default channel, with the exception message as the record message and the exception object in the record context, which the default text formatter renders with class, file, line, stack trace and the chained previous exceptions;
- the framework adds the authenticated user identifier — here the Actor Reference `id` — to that record, and nothing else from the request;
- values placed in the framework's log context repository reach every record produced during that execution;
- the message of a database failure is different in kind: it carries the statement with the binding values interpolated into it, the driver's own message and the connection host, port and database name, and in PostgreSQL the driver message of a constraint violation can carry the value of the offending column.

This ADR closes the baseline. It implements nothing.

## Decision

### Functional History is not operational telemetry

The separation already stated in [requirements.md](../../requirements.md) and in [ADR-003](0003-lifecycle-state-and-persistence-baseline.md) is normative here, in both directions. Operational telemetry exists to diagnose and correlate failures of the running system; the Functional History exists to reconstruct what happened to an access request. Consequently:

- `execution_id` is not a functional fact and is never attached to one;
- `operation` is not a functional fact;
- log records never become Functional History entries, and Functional History is never derived from them;
- no domain table is created for telemetry, and no migration is required by this baseline;
- decisions, grant confirmations and revocation confirmations store no operational information;
- `RNF-003` and `RNF-004` keep applying to the Functional History only, and `RNF-006`, `RNF-010` and `RNF-011` to telemetry only;
- [ADR-003](0003-lifecycle-state-and-persistence-baseline.md) and [ADR-008](0008-read-projection-consistency-baseline.md) are unchanged: the preserved facts, the derived states and the projection remain exactly as decided.

### What an execution is

An **execution** is one execution of an entry point of the application. Today that means:

- **HTTP.** One protected or authentication HTTP request is one execution.
- **A critical Action with no external boundary.** When one of the four critical mutation Actions is invoked directly and no execution has been established around it, that invocation is the top-level execution for operational purposes.
- **A future command-line adapter.** A future Artisan command constitutes its own execution when there is a real driver for that adapter. None exists now.

The following are not executions of their own: an SQL query, a log record, any internal call, and a critical Action invoked inside an execution that already exists. An Action that runs inside an existing execution reuses that execution and its identifier.

### Execution identifier

Every execution has one identifier, under the key `execution_id`. It is:

- generated exclusively by the server;
- a **UUIDv4**;
- opaque, with no functional meaning;
- never persisted;
- never reused by another execution;
- not tied to the session, to the session cookie or to the Actor Reference;
- not required to be returned to the client.

UUIDv4 is chosen because this baseline has no ordering requirement and no requirement for time embedded in the identifier — the record already carries its own instant — and because a random, opaque value is exactly what `RNF-011` asks for: something that groups the records of one execution and distinguishes them from other executions. It also keeps the operational identifier conceptually distinct from the domain identifiers, which are generated under [ADR-004](0004-initial-data-model-baseline.md). [ADR-009](0009-functional-time-source-baseline.md) is not the reason: it governs functional time, and operational telemetry is outside that baseline.

### No client-supplied correlation identifier

The application does not accept `X-Request-ID`, `X-Correlation-ID`, `traceparent` or any other client-supplied value as the `execution_id`, and reads no correlation header at this stage. The reasons are concrete: a client could spoof an identifier and make unrelated executions look like one, or repeat a single value and destroy the distinguishability `RNF-011` requires; identifiers chosen elsewhere can collide; a value controlled by the caller can inject content into a text record; and there is no gateway, edge or downstream service whose identifier would need to be propagated.

If a proxy or edge identifier ever becomes relevant, it may be recorded as a separate field once that need exists. It never replaces the internal `execution_id`.

### No response header

The `execution_id` is not exposed in a response header in the current MVP. There is no support interface, no operational attendance flow, no public correlatable error and no external tracking need; the error envelope itself is still open ([ADR-010](0010-application-entry-boundary-baseline.md)). Exposing it by convention would add an observable contract with no benefit and would give a caller a way to correlate its own probing. This is revisited only with a concrete driver.

### Operational context

The propagation mechanism is Laravel's own log context (`Illuminate\Support\Facades\Context`), carrying `execution_id` and `operation`. It is the proportionate local choice because the framework scopes it to the execution, injects it into the records through its own log processor, and works both in HTTP and in invocations with no HTTP involved. It therefore requires no singleton or static state of the project's own, and no custom Monolog processor. No other propagation mechanism is adopted, and no logging façade of the project's own is introduced.

### Operation names

Every execution carries the key `operation`, which names the functionality being executed.

- **HTTP** uses the route name that already exists: `auth.login`, `auth.callback`, `auth.logout` and `api.me.requests-and-accesses`. These names are stable, unique and already asserted by the test suite, and they are not duplicated by a second observability vocabulary.
- **Critical Actions** use stable names of their own, because they must be identifiable without an HTTP surface: `access_request.create`, `access_request.decide`, `access_grant.confirm` and `access_revocation.record`.

No central registry, enumeration or naming service is created. No operation name is invented for the read Actions: when they are exposed, the route name of their endpoint already identifies the functionality, and a read Action invoked directly is not a critical operation.

### Nesting

- An execution has exactly one `execution_id`, from its start to its end.
- Operations may nest, and the innermost operation identifies what is effectively running.
- On success, the previous operation is restored when the inner one ends.
- An unexpected failure of a critical Action is reported **while the context of that Action is still active**, so that the record names the operation that actually failed.
- After the report, the same exception is rethrown and the context may be restored.

This ordering is deliberate: it does not depend on operational context surviving until some outer boundary is reached, and it does not depend on the order in which an outer layer unwinds.

### A critical Action invoked directly

For a critical Action invoked with no execution around it, the operational infrastructure:

1. establishes the execution;
2. assigns the `execution_id`;
3. assigns the Action's `operation`;
4. executes the Action;
5. on an unexpected failure, reports the exception while that context is still active;
6. rethrows the same exception, unchanged;
7. restores or clears the context.

The Action's own behavior does not change: the exception that the caller receives is the same instance, the transaction boundary is untouched and `RNF-008` is unaffected.

For a critical Action invoked inside an HTTP execution, the Action reuses the existing `execution_id`, uses its own operation while it runs, and a failure is reported with that inner operation. The same exception instance must not produce two equivalent records when it later reaches the HTTP boundary. The materialization may rely on the framework's own duplicate-report suppression — Laravel offers `dontReportDuplicates()` for exactly this — to guarantee one report per `Throwable` instance. This ADR decides the semantics, one record per failure, not the code.

### Expected failures

The following are expected, controlled outcomes of the product's own logic, not operational incidents:

- an unauthenticated request to a protected route (`401`);
- a state-changing request without CSRF protection (`419`);
- an expected "not found" for an identifier that does not exist;
- input validation, when it exists;
- a business rule violation (`RN01`–`RN12`);
- a lack of functional authority or scope;
- an authenticated external identity with no Actor Reference;
- an invalid or rejected OpenID Connect callback;
- repetitions and replays rejected by the existing logic.

The rule: **an expected failure is not an operational ERROR**, and none of them is promoted automatically to an application incident. This does not forbid a selected, controlled WARNING when a specific case has operational or security value, as decided below for the OpenID Connect login.

### Unexpected failures

Unexpected failures are the ones that do not belong to the expected functional flow: a database failure (`QueryException`), an unexpected infrastructure failure, a `LogicException` that signals an internal contract being broken, a `TypeError`, an `Error`, an unexpected `RuntimeException`, an unexpected unavailability of the OpenID Connect integration, and any other internal defect.

The baseline is: **an unexpected application failure produces one ERROR record.**

### OpenID Connect failure classification

`OpenIdConnectFailure` carries a fixed reason label and no transaction value, claim, code or token ([ADR-011](0011-authentication-baseline.md) materialization). The reason decides the classification:

| Reason | Level | Why |
| --- | --- | --- |
| `configuration`, `provider_metadata`, `token_exchange` | ERROR | Authentication should have been able to operate and the integration or the configuration could not make it work. This is an operational failure, not a user outcome. |
| `missing_transaction`, `expired_transaction`, `missing_state`, `missing_code`, `provider_error` | not ERROR | Controlled outcomes of the interaction and its correlation: an abandoned, stale or replayed login, or a refusal the provider itself reports. |
| `state_mismatch`, `id_token`, `identity_claims`, and an authenticated identity with no Actor Reference | WARNING, selectively | A closed allow-list of reasons with clear operational or security value: a response that does not correlate to its login, a token that fails validation, claims that fail the client checks, and an identity that authenticates but is not provisioned. |

Such a record carries only the fixed reason code. It contains no external identity, no callback query, no token and no claim, and it does not assert that an attack occurred: it states what the application refused, not who did it or why. No INFO record is produced for a successful login, callback or logout.

### Log levels

The policy is minimal and does not grow into a matrix:

- **ERROR** — an unexpected failure.
- **WARNING** — a selected, controlled operational or security-relevant anomaly, as defined above.
- **INFO** — not used for the functional lifecycle or for routine success.

DEBUG is not a requirement of this baseline. The Functional History remains the record of the business lifecycle; operational records never take that role.

### Telemetry allow-list

Operational context created deliberately by StewardArc starts from an allow-list. The baseline admits:

- `execution_id`;
- `operation`;
- a fixed reason code, when the failure defines one;
- the SQLSTATE, for a sanitized database failure;
- the exception class;
- the code reference needed for diagnosis, such as the application file and line.

Not all of them are required in every record: a record carries only what its kind of failure justifies. Anything outside this list requires a new decision.

### Prohibited data

The following are prohibited by default in operational records and telemetry: client secret and any credential; authorization code; access token; refresh token; ID Token; session cookie and session identifier; PKCE verifier; `nonce`; `state`; the raw authorization response; the callback query string; any full URL containing a query; request and response bodies; headers; cookies; the `Authorization` header; `external_identity_key`; the pair (`iss`, `sub`); display name; the justification of an access request; the justification of a decision; and any functional text written by a user.

A generic regular expression or redaction filter is not an acceptable substitute for the allow-list: what is not allowed is not written in the first place.

### The Actor Reference identifier

The framework's default exception report includes the authenticated user identifier — here the Actor Reference `id` — as `userId`. This baseline accepts that behavior deliberately rather than inheriting it by accident:

- the internal Actor Reference UUID may stay in reports of unexpected failures, as an internal pseudonymous diagnostic identifier;
- it is not an external identity, and it is not exposed to clients;
- it is not added by hand to every record;
- the display name is prohibited;
- `external_identity_key`, `iss` and `sub` are prohibited;
- the actor identifier is never used as the correlation identifier: correlation is `execution_id`.

This permission is narrow, justified by the diagnosis and security purposes that `RNF-006` names, and it authorizes no general logging of personal data.

### Entity identifiers

No entity identifier enters operational records by default. The access request, granted access, resource, access profile and decision identifiers are not added automatically: `RNF-010` requires the operation, the instant and the failure condition, not the row that was touched.

A single opaque functional identifier may be introduced later, for a specific operation, only when a concrete diagnostic driver exists for it. That is not anticipated by the first materialization.

### Database failures

The default raw report of a database failure is explicitly **not** safe enough for this baseline. The reasons are properties of the materialized stack, not hypotheses: the statement can carry the binding values interpolated into it; the driver's message can carry functional values, and in PostgreSQL the `DETAIL` line of a constraint violation can carry the value of the offending column; the exception context and the chained previous exception preserve that text; and the connection host, port and database name are not part of the minimum `RNF-010` needs.

Therefore:

- binding values are masked at the database mechanism;
- the operational record of a database failure is sanitized;
- the raw report is suppressed when the sanitized record is produced, so that one failure still yields one record;
- the raw statement is not recorded;
- the bindings are not recorded;
- the raw driver message is not recorded;
- the connection host, port and database name are not recorded by default.

The sanitized record preserves at least: `execution_id`, `operation`, the exception class, the SQLSTATE when available, the record's own instant and the code reference needed for diagnosis. The implementation is not written here.

### Exception messages in general

No claim is made that every exception of every library is automatically safe to record. This baseline requires instead:

- the project's own exceptions keep their messages free of secrets and payload, as the current authentication and business-rule exceptions already do;
- any third-party integration is audited before its exception messages are trusted in a record;
- the materialization addresses explicitly the two risks known today, database failures and the OpenID Connect paths;
- `RNF-006` therefore stays partial: infrastructure, future libraries and deployment can still introduce new surfaces.

### Log format

JSON is not adopted as an architectural decision now. The current text format of the framework's logging is sufficient for the local environment and for proving what this baseline requires: the execution identifier, the operation, the level, the instant and the failure condition. No formatter of the project's own is created. The production format remains a deployment decision.

### The operational instant

The instant of a failure is the timestamp of the operational record itself. The application does not query PostgreSQL `transaction_timestamp()` for it, does not reuse `FunctionalTransactionTime`, does not persist an operational timestamp in the domain and does not duplicate the instant in a custom field without a need. [ADR-009](0009-functional-time-source-baseline.md) keeps governing functional time only, and its scope boundaries already exclude observability timestamps.

### The health endpoint

`/up` stays outside the execution correlation baseline of the application. It is operational infrastructure ([ADR-010](0010-application-entry-boundary-baseline.md)), it is not part of the protected functional surface, it is polled repeatedly, and there is no driver for producing telemetry per health check. No special exception has to be invented if the chosen entry point simply does not cover it.

### Debug mode

`APP_DEBUG=true` is local and development behavior. A production deployment must not expose debug pages, which carry request, header and query information. That configuration belongs to the deployment, which is not decided here and is not resolved by the materialization of this baseline.

### Verifiability

The materialization must be automatically verifiable, without human inspection of a log file. It must be possible to prove that:

- records of the same execution share one `execution_id`;
- different executions get different identifiers;
- the `operation` nests and is restored;
- a critical Action invoked directly gets its own execution;
- an unexpected failure of a directly invoked Action is reported while its context is still active;
- the same exception instance does not produce a duplicate report;
- expected failures do not become ERROR;
- a database failure exposes neither bindings nor driver values;
- sentinel values of the OpenID Connect flow never appear in any record;
- context does not leak from one execution into the next.

### Materialization status

This ADR is a decision only and materializes nothing. `RNF-010` and `RNF-011` are materialized by the application when the baseline is implemented. `RNF-006` is materialized only in part by the application — allow-list, exclusion of secrets and payload, sanitized database failures, reason codes for the login and sentinel tests — while the log destination, access, retention, rotation, shipping and the secure production configuration stay with the deployment.

## Rationale

The reasoning is specific to StewardArc.

- The product already states, in its own requirements, that the Functional History and operational telemetry are different concerns; a correlation identifier or an operation name leaking into a functional fact would break that separation permanently, which is why the boundary is the first decision and not a footnote.
- Defining the execution at the entry point, rather than per Action, is what makes `RNF-011` hold for the shape the product actually has: one request may run more than one operation, and the four critical Actions can run with no request at all.
- Making a directly invoked critical Action establish its own execution is the only way `RNF-010` covers the operations that change the lifecycle, since three of the four still have no HTTP surface.
- Reporting an Action's failure while its own context is active keeps the record truthful about which functionality failed, and avoids a design that depends on residual context surviving an unwinding stack.
- A server-generated, opaque identifier is the minimum that satisfies correlation; accepting one from the client would let a caller decide how the operator's evidence is grouped, which is the opposite of what `RNF-011` asks for.
- The framework's own context mechanism reaches every record of the execution, including the exception report, and is scoped by the framework; building a project-specific carrier would add state to maintain and a new way to leak between executions.
- Route names and Action names are two vocabularies that already exist and are already stable; inventing a third would guarantee drift between the endpoint and the operation it calls.
- Classifying failures before writing any record is what prevents the product's normal refusals — an unavailable profile, a rejected duplicate, an unauthenticated request — from becoming operational noise that hides a real defect.
- The database failure decision follows from an observed property of the stack, not from caution in the abstract: the statement, the bindings and the driver's own message can carry a justification, a display name or an identity key, and none of them is needed to know which operation failed and why.
- An allow-list is auditable and a filter is not: a list of what may be recorded can be verified with sentinel values, whereas a redaction expression can only be shown to have failed after it did.
- Keeping the identifier out of the response, and JSON out of the format decision, avoids fixing an external contract and a platform choice before there is any consumer for either.

## Consequences

Benefits:

- Failures of one execution become groupable and distinguishable from simultaneous or later ones, in a monolith, with no external platform.
- The risk of leaking secrets, tokens or functional content into operational records is reduced by construction rather than by filtering.
- A failure can be diagnosed from the operation, the instant and the failure condition, without keeping any payload.
- The baseline works with what the project already has: no vendor, no agent, no new dependency.
- Telemetry and the Functional History stay separate, so neither can silently become the other.

Trade-offs:

- Operational records deliberately carry less information, and some investigations will require reproducing the failure instead of reading it.
- Details the driver offers — the statement, its values, the driver's own message — are discarded on purpose, which makes some database failures slower to diagnose.
- Production still has to decide storage, retention, rotation and shipping before these records are useful outside a developer's machine.
- The operational context becomes a cross-cutting responsibility of the application: entry points and critical Actions have to establish it, and future entry points inherit that obligation.
- One more rule set applies to every future endpoint and integration, including the audit of third-party exception messages.

## Deferred decisions

The following remain open:

- the production log destination, and whether it is a file or standard output;
- a production log format, including JSON;
- rotation, retention and log shipping;
- any external observability platform, and any specific vendor;
- alerting, dashboards and metrics;
- distributed tracing, trace and span identifiers, and OpenTelemetry;
- an edge or proxy correlation identifier, and its propagation;
- the reverse proxy, TLS termination and the production session store;
- observability of the frontend;
- rate limiting;
- user activity analytics;
- the concrete classes, namespaces, middleware and helpers of the materialization;
- the operational treatment of any future entry adapter, including a command-line one.

The following are **not** reopened by later work: the separation between Functional History and operational telemetry; the definition of an execution and the reuse of its identifier by inner operations; `execution_id` as an opaque, server-generated UUIDv4 with no persistence and no functional meaning; the refusal of client-supplied correlation identifiers; the operation naming that reuses route names and the four critical Action names; the classification of expected and unexpected failures and the level policy; the telemetry allow-list and the list of prohibited data; the rejection of the raw database failure report; and the requirement that the materialization be automatically verifiable.

## Relationship to previous ADRs

- [ADR-003](0003-lifecycle-state-and-persistence-baseline.md): telemetry is not a functional fact and enters no lifecycle or persistence decision; the preserved facts and the derived states are unchanged.
- [ADR-006](0006-application-implementation-baseline.md): the Actions remain the functional application boundary and keep owning their transactions; operational context is cross-cutting and creates no service layer, command bus, decorator or repository. ADR-006 listed observability and correlation among its deferred decisions; this ADR closes that item at the baseline level.
- [ADR-008](0008-read-projection-consistency-baseline.md): the read transaction, its single snapshot and its reference instant do not change, and no operational record adds a query inside the projection.
- [ADR-009](0009-functional-time-source-baseline.md): functional time and operational time stay separate; the operational instant is the record's own, and PostgreSQL remains the source of functional time only.
- [ADR-010](0010-application-entry-boundary-baseline.md): HTTP stays a thin adapter, the current actor still never comes from the client, and `/up` stays operational infrastructure outside the protected surface. The correlation identifier is not a client input and is not part of the product contract.
- [ADR-011](0011-authentication-baseline.md): the secrets and tokens of the OpenID Connect flow stay protected, and observability reopens neither authentication nor authorization. Provider claims are still not functional authority, and none of them is recorded.

ADR-012 complements the previous ADRs and rewrites none of them.

## Out of scope

This ADR does not create:

- middleware, helpers, classes or namespaces;
- changes to `bootstrap/app.php`, `config/logging.php` or `config/database.php`;
- changes to the Actions, the controllers or the OpenID Connect code;
- log channels, formatters or processors;
- tests;
- migrations, schema or model changes;
- dependencies;
- CI changes;
- frontend changes;
- deployment configuration.
