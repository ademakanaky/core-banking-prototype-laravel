<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Models\BlockchainAddress;
use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Models\RampSession;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Console\Command;

/**
 * Operator query: print everything Bridge-related for a single user. Reads
 * only — never writes. Mirror of the wallet:inspect-user pattern.
 *
 * Use when a mobile user reports "I completed KYC but can't see the bank
 * details" or "deposit went missing":
 *
 *   php artisan bridge:inspect-user user@example.com
 *   php artisan bridge:inspect-user user@example.com --sessions=20
 */
class BridgeInspectUserCommand extends Command
{
    protected $signature = 'bridge:inspect-user
        {email : User email to look up}
        {--sessions=10 : How many recent ramp_sessions to print}';

    protected $description = 'Print Bridge customer, virtual account, Polygon address, and recent ramp sessions for a user';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error(sprintf('No user with email "%s".', $email));

            return self::FAILURE;
        }

        $this->printUserSection($user);
        $this->newLine();
        $this->printBridgeCustomerSection($user);
        $this->newLine();
        $this->printPolygonAddressSection($user);
        $this->newLine();
        $this->printSessionsSection($user, (int) $this->option('sessions'));

        return self::SUCCESS;
    }

    private function printUserSection(User $user): void
    {
        $this->info('User');
        $this->table(
            ['Field', 'Value'],
            [
                ['id', (string) $user->id],
                ['uuid', $user->uuid],
                ['email', $user->email],
                ['kyc_status (Ondato/TrustCert)', (string) ($user->kyc_status ?? 'not_started')],
            ],
        );
    }

    private function printBridgeCustomerSection(User $user): void
    {
        $this->info('Bridge customer');
        $customer = BridgeCustomer::where('user_id', $user->id)->first();

        if ($customer === null) {
            $this->line('  (no bridge_customers row — user has never started Bridge KYC)');

            return;
        }

        $rails = $customer->supported_rails === null
            ? '(none)'
            : implode(', ', $customer->supported_rails);

        $linkExpires = $customer->kyc_link_expires_at instanceof DateTimeInterface
            ? $customer->kyc_link_expires_at->format(DateTimeInterface::ATOM)
            : '(none)';

        $vaDetailsSummary = $customer->virtual_account_details === null
            ? '(none)'
            : 'iban=' . ($customer->virtual_account_details['iban'] ?? '?')
                . ', memo=' . ($customer->virtual_account_details['memo'] ?? '?');

        $this->table(
            ['Field', 'Value'],
            [
                ['bridge_customer_id', $customer->bridge_customer_id],
                ['kyc_status', $customer->kyc_status],
                ['kyc_link_expires_at', $linkExpires],
                ['virtual_account_id', $customer->virtual_account_id ?? '(not provisioned)'],
                ['virtual_account_details', $vaDetailsSummary],
                ['supported_rails', $rails],
                ['developer_fee_bps', (string) $customer->developer_fee_bps],
                ['created_at', $customer->created_at->format(DateTimeInterface::ATOM)],
                ['updated_at', $customer->updated_at->format(DateTimeInterface::ATOM)],
            ],
        );
    }

    private function printPolygonAddressSection(User $user): void
    {
        $this->info('Polygon address (VA provisioning depends on this)');

        $address = BlockchainAddress::where('user_uuid', $user->uuid)
            ->where('chain', 'polygon')
            ->first();

        if ($address === null) {
            $this->line('  (no polygon address registered — VA provisioning would defer)');

            return;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['address', $address->address],
                ['is_active', $address->is_active ? 'yes' : 'no'],
                ['created_at', $address->created_at?->format(DateTimeInterface::ATOM) ?? '(unknown)'],
            ],
        );
    }

    private function printSessionsSection(User $user, int $limit): void
    {
        $this->info(sprintf('Recent ramp_sessions (limit %d)', $limit));

        $sessions = RampSession::where('user_id', $user->id)
            ->where('provider', 'bridge')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        if ($sessions->isEmpty()) {
            $this->line('  (no Bridge ramp sessions)');

            return;
        }

        $this->table(
            ['id', 'type', 'source', 'status', 'fiat', 'crypto', 'provider_session_id', 'created_at'],
            $sessions->map(function (RampSession $s): array {
                return [
                    substr((string) $s->id, 0, 8) . '…',
                    $s->type,
                    $s->source,
                    $s->status,
                    sprintf('%s %s', $s->fiat_amount ?? '?', $s->fiat_currency),
                    sprintf('%s %s', $s->crypto_amount ?? '?', $s->crypto_currency),
                    (string) ($s->provider_session_id ?? ''),
                    $s->created_at->format(DateTimeInterface::ATOM),
                ];
            })->toArray(),
        );
    }
}
