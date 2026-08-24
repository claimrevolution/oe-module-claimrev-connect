<?php

/**
 * ERA file search wrapper for ClaimRev API.
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

class EraSearch
{
    public function __construct(private readonly ClaimRevApi $api)
    {
    }

    /**
     * Static entry point for ERA file search. Resolves the client from
     * globals and delegates.
     *
     * @return array<string, mixed>|false Returns false on error
     */
    public static function search(object $search): array|false
    {
        try {
            return (new self(ClaimRevApi::makeFromGlobals()))->searchDownloadableFiles($search);
        } catch (ClaimRevException) {
            return false;
        }
    }

    /**
     * Static entry point for ERA download. Resolves the client from globals
     * and delegates.
     *
     * @return array<string, mixed>|false Returns false on error
     */
    public static function downloadEra(string $objectId): array|false
    {
        try {
            return (new self(ClaimRevApi::makeFromGlobals()))->fetchFileForDownload($objectId);
        } catch (ClaimRevException) {
            return false;
        }
    }

    /**
     * Search for downloadable ERA files.
     *
     * @return array<string, mixed>
     * @throws ClaimRevApiException on API error
     */
    public function searchDownloadableFiles(object $search): array
    {
        return $this->api->searchDownloadableFiles($search);
    }

    /**
     * Fetch a single ERA file's contents by object ID.
     *
     * @return array<string, mixed>
     * @throws ClaimRevApiException on API error
     */
    public function fetchFileForDownload(string $objectId): array
    {
        return $this->api->getFileForDownload($objectId);
    }
}
