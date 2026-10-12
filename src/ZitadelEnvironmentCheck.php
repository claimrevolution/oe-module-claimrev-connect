<?php

/**
 * Works out which ClaimRev environment a ZITADEL key belongs to.
 *
 * The key file does not name its instance, but only the instance that issued a
 * key will accept it. So when sign-in fails, the other built-in environments'
 * instances are asked for a token; the one that answers is the key's home. A
 * test-portal key on a site set to P (or the reverse) is the commonest setup
 * mistake, and this turns "Token: No" into an instruction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2026 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use SensitiveParameter;

final class ZitadelEnvironmentCheck
{
    public const ENVIRONMENT_NAMES = ['P' => 'Production', 'S' => 'Staging', 'D' => 'Custom (D)'];

    public function __construct(private readonly ClientInterface $client)
    {
    }

    public static function make(): self
    {
        // Short timeouts: this only runs after sign-in has already failed.
        return new self(new Client(['connect_timeout' => 10, 'timeout' => 15]));
    }

    /**
     * The built-in environment whose ZITADEL instance accepts this key, skipping
     * $currentEnvironment (already known to reject it), or null if none does.
     *
     * @param array{keyId: string, key: string, userId: string} $keyFile
     */
    public function findEnvironment(#[SensitiveParameter] array $keyFile, string $currentEnvironment): ?string
    {
        foreach (GlobalConfig::builtInZitadelInstances() as $env => $instance) {
            if ($env === $currentEnvironment) {
                continue;
            }
            try {
                $response = $this->client->request('POST', ZitadelAuth::tokenEndpoint($instance['authority']), [
                    'form_params' => ZitadelAuth::tokenRequestForm($keyFile, $instance['authority'], $instance['projectId'], time()),
                    'http_errors' => false,
                ]);
            } catch (GuzzleException) {
                continue;
            }
            if ($response->getStatusCode() === 200) {
                return $env;
            }
        }
        return null;
    }

    /**
     * A user-facing explanation when the stored key belongs to a different
     * environment than the one configured, else null. Never throws: it only
     * decorates an error that is already being shown.
     */
    public static function mismatchHint(GlobalConfig $config): ?string
    {
        try {
            $current = $config->getEnvironment();
            $found = self::make()->findEnvironment(ZitadelKeyStore::getKeyFile(), $current);
        } catch (\Throwable) {
            return null;
        }
        return $found === null ? null : self::describeMismatch($found, $current);
    }

    public static function describeMismatch(string $keyEnvironment, string $configuredEnvironment): string
    {
        $keyName = self::ENVIRONMENT_NAMES[$keyEnvironment] ?? $keyEnvironment;
        $configuredName = self::ENVIRONMENT_NAMES[$configuredEnvironment] ?? $configuredEnvironment;
        return "This key belongs to the ClaimRev {$keyName} environment ({$keyEnvironment}), "
            . "but ClaimRev Environment is set to {$configuredName} ({$configuredEnvironment}). "
            . "Change ClaimRev Environment to {$keyEnvironment} under Administration > Globals > ClaimRev Connect, "
            . "or load a key from the {$configuredName} portal.";
    }
}
