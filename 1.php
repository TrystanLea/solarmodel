<?php
// ------------------------------------------------------------------------------
// Solar self consumption model
// 1. Model Basics: Reading in solar PV data
// ------------------------------------------------------------------------------
$emoncms_dir = "/var/www/emoncms/";
$model_start = 1499554800;
$model_duration = 3600*24*365;
$model_end = $model_start + $model_duration;
$timestep = 30;

require "ModelHelper.php";
$data = new ModelHelper($emoncms_dir,$model_start,$timestep);
$data->input_feed("model:solar2",0);
$data->output_feed("model:solar");

$solar_feed_capacity = 4000;  // Solar feed is from a 3 kW system
$solar_capacity = 4000;       // Model a 4kW system
$solar_kw = $solar_capacity * 0.001;

$solar_sum = 0;
$solar_kwh = 0;
$itterations = 0;

// Model loop
$time = $model_start;
while(true)
{    
    // Read in solar PV dataset
    $solar = $solar_capacity * $data->read("model:solar2",$time) / $solar_feed_capacity;
    if ($solar>$solar_capacity) $solar = $solar_capacity;  // max limit
    if ($solar<0) $solar = 0;                                        // min limit
    $solar_sum += $solar;
    
    // Cumulative kWh calculation
    $solar_kwh += ($solar * $timestep) / 3600000.0;
    
    // Write output to output feed  
    $data->write("model:solar",$solar);
    
    // Keep track of time and model end
    $itterations ++;
    $time += $timestep;
    if ($time>$model_end) break;
}

// Final results
$solar_mean = $solar_sum / $itterations;
$solar_kwh_per_kw = $solar_kwh / $solar_kw;

print "Solar\tkWp: ".number_format($solar_kw,1)."kW\t".number_format($solar_mean,1)."W\t".round($solar_kwh)." kWh\t".round($solar_kwh_per_kw)." kWh/kWp\n";

// Save output feeds
$data->save_all();
