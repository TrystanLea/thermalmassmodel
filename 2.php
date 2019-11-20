<?php
// ------------------------------------------------------------------------------
// Dynamic Energy Model, Example 2
// This example calculates the steady state total heat requirement to keep a 
// building with a heat loss rate of 204 W/K at 21C with the external temperature
// dataset provided.
// ------------------------------------------------------------------------------
$emoncms_dir = "/var/www/emoncms/";
$model_start = 1496404800;
$model_duration = 3600*24*365;
$model_end = $model_start + $model_duration;
$timestep = 60;

require "ModelHelper.php";
$data = new ModelHelper($emoncms_dir,$model_start,$timestep);
$data->input_feed("model:outsideT",0.1);
$data->output_feed("model:heat");

$outside_sum = 0;
$heatloss_sum = 0;
$itterations = 0;

$wk = 204.4;

// Model loop
$time = $model_start;
while(true)
{
    // Read value from input feeds
    $outside = $data->read("model:outsideT",$time);
    $outside_sum += $outside;
    
    // ---- Model code goes here ----
    $heatloss = $wk * (21.0 - $outside);
    if ($heatloss<0) $heatloss = 0;
    
    $heatloss_sum += $heatloss;
    
    // Write output to output feed  
    $data->write("model:heat",$heatloss);
    
    $itterations ++;
    $time += $timestep;
    if ($time>$model_end) break;
}

// Final results
$average = $outside_sum / $itterations;
print "Outside temperature:\t\t".number_format($average,1)."C\n";

$average = $heatloss_sum / $itterations;
$kwh = $average * 0.024 * 365;
print "Heating:\t\t".round($average)."W\t".round($kwh)." kWh\n";


// Save output feeds
$data->save_all();
