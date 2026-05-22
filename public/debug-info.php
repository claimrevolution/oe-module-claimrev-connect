<?php

/**
 *
 * @package OpenEMR
 * @link    https://www.open-emr.org
 *
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2022 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

    require_once "../../../../globals.php";

    use OpenEMR\Common\Acl\AccessDeniedHelper;
    use OpenEMR\Common\Acl\AclMain;
    use OpenEMR\Common\Csrf\CsrfUtils;
    use OpenEMR\Core\Header;
    use OpenEMR\Modules\ClaimRevConnector\Bootstrap;
    use OpenEMR\Modules\ClaimRevConnector\ConnectivityInfo;
    use OpenEMR\Modules\ClaimRevConnector\CsrfHelper;
    use OpenEMR\Modules\ClaimRevConnector\ModuleInput;
    use OpenEMR\Modules\ClaimRevConnector\ModuleVersionCheckService;

    $tab = "connectivity";

    //ensure user has proper access
if (!AclMain::aclCheckCore('acct', 'bill')) {
    AccessDeniedHelper::denyWithTemplate("ACL check failed for acct/bill: ClaimRev Connect - Connectivity", xl("ClaimRev Connect - Connectivity"));
}

// Manual "Check for Updates" trigger. Bypasses the 24h throttle so the
// operator gets a fresh response on every click. The check method is
// best-effort — null return means the API was unreachable or returned
// something we couldn't parse, and we render that as a soft warning.
$forceCheckResult = null;
$forceCheckRan = false;
$forceCheckFailed = false;
if (ModuleInput::isPostRequest() && ModuleInput::postString('action') === 'check_version') {
    if (!CsrfHelper::verifyCsrfToken(ModuleInput::postString('csrf_token_form'), 'ClaimRevModule')) {
        CsrfUtils::csrfNotVerified();
    }
    $forceCheckRan = true;
    $forceCheckResult = ModuleVersionCheckService::check(true);
    $forceCheckFailed = ($forceCheckResult === null);
}

$versionCheck = $forceCheckResult ?? ModuleVersionCheckService::getLastResult();
$installedVersion = Bootstrap::MODULE_VERSION;
?>

<html>
    <head>
        <title><?php echo xlt("ClaimRev Connect - Connectivity"); ?></title>
        <?php Header::setupHeader(); ?>
    </head>
    <body class="body_top">
        <div class="container-fluid">
            <?php require '../templates/navbar.php'; ?>
            <?php $connectivityInfo = new ConnectivityInfo(); ?>
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="mb-0"><?php echo xlt("Client Connection Information"); ?></h5>
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li><?php echo xlt("Authority");?>: <?php echo text($connectivityInfo->client_authority); ?></li>
                        <li><?php echo xlt("Client ID");?>: <?php echo text($connectivityInfo->clientId); ?></li>
                        <li><?php echo xlt("Client Scope");?>: <?php echo text($connectivityInfo->client_scope); ?></li>
                        <li><?php echo xlt("API Server");?>: <?php echo text($connectivityInfo->api_server); ?></li>
                        <li><?php echo xlt("Default Account");?>: <?php echo text($connectivityInfo->defaultAccount); ?></li>
                        <li><?php echo xlt("Token");?>: <?php echo $connectivityInfo->hasToken ? xlt("Yes") : xlt("No"); ?></li>
                    </ul>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?php echo xlt("Module Version"); ?></h5>
                    <form method="post" class="mb-0">
                        <input type="hidden" name="action" value="check_version" />
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfHelper::collectCsrfToken('ClaimRevModule')); ?>" />
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fa fa-sync"></i> <?php echo xlt("Check for Updates"); ?>
                        </button>
                    </form>
                </div>
                <div class="card-body">
                    <?php if ($forceCheckRan && $forceCheckFailed) { ?>
                        <div class="alert alert-warning py-2 small mb-3">
                            <i class="fa fa-exclamation-triangle"></i>
                            <?php echo xlt("Couldn't reach the ClaimRev version-check service. Showing the last known result if any."); ?>
                        </div>
                    <?php } elseif ($forceCheckRan) { ?>
                        <div class="alert alert-success py-2 small mb-3">
                            <i class="fa fa-check"></i>
                            <?php echo xlt("Version check completed."); ?>
                        </div>
                    <?php } ?>

                    <ul class="mb-0">
                        <li><?php echo xlt("Installed Version"); ?>: <strong><?php echo text($installedVersion); ?></strong></li>
                        <?php if ($versionCheck === null) { ?>
                            <li class="text-muted"><?php echo xlt("No version check has succeeded yet — click \"Check for Updates\" to try now."); ?></li>
                        <?php } else { ?>
                            <li><?php echo xlt("Latest Version"); ?>: <strong><?php echo text($versionCheck->currentVersion); ?></strong>
                                <?php if ($versionCheck->isCurrent) { ?>
                                    <span class="badge badge-success ml-2"><?php echo xlt("Up to date"); ?></span>
                                <?php } else { ?>
                                    <span class="badge badge-warning ml-2"><?php echo xlt("Update available"); ?></span>
                                <?php } ?>
                            </li>
                            <li><?php echo xlt("Supported"); ?>:
                                <?php if ($versionCheck->isSupported) { ?>
                                    <span class="text-success"><i class="fa fa-check"></i> <?php echo xlt("Yes"); ?></span>
                                <?php } else { ?>
                                    <span class="text-warning"><i class="fa fa-exclamation-triangle"></i> <?php echo xlt("No — past end-of-support"); ?></span>
                                <?php } ?>
                            </li>
                            <li><?php echo xlt("Severity"); ?>: <code><?php echo text($versionCheck->severity); ?></code></li>
                            <?php if ($versionCheck->disabled) { ?>
                                <li class="text-danger">
                                    <i class="fa fa-ban"></i> <strong><?php echo xlt("Disabled by ClaimRev"); ?></strong>
                                    <?php if ($versionCheck->disableReason !== '') { ?>
                                        — <?php echo text($versionCheck->disableReason); ?>
                                    <?php } ?>
                                </li>
                            <?php } ?>
                            <?php if ($versionCheck->message !== '') { ?>
                                <li><?php echo xlt("Message"); ?>: <?php echo text($versionCheck->message); ?></li>
                            <?php } ?>
                            <?php if ($versionCheck->downloadUrl !== '') { ?>
                                <li><a href="<?php echo attr($versionCheck->downloadUrl); ?>" target="_blank"><?php echo xlt("Download the latest version"); ?></a></li>
                            <?php } ?>
                        <?php } ?>
                    </ul>
                </div>
            </div>
        </div>
    </body>
</html>
