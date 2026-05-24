<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Domain\Compliance\Kyc\Services\BridgeDeveloperFeeSync;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator command: reconcile each Bridge customer's per-customer
 * `developer_fee_bps` with the user's current subscription tier per
 * ADR-0006. Use cases:
 *
 *   - One-off fix after a manual tier change in admin:
 *       php artisan bridge:sync-dev-fee --email=user@example.com
 *
 *   - Batch backfill (e.g. after a billing event the auto-trigger
 *     missed, or before flipping a feature flag that depends on
 *     correct dev-fee defaults):
 *       php artisan bridge:sync-dev-fee --all
 *
 * Until the Subscription domain emits a SubscriptionTierChanged event
 * we can hook automatically, this command is the source of truth for
 * keeping the per-customer default correct on Pro upgrade / downgrade.
 *
 * @see docs/adr/0006-bridge-developer-fees-as-markup-mechanism.md
 */
class BridgeSyncDevFeeCommand extends Command
{
    /** @var string */
    protected $signature = 'bridge:sync-dev-fee
                            {--email= : Sync a single user by email}
                            {--all : Sync every user with a bridge_customers row}
                            {--dry-run : Print intended changes without PATCHing Bridge}';

    /** @var string */
    protected $description = 'Reconcile each Bridge customer\'s per-customer developer_fee_bps with the user\'s tier';

    public function handle(BridgeDeveloperFeeSync $sync): int
    {
        $email = $this->option('email');
        $all = (bool) $this->option('all');
        $dryRun = (bool) $this->option('dry-run');

        if ($email === null && ! $all) {
            $this->error('Provide --email=<user@example.com> or --all.');

            return 1;
        }

        if ($email !== null && $all) {
            $this->error('--email and --all are mutually exclusive.');

            return 1;
        }

        if ($email !== null) {
            return $this->syncOne((string) $email, $sync, $dryRun);
        }

        return $this->syncAll($sync, $dryRun);
    }

    private function syncOne(string $email, BridgeDeveloperFeeSync $sync, bool $dryRun): int
    {
        $user = User::where('email', $email)->first();
        if ($user === null) {
            $this->error("User not found: {$email}");

            return 1;
        }

        return $this->process($user, $sync, $dryRun);
    }

    private function syncAll(BridgeDeveloperFeeSync $sync, bool $dryRun): int
    {
        $count = BridgeCustomer::count();
        if ($count === 0) {
            $this->info('No bridge_customers rows to sync.');

            return 0;
        }

        $this->info("Syncing {$count} Bridge customers...");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $failures = 0;

        BridgeCustomer::query()
            ->select(['user_id', 'developer_fee_bps'])
            ->cursor()
            ->each(function (BridgeCustomer $customer) use ($sync, $dryRun, $bar, &$failures): void {
                $user = User::find($customer->user_id);
                if ($user === null) {
                    $failures++;
                    $bar->advance();

                    return;
                }

                try {
                    if ($dryRun) {
                        $bar->advance();

                        return;
                    }
                    $sync->syncForUser($user);
                } catch (Throwable $e) {
                    $failures++;
                }

                $bar->advance();
            });

        $bar->finish();
        $this->newLine(2);

        if ($failures > 0) {
            $this->warn("{$failures} customer(s) failed — see logs for details.");

            return 2;
        }

        $this->info('All customers synced.');

        return 0;
    }

    private function process(User $user, BridgeDeveloperFeeSync $sync, bool $dryRun): int
    {
        $customer = BridgeCustomer::where('user_id', $user->id)->first();
        if ($customer === null) {
            $this->warn("No bridge_customers row for {$user->email} (KYC not started).");

            return 0;
        }

        if ($dryRun) {
            $this->line(sprintf(
                'DRY-RUN: would sync %s — current developer_fee_bps=%d',
                $user->email,
                $customer->developer_fee_bps,
            ));

            return 0;
        }

        $result = $sync->syncForUser($user);

        if ($result === null) {
            $this->error("Unexpected: bridge_customers row vanished mid-sync for {$user->email}.");

            return 1;
        }

        $this->info(sprintf('%s: developer_fee_bps now %d.', $user->email, $result));

        return 0;
    }
}
