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

    require_once "../../../../globals.php";

    use OpenEMR\Common\Acl\AccessDeniedHelper;
    use OpenEMR\Common\Acl\AclMain;
    use OpenEMR\Core\Header;
    use OpenEMR\Modules\ClaimRevConnector\ClaimRevApi;

    $tab = "home";

//ensure user has proper access
if (!AclMain::aclCheckCore('acct', 'bill')) {
    AccessDeniedHelper::denyWithTemplate("ACL check failed for acct/bill: ClaimRev Connect - Home", xl("ClaimRev Connect - Home"));
}

// Fetch contact info from ClaimRev API (anonymous, no token needed)
$contactInfo = ClaimRevApi::getSupportInfo();
$phone = $contactInfo['phone'] ?? '918-842-9564';
$supportEmail = $contactInfo['supportEmail'] ?? 'support@claimrev.com';
$salesEmail = $contactInfo['salesEmail'] ?? 'sales@claimrev.com';
?>
<html>
    <head>
        <title><?php echo xlt("ClaimRev Connect - Home"); ?></title>
        <?php Header::setupHeader(); ?>
    </head>
    <body class="body_top">
        <div class="container-fluid">
            <?php require '../templates/navbar.php'; ?>
            <h3 class="mt-3"><?php echo xlt("ClaimRev - Client Access"); ?></h3>
            <div class="card mt-3">
                <div class="card-body">
                    <p>
                        <?php echo xlt("Welcome to the ClaimRev Connector. This is your link to the portal and claim processing."); ?>
                    </p>

                    <h6><?php echo xlt("Tab Descriptions"); ?></h6>
                    <ul>
                        <li><?php echo xlt("Claims -> Lets you search claims sent to ClaimRev and view the status."); ?></li>
                        <li><?php echo xlt("ERAs -> View Electronic Remittance Advice files."); ?></li>
                        <li><?php echo xlt("Appointments -> Check eligibility for upcoming appointments."); ?></li>
                        <li><?php echo xlt("X12 Tracker -> Display's files that have been submitted or in the process of being submitted."); ?></li>
                        <li><?php echo xlt("Setup -> Helps identify any setup issues along with checking background services."); ?></li>
                        <li><?php echo xlt("Connectivity -> Display's information that may help support fix connection issues you maybe having."); ?></li>
                    </ul>

                    <h6><?php echo xlt("Support/Sales"); ?></h6>
                    <ul>
                        <li><?php echo xlt("Call"); ?>: <a href="tel:<?php echo attr(preg_replace('/[^0-9]/', '', $phone)); ?>"><?php echo text($phone); ?></a></li>
                        <li><?php echo xlt("Email Support"); ?>: <a href="mailto:<?php echo attr($supportEmail); ?>"><?php echo text($supportEmail); ?></a></li>
                        <li><?php echo xlt("Email Sales"); ?>: <a href="mailto:<?php echo attr($salesEmail); ?>"><?php echo text($salesEmail); ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
    </body>
</html>
