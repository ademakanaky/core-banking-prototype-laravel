<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Certificate Authority Configuration
    |--------------------------------------------------------------------------
    */
    'certificate_authority' => [
        'ca_id'            => env('TRUSTCERT_CA_ID', 'finaegis-root-ca'),
        'ca_signing_key'   => env('TRUSTCERT_CA_SIGNING_KEY'),
        'default_validity' => [
            'days' => 365,
        ],
        'max_chain_depth' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Credential Signing Configuration
    |--------------------------------------------------------------------------
    */
    'credentials' => [
        'credential_signing_key'   => env('TRUSTCERT_CREDENTIAL_SIGNING_KEY'),
        'presentation_signing_key' => env('TRUSTCERT_PRESENTATION_SIGNING_KEY'),
        'default_issuer'           => env('TRUSTCERT_DEFAULT_ISSUER', 'did:finaegis:issuer:default'),
        'supported_proof_types'    => [
            'Ed25519Signature2020',
            'JsonWebSignature2020',
        ],
        'context_urls' => [
            'https://www.w3.org/2018/credentials/v1',
            'https://www.w3.org/ns/did/v1',
            'https://w3id.org/security/suites/ed25519-2020/v1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Revocation Registry Configuration
    |--------------------------------------------------------------------------
    */
    'revocation' => [
        'enabled'            => true,
        'cache_ttl'          => 300, // seconds
        'batch_check_limit'  => 100,
        'status_list_format' => 'StatusList2021',
    ],

    /*
    |--------------------------------------------------------------------------
    | Trust Framework Configuration
    |--------------------------------------------------------------------------
    */
    'trust_framework' => [
        'enabled'              => true,
        'require_chain'        => false, // Require complete chain for verification
        'max_chain_depth'      => 10,
        'default_trust_level'  => 'basic',
        'allowed_issuer_types' => [
            'root_ca',
            'intermediate_ca',
            'issuing_ca',
            'trusted_issuer',
            'delegated_issuer',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trust Level Requirements
    |--------------------------------------------------------------------------
    */
    'trust_levels' => [
        'unknown' => [
            'requirements' => [],
        ],
        'basic' => [
            'requirements' => [
                'email_verified' => true,
            ],
        ],
        'verified' => [
            'requirements' => [
                'email_verified'    => true,
                'identity_verified' => true,
            ],
        ],
        'high' => [
            'requirements' => [
                'email_verified'    => true,
                'identity_verified' => true,
                'kyc_completed'     => true,
            ],
        ],
        'ultimate' => [
            'requirements' => [
                'email_verified'    => true,
                'identity_verified' => true,
                'kyc_completed'     => true,
                'audit_completed'   => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification Options
    |--------------------------------------------------------------------------
    */
    'verification' => [
        'check_expiration' => true,
        'check_revocation' => true,
        'verify_proof'     => true,
        'verify_issuer'    => true,
        'cache_results'    => true,
        'cache_ttl'        => 60, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification Fees
    |--------------------------------------------------------------------------
    |
    | KYC / verification fee schedule. `enabled` is the master switch: when
    | false (the launch default) verification is free — the API reports a
    | $0 fee for every level so the mobile "fee = 0 -> no payment step" path
    | applies, and the pay endpoints reject. Paid verification can be turned
    | back on later without an app release.
    |
    | Note: charging for a digital service outside Play Billing risks Android
    | store rejection — only re-enable once the IAP rail is production-ready.
    |
    */
    'verification_fees' => [
        'enabled' => (bool) env('TRUSTCERT_VERIFICATION_FEES_ENABLED', false),

        // Fee schedule by numeric trust level (USD). Applied only when enabled.
        'level_fees' => [
            1 => '4.99',
            2 => '4.99',
            3 => '9.99',
            4 => '9.99',
        ],

        // IAP product IDs by numeric level — must exist in App Store Connect
        // and Play Console before the IAP rail can be used.
        'iap_product_ids' => [
            1 => 'kyc_verification_level_1',
            2 => 'kyc_verification_level_2',
            3 => 'kyc_verification_level_3',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | W3C Standards Compliance
    |--------------------------------------------------------------------------
    */
    'w3c' => [
        'vc_data_model_version' => '1.1',
        'did_method'            => 'did:finaegis',
        'status_list_version'   => '2021',
    ],
];
