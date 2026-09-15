# Requirements

This document is the public baseline of StewardArc's functional requirements, business rules and non-functional requirements. It is a curated version of the approved baselines *Functional Requirements of the MVP v1.2* and *Non-Functional Requirements v1*.

States (`S1`–`S5`, `A1`–`A3`), use cases and acceptance criteria are described in [behavior.md](behavior.md). Relationships between items are in [traceability.md](traceability.md).

## Identifier conventions

Identifiers are preserved from the original baseline and are normative:

| Prefix | Meaning |
| --- | --- |
| `RF` | Functional requirement |
| `RN` | Business rule |
| `RNF` | Non-functional requirement |
| `UC` | Use case |
| `CA` | Acceptance criterion |

## Cross-cutting assumptions

- The **authenticated identity** is provided externally and is not part of the domain. **Authorization** belongs to the product.
- The MVP actors are **Requester**, **Resource Owner** and **Governance**. No administrative actor is required for the MVP flow; base data may be provided as demonstration data.
- Each access profile belongs to exactly one resource and is classified as **Standard** or **Privileged**.
- Grants and revocations are performed **outside** StewardArc and later confirmed in it.

## Functional requirements

| ID | Requirement | Description |
| --- | --- | --- |
| RF-001 | Consult access catalog | Users can consult the access profiles available for new requests. |
| RF-002 | Register access request | The Requester registers a request for themselves, selecting an available access profile, stating a justification and, when required by the profile, the applicable validity information. The request enters its lifecycle. |
| RF-003 | Determine approval flow | StewardArc determines whether the request follows the Standard or the Privileged approval flow, according to the requested profile. |
| RF-004 | Consult pending approvals | Deciding actors consult the requests pending their decision, restricted to their authority. |
| RF-005 | Record approval/rejection decision | The authority of the currently pending step (the Resource Owner, or Governance for the Privileged step) records an approval or a justified rejection. |
| RF-006 | Record grant confirmation | The system allows the Resource Owner to record the confirmation that the grant was executed externally, only after all required approvals are complete. The confirmation concludes the request and creates the Granted Access. |
| RF-007 | Record access revocation | The system allows the Resource Owner to record that a previously granted access was revoked in the external system. StewardArc records this fact; it does not perform deprovisioning. |
| RF-008 | End validity in governance by expiration | When the validity end of a temporary authorization is reached, the system stops considering it active in the governance layer. This does not prove that the external permission was technically removed. |
| RF-009 | Consult own requests | The Requester consults their own access requests and their current state. |
| RF-010 | Consult accesses | Actors consult Granted Accesses within their scope: the Requester consults their own accesses; the Resource Owner consults accesses tied to resources under their responsibility; Governance consults accesses of Privileged profiles. |
| RF-011 | Consult request history | Actors consult request history within their scope: the Requester for their own requests; the Resource Owner for requests of resources under their responsibility; Governance for requests of Privileged profiles. The history includes decisions and, when it exists, the grant confirmation. |

## Business rules

| ID | Rule | Statement |
| --- | --- | --- |
| RN01 | Available profile | Only profiles available for new requests can be requested. |
| RN02 | Own request | In the MVP, a user requests access only for themselves. |
| RN03 | Duplicates | A new request for a profile is not allowed while there is an equivalent request in progress or an equivalent active access. |
| RN04 | Self-approval forbidden | Nobody can decide their own request. |
| RN05 | Standard access | A Standard profile requires approval by the Resource Owner. |
| RN06 | Privileged access | A Privileged profile requires, in this order, approval by the Resource Owner and then by Governance. |
| RN07 | Privileged validity | See below. |
| RN08 | Justified rejection | Every rejection requires a justification and ends the request. |
| RN09 | Authority and sequence | Only the authority of the currently pending step can decide. Governance does not decide before the Resource Owner's approval. |
| RN10 | Approval ≠ grant | A grant can only be confirmed after all required approvals. |
| RN11 | Resource Owner restriction | A Resource Owner cannot, in this flow, request for themselves a profile that belongs to a resource they are responsible for. |

### RN07 — Privileged validity

A request for a Privileged profile must state the required duration of the access. The duration must be greater than zero and cannot exceed 90 days.

The effective validity period starts only when the external grant is confirmed in the product, and ends when the stated duration elapses.

The rule sets no minimum unit, no whole-day requirement, no hour granularity and no technical representation for the duration.

## Relevant semantic decisions

- **Approval ≠ grant.** An approved request (`S3`) is waiting for the external grant to be confirmed. It is not a Granted Access.
- **Awaiting grant does not consume validity.** Time spent in `S3` does not count towards the effective validity period.
- **Expiration is a governance fact.** Expiration applies when a calculable validity end exists, in particular the Privileged validity governed by `RN07`. Ending an access by expiration (`A2`) does not prove that a revocation happened in the target system.
- **Revocation is external.** The Resource Owner records the confirmation of a revocation performed elsewhere; StewardArc does not perform it. An active access (`A1`) moves to `A3`. An access already in `A2` remains in `A2`, and the revocation confirmation is preserved as an additional history event.
- **No direct renewal or reactivation.** After a Granted Access ends, a later need requires a new request.
- **RF-008 has no use case.** It is automatic, time-based behavior rather than an actor's goal; its objective coverage is `CA-017`.
- **Functional history ≠ operational telemetry.** The history of a request is part of the product's functional behavior. Operational diagnostics and event correlation are a separate concern.

## Non-functional requirements

Each requirement states the normative constraint and how conformance is observed. A *critical operation* is an operation that changes the functional lifecycle.

### RNF-001 — Identity and authorization enforcement

- **Requirement:** Every protected functionality requires a valid authenticated identity and enforces the authorization and scope rules of the functional baselines. Without valid authentication or sufficient authorization, the operation must not execute and protected content must not be exposed.
- **Verification:** Attempts without valid authentication, or with insufficient authorization or scope, neither execute the operation nor expose protected content.

### RNF-002 — Authenticated context and communication protection

- **Requirement:** Authentication information and the artifacts used to maintain the authenticated context are protected against exposure and misuse. Communications associated with protected functionality preserve confidentiality and integrity in transit.
- **Verification:** Authentication information and context artifacts are not exposed and cannot be misused; protected communications cannot be read or altered in transit.

### RNF-003 — Functional history integrity

- **Requirement:** Events already recorded in the Functional History are not altered or deleted by the functional operations defined for the MVP, and are protected against unauthorized alteration or deletion.
- **Verification:** No MVP functional operation alters or deletes a recorded history event; unauthorized attempts to alter or delete one do not succeed.

### RNF-004 — Attribution and temporal coherence

- **Requirement:** The authorship and temporal-reference information required by the functional baselines remains associated with its events, so that the functional sequence can be reconstructed coherently.
- **Verification:** For each recorded event, the required author and temporal reference are available, and the sequence of events can be reconstructed without contradiction.

### RNF-005 — Data minimization

- **Requirement:** The product collects, processes, retains and exposes only the data necessary for the MVP's functions, authorization rules, traceability and explicitly defined operational purposes.
- **Verification:** Every data item collected, processed, retained or exposed is justified by one of those purposes.

### RNF-006 — Operational and diagnostic information protection

- **Requirement:** Operational records and telemetry do not contain credentials, authentication secrets, tokens, or personal data beyond what is necessary for diagnosis, security and operational correlation.
- **Verification:** Inspected operational records and telemetry contain no credentials, authentication secrets or tokens, and no personal data beyond those purposes.

### RNF-007 — Accessibility

- **Requirement:** The implemented MVP interfaces and flows meet the applicable WCAG 2.2 Level A and AA success criteria.
- **Verification:** Implemented interfaces and flows conform to the applicable WCAG 2.2 Level A and AA success criteria.

### RNF-008 — Consistency under failures

- **Requirement:** A critical operation either produces a complete, valid functional result or preserves the previous valid functional state. A failure must not leave partial combinations inconsistent with states, transitions or invariants.
- **Verification:** After a failure during a critical operation, the functional state is either the complete valid result or the previous valid state.

### RNF-009 — Consistency under concurrency and repetition

- **Requirement:** Retries, duplicate submissions, repetitions or concurrent execution of the same critical operation do not create duplicate or conflicting functional effects and do not violate functional invariants.
- **Verification:** Repeating, resubmitting, retrying or concurrently executing the same critical operation creates no duplicate or conflicting functional effect and violates no functional invariant.

### RNF-010 — Failure diagnosability

- **Requirement:** Unexpected failures in protected functionality or critical operations produce enough operational information to identify the affected functionality or operation, the time of occurrence and the failure condition, while respecting data-protection and minimization constraints (`RNF-005`, `RNF-006`).
- **Verification:** For an unexpected failure, the operational information identifies the affected functionality or operation, when it occurred and the failure condition, without violating `RNF-005` or `RNF-006`.

### RNF-011 — Operational event correlation

- **Requirement:** When one execution produces multiple operational records or events, they are unequivocally correlatable to that execution without relying on credentials, secrets or unnecessary personal content. Evidence from simultaneous or subsequent executions remains distinguishable.
- **Verification:** The records of one execution can be unequivocally grouped by that execution, without credentials, secrets or unnecessary personal content, and are distinguishable from those of simultaneous or later executions.

### Functional history and operational telemetry

`RNF-003` and `RNF-004` concern the Functional History, which is part of the product's functional behavior. `RNF-006`, `RNF-010` and `RNF-011` concern operational records and telemetry. The two must not be conflated.

### Performance

No performance requirement is defined. This is deliberate: there is currently no basis for normative targets, so no SLO, SLA, latency or throughput figure is set. A performance requirement should only be introduced when a real driver exists.
