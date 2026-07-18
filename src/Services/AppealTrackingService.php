<?php

namespace App\Services;

/**
 * Pure helpers for the public appeal-status lookup (api/appeals.php?action=track).
 * Extracted so the anti-enumeration match rule and the public-safe projection can
 * be unit-tested without booting the controller (which opens a DB + session).
 */
class AppealTrackingService
{
    /**
     * The lookup only succeeds when the row exists AND the citizen proves knowledge
     * of it: the verifier equals the submitted email (case-insensitive) or is a
     * substring of the full name. Any mismatch → false → generic "not found", so
     * sequential appeal numbers can't be enumerated by number alone.
     */
    public static function verifierMatches(?array $row, string $verifier): bool
    {
        $verifier = trim($verifier);
        if (!$row || $verifier === '') {
            return false;
        }
        $v = mb_strtolower($verifier);
        $email = mb_strtolower(trim((string)($row['email'] ?? '')));
        if ($email !== '' && $email === $v) {
            return true;
        }
        $fullName = (string)($row['full_name'] ?? '');
        return $fullName !== '' && mb_stripos($fullName, $verifier) !== false;
    }

    /**
     * Public-safe projection: status-timeline fields only. The response text is
     * exposed ONLY once the appeal is responded/closed — never while new/in_review.
     */
    public static function trackingView(array $row): array
    {
        $responded = in_array($row['status'] ?? '', ['responded', 'closed'], true);
        return [
            'appeal_number' => $row['appeal_number'] ?? null,
            'subject'       => $row['subject'] ?? null,
            'category'      => $row['category'] ?? null,
            'status'        => $row['status'] ?? null,
            'created_at'    => $row['created_at'] ?? null,
            'responded_at'  => $responded ? ($row['responded_at'] ?? null) : null,
            'response_text' => $responded ? ($row['response_text'] ?? null) : null,
        ];
    }
}
