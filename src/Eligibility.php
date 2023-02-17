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
                                Message: <?php echo($check["response_message"]);?>
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
                      //yeah.... this weird. why would someone do this? because I couldn't get the include 
                      //see my files one directory up. I might of done something wrong but I spent a good hour
                      //trying to figure it out. I finally decided to go use something like this and make it 
                      //look at a hack.
                      $path = __DIR__;
                      $path = str_replace("src","templates",$path);
                      
                      include_once $path . '/validation_function.php';
                        foreach( $eligibilityCheck as $check )
                        { 
                            if($check["eligibility_json"] == null)
                            { 
                                echo("No Results");
                            }
                            else
                            {
                                $result = $check["eligibility_json"];
                                $eligibilityData = json_decode($result); 
                                $benefits = null;
                                $subscriberPatient = null;
                                $data = null;
                                if (property_exists($eligibilityData, 'mapped271'))
                                {
                                    $data = $eligibilityData->mapped271;
                                }

                                if (property_exists($data, 'dependent'))
                                {
                                    $dependent = $data->dependent;
                                    if($dependent != null)
                                    {
                                        if (property_exists($dependent, 'benefits'))
                                        {
                                            $benefits = $dependent->benefits;
                                            $subscriberPatient = $dependent;
                                        }
                                    }                                            
                                }   

                                 if (property_exists($data, 'subscriber'))
                                 {
                                    $subscriber = $data->subscriber;
                                    if($subscriber != null)
                                    {                                    
                                        if (property_exists($subscriber, 'benefits'))
                                        {
                                            $benefits = $subscriber->benefits;
                                            $subscriberPatient = $subscriber;
                                        } 

                                    }
                                   
                                 }                       

                    ?>
                                <ul class="nav nav-tabs mb-2">
                                <?php  $classActive = "active"; $first="true"; ?>
                                <li class="nav-item" role="presentation">
                                        <a id="claimrev-ins-benefits-tab" aria-selected="<?php echo($first); ?>" class="nav-link active"  data-toggle="tab" role="tab" href="#eligibility-benefits"> Benefits  </a>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <a id="claimrev-ins-validations-tab" aria-selected="<?php echo($first); ?>" class="nav-link"  data-toggle="tab" role="tab" href="#eligibility-validations"> Validations  </a>
                                    </li>                                 
                                    <?php $first = "false"; $classActive="";  ?>                                
                                </ul>
                            <div class="tab-content">
                                <div id="eligibility-benefits" class="tab-pane active">
                                    <div class="row">
                                        <div class="col">
                                            <?php 
                                                $source = $data->informationSourceName;
                                                include $path .'/source.php';
                                            ?>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col">
                                            <?php 
                                                $receiver = $data->receiver;
                                                include $path . '/receiver.php';
                                            ?>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col">
                                            <?php 
                                                             
                                             if($benefits != null)
                                             {
                                                include $path .'/subscriber_patient.php';
                                                include $path .'/benefit.php';
                                             }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                                <div id="eligibility-validations" class="tab-pane">
                                    <div class="row">
                                        <div class="col">
                                            <?php 
                                            include $path . '/validation.php';
                                                                                     
                                               
                                            ?>
                                        </div>
                                    </div>
                                </div>     
                            </div>
  
                           <?php }
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
    $requestObjects = EligibilityObjectCreator::BuildObject($pid,$pr,null,null,null);
    EligibilityObjectCreator::SaveToDatabase($requestObjects,$pid );
    $request = $requestObjects[0];  
}


?>

