<?php

namespace App\Auth;

/**
 * Thrown by {@see AccessPolicy} when an authorization decision denies the
 * request. The HTTP layer (auth_middleware.php) catches it and converts it
 * into an `denyWithStatus()` JSON error response, keeping the decision logic
 * free of `exit`/`header` side effects so it can be unit-tested.
 */
class AccessDenied extends \RuntimeException
{
    public function __construct(private int $status, string $message)
    {
        parent::__construct($message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
