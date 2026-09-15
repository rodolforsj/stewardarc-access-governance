# Behavior

This document describes how StewardArc behaves: lifecycle vocabulary, states and transitions, approval flows, use cases and acceptance criteria. It is a curated version of the approved baselines *Functional Flows and States v1*, *Use Cases v1* and *Acceptance Criteria v1*.

Requirement and rule identifiers (`RF`, `RN`, `RNF`) are defined in [requirements.md](requirements.md).

## Two lifecycles

StewardArc has two distinct lifecycles:

- the **Access Request** lifecycle (`S1`–`S5`) covers the path from submission to decision and grant confirmation;
- the **Granted Access** lifecycle (`A1`–`A3`) starts only when a grant is confirmed and covers the access until it ends.

An approved request is not a Granted Access. The request waits in `S3` until the external grant is confirmed; only then does it reach `S4` and a Granted Access is created in `A1`.

## Access Request states

| State | Name | Terminal |
| --- | --- | --- |
| `S1` | Awaiting Resource Owner approval | No |
| `S2` | Awaiting Governance approval | No |
| `S3` | Awaiting grant | No |
| `S4` | Grant confirmed | Yes |
| `S5` | Rejected | Yes |

### Allowed transitions

| From | To | Trigger | Flow |
| --- | --- | --- | --- |
| `S1` | `S3` | Resource Owner approves | Standard |
| `S1` | `S2` | Resource Owner approves | Privileged |
| `S2` | `S3` | Governance approves | Privileged |
| `S1` | `S5` | Resource Owner rejects with justification | Standard, Privileged |
| `S2` | `S5` | Governance rejects with justification | Privileged |
| `S3` | `S4` | External grant is confirmed | Standard, Privileged |

No other transitions exist. `S4` and `S5` are terminal.

```mermaid
stateDiagram-v2
    [*] --> S1: request registered
    S1 --> S3: Resource Owner approves (Standard)
    S1 --> S2: Resource Owner approves (Privileged)
    S2 --> S3: Governance approves
    S1 --> S5: rejected with justification
    S2 --> S5: rejected with justification
    S3 --> S4: external grant confirmed
    S4 --> [*]
    S5 --> [*]

    S1: S1 Awaiting Resource Owner approval
    S2: S2 Awaiting Governance approval
    S3: S3 Awaiting grant
    S4: S4 Grant confirmed
    S5: S5 Rejected
```

## Approval flows

The flow is determined by the requested profile (`RF-003`).

**Standard flow** (`RN05`): `S1 → S3 → S4`, with rejection at its decision step: `S1 → S5`.

**Privileged flow** (`RN06`): `S1 → S2 → S3 → S4`, with rejection at any current decision step: `S1/S2 → S5`.

In both flows:

- only the authority of the currently pending step can decide (`RN09`);
- nobody decides their own request (`RN04`);
- every rejection requires a justification and ends the request (`RN08`);
- approval does not grant access; the grant is confirmed afterwards (`RN10`).

## Granted Access states

| State | Name |
| --- | --- |
| `A1` | Active |
| `A2` | Ended by expiration |
| `A3` | Ended by confirmed revocation |

A Granted Access exists only after grant confirmation (`S3 → S4`).

| From | To | Trigger |
| --- | --- | --- |
| — | `A1` | Resource Owner records the grant confirmation; the effective validity period starts |
| `A1` | `A2` | A calculable validity end is reached, in particular the Privileged validity governed by `RN07` |
| `A1` | `A3` | Resource Owner records the confirmation of an external revocation |

```mermaid
stateDiagram-v2
    [*] --> A1: grant confirmed (request S3 to S4)
    A1 --> A2: calculable validity end reached
    A1 --> A3: external revocation confirmed
    A2 --> [*]
    A3 --> [*]

    A1: A1 Active
    A2: A2 Ended by expiration
    A3: A3 Ended by confirmed revocation
```

Notes:

- The effective validity period starts at grant confirmation, never at approval. Time in `S3` does not consume validity (`RN07`).
- Expiration applies when a calculable validity end exists, in particular for Privileged access (`RN07`).
- `A1 → A2` is a governance expiration. It does not prove that a revocation happened in the target system.
- If the revocation confirmation is recorded for an access already in `A2`, the access remains in `A2`; the confirmation is preserved as an additional history event (`CA-018`, scenario B).
- There is no direct renewal or reactivation. A later need requires a new request.

### RF-008: expiration without a use case

`RF-008` (end validity in governance by expiration) is automatic, time-based behavior, not a goal pursued by an actor. For that reason it deliberately has no use case of its own. Its objective coverage is `CA-017`.

## Use cases

### UC-001 — Request access

- **Primary actor:** Requester.
- **Goal:** request access to an available profile for themselves.
- **Outcome:** a valid request is registered in `S1`, following the Standard or Privileged flow.
- **Rules:** `RN01`, `RN02`, `RN03`, `RN07`, `RN11`.
- **Requirements:** `RF-001`, `RF-002`, `RF-003`.

### UC-002 — Decide access request

- **Primary actors:** Resource Owner; Governance for the Privileged step.
- **Goal:** approve or reject a request pending the actor's decision.
- **Outcome:** the request advances along its flow or ends in `S5`.
- **Rules:** `RN04`, `RN05`, `RN06`, `RN08`, `RN09`.
- **Requirements:** `RF-004`, `RF-005`.

### UC-003 — Confirm external access grant

- **Primary actor:** Resource Owner.
- **Secondary actors:** none. The external technical execution is not an MVP actor.
- **Goal:** record that the access was actually granted outside the product.
- **Preconditions:** the request is in `S3`; all required approvals are complete; the grant was actually executed outside the product.
- **Outcome:** the request moves `S3 → S4`, the Granted Access is created in `A1` and its effective validity period starts.
- **Rules:** `RN07`, `RN10`.
- **Requirements:** `RF-006`.

### UC-004 — Record external access revocation

- **Primary actor:** Resource Owner.
- **Secondary actors:** none.
- **Goal:** record a revocation that occurred outside the product.
- **Outcome:** an access in `A1` moves to `A3`. For an access already in `A2`, the state is unchanged and the revocation confirmation is preserved in the history (`CA-018`).
- **Requirements:** `RF-007`; contributes the revocation event to the history consulted through `RF-011`.

### UC-005 — Follow my requests and accesses

- **Primary actor:** Requester.
- **Goal:** follow their own Access Requests, their Granted Accesses and the associated Functional History.
- **Rules:** `RN07` applies when viewing the validity of a Privileged access.
- **Requirements:** `RF-009`, `RF-010`, `RF-011`. `RF-008` is observed through the displayed status of accesses.

### UC-006 — Consult accesses and history within responsibility scope

- **Primary actors:** Resource Owner; Governance.
- **Goal:** consult Granted Accesses and request history within the actor's responsibility scope.
  - The Resource Owner reads accesses and request history associated with resources under their responsibility.
  - Governance reads accesses and request history for Privileged profiles.
- **Requirements:** `RF-010`, `RF-011`. `RF-008` is reflected in the status of the accesses consulted.

## Acceptance criteria

| ID | Verifiable behavior |
| --- | --- |
| CA-001 | Consulting the catalog shows the profiles available for new requests. |
| CA-002 | A valid request is registered and enters its lifecycle. |
| CA-003 | A request for an unavailable profile is prevented. |
| CA-004 | A request on behalf of another user is prevented. |
| CA-005 | A duplicate request is prevented while an equivalent request is in progress or an equivalent access is active. |
| CA-006 | A Resource Owner is prevented from requesting for themselves a profile of their own resource. |
| CA-007 | The duration of a Privileged request is validated according to `RN07`. |
| CA-008 | Pending approvals are restricted to the applicable scope and authority. |
| CA-009 | Approval in the Standard flow moves the request from `S1` to `S3`. |
| CA-010 | The Privileged flow follows `S1 → S2 → S3`. |
| CA-011 | Self-approval is prevented. |
| CA-012 | A decision outside the current authority or step is prevented. |
| CA-013 | A rejection requires a justification and ends the request in `S5`. |
| CA-014 | The request remains in `S3` while the external grant is not confirmed, without consuming validity. |
| CA-015 | Grant confirmation before all approvals are complete is prevented. |
| CA-016 | Grant confirmation moves the request `S3 → S4`, creates the Granted Access and starts its effective validity period. |
| CA-017 | See [CA-017](#ca-017--expiration-of-a-privileged-access). |
| CA-018 | See [CA-018](#ca-018--confirmation-of-external-revocation). |
| CA-019 | After an access ends, a new need requires a new request; there is no direct renewal or reactivation. |
| CA-020 | See [CA-020](#ca-020--consultation-scopes). |
| CA-021 | See [CA-021](#ca-021--functional-history-content). |

### CA-017 — Expiration of a Privileged access

Given a Privileged Granted Access in `A1` with a defined duration, when its effective validity period ends, the access moves `A1 → A2` and is no longer considered active by governance. This neither implies nor records an external technical revocation.

### CA-018 — Confirmation of external revocation

- **Scenario A:** given an access in `A1` that was revoked externally, when the Resource Owner records the revocation confirmation, the access moves `A1 → A3`.
- **Scenario B:** given an access already in `A2` because it expired, when an external revocation occurs and its confirmation is recorded, the access does **not** move to `A3`. It remains in `A2`, and the revocation confirmation is preserved as an additional history event.

### CA-020 — Consultation scopes

Queries return only what is within the actor's scope:

- **Requester:** their own requests, histories and accesses.
- **Resource Owner:** accesses tied to resources under their responsibility, and requests for those resources.
- **Governance:** accesses of Privileged profiles, and requests for Privileged profiles.

### CA-021 — Functional History content

The history of a request preserves, when applicable:

- approval and rejection decisions, with the responsible actor and time;
- the rejection justification;
- the grant confirmation, with the recording actor and time;
- the revocation confirmation, with the recording actor and time;
- other relevant recorded functional events.

The actor recorded for a confirmation is the person who records it in StewardArc. It does not presume who technically executed the grant or the revocation externally.
