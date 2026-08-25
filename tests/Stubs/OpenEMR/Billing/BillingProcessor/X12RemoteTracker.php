<?php

declare(strict_types=1);

namespace OpenEMR\Billing\BillingProcessor;

/**
 * Doubles the X12 remote tracker ClaimUpload uses to read waiting claim
 * files and record their outcome.
 */
class X12RemoteTracker
{
    /** @return list<array<string, mixed>> */
    public function fetchByStatus(string $status): array
    {
        return \ClaimRevStubState::$x12Rows;
    }

    /** @param array<string, mixed> $row */
    public function update(array $row): void
    {
        \ClaimRevStubState::$x12Updates[] = $row;
    }
}
