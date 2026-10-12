<?php

/**
 * Connectivity information for the ClaimRev module.
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

use OpenEMR\Modules\ClaimRevConnector\Compat\KernelCompat;

class ConnectivityInfo
{
    public string $identityProvider;
    public string $client_authority;
    public string $clientId;
    public string $client_scope;
    public string $client_secret;
    public string $api_server;
    public bool $hasToken;
    public string $defaultAccount;
    /** Why sign-in or the account lookup failed, for display; '' when it worked. */
    public string $authError = '';
    /** Set when the ZITADEL key belongs to a different environment than the configured one. */
    public string $environmentHint = '';

    public function __construct()
    {
        $bootstrap = new Bootstrap(KernelCompat::resolve()->getEventDispatcher());
        $globalsConfig = $bootstrap->getGlobalConfig();

        $usesZitadel = $globalsConfig->usesZitadel();
        $this->identityProvider = $usesZitadel ? 'ZITADEL' : 'Azure B2C';

        $clientIdRaw = $globalsConfig->getClientId();
        $this->clientId = is_string($clientIdRaw) ? $clientIdRaw : '';
        if ($usesZitadel) {
            // The key file carries the service user's id, not its cr-… client name.
            $keyInfo = ZitadelKeyStore::getKeyInfo();
            $this->clientId = $keyInfo !== null ? 'service user ' . $keyInfo['userId'] . ', key ' . $keyInfo['keyId'] : '';
        }

        try {
            if ($usesZitadel) {
                $this->client_authority = $globalsConfig->getZitadelAuthority();
                $this->client_scope = 'urn:zitadel:iam:org:project:id:' . $globalsConfig->getZitadelProjectId() . ':aud';
            } else {
                $this->client_authority = $globalsConfig->getClientAuthority();
                $this->client_scope = $globalsConfig->getClientScope();
            }
            $this->api_server = $globalsConfig->getApiServer();
        } catch (ModuleNotConfiguredException $e) {
            $this->authError = $e->getMessage();
            $this->client_authority = '';
            $this->client_scope = '';
            $this->api_server = '';
            $this->client_secret = '';
            $this->hasToken = false;
            $this->defaultAccount = '';
            return;
        }

        $clientSecret = $usesZitadel ? '' : $globalsConfig->getClientSecret();
        $this->client_secret = is_string($clientSecret) ? $clientSecret : '';

        try {
            $api = ClaimRevApi::makeFromGlobals();
            $this->hasToken = $api->canConnect();
            $account = $api->getDefaultAccount();
            $accountValue = $account['value'] ?? null;
            $this->defaultAccount = is_string($accountValue)
                ? $accountValue
                : json_encode($account, JSON_THROW_ON_ERROR);
        } catch (ClaimRevAuthenticationException $e) {
            $this->hasToken = false;
            $this->defaultAccount = '';
            $this->authError = $e->getMessage();
            if ($usesZitadel) {
                $this->environmentHint = ZitadelEnvironmentCheck::mismatchHint($globalsConfig) ?? '';
            }
        } catch (ClaimRevApiException $e) {
            $this->hasToken = true;
            $this->defaultAccount = '';
            $this->authError = $e->getMessage();
        } catch (ModuleNotConfiguredException $e) {
            $this->hasToken = false;
            $this->defaultAccount = '';
            $this->authError = $e->getMessage();
        }
    }
}
