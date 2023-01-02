<?php  
    namespace OpenEMR\Modules\ClaimRevConnector;
    require_once "../../../../globals.php";
    require_once '../src/ClaimSearchModel.php';
    require_once '../src/ClaimSearch.php';
    require_once '../src/ClaimRevApi.php';
    require_once '../src/AuthoParam.php';
    require_once '../src/UploadEdiFileContentModel.php';

   
    use OpenEMR\Modules\ClaimRevConnector\ClaimSearch;
    use OpenEMR\Modules\ClaimRevConnector\ClaimSearchModel;

    class ClaimsPage
    {
        public static function SearchClaims($postData)
        {
            $firstName = $_POST['patFirstName']; 
            $lastName = $_POST['patLastName']; 
            $startDate = $_POST['startDate']; 
            $endDate = $_POST['endDate']; 

            $model = new ClaimSearchModel();
            $model->patientFirstName = $firstName;
            $model->patientLastName = $lastName;
            $model->receivedDateStart = $startDate;
            $model->receivedDateEnd = $endDate;

            $data = ClaimSearch::Search($model);
            return $data;
        }
    }
?>
<html>
    <head>
        <link rel="stylesheet" href="../../../../../public/assets/bootstrap/dist/css/bootstrap.min.css">
    </head>
    <title>ClaimRev Connect - Claims</title>
    <body>
        <div class="row"> 
            <div class="col">
                <nav class="navbar navbar-expand-lg navbar-light bg-light">
                    <a class="navbar-brand" href="#">ClaimRev Connect</a>
                    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarSupportedContent">
                        <ul class="navbar-nav mr-auto">
                            <li class="nav-item active">
                                <a class="nav-link" href="index.php">Home</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="claims.php">Claims <span class="sr-only">(current)</span></a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="debug-info.php">Debug</a>
                            </li>

                        </ul>        
                    </div>
                </nav>       
            </div>
        </div>
        <form method="post" action="<?=$_SERVER['PHP_SELF'];?>">
            <div class="card">  
                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label for="startDate">Send Date Start</label>
                            <input type="date" class="form-control"  id="startDate" name="startDate"  placeholder="yyyy-mm-dd"/>
                        </div>
                    </div>                    
                    <div class="col">
                        <div class="form-group">
                            <label for="endDate">Send Date End</label>
                            <input type="date" class="form-control"  id="endDate" name="endDate"  placeholder="yyyy-mm-dd"/>
                        </div>
                    </div>
                    <div class="col">
                      
                    </div>                    
                    <div class="col">
                        
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label for="patFirstName">Patient First Name</label>
                            <input type="text" class="form-control"  id="patFirstName" name="patFirstName"  placeholder="Patient First Name"/>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label for="patLastName">Patient Last Name</label>
                            <input type="text" class="form-control"  id="patLastName" name="patLastName"  placeholder="Patient Last Name"/>
                        </div>
                    </div>
                    <div class="col">
                    
                    </div>
                    <div class="col">
                    
                    </div>
                </div>   
                <div class="row">
                    <div class="col">
                        <button type="submit" name="SubmitButton" class="btn btn-primary">Submit</button>
                    </div>
                    <div class="col-10">
                    
                    </div>
                </div>            
                
            </div> 
        </form>


        <?php                  
            $datas = null;
            if(isset($_POST['SubmitButton'])) { //check if form was submitted

                $datas = ClaimsPage::SearchClaims($_POST);
                

            }
            
            if($datas != null){ ?>
                <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Status</th>
                        <th scope="col">Payer Info</th>
                        <th scope="col">Provider Info</th>
                        <th scope="col">Patient Info</th>
                        <th scope="col">Claim Info</th>
                        <th scope="col">Messages</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                        foreach($datas as $data) 
                        {
                    ?>                            
                        <tr>
                            <td>
                                <div class="row">
                                    <div class="col">
                                        <div class="row">
                                            <div class="font-weight-bold col">
                                                ClaimRev Status:
                                            </div>
                                        </div>
                                         <div class="row">
                                            <div class="col">
                                                <?php echo($data->statusName); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <div class="row">
                                            <div class="font-weight-bold col">
                                                File Status:
                                            </div>
                                        </div>
                                         <div class="row">
                                            <div class="col">
                                                <?php echo($data->payerFileStatusName); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col">
                                        <div class="row">
                                            <div class="font-weight-bold col">
                                                Payer Acceptance:
                                            </div>
                                        </div>
                                         <div class="row">
                                            <div class="col">
                                                <?php echo($data->payerAcceptanceStatusName); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <div class="row">
                                            <div class="font-weight-bold col">
                                                ERA:
                                            </div>
                                        </div>
                                         <div class="row">
                                            <div class="col">
                                                <?php echo($data->paymentAdviceStatusName); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>                              
                            </td>
                            <td>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        Name:
                                    </div>
                                    <div class="col">
                                        <?php echo($data->payerName); ?> 
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        Number:
                                    </div>
                                    <div class="col">
                                        <?php echo($data->payerNumber); ?> 
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        Control #:
                                    </div>
                                    <div class="col">
                                        <?php echo($data->payerControlNumber); ?> 
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        Name:
                                    </div>
                                    <div class="col">
                                        <?php echo($data->providerFirstName); ?>  <?php echo($data->providerLastName); ?>  
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        NPI:
                                    </div>
                                    <div class="col">
                                        <?php echo($data->providerNpi); ?> 
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        Name:
                                    </div>
                                    <div class="col">
                                        <?php echo($data->pLastName); ?>, <?php echo($data->pFirstName); ?>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        DOB:
                                    </div>
                                    <div class="col">
                                    <?php echo(substr($data->birthDate,0,10) ); ?>  
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        Gender:
                                    </div>
                                    <div class="col">
                                        <?php echo($data->patientGender); ?> 
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        Member #:
                                    </div>
                                    <div class="col">
                                        <?php echo($data->memberNumber); ?> 
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        Trace #:
                                    </div>
                                    <div class="col">
                                        <?php echo($data->traceNumber); ?>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        Control #:
                                    </div>
                                    <div class="col">
                                        <?php echo($data->payerControlNumber); ?> 
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        Billed Amt:
                                    </div>
                                    <div class="col">
                                        <?php echo($data->billedAmount); ?> 
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        Payed Amt:
                                    </div>
                                    <div class="col">
                                        <?php echo($data->payerPaidAmount); ?> 
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="font-weight-bold col">
                                        Service Date:
                                    </div>
                                    <div class="col">
                                        <?php echo(substr($data->serviceDate,0,10) ); ?> / <?php echo(substr($data->serviceDateEnd,0,10) ); ?> 
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php 
                                    foreach($data->errors as $err)
                                    { 
                                ?>
                                        <?php echo($err->errorMessage); ?>
                                <?php 
                                    } 
                                ?>
                            </td>
                        </tr>                   
                  <?php } ?>    
                  </tbody>               
                </table>
            <?php }
            ?>

       
        
    </body>
</html>

