<?php

/**
 * Claim search wrapper for ClaimRev API.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2022 Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector;

class ClaimSearch
{
    public function __construct(private readonly ClaimRevApi $api)
    {
    }

    /**
     * Static entry point. Resolves the API client from globals and delegates.
     *
     * Returns false when the module is unconfigured or the API call fails,
     * which is the contract callers such as ClaimsPage already test for.
     *
     * @return array<string, mixed>|false Returns false on error
     */
    public static function search(object $search): array|false
    {
        try {
            return (new self(ClaimRevApi::makeFromGlobals()))->searchClaims($search);
        } catch (ClaimRevException) {
            return false;
        }
    }

    /**
     * Search for claims.
     *
     * @return array<string, mixed>
     * @throws ClaimRevApiException on API error
     */
    public function searchClaims(object $search): array
    {
        return $this->api->searchClaims($search);
    }
}
