<?php

// -------------------------------------------------------------------------------
// Dynamic heating model
// -------------------------------------------------------------------------------
// Author: Trystan Lea
// part of the OpenEnergyMonitor project

// -------------------------------------------------------------------------------
// How to use: Configuration
// -------------------------------------------------------------------------------
$emoncms_dir = "/var/www/emoncms/";
$model_start = 1496404800;
$model_duration = 3600*24*365;
$model_end = $model_start + $model_duration;
$timestep = 60;

require "ModelHelper.php";
$data = new ModelHelper($emoncms_dir,$model_start,$timestep);
$data->input_feed("model:outsideT",0.1);
$data->output_feed("model:roomT");
$data->output_feed("model:heat");

$date = new DateTime();
$date->setTimezone(new DateTimeZone("UTC"));
$date->setTimestamp($model_start);
$startdayofweek = $date->format("N");
$startday = floor($model_start / 86400);

// --------------------------------------------------------------
// Building config
// --------------------------------------------------------------
// 1. Overall dimensions
$floorarea = 76.8;
$storey_height = 2.42;
// 2. Wall area
$wall_uvalue = 1.7; $wall_area = 42.8;
// 3. Windows
$window_uvalue = 3.1; $window_area = 9.8;
$window_wk = $window_uvalue * $window_area;
// 4. Doors
$door_uvalue = 3.1; $door_area = 3.4;
$door_wk = $door_uvalue * $door_area;
// 5. Loft
$loft_uvalue = 0.18; $loft_area = 38.4;
$loft_wk = $loft_uvalue * $loft_area;
// 6. Floor
$floor_uvalue = 0.60; $floor_area = 38.4;
$floor_wk = $floor_uvalue * $floor_area;
// 7. Thermal Bridge
$thermalbridge_wk = 14.0;

// Wall layers configuration
// 0.5m of Stone Wall, Density 2227 kg/m3, Specific Heat J/kg/K, Thermal Conductivity: 0.85
$d = 2227; $sh = 1776; $c = $wall_uvalue * 0.5;
$wall = array();
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.050);
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.050);
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.100);
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.100);
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.100);
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.050);
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.050);

// Heating control
$continuous_heating = true;
$preheat = 1.0;
$setpoint = 21.0;
$hs = 0.1;

// Infiltration & Ventilation
// DIN1946: 30m3 per person per hour
$air_change_rate = 0.74;

// --------------------------------------------------------------------------------
// Preprocess wall elements
// --------------------------------------------------------------------------------
$wlen = count($wall);
$external = 0;
$internal = $wlen-1;

$u = array();
$k = array();
$t = array();
$e = array();
$h = array();

$wksum = 0;
for ($i=0; $i<$wlen; $i++) {
    $u[$i] = $wall_area * $wall[$i]['conductivity'] / $wall[$i]['thickness'];
    $k[$i] = $wall_area * $wall[$i]['thickness'] * $wall[$i]['density'] * $wall[$i]['specificheat'];
    $t[$i] = 17.0;
    $e[$i] = $k[$i] * $t[$i];
    $h[$i] = 0;
    
    $wksum += (1/$u[$i]);
    // print $i." u:".round($u[$i])." k:".round($k[$i])." t:".number_format($t[$i],1)."\n";
}
$wall_wk = 1 / $wksum;

// -------------------------------------------------------------------------------

$wallair_u = 5000;  // W/K
$room_k = 0.5 * 3600000;  // 0.5 kWh of heat storage, air ~0.06 kWh/K + furniture, carpets, thin layer of internal walls
$room_t = 17.0;
$room_e = $room_k * $room_t;
$room_h = 0;

// -------------------------------------------------------------------------------

$total_air_volume = $floorarea * $storey_height;
$volume_ACH = $total_air_volume * $air_change_rate;
$infiltration_wk = 1000 * 1.225 * $volume_ACH * (1/3600);

$nonthermalmass_wk = $infiltration_wk+$window_wk+$door_wk+$floor_wk+$loft_wk+$thermalbridge_wk;

print "Wall WK:".round($wall_wk)."\n";
print "Windows & doors WK:".round($window_wk+$door_wk)."\n";
print "Floor WK:".round($floor_wk)."\n";
print "Loft WK:".round($loft_wk)."\n";
print "Infiltration WK:".round($infiltration_wk)."\n";
print "Total WK:".number_format($wall_wk+$nonthermalmass_wk,1)."\n";
print "total_air_volume: ".$total_air_volume." m3\n";
print "volume_ACH: ".$volume_ACH." m3\n";

// --------------------------------------------------------------------------------

// Init variables
$last_internal = 15;

$outside_sum = 0;
$heat_sum = 0;
$heat_output = 0;
$itterations = 0;
$occupied_room_t = 0;
$room_t_sum = 0;
$n = 0;

$time = $model_start;
while(true)
{
    // Time
    $day = floor($time / 86400);
    $seconds_in_day = ($time - ($day*86400));
    $hour_in_day = $seconds_in_day/3600;
    $dayofweek = ($startdayofweek + ($day - $startday)) % 7;

    // Many demands are based on occupation
    $occupied = false;
    if ($dayofweek<5 && ($hour_in_day>=7 && $hour_in_day<9)) $occupied = true;   // mon - fri, morning
    if ($dayofweek<5 && ($hour_in_day>=18 && $hour_in_day<23)) $occupied = true; // mon - fri, morning
    if ($dayofweek>=5 && ($hour_in_day>=7 && $hour_in_day<23)) $occupied = true; // weekend
    
    $occupied_preheat = false;
    if ($dayofweek<5 && ($hour_in_day>=7-$preheat && $hour_in_day<9)) $occupied_preheat = true;   // mon - fri, morning
    if ($dayofweek<5 && ($hour_in_day>=18-$preheat && $hour_in_day<23)) $occupied_preheat = true; // mon - fri, morning
    if ($dayofweek>=5 && ($hour_in_day>=7-$preheat && $hour_in_day<23)) $occupied_preheat = true; // weekend
    
    // ------------------------------------------------------------------------------
    // Load dataset
    // ------------------------------------------------------------------------------
    $outside = $data->read("model:outsideT",$time);
    
    // ------------------------------------------------------------------------------
    // Model start
    // ------------------------------------------------------------------------------    
    if ($occupied_preheat) $setpoint = 21.0; else $setpoint = 0;
    if ($continuous_heating) $setpoint = 21.0; 
    
    // Variable drive heat output
    $diff = $setpoint - $room_t; 
    $rate = $room_t - $last_internal;
    $internal_prediction = $room_t+(30*$rate);
    if ($internal_prediction>=$setpoint+($hs/2)) $heat_output -= 500 * abs($diff);
    if ($internal_prediction<$setpoint-($hs/2)) $heat_output += 100 * abs($diff);
    
    // Maximum heating system heat output
    $max_heat_output = 35000;
    
    // Limit heat output to max output capacity
    if ($heat_output>$max_heat_output) $heat_output = $max_heat_output;
    if ($heat_output<0) $heat_output = 0;
    
    // -------------------------------------------------------------------------------
    // Remaining gains and sum all
    // -------------------------------------------------------------------------------
    
    // Add up all contributions to heat input
    $heat_input = $heat_output;
    
    // Non thermal mass heat loss
    $deltaT = $room_t - $outside;
    $nonthermalmass_heatloss = $deltaT * $nonthermalmass_wk;
    
    $room_h = $heat_input - $nonthermalmass_heatloss;
    if ($wlen>0) $room_h -= $wallair_u*($room_t-$t[$wlen-1]);
    
    // ------------------------------------------------------------------------------
    // Thermal mass model
    // ------------------------------------------------------------------------------
    for ($i=0; $i<$wlen; $i++) {
        if ($i==0) {               // outside section
            // heat flow to outside section, heat flow from next internal - heat flow to outside
            $h[$i] = $u[$i+1]*($t[$i+1]-$t[$i]) - $u[$i]*($t[$i]-$outside);
            
        } else if ($i<$internal) {  // center section
            $h[$i] = $u[$i+1]*($t[$i+1]-$t[$i]) - $u[$i]*($t[$i]-$t[$i-1]);
            
        } else if ($i==$internal) { // inside section
            // heat flow to internal layer, heat input from heating system - heat flow to next section
            $h[$i] = $wallair_u*($room_t-$t[$i]) - $u[$i]*($t[$i]-$t[$i-1]);
        }
    }
    
    $last_internal = $room_t;
    $room_e += $room_h * $timestep;
    $room_t = $room_e / $room_k;
    
    // External wall energy and temperature calc
    for ($i=0; $i<$wlen; $i++) $e[$i] += $h[$i] * $timestep;
    for ($i=0; $i<$wlen; $i++) $t[$i] = $e[$i] / $k[$i];

    $outside_sum += $outside;
    $heat_sum += $heat_input;   
    
    if ($occupied) {
        $occupied_room_t += $room_t;
        $n++;
    } 
    
    $room_t_sum += $room_t;

    // Write output to output feeds
    $data->write("model:roomT",$room_t);
    $data->write("model:heat",$heat_output);

    $itterations ++;
    $time += $timestep;
    if ($time>$model_end) break;
}
// ------------------------------------------------------------------------------
// Final results
// ------------------------------------------------------------------------------
$average = $heat_sum / $itterations;
$kwh = $average * 0.024 * 365;
print "Heating:\t\t".round($average)."W\t".round($kwh)." kWh\n";

$average = $outside_sum / $itterations;
print "Outside temperature:\t\t".number_format($average,1)."C\n";

$average = $occupied_room_t / $n;
print "Temperature during heating period:\t".number_format($average,1)."C\n";

$average = $room_t_sum / $itterations;
print "Mean internal temperature:\t\t".number_format($average,1)."C\n";

// Save output feeds
$data->save_all();
