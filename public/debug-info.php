<?php

/**
 * Sample HTML page with display of global settings
 *
 * @package   OpenEMR
 * @link      http://www.claimrev.com
 *
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2022 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// we want to have access to events, the autoloader and our module bootstrap so we include globals here
require_once "../../../../globals.php";
use OpenEMR\Modules\ClaimRevConnector\Bootstrap;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApi;

// Note we have to grab the event dispatcher from the globals kernel which is instantiated in globals.php
$bootstrap = new Bootstrap($GLOBALS['kernel']->getEventDispatcher());
$globalsConfig = $bootstrap->getGlobalConfig();

?>
<html>
<head>
   
</head>
<body>
    <?php
        $clientId = $globalsConfig->getClientId();
        $client_secret = $globalsConfig->getClientSecret();
        $userName = $globalsConfig->getUserName();
        $password = $globalsConfig->getPassword();                    
    ?>
    <ul>
        <li>User name: <?php echo $userName?></li>
        <li>Password: <?php echo $password; ?></li>

        <li>Client ID: <?php echo $clientId; ?></li>
        <li>Client Secret: <?php echo  $client_secret?></li>
        <?php 
        
            $token = ClaimRevApi::GetAccessToken($clientId,$client_secret, $userName, $password); 
    
        ?>
        <li> Token <?php echo $token ?>  </li>
    </ul>

<a href="index.php">Back to index</a>
</body>



</html>

