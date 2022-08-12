<?php 
use OpenEMR\Modules\ClaimRevConnector\EligibilityData;
use OpenEMR\Modules\ClaimRevConnector\EligibilityInquiryRequest;
use OpenEMR\Modules\ClaimRevConnector\SubscriberPatientEligibilityRequest;
use OpenEMR\Modules\ClaimRevConnector\EligibilityObjectCreator;
use OpenEMR\Modules\ClaimRevConnector\ValueMapping;


$insurance = EligibilityData::getInsuranceData($pid);
?>

<div class="row">
    <div class="col">
    <ul class="nav nav-tabs mb-2">
        <?php  $classActive = "active"; $first="true"; foreach ($insurance as $row) { ?>
            <li class="nav-item" role="presentation">
                <a id="claimrev-ins-<?php echo(ucfirst($row['payer_responsibility']));?>-tab" aria-selected="<?php echo($first); ?>" class="nav-link <?php echo($classActive);?>"  data-toggle="tab" role="tab" href="#<?php echo(ucfirst($row['payer_responsibility']));?>"> <?php echo(ucfirst($row['payer_responsibility']));?>  </a>
            </li>
        <?php $first = "false"; $classActive=""; } ?>
        
    </ul>
    <div class="tab-content">
        <?php 
        $classActive = "in active"; 
        foreach ($insurance as $row) { 
            $eligibilityCheck = EligibilityData::getEligibilityResult($pid,$row['payer_responsibility']);
            ?>
            <div id="<?php echo(ucfirst($row['payer_responsibility']));?>" class="tab-pane <?php echo($classActive);?>">
                <div class="row">
                    <div class="col-2">
                        <form method="post" action="<?=$_SERVER['PHP_SELF'];?>">
                            <input type="hidden" id="responsibility" name="responsibility" value="<?php echo(ucfirst($row['payer_responsibility']));?>">
                            <button type="submit" name="checkElig" class="btn btn-primary">Check</button>
                        </form>
                    </div>
                    <div class="col">
                    <?php 
                        foreach( $eligibilityCheck as $check )
                        { ?>
                        <div class="row">
                            <div class="col">
                                Status: <?php echo($check["status"]);?>
                            </div>
                            <div class="col">
                                (Last Update: <?php echo($check["last_update"]);?>)
                            </div>
                            <div class="col">
                              
                            </div>
                                     
                        </div>
                        <?php } ?>                        
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <hr/>
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                    <?php 
                        foreach( $eligibilityCheck as $check )
                        { 
                            if($check["response_json"] == null)
                            { 
                                echo("No Results");
                            }
                            else
                            {
                                
                            }
                        } ?>   
                    </div>
                </div>
            </div>            
        <?php 
            $classActive = ""; 
            } 
        ?>
    </div>
</div>
</div>

<?php 
if(isset($_POST['checkElig'])) { //check if form was submitted

    $pr=$_POST['responsibility'];
    //$pid is found on the parent page that is including this php file
    $formatedPr = ValueMapping::MapPayerResponsibility($pr);
    EligibilityData::RemoveEligibilityCheck($pid,$formatedPr);
    $requestObjects = EligibilityObjectCreator::BuildObject($pid,$pr);
    EligibilityObjectCreator::SaveToDatabase($requestObjects,$pid );
    $request = $requestObjects[0];  
}


?>

