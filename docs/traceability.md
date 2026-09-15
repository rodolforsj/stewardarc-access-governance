# Traceability

This document is the public view of the approved *Consolidated Traceability Matrix v1*. It links the organization's needs to functional requirements, business rules, use cases or flows, acceptance criteria and, where applicable, non-functional requirements.

The matrix is intentionally conservative: a relationship is listed only when the baseline supports it. An empty cell (`—`) means no direct relationship is asserted, not that one is missing.

Definitions: [requirements.md](requirements.md) · [behavior.md](behavior.md).

## Needs to functional requirements

| Need (from the [problem statement](product.md#problem-statement)) | Functional requirements |
| --- | --- |
| Who requested which access, and why | RF-001, RF-002 |
| Who authorized it, and through which decision path | RF-003, RF-004, RF-005 |
| When the external grant was confirmed | RF-006 |
| For how long the access is valid | RF-006, RF-008 |
| Whether it was revoked | RF-007 |
| What its history is | RF-009, RF-010, RF-011 |

## Functional matrix

| RF | Business rules | Use case / flow | Acceptance criteria |
| --- | --- | --- | --- |
| RF-001 Consult access catalog | RN01 | UC-001 | CA-001 |
| RF-002 Register access request | RN01, RN02, RN03, RN07, RN11 | UC-001 | CA-002, CA-003, CA-004, CA-005, CA-006, CA-007 |
| RF-003 Determine approval flow | RN05, RN06 | UC-001; Standard and Privileged flows | CA-009, CA-010 |
| RF-004 Consult pending approvals | RN09 | UC-002 | CA-008 |
| RF-005 Record approval/rejection decision | RN04, RN05, RN06, RN08, RN09 | UC-002; Standard and Privileged flows | CA-009, CA-010, CA-011, CA-012, CA-013 |
| RF-006 Record grant confirmation | RN07, RN10 | UC-003 | CA-014, CA-015, CA-016 |
| RF-007 Record access revocation | — | UC-004 | CA-018 |
| RF-008 End validity in governance by expiration | RN07 | No use case (time-based behavior) | CA-017 |
| RF-009 Consult own requests | — | UC-005 | CA-020 |
| RF-010 Consult accesses | — | UC-006 | CA-020 |
| RF-011 Consult request history | — | UC-006 | CA-020, CA-021 |

## Use case coverage

| Use case | Covers |
| --- | --- |
| UC-001 Request access | The request flow |
| UC-002 Decide access request | Decision and approval |
| UC-003 Confirm external access grant | Grant confirmation |
| UC-004 Record external access revocation | External revocation |
| UC-005 Follow my requests | Following one's own requests |
| UC-006 Consult accesses and history | Accesses and request history |

`RF-008` is time-based behavior with no use case of its own. It is objectively covered by `CA-017`.

## Cross-lifecycle criterion

`CA-019` (no direct renewal or reactivation; a new need requires a new request) spans the end of the Granted Access lifecycle (`A2`, `A3`) and the registration of new requests. It is not anchored to a single RF in this view.

## Non-functional requirements

Most non-functional requirements are cross-cutting. They are not forced onto a single RF. The anchors below are the most direct functional touchpoints and are not exhaustive.

| RNF | Scope | Most direct functional touchpoints |
| --- | --- | --- |
| RNF-001 Identity and authorization enforcement | Cross-cutting | Authority and scope rules: RN04, RN09, RN11; CA-008, CA-012, CA-020 |
| RNF-002 Authenticated context and communication protection | Cross-cutting | — |
| RNF-003 Functional history integrity | Functional history | RF-011; CA-021 |
| RNF-004 Attribution and temporal coherence | Recorded facts | RF-005, RF-006, RF-007, RF-008, RF-011 |
| RNF-005 Data minimization | Cross-cutting | — |
| RNF-006 Operational and diagnostic information protection | Operational | — |
| RNF-007 Accessibility | User-facing interfaces | — |
| RNF-008 Consistency under failures | State-changing operations | RF-002, RF-005, RF-006, RF-007, RF-008 |
| RNF-009 Consistency under concurrency and repetition | State-changing operations | RF-002, RF-005, RF-006, RF-007, RF-008; RN03; CA-005 |
| RNF-010 Failure diagnosability | Operational | — |
| RNF-011 Operational event correlation | Operational | — |

Functional history (`RNF-003`, `RNF-004`) and operational telemetry (`RNF-006`, `RNF-010`, `RNF-011`) are traced separately on purpose.
