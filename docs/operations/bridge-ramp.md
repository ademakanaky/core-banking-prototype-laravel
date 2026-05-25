# Bridge.xyz Ramp Operator Runbook

Operator-only runbook for diagnosing and reconciling Bridge.xyz integrations. Paired with the design docs at `docs/adr/0005-bridge-xyz-over-stripe-crypto-onramp.md` and `docs/adr/0006-bridge-developer-fees-as-markup-mechanism.md`.

## When to use this runbook

- A mobile user reports *"I completed KYC but can't see the bank details"* (VA provisioning failed or deferred).
- A mobile user reports *"my deposit went missing"* (webhook didn't land or session lookup failed).
- A mobile user reports *"I upgraded to Pro but I'm still being charged the markup"* (dev-fee PATCH didn't fire).
- Pre-prod sanity check before flipping `RAMP_PROVIDER=bridge` on a new environment.
- Pre-flight after rotating Bridge credentials.

## Environment & credentials

Required `.env` keys (see also `.env.production.example`):

```ini
RAMP_PROVIDER=bridge                       # canonical; deprecated alias `stripe_bridge` still resolved
BRIDGE_API_KEY=<from Bridge dashboard>
BRIDGE_WEBHOOK_SECRET=<from Bridge dashboard>
BRIDGE_API_BASE_URL=https://api.bridge.xyz # or sandbox URL when set up
BRIDGE_DEFAULT_DEV_FEE_BPS=75              # Free tier; Pro is 0 (per ADR-0006)
```

`KYC_RAMP_PROVIDER` and `KYC_CARDS_PROVIDER` both default to `bridge` in `config/kyc.php`; `KYC_TRUSTCERT_PROVIDER` stays on `ondato`.

## Bridge dashboard configuration

Configure the Bridge dashboard to POST all webhook events to:

```
https://<your-domain>/api/v1/webhooks/bridge
```

The single endpoint handles both KYC events (`customer.kyc_link_*`) and ramp events (`virtual_account.activity`, `transfer.*`). Event-level dedupe via `processed_webhook_events (provider='bridge', event_id)`.

**Critical TODO before production**: `BridgeWebhookVerifier` currently assumes a Stripe-style `t=<ts>,v1=<hex_hmac>` signature header format. **Verify against a real Bridge sandbox webhook payload before first prod deploy.** If Bridge's actual scheme differs, update `BridgeWebhookVerifier::verify`. The file has an inline `TODO` marker.

## Inspecting a single user

First thing to run when triaging any Bridge-related user report:

```bash
php artisan bridge:inspect-user user@example.com
php artisan bridge:inspect-user user@example.com --sessions=20  # last 20 ramp sessions
```

Output sections (all read-only — never writes):

| Section | What to look for |
|---|---|
| **User** | `id`, `uuid`, `email`, Ondato `kyc_status` (separate from Bridge KYC per §7.5) |
| **Bridge customer** | `bridge_customer_id`, `kyc_status`, `virtual_account_id`, deposit details summary, `supported_rails`, `developer_fee_bps`, timestamps |
| **Polygon address** | Whether a Polygon address is registered. **VA provisioning depends on this.** If missing, VA was deferred — see "VA never created" below |
| **Recent ramp_sessions** | Per-session: id, type (`on`/`off`), source (`user_initiated`/`bridge_initiated`), status, fiat/crypto amounts, `provider_session_id`, `created_at` |

## Common scenarios

### KYC approved but VA never created (`virtualAccountReady=false`)

Most common cause: user completed KYC before mobile finished wallet setup, so there was no Polygon `blockchain_addresses` row at the moment `customer.kyc_link_completed` arrived. `BridgePostKycHandler` logged `deferring VA provisioning — no Polygon address yet`.

Resolution paths (in order of preference):

1. **Wait for the auto-retry** — `BlockchainAddressBridgeObserver` registers VA provisioning the moment a Polygon address gets added. If the user re-opens the app and wallet setup completes, the VA shows up automatically. **Confirm via `bridge:inspect-user` 60s after the user retries.**
2. **Manually trigger from tinker** if the user has the address but the observer didn't fire (e.g., the address pre-existed and KYC completed in a different flow):
   ```php
   php artisan tinker
   >>> $customer = \App\Domain\Compliance\Kyc\Models\BridgeCustomer::where('user_id', $userId)->first();
   >>> app(\App\Domain\Compliance\Kyc\Services\BridgePostKycHandler::class)->tryProvisionVirtualAccount($customer);
   >>> // re-inspect with `bridge:inspect-user` to confirm
   ```
3. **Last resort**: if Bridge keeps rejecting the VA call (logged as `BridgePostKycHandler: VA provisioning failed`), open a ticket with Bridge support. Do NOT manually insert a fake `virtual_account_id` — downstream webhook flows depend on it being the real Bridge id.

### Deposit didn't show up in the app

Check, in order:

1. **Did Bridge fire the webhook?** Grep logs for the user's `bridge_customer_id` or `virtual_account_id`. If no webhook, check Bridge dashboard delivery logs.
2. **Did our endpoint reject it?** Look for `Bridge webhook: invalid signature` (401) or `Bridge webhook: invalid JSON body` (400). The former means signature scheme drift (see TODO above) or a rotated secret.
3. **Was the event deduped?** A `Bridge webhook: duplicate event ignored` log means we already processed an event with that `id`. If the deposit really didn't land, Bridge may have reused an `id` — open a ticket.
4. **Was the session not found?** `Bridge ramp webhook: session not found` means the `virtual_account_id` in the payload doesn't match any of our `bridge_customers` rows. Confirm the user's `virtual_account_id` matches the one in the webhook payload via `bridge:inspect-user`.
5. **For unsolicited deposits** (user wired fiat without going through `POST /api/v1/ramp/session`): the handler auto-creates a retroactive `ramp_sessions` row with `source: 'bridge_initiated'`. If this didn't happen, the bridge_customers lookup by `virtual_account_id` failed — check the inspect output for VA mismatch.

### Pro upgrade didn't waive the markup

The auto-trigger is wired via `SubscriptionTierChanged` event → `SyncBridgeDevFeeOnTierChange` listener (queued on `events`). Check, in order:

1. **Is the queue running?** Listener is `ShouldQueue` — if no queue worker is processing `events`, the PATCH never happens.
2. **What does the listener log?** Look for `BridgeDeveloperFeeSync: dev fee updated` (success) or `BridgeDeveloperFeeSync: PATCH failed` (Bridge rejected).
3. **Manual reconciliation** if the user is mid-issue:
   ```bash
   php artisan bridge:sync-dev-fee --email=user@example.com
   # Output: "user@example.com: developer_fee_bps now 0." (success) or
   #         "No bridge_customers row for ... (KYC not started)." (no-op)
   ```
4. **Batch backfill** before flipping a feature flag or after an extended outage:
   ```bash
   php artisan bridge:sync-dev-fee --all --dry-run   # preview
   php artisan bridge:sync-dev-fee --all              # apply
   ```

### Webhook secret rotation

1. Generate the new secret in the Bridge dashboard. **Do not yet remove the old secret.**
2. Set `BRIDGE_WEBHOOK_SECRET` in the deployed `.env` to the new value.
3. Smoke-test: trigger a webhook from Bridge sandbox or wait for the next live event. Expect `Bridge webhook: invalid signature` in logs if the new secret is wrong → roll back to old secret.
4. Once the new secret verifies cleanly, remove the old secret from Bridge dashboard.

`BridgeWebhookVerifier::verify` returns `true` for empty secret in non-production environments only (dev convenience). Production with an empty secret returns `false` → 401 on every webhook. **Confirm the secret is set before going live on a new deployment.**

## Provider rename ops note

The provider previously called `stripe_bridge` is now canonically `stripe_crypto_onramp` (per ADR-0005 — the name was a conflation between Stripe Crypto Onramp and Bridge.xyz). The legacy string is still recognized as a deprecation alias:

- `RAMP_PROVIDER=stripe_bridge` resolves to `StripeCryptoOnrampProvider` with a logged warning.
- `STRIPE_BRIDGE_WEBHOOK_SECRET` is still read as a fallback when `STRIPE_CRYPTO_ONRAMP_WEBHOOK_SECRET` is unset.
- Historical `ramp_sessions.provider='stripe_bridge'` rows still resolve via the registry alias.

Update deployed `.env` files to the canonical names at your leisure; alias removal is targeted for v1.1.

## Pre-flight after rotating Bridge credentials

```bash
# 1. Update .env
BRIDGE_API_KEY=<new key>
BRIDGE_WEBHOOK_SECRET=<new webhook secret>

# 2. Restart workers (so the queued SyncBridgeDevFeeOnTierChange listener picks up the new BridgeClient)
php artisan queue:restart

# 3. Smoke-test API key with a no-op inspection
php artisan bridge:inspect-user <some staging user>
# Confirm the bridge_customer section renders without errors.

# 4. Smoke-test webhook signature with a sandbox event
# (or wait for the next live webhook and grep logs for invalid_signature)
```

## Escalation

- **Bridge integration silently broken for >5 min on prod**: check the auto-VA path log line `BridgePostKycHandler: VA provisioning failed` — if it's the cause, page Bridge support with the failed `bridge_customer_id` list (pull from `bridge_customers WHERE kyc_status='approved' AND virtual_account_id IS NULL ORDER BY updated_at DESC`).
- **Signature scheme mismatch suspected**: do NOT toggle the empty-secret bypass in production. Capture a real failing payload (raw body + signature header) and verify against Bridge's documented signature format. Update `BridgeWebhookVerifier::verify` in code.
- **Pro user complaining about markup persisting**: run `bridge:sync-dev-fee --email=<addr>` first; if the dev fee was already 0 then the issue is in the quote-display layer (`RampService::getQuotes`), not Bridge.

## Files referenced

- `app/Domain/Compliance/Kyc/Models/BridgeCustomer.php` — the per-user state
- `app/Domain/Compliance/Kyc/Services/BridgePostKycHandler.php` — VA provisioning + WS + push
- `app/Domain/Compliance/Kyc/Services/BridgeDeveloperFeeSync.php` — dev-fee PATCH
- `app/Domain/Compliance/Kyc/Listeners/SyncBridgeDevFeeOnTierChange.php` — auto-trigger
- `app/Domain/Compliance/Kyc/Observers/BlockchainAddressBridgeObserver.php` — VA retry
- `app/Http/Controllers/Api/V1/BridgeWebhookController.php` — webhook dispatcher
- `app/Http/Controllers/Api/V1/BridgeSetupController.php` — mobile-facing setup endpoints
- `app/Infrastructure/Bridge/BridgeClient.php` — HTTP wrapper
- `app/Infrastructure/Bridge/BridgeWebhookVerifier.php` — signature verification (TODO marker inside)
- `app/Console/Commands/BridgeInspectUserCommand.php`
- `app/Console/Commands/BridgeSyncDevFeeCommand.php`
- `docs/adr/0005-bridge-xyz-over-stripe-crypto-onramp.md`
- `docs/adr/0006-bridge-developer-fees-as-markup-mechanism.md`
- `docs/BACKEND_HANDOVER_BRIDGE_RAMP.md` (original mobile-authored brief)
