<?php 
if($benefit->dates != null && $benefit->dates )
{
    
?>
    <div class="row">
        <div class="col">
            <div class="card">
                <div class="card-body">
                    <h6>Dates</h6>
                    <div class="row">
                        <div class="col">
                            <ul>
                                <li>
                                    <?php
                                        foreach($benefit->dates as $dtp)
                                        {
                                    ?>
                                            <div class="row">
                                                <div class="col">
                                                    <?php echo($dtp->dateDescription) ?>
                                                </div>
                                                <div class="col">                                                
                                                    Start: <?php echo(substr($dtp->startDate,0,10));  ?> End:  <?php echo(substr($dtp->endDate,0,10)); ?>
                                                </div>
                                            </div>

                                    <?php
                                        }
                                    ?>
                                </li>
                            </ul>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
?>