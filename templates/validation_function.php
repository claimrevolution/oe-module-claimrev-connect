<?php
function printValidation($title,$validations)
{
   if($validations != null)
   {
?>
       <div class="row">
           <div class="col">
               <div class="card">
                   <div class="card-body">
                       <h6><?php echo($title); ?></h6>
<?php
                       foreach( $validations as $validation )
                       {
                           $noValidations = false;
?>
                           <div class="row">
                               <div class="col">
                                   Is Valid Request?
                               </div>
                               <div class="col">
                                   <?php echo($validation->validRequestIndicator); ?>
                               </div>
                           </div>
                           <div class="row">
                               <div class="col">
                                   Reject Reason
                               </div>
                               <div class="col">
                                   <?php echo($validation->rejectReasonCode); ?>
                               </div>
                           </div>
                           <div class="row">
                               <div class="col">
                                   Followup Action
                               </div>
                               <div class="col">
                                   <?php echo($validation->followUpActionCode); ?>
                               </div>
                           </div>
<?php
                       }
?>
                   </div>
               </div>
           </div>
       </div>
<?php
   }
}
?>