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
$data->input_feed("model:wind",0.1);
$data->input_feed("model:solar2",0.1);
$data->input_feed("model:outsideT",0.1);
$data->output_feed("model:roomT");
$data->output_feed("model:flowT");
$data->output_feed("model:returnT");
$data->output_feed("model:heat");
$data->output_feed("model:heatpump_use");
$data->output_feed("model:COP");

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
$party_wall_uvalue = 1.7; $party_wall_area = 85.91;
// 3. Windows
$window_uvalue = 0.85; $window_area = 9.8;
$window_wk = $window_uvalue * $window_area;
// 4. Doors
$door_uvalue = 0.85; $door_area = 3.4;
$door_wk = $door_uvalue * $door_area;
// 5. Loft
$loft_uvalue = 0.12; $loft_area = 38.4;
$loft_wk = $loft_uvalue * $loft_area;
// 6. Floor
$floor_uvalue = 0.23; $floor_area = 38.4;
$floor_wk = $floor_uvalue * $floor_area;
// 7. Thermal Bridge
$thermalbridge_wk = 10.0;

// Wall layers configuration
// 0.5m of Stone Wall, Density 2227 kg/m3, Specific Heat J/kg/K, Thermal Conductivity: 0.85

$wall = array();

$d = 270; $sh = 2300; $c = 0.039;
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.060);
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.060);

$d = 2227; $sh = 1776; $c = $wall_uvalue * 0.5;
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.050);
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.050);
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.100);
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.100);
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.100);
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.050);
$wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.050);

// Party Wall
$party_wall = array();
// 0.5m of stone wall
$d = 2227; $sh = 1776; $c = $party_wall_uvalue * 0.5;
$party_wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.050);
$party_wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.050);
$party_wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.100);
$party_wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.100);
$party_wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.100);
$party_wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.010);
$party_wall[] = array("conductivity"=>$c,"density"=>$d,"specificheat"=>$sh,"thickness"=>0.010);

// Heating control
$smart_heating = true;
$continuous_heating = true;
$preheat = 1.0;
$setpoint = 21.0;
$hs = 0.1;

// Radiator model params
$RatedPower = 16000;
$RatedDeltaT = 50;
// Heatpump model params
$heatpump_capacity = 4800;

$wind_capacity = 160;

// Solar gains factor
$solar_gain_factor  = 0.0;
$solar_gain_factor += 5.2 * 43.7 * 12.5;  // South              43.7 W/m2
$solar_gain_factor += 0.0 * 41.3 * 12.5;  // South west/east    41.3 W/m2
$solar_gain_factor += 2.9 * 34.3 * 12.5;  // West/East          34.3 W/m2
$solar_gain_factor += 0.0 * 25.6 * 12.5;  // North west/east    25.6 W/m2
$solar_gain_factor += 3.4 * 21.3 * 12.5;  // North              21.3 W/m2

// Infiltration & Ventilation
// DIN1946: 30m3 per person per hour
$air_change_rate = 0.3;

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

// --------------------------------------------------------------------------------

$party_wlen = count($party_wall);
$party_external = 0;
$party_internal = $party_wlen-1;

$party_u = array();
$party_k = array();
$party_t = array();
$party_e = array();
$party_h = array();

$party_wksum = 0;
for ($i=0; $i<$party_wlen; $i++) {
    $party_u[$i] = $party_wall_area * $party_wall[$i]['conductivity'] / $party_wall[$i]['thickness'];
    $party_k[$i] = $party_wall_area * $party_wall[$i]['thickness'] * $party_wall[$i]['density'] * $party_wall[$i]['specificheat'];
    $party_t[$i] = 17.0;
    $party_e[$i] = $party_k[$i] * $party_t[$i];
    $party_h[$i] = 0;
    
    $party_wksum += (1/$party_u[$i]);
    // print $i." u:".round($party_u[$i])." k:".round($party_k[$i])." t:".number_format($party_t[$i],1)."\n";
}

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

$nonthermalmass_wk = $window_wk+$door_wk+$floor_wk+$loft_wk+$thermalbridge_wk;

print "Wall WK:".round($wall_wk)."\n";
print "Windows & doors WK:".round($window_wk+$door_wk)."\n";
print "Floor WK:".round($floor_wk)."\n";
print "Loft WK:".round($loft_wk)."\n";
print "Infiltration WK:".round($infiltration_wk)."\n";
print "Total WK:".number_format($wall_wk+$nonthermalmass_wk+$infiltration_wk,1)."\n";
print "total_air_volume: ".$total_air_volume." m3\n";
print "volume_ACH: ".$volume_ACH." m3\n";

// --------------------------------------------------------------------------------

// Init variables
$last_internal = 15;

$outside_sum = 0;
$heatpump_use_sum = 0;
$heatpump_heat_sum = 0;
$heatpump_flowT_sum = 0;
$heatpump_returnT_sum = 0;

$heating_output = 0;
$heatpump_use = 0;
$itterations = 0;
$occupied_room_t = 0;
$room_t_sum = 0;
$solar_gain_sum = 0;
$flow_temperature = 17.0;
$return_temperature = 17.0;

$n = 0;

$time = $model_start;
while(true)
{
    // Time
    $date->setTimestamp($time);
    $month = (int) $date->format("m");
    
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
    $wind = $data->read("model:wind",$time) / 11000.0;
    $solar = $data->read("model:solar2",$time) / 3750.0;
    if ($solar<0) $solar = 0;
    
    $supply = $wind * $wind_capacity;
    $balance = $supply;
    
    // ------------------------------------------------------------------------------
    // Model start
    // ------------------------------------------------------------------------------    
    if ($occupied_preheat) $setpoint = 21.0; else $setpoint = 0;
    if ($continuous_heating) $setpoint = 21.0; 
    
    // Variable drive heat output
    $diff = $setpoint - $room_t; 
    $rate = $room_t - $last_internal;
    $internal_prediction = $room_t+(30*$rate);
    if ($internal_prediction>=$setpoint+($hs/2)) {
        $heatpump_use -= 125 * abs($diff);
        // if ($heatpump_use<450) $heatpump_use = 0;
    }
    
    if ($internal_prediction<$setpoint-($hs/2)) {
        $heatpump_use += 25 * abs($diff);
        // if ($heatpump_use<450) $heatpump_use = 450;
    }

    // Typically the max heat output is the heatpump capacity
    $max_heatpump_use = 0.36 * $heatpump_capacity;
    // $min_heatpump_use = 450;

    // However when smart heating is enabled the max heat output can be less as it is 
    // driven by the available electricity supply multipled by the COP of the last run 30s ago
    if ($smart_heating) $max_heatpump_use = $balance;
    
    // Limit heat output to max output capacity
    if ($heatpump_use>$max_heatpump_use) $heatpump_use = $max_heatpump_use;
    if ($heatpump_use<0) $heatpump_use = 0;
    
    // Radiator & Heatpump model
    // 1. Calculate required condencing and refrigerant temperature based on current temperatures
    $condencing = $flow_temperature + 4.0;                                          // 35+3 = 38C
    $refrigerant = $outside - 6.0;                                                  // 10-3 = 7C
    // 2. Work out the resulting COP
    $IdealCOP = ($condencing + 273.15) / (($condencing+273.15) - ($refrigerant + 273.15)); // COP:10.0
    $PracticalCOP = 0.45 *  $IdealCOP;                                            // COP:4.5
    // 3. Calculate the heatpump output heat
    $heatpump_heat = $heatpump_use * $PracticalCOP;                               // 450W
    // 4. Calculate the rise in temperature in the heating circuit
    $heating_circuit_water_volume = 10.0; // Litres                                 // 35 + 0.00537506 k
    $flow_temperature = $return_temperature + (($heatpump_heat*$timestep) / ($heating_circuit_water_volume * 4186.0));
    // 5. Calculate radiator output, uses the last return temperature to estimate the MWT
    $MWT = ($flow_temperature + $return_temperature)*0.5;
    $Delta_T = $MWT - $room_t;
    $heating_output = $RatedPower * ($Delta_T / $RatedDeltaT) ^ 1.3;
    
    // 6. Calculate resulting return temperature
    $return_temperature = $flow_temperature - ((($heating_output)*$timestep) / ($heating_circuit_water_volume * 4186.0));

    $heatpump_use_sum += $heatpump_use;
    $heatpump_heat_sum += $heating_output;
    $heatpump_flowT_sum += $flow_temperature;
    $heatpump_returnT_sum += $return_temperature;
    
    // -------------------------------------------------------------------------------
    // Remaining gains and sum all
    // -------------------------------------------------------------------------------
    $solar_gain = $solar_gain_factor * $solar;
    $solar_gain_sum += $solar_gain;
        
    // Add up all contributions to heat input
    $heat_input = $heating_output + $solar_gain + 350;
    
    // Open windows if temperature goes over 22C
    $volume_ACH_out = $volume_ACH;
    if ($room_t>22.0) $volume_ACH_out = $volume_ACH + ($total_air_volume * 3.0);
    
    // Ventilation and infiltration
    $infiltration_wk = 1000 * 1.225 * $volume_ACH_out * (1/3600);
    
    // Non thermal mass heat loss
    $deltaT = $room_t - $outside;
    $nonthermalmass_heatloss = $deltaT * ($nonthermalmass_wk + $infiltration_wk);
    
    $room_h = $heat_input - $nonthermalmass_heatloss;
    if ($wlen>0) $room_h -= $wallair_u*($room_t-$t[$wlen-1]);
    if ($party_wlen>0) $room_h -= $wallair_u*($room_t-$party_t[$party_wlen-1]); 
     
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
    
    // ------------------------------------------------------------------------------
    // Party Wall Thermal mass model
    // ------------------------------------------------------------------------------
    for ($i=0; $i<$party_wlen; $i++) {
        if ($i==0) {               // outside section
            // heat flow to outside section, heat flow from next internal - heat flow to neighbouring house assumed same temperature
            $party_h[$i] = $party_u[$i+1]*($party_t[$i+1]-$party_t[$i]) - $party_u[$i]*($party_t[$i]-$room_t);
            
        } else if ($i<$party_internal) {  // center section
            $party_h[$i] = $party_u[$i+1]*($party_t[$i+1]-$party_t[$i]) - $party_u[$i]*($party_t[$i]-$party_t[$i-1]);
            
        } else if ($i==$party_internal) { // inside section
            // heat flow to internal layer, heat input from heating system - heat flow to next section
            $party_h[$i] = $wallair_u*($room_t-$party_t[$i]) - $party_u[$i]*($party_t[$i]-$party_t[$i-1]);
        }
    }
    
    $last_internal = $room_t;
    $room_e += $room_h * $timestep;
    $room_t = $room_e / $room_k;
    
    // External wall energy and temperature calc
    for ($i=0; $i<$wlen; $i++) $e[$i] += $h[$i] * $timestep;
    for ($i=0; $i<$wlen; $i++) $t[$i] = $e[$i] / $k[$i];

    // Party wall energy and temperature calc
    for ($i=0; $i<$party_wlen; $i++) $party_e[$i] += $party_h[$i] * $timestep;
    for ($i=0; $i<$party_wlen; $i++) $party_t[$i] = $party_e[$i] / $party_k[$i];
    
    $outside_sum += $outside;
    
    if ($occupied) {
        $occupied_room_t += $room_t;
        $n++;
    } 
    
    $room_t_sum += $room_t;

    // Write output to output feeds
    $data->write("model:flowT",$flow_temperature);
    $data->write("model:returnT",$return_temperature);
    $data->write("model:roomT",$room_t);
    $data->write("model:heat",$heating_output);
    $data->write("model:heatpump_use",$heatpump_use);
    $data->write("model:COP",$PracticalCOP);
    
    $itterations ++;
    $time += $timestep;
    if ($time>$model_end) break;
}

// ------------------------------------------------------------------------------
// Final results
// ------------------------------------------------------------------------------
$average = $solar_gain_sum / $itterations;
$kwh = $average * 0.024 * 365;
print "Solar Gain:\t\t".round($average)."W\t".round($kwh)." kWh\n";

$average = $heatpump_use_sum / $itterations;
$kwh1 = $average * 0.024 * 365;
print "Heatpump use:\t\t".round($average)."W\t".round($kwh1)." kWh\n";

$average = $heatpump_heat_sum / $itterations;
$kwh2 = $average * 0.024 * 365;
print "Heatpump output:\t".round($average)."W\t".round($kwh2)." kWh\n";

print "Heatpump COP:\t\t".number_format($kwh2/$kwh1,1)."\n";

$average = $outside_sum / $itterations;
print "Outside temperature:\t\t".number_format($average,1)."C\n";

$average = $heatpump_flowT_sum / $itterations;
print "Flow temperature:\t\t".number_format($average,1)."C\n";

$average = $heatpump_returnT_sum / $itterations;
print "Return temperature:\t\t".number_format($average,1)."C\n";

$average = $occupied_room_t / $n;
print "Temperature during heating period:\t".number_format($average,1)."C\n";

$average = $room_t_sum / $itterations;
print "Mean internal temperature:\t\t".number_format($average,1)."C\n";

// Save output feeds
$data->save_all();
