# ADR-0005 — Bridge.xyz direct integration as the v1 fiat ramp rail

**Status:** Accepted
**Date:** 2026-05-23
**Decision-maker:** Backend engineer
**Related:** `docs/BACKEND_HANDOVER_BRIDGE_RAMP.md` (mobile-authored brief, §1, §3.7); supersedes `docs/superpowers/specs/2026-04-12-stripe-bridge-ramp-design.md`

---

## Context

Mobile is shipping a ramp surface in v1.x. Three options have been on the table at various points, with internal language conflating two distinct Stripe products under the same "Stripe Bridge" label:

- **Stripe Crypto Onramp** — hosted card-payment checkout. The existing `StripeBridgeProvider` + `StripeBridgeService` in `app/Domain/Ramp/` is scaffolding for *this* product, not for Bridge.xyz. 1.5–3% per transaction, on-ramp only (no off-ramp in their public API), ~6-month "audition" before Stripe will discuss a cards path.
- **Onramper** — aggregator over many providers. $200/month subscription floor plus per-tx fees. Cost-prohibitive at our launch scale.
- **Bridge.xyz (direct KYB)** — the stablecoin orchestration company Stripe acquired in 2024, available as a direct partner. 10 bps + network + ≤1% FX spread; covers onramp + offramp + cards path in one integration; bundled regulated KYC; KYB invitation in hand skips the audition entirely.

The "Stripe Bridge" naming in the prior memory and codebase was a conflation between Stripe Crypto Onramp and Bridge.xyz. They are two different Stripe products with different APIs, contracts, and pricing. This ADR commits to the second one and removes the conflation from the codebase.

The Bridge.xyz KYB invitation is the load-bearing trigger: it is the form of access most teams have to wait months to negotiate, and it has expiry timing. Locking the decision in time to act on the invite is the practical pressure that elevated this to an ADR-worthy moment.

---

## Decision

**Bridge.xyz is the v1 primary fiat ↔ stablecoin rail.** A new `BridgeProvider` implementing the existing `RampProviderInterface` slots alongside `MockRampProvider` / `OnramperProvider` under the existing seam in `app/Domain/Ramp/Providers/`.

**No hosted card-payment onramp in v1.** Bridge's primary onramp rail is bank transfer (ACH push, SEPA, SEPA Instant via virtual accounts). Card-payment onramp ships in v1.1 only if activation data shows we lose more than 30% of users at "wait 1–3 days for ACH" — and the v1.1 fallback is the *renamed* Stripe Crypto Onramp provider, not Bridge.

**The existing `StripeBridgeProvider` is renamed**, not deleted. Rationale below.

---

## Alternatives considered

### (α) Stripe Crypto Onramp (the existing scaffolding)

Adopt the in-tree `StripeBridgeProvider`/`StripeBridgeService` as the v1 ramp rail.

**Pros:** code is already written; hosted UX is excellent (cards, Apple Pay, Google Pay); fast time-to-funds; users are familiar with Stripe checkout shells.

**Rejected because:** 1.5–3% per transaction is too expensive for our pricing envelope (Pro tier at €4.99/month requires ~€665/month ramp volume per Pro user to break even on Bridge fees alone; Stripe Crypto Onramp pushes that 5–6× higher and there is no per-customer markup mechanism to recover it); on-ramp only — no off-ramp in their public API, leaving the "Sell" surface unmet; ~6-month audition before any cards-issuing conversation; no first-party stablecoin orchestration (we'd still need Bridge.xyz or an equivalent for off-ramp regardless).

### (β) Onramper (aggregator)

Use Onramper as the front door and let it route to underlying providers.

**Rejected because:** $200/month subscription floor is unjustifiable at our launch volume; per-tx fees on top of subscription mean we pay twice; aggregator latency on quote/settlement APIs; one more vendor in the dependency graph for a layer that adds little once Bridge.xyz is already direct.

### (γ) Bridge.xyz direct integration — **chosen**

Direct KYB partnership with Bridge.xyz.

**Pros:** 10 bps + network + ≤1% FX spread (lowest blended cost of the three by a wide margin); covers onramp (ACH/SEPA/SEPA Instant) **and** offramp (ACH/SEPA/SWIFT to 30+ currencies) in one integration — single contract, single webhook surface, single KYC flow; **first-party support for per-transaction developer fees** (see [ADR-0006](0006-bridge-developer-fees-as-markup-mechanism.md)) so the Free-tier 0.75% Zelta markup is collected through the rail itself, not via out-of-band billing; bundled regulated KYC under Bridge's wrapper means we don't need to be a money-services business for ramp; the same Bridge customer record extends to Stripe Issuing cards in a future card-program slice with no separate KYB ceremony; the KYB invitation skips the audition that Stripe Crypto Onramp would impose.

**Cons:** no hosted card-payment onramp in v1 (users wait 1–3 days for ACH the first time; SEPA Instant is faster for EU); requires Bridge's own KYC ceremony — partitioned from Ondato per §7.5 of the handover, which means users who already completed Ondato TrustCert KYC will run Bridge KYC as a second ceremony; the existing `StripeBridgeProvider` code becomes dead weight unless renamed.

### (δ) Bridge.xyz + Stripe Crypto Onramp parallel (cards as fast-lane, ACH as cheap-lane)

Build both, let users pick at checkout.

**Rejected (for v1) because:** doubles the implementation surface for a launch where we have no activation data justifying the cards path; introduces a routing/UX decision (which provider does the user pick? do we pre-select based on amount?) that adds product complexity without validated need. Deferred to v1.1 contingent on the >30% drop-off signal.

---

## Consequences

### Positive

- **Unit economics work.** At 10 bps + at-cost network + 0.75% Zelta markup, Free-tier ramp covers Bridge's invoice and contributes positive margin without making us uncompetitive against the cheapest banking-aggregator UX. Pro waiver (no Zelta markup) is cheap to absorb.
- **One vendor, one contract, one KYC ceremony per user.** Onramp + offramp + cards path all flow through the same Bridge customer record. We don't have to glue together separate vendors for each surface.
- **Non-custody intact.** Bridge wires fiat → USDC → user's wallet directly. We are the orchestration interface, never the funds-holder. See `BACKEND_HANDOVER_BRIDGE_RAMP.md` §7 for the merge-gate invariants.
- **Future cards optionality is preserved cheaply.** Stripe Issuing on the stablecoin rail is Bridge's native cards offering — no second integration required when we get there.
- **Existing `RampProviderInterface` seam holds.** `BridgeProvider` is "just another provider"; the seven-case contract test in `RampProviderContractTest` parameterizes over it. No domain-level refactor.

### Negative

- **No card-payment onramp in v1.** New EU users with SEPA Instant get money in within hours. New US users on ACH wait 1–3 days for the first deposit. This is the v1 acceptance trade-off. Activation data over the first 90 days informs whether v1.1 needs the Stripe Crypto Onramp fallback.
- **Second KYC ceremony.** Users who completed Ondato TrustCert KYC will redo identity verification under Bridge. §7.5 of the brief forbids conflating the two datasets, so we can't bypass Bridge's KYC even when Ondato data is technically equivalent. Mitigation: an email to Bridge BD about reusable partner KYC was opened (handover §8 Q3); not blocking v1.
- **Bridge developer-fee mechanic forces per-customer fee defaults** (see ADR-0006) — Pro upgrade/downgrade requires a PATCH to the Bridge customer record. A user who upgrades mid-billing-cycle and immediately wires fiat to their virtual account will pay the new (0%) rate; we cannot retroactively waive fees on transfers Bridge has already initiated.
- **The existing `StripeBridgeProvider` scaffolding sits in the codebase as a renamed, disabled provider** (`StripeCryptoOnrampProvider`). This is dead weight until/unless v1.1 enables it. We chose to *rename rather than delete* (handover §3.6) so the contract-conformant work isn't lost — but it costs code-review surface area.

### Neutral

- **v1 ramp network = Polygon USDC only.** Matches the mobile default network; ramped USDC lands where smart-account, privacy-shielding, and future cards features already operate. Solana + Base + Arbitrum ship in v1.1 by extending the `network` param accepted on `POST /api/v1/ramp/session`.
- **SWIFT payouts are deferred to v1.1.** Bridge offers SWIFT at $15–30/wire but v1 restricts to ACH + SEPA + SEPA Instant per handover §8 Q2.

---

## Implementation notes

- New `BridgeProvider` + `BridgeService` under `app/Domain/Ramp/`. Implements existing `RampProviderInterface` with no signature changes except the additive `network: ?string` param on `createSession` (default Polygon for v1; required for v1.1).
- New encrypted `ramp_sessions.deposit_instructions` column for the Bridge onramp shape (no checkout URL — fiat lands at an account number / IBAN + memo). Mobile reads it as a top-level field on the session response.
- Existing `StripeBridgeProvider` + `StripeBridgeService` renamed to `StripeCryptoOnramp*`; `enabled: false` by default in `config/ramp.php`; env var `STRIPE_BRIDGE_WEBHOOK_SECRET` renamed to `STRIPE_CRYPTO_ONRAMP_WEBHOOK_SECRET`.
- KYC partitioning enforced at the schema level — Bridge KYC state lives in a new `bridge_customers` table, not on `users.kyc_status` (which remains Ondato's). See the migration scaffolding in this branch.
- Markup collection via Bridge developer fees — see [ADR-0006](0006-bridge-developer-fees-as-markup-mechanism.md).

---

## References

- `docs/BACKEND_HANDOVER_BRIDGE_RAMP.md` — mobile-authored brief, the source material for this ADR
- `docs/superpowers/specs/2026-04-12-stripe-bridge-ramp-design.md` — superseded design (Stripe Crypto Onramp); kept for git-blame context
- `docs/adr/0006-bridge-developer-fees-as-markup-mechanism.md` — sibling ADR on how the 0.75% Zelta markup is actually collected
- Bridge API docs — https://apidocs.bridge.xyz/
- Bridge developer fees — https://apidocs.bridge.xyz/platform/orchestration/fees-and-mins/devfees
- Bridge virtual accounts — https://apidocs.bridge.xyz/get-started/guides/move-money/virtualaccounts
