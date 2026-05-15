<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Services;

use App\Domain\Mobile\Models\MobileDevice;
use App\Domain\Mobile\Models\MobilePushNotification;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * Service for sending push notifications to mobile devices.
 *
 * Supports Firebase Cloud Messaging (FCM) HTTP v1 API for Android and iOS
 * via the kreait/firebase-php SDK.
 */
class PushNotificationService
{
    public function __construct(
        private readonly ?Messaging $messaging = null,
    ) {
    }

    /**
     * Send a push notification to a user on all their devices.
     *
     * @param array<string, mixed> $data
     * @return array<string, array<string, mixed>>
     */
    public function sendToUser(
        User $user,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?string $scheduledAt = null
    ): array {
        $devices = MobileDevice::where('user_id', $user->id)
            ->active()
            ->withPushToken()
            ->get();

        $results = [];

        if ($devices->isEmpty()) {
            // No push-capable device registered (e.g. after a Privy account
            // switch the device row is still bound to the previous user).
            // Persist the in-app notification record anyway so the notification
            // feed and unread count stay consistent — push delivery resumes
            // once a device is registered.
            $notification = $this->createNotification(
                $user,
                null,
                $type,
                $title,
                $body,
                $data,
                $scheduledAt
            );

            $results['no_device'] = ['status' => 'recorded', 'notification_id' => $notification->id];
        }

        foreach ($devices as $device) {
            $notification = $this->createNotification(
                $user,
                $device,
                $type,
                $title,
                $body,
                $data,
                $scheduledAt
            );

            if (! $scheduledAt) {
                $results[$device->id] = $this->sendNotification($notification);
            } else {
                $results[$device->id] = ['status' => 'scheduled', 'notification_id' => $notification->id];
            }
        }

        // Broadcast updated unread count for real-time UI updates
        $this->broadcastUnreadCount($user);

        return $results;
    }

    /**
     * Send a push notification to a specific device.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function sendToDevice(
        MobileDevice $device,
        string $type,
        string $title,
        string $body,
        array $data = []
    ): array {
        if (! $device->canReceivePush()) {
            return [
                'status'  => 'error',
                'message' => 'Device cannot receive push notifications',
            ];
        }

        $user = $device->user;
        if (! $user) {
            return [
                'status'  => 'error',
                'message' => 'Device has no associated user',
            ];
        }

        $notification = $this->createNotification(
            $user,
            $device,
            $type,
            $title,
            $body,
            $data
        );

        return $this->sendNotification($notification);
    }

    /**
     * Create a notification record.
     *
     * @param array<string, mixed> $data
     */
    private function createNotification(
        User $user,
        ?MobileDevice $device,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?string $scheduledAt = null
    ): MobilePushNotification {
        return MobilePushNotification::create([
            'user_id'           => $user->id,
            'mobile_device_id'  => $device?->id,
            'notification_type' => $type,
            'title'             => $title,
            'body'              => $body,
            'data'              => $data,
            'status'            => MobilePushNotification::STATUS_PENDING,
            'scheduled_at'      => $scheduledAt ? \Carbon\Carbon::parse($scheduledAt) : null,
        ]);
    }

    /**
     * Send a notification via FCM HTTP v1 API.
     *
     * @return array<string, mixed>
     */
    public function sendNotification(MobilePushNotification $notification): array
    {
        $device = $notification->mobileDevice;

        if (! $device || ! $device->push_token) {
            $notification->markAsFailed('Device or push token not found');

            return [
                'status'  => 'error',
                'message' => 'Device or push token not found',
            ];
        }

        // Check if FCM is configured (Messaging is null when credentials are absent)
        if (! $this->messaging) {
            Log::warning('FCM not configured, notification not sent', [
                'notification_id' => $notification->id,
            ]);

            // Mark as sent for development purposes
            $notification->markAsSent('dev_' . uniqid());

            return [
                'status'          => 'skipped',
                'message'         => 'FCM not configured',
                'notification_id' => $notification->id,
            ];
        }

        try {
            $response = $this->sendViaFcm($device, $notification);

            $notification->markAsSent($response['name'] ?? uniqid());

            return [
                'status'          => 'sent',
                'message_id'      => $response['name'] ?? null,
                'notification_id' => $notification->id,
            ];
        } catch (NotFound $e) {
            Log::info('Cleared invalid push token (not found)', ['device_id' => $device->id]);
            $device->update(['push_token' => null]);
            $notification->markAsFailed('Invalid token: not found');

            return [
                'status'          => 'failed',
                'error'           => 'Invalid token: not found',
                'notification_id' => $notification->id,
            ];
        } catch (InvalidMessage $e) {
            Log::info('Cleared invalid push token (invalid message)', ['device_id' => $device->id]);
            $device->update(['push_token' => null]);
            $notification->markAsFailed('Invalid token: ' . $e->getMessage());

            return [
                'status'          => 'failed',
                'error'           => 'Invalid token: ' . $e->getMessage(),
                'notification_id' => $notification->id,
            ];
        } catch (MessagingException $e) {
            Log::error('FCM messaging error', [
                'notification_id' => $notification->id,
                'error'           => $e->getMessage(),
            ]);

            $notification->markAsFailed($e->getMessage());

            return [
                'status'          => 'failed',
                'error'           => $e->getMessage(),
                'notification_id' => $notification->id,
            ];
        } catch (Exception $e) {
            Log::error('Push notification error', [
                'notification_id' => $notification->id,
                'error'           => $e->getMessage(),
            ]);

            $notification->markAsFailed($e->getMessage());

            return [
                'status'          => 'error',
                'message'         => $e->getMessage(),
                'notification_id' => $notification->id,
            ];
        }
    }

    /**
     * Send notification via FCM HTTP v1 API using kreait/firebase-php.
     *
     * @return array<string, mixed>
     */
    private function sendViaFcm(MobileDevice $device, MobilePushNotification $notification): array
    {
        assert($this->messaging !== null);

        /** @var array<non-empty-string, string> $dataPayload */
        $dataPayload = array_map('strval', array_merge($notification->data ?? [], [
            'notification_id'   => (string) $notification->id,
            'notification_type' => $notification->notification_type,
            'click_action'      => 'FLUTTER_NOTIFICATION_CLICK',
        ]));

        /** @var non-empty-string $token */
        $token = (string) $device->push_token;

        $message = CloudMessage::new()
            ->withToken($token)
            ->withNotification(Notification::create($notification->title, $notification->body))
            ->withData($dataPayload)
            ->withHighestPossiblePriority()
            ->withDefaultSounds();

        // Platform-specific configuration
        if ($device->platform === 'android') {
            $message = $message->withAndroidConfig([
                'notification' => [
                    'channel_id' => 'finaegis_default',
                ],
            ]);
        } elseif ($device->platform === 'ios') {
            $message = $message->withApnsConfig([
                'payload' => [
                    'aps' => [
                        'badge' => 1,
                    ],
                ],
            ]);
        }

        /** @var array<string, mixed> */
        return $this->messaging->send($message);
    }

    /**
     * Process pending scheduled notifications.
     */
    public function processScheduledNotifications(): int
    {
        $notifications = MobilePushNotification::pending()
            ->where('scheduled_at', '<=', now())
            ->limit(100)
            ->get();

        $sent = 0;
        foreach ($notifications as $notification) {
            $result = $this->sendNotification($notification);
            if ($result['status'] === 'sent') {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Retry failed notifications.
     */
    public function retryFailedNotifications(int $batchSize = 50): int
    {
        $notifications = MobilePushNotification::retryable()
            ->limit($batchSize)
            ->get();

        $sent = 0;
        foreach ($notifications as $notification) {
            $notification->resetForRetry();
            $result = $this->sendNotification($notification);
            if ($result['status'] === 'sent') {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Get unread notification count for a user.
     */
    public function getUnreadCount(User $user): int
    {
        return MobilePushNotification::where('user_id', $user->id)
            ->unread()
            ->count();
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(MobilePushNotification $notification): void
    {
        $notification->markAsRead();

        // Broadcast updated unread count
        $user = User::find($notification->user_id);
        if ($user) {
            $this->broadcastUnreadCount($user);
        }
    }

    /**
     * Mark all notifications as read for a user.
     */
    public function markAllAsRead(User $user): int
    {
        $count = MobilePushNotification::where('user_id', $user->id)
            ->unread()
            ->update([
                'status'  => MobilePushNotification::STATUS_READ,
                'read_at' => now(),
            ]);

        // Broadcast updated unread count (now 0)
        $this->broadcastUnreadCount($user);

        return $count;
    }

    /**
     * Get notification history for a user.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, MobilePushNotification>
     */
    public function getNotificationHistory(User $user, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return MobilePushNotification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Clean up old notifications.
     */
    public function cleanupOldNotifications(int $daysOld = 30): int
    {
        $threshold = now()->subDays($daysOld);

        return MobilePushNotification::where('created_at', '<', $threshold)
            ->delete();
    }

    /**
     * Send transaction received notification.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sendTransactionReceived(
        User $user,
        string $amount,
        string $currency,
        string $senderName
    ): array {
        return $this->sendToUser(
            $user,
            MobilePushNotification::TYPE_TRANSACTION_RECEIVED,
            'Payment Received',
            "You received {$amount} {$currency} from {$senderName}",
            [
                'amount'   => $amount,
                'currency' => $currency,
                'sender'   => $senderName,
            ]
        );
    }

    /**
     * Send transaction sent notification.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sendTransactionSent(
        User $user,
        string $amount,
        string $currency,
        string $recipientName
    ): array {
        return $this->sendToUser(
            $user,
            MobilePushNotification::TYPE_TRANSACTION_SENT,
            'Payment Sent',
            "You sent {$amount} {$currency} to {$recipientName}",
            [
                'amount'    => $amount,
                'currency'  => $currency,
                'recipient' => $recipientName,
            ]
        );
    }

    /**
     * Send security login notification.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sendSecurityLoginNotification(User $user, string $deviceName, string $location): array
    {
        return $this->sendToUser(
            $user,
            MobilePushNotification::TYPE_SECURITY_LOGIN,
            'New Login Detected',
            "New login from {$deviceName} in {$location}",
            [
                'device_name' => $deviceName,
                'location'    => $location,
            ]
        );
    }

    /**
     * Send low balance notification.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sendLowBalanceNotification(User $user, string $currency, string $balance, string $threshold): array
    {
        return $this->sendToUser(
            $user,
            MobilePushNotification::TYPE_BALANCE_LOW,
            'Low Balance Alert',
            "Your {$currency} balance ({$balance}) is below {$threshold}",
            [
                'currency'  => $currency,
                'balance'   => $balance,
                'threshold' => $threshold,
            ]
        );
    }

    /**
     * Broadcast the current unread notification count for real-time updates.
     */
    private function broadcastUnreadCount(User $user): void
    {
        $unreadCount = $this->getUnreadCount($user);

        \App\Domain\Mobile\Events\Broadcast\NotificationCountUpdated::dispatch(
            $user->id,
            $unreadCount
        );
    }
}
