# Product

This document describes the context, problem and scope of StewardArc. Requirements are detailed in [requirements.md](requirements.md) and behavior in [behavior.md](behavior.md).

## Fictional context

StewardArc is set in a fictional mid-sized organization. Employees need access to internal resources, and those requests currently arrive through decentralized channels. Whoever handles a request may approve it, ask someone else, or grant access directly in the target system, but the path is not recorded in a single, consistent place.

As a result, when someone later asks why a person has a given access, the answer depends on memory, scattered messages or the target system's own state, none of which explains the decision behind it.

## Problem statement

The organization cannot reliably and traceably answer, for each access request:

- who requested it;
- which access was requested;
- why it was requested;
- who authorized it;
- which decision path it followed;
- when the external grant was confirmed;
- for how long the access is valid;
- whether it was revoked;
- what its history is.

## Objective

Provide a single, accountable record of access requests from submission to recorded outcome, so that every decision, grant confirmation, expiration and revocation confirmation can be explained afterwards.

## Actors

| Actor | Description |
| --- | --- |
| Requester | A user who requests access for themselves and follows their own requests. |
| Resource Owner | The authority responsible for a resource, who decides requests for that resource's profiles. |
| Governance | The authority that decides Privileged requests after the Resource Owner has approved them. |

Base data such as resources, access profiles and actor references may be provided as demonstration data. An administrative actor is not a required part of the MVP flow.

## Functional concepts

- **Resource** and **Access Profile**: what can be requested. Each profile belongs to exactly one resource and is either Standard or Privileged.
- **Access Request**: a user's request for one profile, with its own lifecycle up to a terminal outcome.
- **Decision**: an approval or a rejection taken by the authority of the pending step.
- **Grant confirmation**: the record that the grant happened externally.
- **Granted Access**: the access record that exists only after grant confirmation, with its own lifecycle.
- **Expiration** and **revocation confirmation**: the two ways a Granted Access ends.
- **Functional History**: the preserved record of decisions and relevant lifecycle events of a request.

The full conceptual model is described in [architecture/domain-model.md](architecture/domain-model.md).

## Governance of decisions, not IAM

StewardArc governs the **decision** and the **record** of access. The technical grant and the technical revocation happen in external systems, outside the product, and are later confirmed in StewardArc.

This separation is expressed by the invariant **approval ≠ grant**:

- approval means the required decisions were taken;
- grant confirmation means the external grant was confirmed in the product;
- only grant confirmation creates a Granted Access and starts its effective validity period.

StewardArc is not an identity and access management platform and is not a provisioning tool.

## MVP scope

- Consult the catalog of profiles available for new requests.
- Register an access request for oneself, with duplicate prevention.
- Route each request through the Standard or the Privileged approval flow.
- Record approvals and justified rejections by the correct authority, in sequence, without self-approval.
- Record the confirmation of an external grant after all required approvals.
- Record the confirmation of an external revocation.
- End a Granted Access in the governance model when its validity period ends.
- Consult own requests, accesses and request history within each actor's scope.

## Out of scope

- Provisioning, deprovisioning or any technical change in target systems.
- Acting as an identity provider or managing authenticated identities.
- Requesting access on behalf of another user.
- Direct renewal or reactivation of an ended access; a later need requires a new request.
- A mandatory administrative actor or back-office flow.

## Success criteria

The MVP is successful when:

- every question in the [problem statement](#problem-statement) can be answered from StewardArc's records for any request handled by the product;
- approval and grant remain distinct in every flow, state and record;
- decisions can only be taken by the applicable authority, in the required sequence;
- the functional history of a request remains consistent with its decisions and state transitions.

## Risks of undue expansion

- **Drifting into IAM or provisioning**: integrating with target systems to execute grants would change the nature of the product and break the approval ≠ grant separation.
- **Adding administrative flows as prerequisites**: base data can be demonstration data; a back-office is not needed to prove the core flow.
- **Relaxing lifecycle rules for convenience**: renewals, reactivations or requests on behalf of others would reopen the functional baseline.
- **Premature technical ambition**: distribution, extra architectural styles or arbitrary performance targets without a real driver.
