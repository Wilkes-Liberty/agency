# Discovery failure and verification matrix

Write and exercise these failures before treating the contract as implemented.
Use synthetic people, identities, contacts, policies and signing keys only.

| Boundary | Failure to demonstrate |
| --- | --- |
| Identity | Unavailable or deactivated identity writes nothing; wrong issuer or subject does not consume invitation; email equality is irrelevant. |
| Authority | No entity-route/admin bypass; explicit permission plus eligible effective human plus assignment required; unrelated contexts equal missing. |
| Invitation | GET, expiry, revoke, supersede, replay and competing acceptance cannot activate a second grant or create users/roles/profiles. |
| Ordinary commands | Stale version conflicts; same key/different payload refuses; exact retry records once; response uses fresh projection. |
| Pause/proposal | Agency pause cannot be resumed by participant; date survives; participant pause retains access; decline proposal does not withdraw; move-forward requires explicit consent. |
| Required evidence | Start/accept/respond/action/pause/resume/close/move-forward roll back on local outbox failure. |
| Denial | Withdrawal/revoke/suspend with chain unavailable commit denial plus one local intent; local intent failure rolls back both and never claims success. |
| Summary | Separate exact-principal entitlement; allowlisted fields; expiry/suspension/rebinding/deactivation deny immediately; no-summary preparation failure still denies workspace. |
| Unknown Stop | Terminal retry is not-found; no new intent or grant; withdrawal→suspension→retry and failed-withdrawal→suspension→retry stay indistinguishable. |
| Draining | Missing key, append exception, marker failure and interrupted transaction retain one intent; retry/concurrent drainers append once. |
| Database | Two real PostgreSQL 16 connections prove mutex/claim blocking, rollback, savepoints and no duplicate delivery; repeat on MySQL supported-core lanes. |
| Policy | Missing name/required periods refuse; zero and malformed periods refuse; calendar month/year clamping, fraction precision and summary expiry are explicit. |
| Upgrade | An enabled foundation with existing configuration/user data gains all five entity schemas, three integration tables and one mutex seed; repeating the update preserves existing Discovery data. |
| Privacy | Stored/chained metadata contains no contact, note, token, guessed ID, credential or protected data; no raw evidence projection. |
| Review regressions | Replay after elapsed deadlines or changed policy; prepared proposal/action parity; past-summary discovery; identity-outage indistinguishability; paused ownerless recovery with a separate restricted permission. |
| Experience follow-up | Consent leaves a dated agency obligation and allows Stop/close; a participant reply cannot postpone an existing agency promise; assigned-owner notes stay out of participant/recovery projections; reply choices and owner-only action modes are explicit. |
| Rendering facts | Workspace retention facts precede Stop; summary name fails closed when unconfigured; every invitation status has the same tenant support route, with inviter display name only on active unexpired links and no inviter contact disclosure; used-link reopening respects current binding. |
| Acceptance coverage | Invalid close inputs change neither state nor evidence; a second synthetic tenant changes only configuration; draining a Stop intent never restores workspace access. |
| Contact and note follow-up | Fallback support is an explicit typed route on workspace and invitation reads; text-only, missing, malformed or oversized policy refuses with policy_unavailable without preparing invitations. Such policy failure cannot prevent Stop or restore access. A resumed relationship no longer projects the earlier pause note. |
| Portability | A second synthetic tenant changes only config; package creates no CRM/worker records and sends no notifications. |

Kernel/service tests verify domain contracts, not browser accessibility or host
authentication. Host integration must prove the existing classified staff-invite
flow still works, and the participant UI must pass keyboard/mobile, copy,
translation and full two-actor experience checks before release. CI must execute
the domain tests, not just discover them. A passing engine lane without the
two-connection cases is insufficient concurrency evidence.
