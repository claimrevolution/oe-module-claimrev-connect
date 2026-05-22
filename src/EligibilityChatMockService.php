<?php

/**
 * Mock service for the AI eligibility chat answers.
 *
 * Mirrors EligibilityMockService / PaymentAdviceMockService: when the
 * global Test Mode toggle is on, the eligibility chat endpoint routes
 * through this service instead of the live AI backend so demos /
 * training / development can exercise the chat UI without hitting a
 * paid model and without depending on a real 271 being present.
 *
 * The mock pattern-matches the question against a small set of common
 * eligibility topics (deductible, copay, out-of-pocket, coverage, prior
 * auth) and returns a credible-sounding paragraph. Falls through to a
 * generic answer when no keyword matches.
 *
 * Numeric values (deductible remaining, OOP remaining, copay amounts)
 * are derived from a CRC32-seeded PRNG keyed on the SharpRevenue object
 * id so the same patient produces the same numbers across calls — useful
 * if a viewer freeze-frames the video and the previous chat scrollback
 * stays consistent.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2026 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector;

class EligibilityChatMockService
{
    /**
     * Build a credible-sounding answer to an eligibility question
     * without hitting the live AI backend.
     */
    public static function buildAnswer(
        string $sharpRevenueObjectId,
        string $question,
        ?string $payerCode = null,
    ): string {
        $q = strtolower($question);

        // Deterministic per-patient numbers so a re-recorded take produces
        // the same figures as the prior take for the same patient.
        $seed = abs(crc32($sharpRevenueObjectId));
        mt_srand($seed);
        $deductibleRemaining = 750 + mt_rand(0, 1500);
        $deductibleTotal = 2500;
        $oopRemaining = 1800 + mt_rand(0, 2000);
        $oopMax = 5000;
        $copayPcp = [15, 20, 25, 30][mt_rand(0, 3)];
        $copaySpec = [40, 50, 60, 75][mt_rand(0, 3)];

        if (str_contains($q, 'deductible')) {
            return sprintf(
                'Based on the most recent 271, the patient has $%s remaining on a $%s in-network deductible for plan year 2026. The deductible resets January 1.',
                number_format($deductibleRemaining),
                number_format($deductibleTotal),
            );
        }

        if (
            str_contains($q, 'out-of-pocket')
            || str_contains($q, 'out of pocket')
            || str_contains($q, ' oop')
            || str_starts_with($q, 'oop')
            || str_contains($q, 'max')
        ) {
            return sprintf(
                'The in-network out-of-pocket maximum is $%s for 2026, with $%s remaining. Family OOP is twice the individual amount.',
                number_format($oopMax),
                number_format($oopRemaining),
            );
        }

        if (str_contains($q, 'copay') || str_contains($q, 'co-pay')) {
            return sprintf(
                'Primary care visits are $%d, specialists are $%d, and urgent care is $50. Preventive visits are no-cost in-network.',
                $copayPcp,
                $copaySpec,
            );
        }

        if (
            str_contains($q, 'covered')
            || str_contains($q, 'coverage')
            || str_contains($q, 'in-network')
            || str_contains($q, 'in network')
            || str_contains($q, 'plan')
        ) {
            return 'Yes — the patient has active in-network coverage with this payer. The plan covers professional, hospital, and emergency services. Out-of-network requires prior authorization for non-emergency care.';
        }

        if (
            str_contains($q, 'prior auth')
            || str_contains($q, 'preauth')
            || str_contains($q, 'pre-auth')
            || str_contains($q, 'authorization')
        ) {
            return 'Prior authorization is required for non-emergency hospital admissions, specialty imaging (MRI, PET), and most injectable specialty medications. Routine office visits are not affected.';
        }

        if (
            str_contains($q, 'effective')
            || str_contains($q, 'active')
            || str_contains($q, 'eligible')
            || str_contains($q, 'termination')
            || str_contains($q, 'end date')
        ) {
            return 'Coverage is active as of the date of the 271 response. No termination date is reported by the payer, so the plan remains in force through the end of the plan year unless cancelled.';
        }

        // Fallback — generic Norah-toned response that doesn't commit to a number.
        return 'Based on the 271 response, the patient has active in-network coverage with this payer. Their plan covers professional and hospital services, subject to the deductible and copay structure shown on the eligibility card. Let me know if you want specifics on a particular benefit.';
    }
}
