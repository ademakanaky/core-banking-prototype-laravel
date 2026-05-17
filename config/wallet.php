<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Solana Send Configuration
    |--------------------------------------------------------------------------
    */

    'solana' => [
        // Public RPC endpoint used to broadcast signed transactions.
        // Helius mainnet RPC supports api-key as query param.
        'rpc_url' => (string) env('HELIUS_RPC_URL', 'https://mainnet.helius-rpc.com'),
        // Confirmation level required before treating a tx as confirmed.
        // Options: processed | confirmed | finalized
        'commitment' => (string) env('SOLANA_COMMITMENT', 'confirmed'),
        // Microlamports per compute unit for priority fee. Bump under congestion.
        'priority_fee_microlamports' => (int) env('SOLANA_PRIORITY_FEE_MICROLAMPORTS', 1000),
        // Compute unit limit for a single SPL transfer (with optional ATA create).
        'compute_unit_limit' => (int) env('SOLANA_COMPUTE_UNIT_LIMIT', 200000),
    ],

    /*
    |--------------------------------------------------------------------------
    | EVM Send Configuration
    |--------------------------------------------------------------------------
    */

    'evm' => [
        // Networks enabled for outbound dispatch. Mobile validates against
        // PaymentNetwork enum, so values must match (lowercase casing).
        //
        // Ethereum L1 is intentionally excluded by default: a single L1 send
        // can cost $1-$20+ in gas (vs fractions of a cent on the L2s), and we
        // sponsor that gas. The L2s — polygon/base/arbitrum — cover real usage
        // cheaply. Re-add 'ethereum' only with a deliberate cost decision.
        'enabled_networks' => array_filter(array_map(
            'trim',
            explode(',', (string) env('WALLET_SEND_EVM_NETWORKS', 'polygon,base,arbitrum')),
        )),
        // Token used to pay sponsorship fee. Must be supported by the paymaster.
        'fee_token' => (string) env('WALLET_SEND_EVM_FEE_TOKEN', 'USDC'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sponsored-Send Abuse Guardrail
    |--------------------------------------------------------------------------
    |
    | Gas-sponsored sends (EVM via the Pimlico paymaster, Solana via the fee
    | payer) cost the platform real money per transaction. These caps bound
    | that exposure: a per-user daily limit stops a single scripted account,
    | and a global daily ceiling is a kill-switch against a mass-abuse spike.
    | Counts reset at 00:00 UTC. Defaults are generous enough to be invisible
    | to real users.
    |
    */

    'sponsorship' => [
        'per_user_daily_limit' => (int) env('WALLET_SEND_PER_USER_DAILY_LIMIT', 30),
        'global_daily_limit'   => (int) env('WALLET_SEND_GLOBAL_DAILY_LIMIT', 5000),
    ],
];
