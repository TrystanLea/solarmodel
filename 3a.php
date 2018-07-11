<?php
// ------------------------------------------------------------------------------
// Solar self consumption model: 1) Flat demand
// ------------------------------------------------------------------------------
$emoncms_dir = "/var/www/emoncms/";
$model_start = 1496404800;
$model_duration = 3600*24*365;
$model_end = $model_start + $model_duration;
$timestep = 30;

require "ModelHelper.php";
$data = new ModelHelper($emoncms_dir,$model_start,$timestep);
$data->input_feed("model:solar1",0);
$data->output_feed("model:solar");
$data->output_feed("model:use");
$data->output_feed("model:excess");
$data->output_feed("model:unmet");

$date = new DateTime();
$date->setTimezone(new DateTimeZone("UTC"));
$date->setTimestamp($model_start);
$startdayofweek = $date->format("N");
$startday = floor($model_start / 86400);

$solar_feed_capacity = 4000;  // Solar feed is from a 3 kW system
$solar_capacity = 1589;       // Model a 4kW system
$solar_kw = $solar_capacity * 0.001;

$solar_sum = 0;
$solar_kwh = 0;
$use_kwh = 0;
$excess_kwh = 0;
$unmet_kwh = 0;
$itterations = 0;

// Model loop
$time = $model_start;
while(true)
{    
    $day = floor($time / 86400);
    $seconds_in_day = ($time - ($day*86400));
    $hour_in_day = $seconds_in_day/3600;
    $dayofweek = ($startdayofweek + ($day - $startday)) % 7;
    
    // Read in solar PV dataset
    $solar = $solar_capacity * $data->read("model:solar1",$time) / $solar_feed_capacity;
    if ($solar>$solar_capacity) $solar = $solar_capacity;            // max limit
    if ($solar<0) $solar = 0;                                        // min limit
    $solar_sum += $solar;                                            // used for mean
    
    // -------------------------------------------------------------------------------
    // 1. Central heating or other standby
    // -------------------------------------------------------------------------------
    $centralheating_standby = 15; // Watts
    
    // -------------------------------------------------------------------------------
    // 2. Internet router
    // -------------------------------------------------------------------------------
    $router = 12; // Watts

    // -------------------------------------------------------------------------------
    // 3. Internet router
    // -------------------------------------------------------------------------------
    $monitoring = 10; // Watts
        
    // -------------------------------------------------------------------------------
    // 4. Lighting 
    // -------------------------------------------------------------------------------
    $lighting = 0;
    
    if ($dayofweek>=0 && $dayofweek<5) {
        if ($hour_in_day>=7.5 && $hour_in_day<8.5) $lighting = 11 + 11 + 12 + 11 + 6; // Watts
        if ($hour_in_day>=18.0 && $hour_in_day<23.0) $lighting = 11 + 11 + 12 + 11+ 6; // Watts
    } else {
        if ($hour_in_day>=7.5 && $hour_in_day<23.0) $lighting = 11 + 11 + 12 + 11+ 6; // Watts
    }

    // -------------------------------------------------------------------------------
    // 5. Laptop 
    // -------------------------------------------------------------------------------
    $laptop1 = 0;
    $laptop2 = 0;
    
    if ($dayofweek>=0 && $dayofweek<5) {
        if ($hour_in_day>=18.0 && $hour_in_day<23.0) $laptop1 = 40; // Watts
        if ($hour_in_day>=20.0 && $hour_in_day<21.0) $laptop2 = 35; // Watts
    } else {
        if ($hour_in_day>=10.0 && $hour_in_day<22.0) $laptop1 = 40; // Watts
    }      
    // -------------------------------------------------------------------------------
    // 6. Fridge/Freezer 
    // -------------------------------------------------------------------------------
    $fridge = 0;
    if (($time % 3960)<600) $fridge = 120; // Watts

    // -------------------------------------------------------------------------------
    // 7. Washing machine 
    // -------------------------------------------------------------------------------
    $washing = 0;
    
    if ($dayofweek==4 && $hour_in_day>=10.0 && $hour_in_day<10.0+(900/3600)) $washing += 1700; // Watts
    if ($dayofweek==4 && $hour_in_day>=10.0 && $hour_in_day<11.5) $washing += 150; // Watts

    if ($dayofweek==1 && $hour_in_day>=10.0 && $hour_in_day<10.0+(900/3600)) $washing += 1700; // Watts
    if ($dayofweek==1 && $hour_in_day>=10.0 && $hour_in_day<11.5) $washing += 150; // Watts
    
    // -------------------------------------------------------------------------------
    // 8. Kettle
    // -------------------------------------------------------------------------------
    $kettle = 0;
    if ($dayofweek>=0 && $dayofweek<5) {
        if ($hour_in_day>=7.5 && $hour_in_day<7.5+(120/3600)) $kettle = 3060; // Watts
        if ($hour_in_day>=18.5 && $hour_in_day<18.5+(120/3600)) $kettle = 3060; // Watts
        if ($hour_in_day>=21.0 && $hour_in_day<21.0+(120/3600)) $kettle = 3060; // Watts
    } else {
        if ($hour_in_day>=7.5 && $hour_in_day<7.5+(120/3600)) $kettle = 3060; // Watts
        if ($hour_in_day>=11.0 && $hour_in_day<11.0+(120/3600)) $kettle = 3060; // Watts
        if ($hour_in_day>=13.0 && $hour_in_day<13.0+(120/3600)) $kettle = 3060; // Watts
        if ($hour_in_day>=18.5 && $hour_in_day<18.5+(120/3600)) $kettle = 3060; // Watts
        if ($hour_in_day>=21.0 && $hour_in_day<21.0+(120/3600)) $kettle = 3060; // Watts
    }
    
    // -------------------------------------------------------------------------------
    // 9. hob
    // -------------------------------------------------------------------------------
    $hob = 0;
    if ($dayofweek>=0 && $dayofweek<5) {
        if ($hour_in_day>=7.5 && $hour_in_day<7.5+(600/3600)) $hob = 1000; // Watts
        if ($hour_in_day>=18.6 && $hour_in_day<18.6+(1200/3600)) $hob = 1500; // Watts
    } else {
        if ($hour_in_day>=7.5 && $hour_in_day<7.5+(600/3600)) $hob = 1000; // Watts
        if ($hour_in_day>=13.0 && $hour_in_day<13.0+(600/3600)) $hob = 1000; // Watts
        if ($hour_in_day>=18.6 && $hour_in_day<18.6+(1200/3600)) $hob = 1500; // Watts
    }    
    
    // -------------------------------------------------------------------------------
    // TOTAL CONSUMPTION BEFORE HOT WATER STORE DIVERT
    // -------------------------------------------------------------------------------
    $use = $centralheating_standby + $router + $monitoring + $lighting + $laptop1 + $laptop2 + $fridge + $washing + $kettle + $hob;

    // 3. Calculate balance: supply - demand
    $balance = $solar - $use;
    
    // 4. Calculate excess solar and unmet demand
    $excess = 0;
    $unmet = 0;
    if ($balance>0.0) {
        $excess = $balance;
    } else {
        $unmet = -1 * $balance;
    }
    
    // 5. Cumulative kWh calculation
    $solar_kwh += ($solar * $timestep) / 3600000.0;
    $use_kwh += ($use * $timestep) / 3600000.0;
    $excess_kwh += ($excess * $timestep) / 3600000.0;
    $unmet_kwh += ($unmet * $timestep) / 3600000.0;
    
    // 6. Write output to output feed  
    $data->write("model:solar",$solar);
    $data->write("model:use",$use);
    $data->write("model:excess",$excess);
    $data->write("model:unmet",$unmet);
    
    // Keep track of time and model end
    $itterations ++;
    $time += $timestep;
    if ($time>$model_end) break;
}

$solar_self_use = ($solar_kwh - $excess_kwh);
$solar_self_use_prc = $solar_self_use / $solar_kwh;
$use_from_solar_prc = ($use_kwh - $unmet_kwh) / $use_kwh;

// Final results
$average = $solar_sum / $itterations;
print "Solar:\t\t".number_format($average,1)."W\t".round($solar_kwh)." kWh\n";
print "Use:\t\t\t".round($use_kwh)." kWh\n";
print "Excess:\t\t\t".round($excess_kwh)." kWh\n";
print "Unmet:\t\t\t".round($unmet_kwh)." kWh\n";
print "Solar self consumption:\t".round($solar_self_use_prc*100)." %\n";
print "Demand supplied from solar:\t".round($use_from_solar_prc*100)." %\n";
// Save output feeds
$data->save_all();
