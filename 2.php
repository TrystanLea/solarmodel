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

$solar_feed_capacity = 4000;  // Solar feed is from a 3 kW system
$solar_capacity = 4000;       // Model a 4kW system
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
    // 1. Read in solar PV dataset
    $solar = $solar_capacity * $data->read("model:solar1",$time) / $solar_feed_capacity;
    if ($solar>$solar_capacity) $solar = $solar_capacity;            // max limit
    if ($solar<0) $solar = 0;                                        // min limit
    $solar_sum += $solar;                                            // used for mean
    
    // 2. Flat consumption profile
    $use = 350.3;

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
