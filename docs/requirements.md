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
| RF-002 | Register access request | A user registers a request for one access profile, for themselves, stating why the access is needed. The request enters its lifecycle. |
| RF-003 | Determine approval flow | StewardArc determines whether the request follows the Standard or the Privileged approval flow, according to the requested profile. |
| RF-004 | Consult pending approvals | Deciding actors consult the requests pending their decision, restricted to their authority. |
| RF-005 | Record approval/rejection decision | The authority of the currently pending step records an approval or a justified rejection. |
| RF-006 | Record grant confirmation | After all required approvals, the external grant is confirmed in StewardArc. The confirmation concludes the request and creates the Granted Access. |
| RF-007 | Record access revocation | A revocation performed externally is confirmed in StewardArc, ending the Granted Access. |
| RF-008 | End validity in governance by expiration | When the effective validity period of a Granted Access ends, StewardArc treats it as ended by expiration in the governance model. |
| RF-009 | Consult own requests | Requesters consult their own requests and their current state. |
| RF-010 | Consult accesses | Actors consult Granted Access records within their functional scope. |
| RF-011 | Consult request history | Actors consult the history of a request, including its decisions and relevant lifecycle events. |

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
- **Expiration is a governance fact.** Ending an access by expiration (`A2`) does not prove that a revocation happened in the target system.
- **Revocation is external.** StewardArc records the confirmation of a revocation performed elsewhere (`A3`); it does not perform it.
- **No direct renewal or reactivation.** After a Granted Access ends, a later need requires a new request.
- **RF-008 has no use case.** It is automatic, time-based behavior rather than an actor's goal; its objective coverage is `CA-017`.
- **Functional history ≠ operational telemetry.** The history of a request is part of the product's functional behavior. Operational diagnostics and event correlation are a separate concern.

## Non-functional requirements

| ID | Concern | Requirement |
| --- | --- | --- |
| RNF-001 | Identity and authorization enforcement | Every functional operation is performed on behalf of an authenticated identity, and the product enforces authorization for it. |
| RNF-002 | Authenticated context and communication protection | The authenticated context and the communication with the product are protected. |
| RNF-003 | Functional history integrity | The functional history preserves the relevant decisions and lifecycle events without undue alteration. |
| RNF-004 | Attribution and temporal coherence | Recorded facts are attributable to the actor responsible for them and remain temporally coherent. |
| RNF-005 | Data minimization | The product handles only the data needed for its functional purpose. |
| RNF-006 | Operational and diagnostic information protection | Operational and diagnostic information is protected and minimized. |
| RNF-007 | Accessibility | User-facing interfaces meet the applicable WCAG 2.2 level A and AA criteria. |
| RNF-008 | Consistency under failures | Failures do not leave decisions, state transitions, Granted Access records and functional history inconsistent with each other. |
| RNF-009 | Consistency under concurrency and repetition | Concurrent or repeated operations do not produce inconsistent states or duplicates. |
| RNF-010 | Failure diagnosability | Failures can be diagnosed. |
| RNF-011 | Operational event correlation | Operational events related to the same processing can be correlated. |

`RNF-003` and `RNF-004` concern the functional history. `RNF-006`, `RNF-010` and `RNF-011` concern operational information. The two must not be conflated.

### Performance

No performance requirement is defined. This is deliberate: there is currently no basis for normative targets, so no SLO, SLA, latency or throughput figure is set. A performance requirement should only be introduced when a real driver exists.
