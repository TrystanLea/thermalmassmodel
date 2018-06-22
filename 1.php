<?php
// ------------------------------------------------------------------------------
// Dynamic Energy Model, Example 1
//
// This example reads in an outside temperature feed from emoncms
// The feed interval is half-hourly but our model requires a higher resolution.
// The input data reader implements linear interpolation with smoothing to
// generate a higher resolution output feed.
// ------------------------------------------------------------------------------
$emoncms_dir = "/var/www/emoncms/";
$model_start = 1496404800;
$model_duration = 3600*24*365;
$model_end = $model_start + $model_duration;
$timestep = 60;

require "ModelHelper.php";
$data = new ModelHelper($emoncms_dir,$model_start,$timestep);
$data->input_feed("model:outsideT",0.1);
$data->output_feed("model:output");

$outside_sum = 0;
$itterations = 0;

// Model loop
$time = $model_start;
while(true)
{
    // Read value from input feeds
    $outside = $data->read("model:outsideT",$time);
    $outside_sum += $outside;
    
    // ---- Model code goes here ----
    
    // Write output to output feed  
    $data->write("model:output",$outside);
    
    $itterations ++;
    $time += $timestep;
    if ($time>$model_end) break;
}

// Final results
$average = $outside_sum / $itterations;
print "Outside temperature:\t\t".number_format($average,1)."C\n";

// Save output feeds
$data->save_all();
