<?php

/**
 * Report download service for ClaimRev.
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

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Core\OEGlobalsBag;

/**
 * Downloads ClaimRev report files (999/277 acknowledgements and 835 ERAs) to
 * the site's documents tree.
 *
 * Does not extend BaseService: nothing here used it, and its constructor
 * queries the database and calls OEGlobalsBag::getKernel(), which does not
 * exist on OpenEMR 8.0.x.
 */
class ReportDownload
{
    /** @var list<string> Report types fetched by saveWaitingFiles(). */
    private const REPORT_TYPES = ['999', '277'];

    public function __construct(private readonly ClaimRevApi $api)
    {
    }

    /** Static entry point. Resolves the client from globals and delegates. */
    public static function getWaitingFiles(): void
    {
        try {
            $service = new self(ClaimRevApi::makeFromGlobals());
        } catch (ClaimRevException) {
            return;
        }
        $service->saveWaitingFiles();
    }

    /** Static entry point. Resolves the client from globals and delegates. */
    public static function download835(string $objectId): void
    {
        try {
            $service = new self(ClaimRevApi::makeFromGlobals());
        } catch (ClaimRevException) {
            return;
        }
        $service->save835($objectId);
    }

    /**
     * Fetch every waiting 999 and 277 report and write it under the site's
     * EDI history tree. A failure on one report type does not stop the others.
     */
    public function saveWaitingFiles(): void
    {
        $siteDir = OEGlobalsBag::getInstance()->get('OE_SITE_DIR');

        foreach (self::REPORT_TYPES as $reportType) {
            // 999s have always been filed under f997; kept for continuity.
            $reportFolder = $reportType === '999' ? 'f997' : 'f' . $reportType;
            $savePath = $siteDir . '/documents/edi/history/' . $reportFolder . '/';

            if (!file_exists($savePath)) {
                mkdir($savePath, 0750, true);
            }

            try {
                $datas = $this->api->getReportFiles($reportType);
            } catch (ClaimRevApiException) {
                continue;
            }

            foreach ($datas as $data) {
                if (!isset($data['fileText'])) {
                    ServiceContainer::getLogger()->error(
                        'Unable to find property fileText in response',
                        ['class' => self::class, 'method' => 'saveWaitingFiles'],
                    );
                    continue;
                }
                $filePathName = $savePath . $data['fileName'] . '.txt';
                file_put_contents($filePathName, $data['fileText']);
                chmod($filePathName, 0640);
            }
        }
    }

    /** Fetch one 835 by object ID and write it to the site's ERA directory. */
    public function save835(string $objectId): void
    {
        $siteDir = OEGlobalsBag::getInstance()->get('OE_SITE_DIR');
        $savePath = $siteDir . '/documents/era/';

        if (!file_exists($savePath)) {
            mkdir($savePath, 0750, true);
        }

        try {
            $data = $this->api->getFileForDownload($objectId);
        } catch (ClaimRevApiException $e) {
            ServiceContainer::getLogger()->error(
                'Unable to download file',
                ['class' => self::class, 'method' => 'save835', 'exception' => $e->getMessage()],
            );
            return;
        }

        if (!isset($data['fileText'])) {
            ServiceContainer::getLogger()->error(
                'Unable to find property fileText in response',
                ['class' => self::class, 'method' => 'save835'],
            );
            return;
        }

        $filePathName = $savePath . $objectId . '.edi';
        file_put_contents($filePathName, $data['fileText']);
        chmod($filePathName, 0640);
    }
}
