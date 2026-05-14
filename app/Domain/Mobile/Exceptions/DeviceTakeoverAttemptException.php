<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Exception thrown when an attempt is made to register a device that belongs to another user.
 *
 * This is a security-critical exception that indicates a potential account takeover attempt.
 * The device registration flow should not allow transferring devices between users.
 */
class DeviceTakeoverAttemptException extends Exception
{
    public function __construct(
        public readonly string $deviceId,
        public readonly int $existingUserId,
        public readonly int $attemptedUserId,
        string $message = 'Device already registered to another user. Contact support if you believe this is an error.',
    ) {
        parent::__construct($message);
    }

    /**
     * Get the HTTP status code for this exception.
     */
    public function getHttpStatusCode(): int
    {
        return 409; // Conflict
    }

    /**
     * Render the exception as an HTTP response.
     *
     * Returns 409 so mobile clients can distinguish a takeover-blocked
     * registration from a generic 500. Existing user identifiers are
     * deliberately NOT echoed in the response body — that information stays
     * in the server log via the `context()` array.
     */
    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'error'   => 'DEVICE_REGISTERED_TO_DIFFERENT_USER',
        ], $this->getHttpStatusCode());
    }

    /**
     * Get additional context for logging.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'device_id'         => $this->deviceId,
            'existing_user_id'  => $this->existingUserId,
            'attempted_user_id' => $this->attemptedUserId,
        ];
    }
}
