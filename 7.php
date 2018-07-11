<?php
// ------------------------------------------------------------------------------
// Solar self consumption model: 1) Flat demand
// ------------------------------------------------------------------------------
$emoncms_dir = "/var/www/emoncms/";
$model_start = 1499554800; //1496404800;
$model_duration = 3600*24*365*1;
$model_end = $model_start + $model_duration;
$timestep = 30;

require "ModelHelper.php";
$data = new ModelHelper($emoncms_dir,$model_start,$timestep);
$data->input_feed("model:solar1",0);
$data->output_feed("model:solar");
$data->output_feed("model:use");
$data->output_feed("model:excess");
$data->output_feed("model:unmet");
$data->output_feed("model:cylinderT");
$data->output_feed("model:battery_energy");

$date = new DateTime();
$date->setTimezone(new DateTimeZone("UTC"));
$date->setTimestamp($model_start);
$startdayofweek = $date->format("N");
$startday = floor($model_start / 86400);

$solar_feed_capacity = 4000;  // Solar feed is from a 3 kW system
$solar_capacity = 4000;       // Model a 4kW system
$solar_kw = $solar_capacity * 0.001;

$dhw = true;
$ev = true;

// Battery storage
$battery_capacity = 2.5;
$battery_energy = $battery_capacity * 0.5;
   
$solar_sum = 0;
$solar_kwh = 0;
$use_kwh = 0;
$excess_kwh = 0;
$unmet_kwh = 0;
$itterations = 0;
$cylinder_temperature = 10;

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
    // 10. EV Fixed rate charge
    // -------------------------------------------------------------------------------
    $evcharge = 0;
    
    if ($ev) {
        // Monday
        if ($dayofweek==0 && $hour_in_day>=9.0 && $hour_in_day<11.0) $evcharge = 10*230;
        // Tuesday
        if ($dayofweek==1 && $hour_in_day>=14.0 && $hour_in_day<16.0) $evcharge = 10*230;
        // Wednesday
        if ($dayofweek==2 && $hour_in_day>=10.0 && $hour_in_day<15.0) $evcharge = 10*230;
        // Saturday morning
        if ($dayofweek==5 && $hour_in_day>=9.0 && $hour_in_day<11.0) $evcharge = 10*230;
        // Sunday all day
        if ($dayofweek==6 && $hour_in_day>=9.0 && $hour_in_day<15.0) $evcharge = 10*230;
    }

        
    // -------------------------------------------------------------------------------
    // TOTAL CONSUMPTION BEFORE HOT WATER STORE DIVERT
    // -------------------------------------------------------------------------------
    $use_before_hw = $centralheating_standby + $router + $monitoring + $lighting + $laptop1 + $laptop2 + $fridge + $washing + $kettle + $hob + $evcharge;

    // 3. Calculate balance: supply - demand
    $balanceA = $solar - $use_before_hw;
    
    // -------------------------------------------------------------------------------
    // 11. Battery storage
    // -------------------------------------------------------------------------------
    if ($balanceA>0) {
        $charge = $balanceA;
        $battery_delta = ($charge*0.95 * $timestep)/3600000.0;
        
        if (($battery_energy+$battery_delta)<=$battery_capacity) {
            $battery_energy += $battery_delta;
        } else {
            $charge = 0;
        }
        
        $balanceA -= $charge;
        $use_before_hw += $charge;
    } else {
        $discharge = -$balanceA;
        $battery_delta = (($discharge/0.95) * $timestep)/3600000.0;
        
        if (($battery_energy-$battery_delta)>=0) {
            $battery_energy -= $battery_delta;
        } else {
            $discharge = 0;
        }
        
        $balanceA += $discharge;
        $use_before_hw -= $discharge;
    }
    
    
    // -------------------------------------------------------------------------------
    // 11. Domestic hot water
    // -------------------------------------------------------------------------------
    $shower_flowrate = 0.07;
    $shower_duration = 10*60; // 10 mins
    $shower_temperature = 38;
    $inlet_temperature = 10;
    $tank_litres = 120;
    $immersion_topup = 0;
    $immersion_power = 0;
    $dhw_heat = 0;
    $tank_heat_loss = 0;
    
    if ($dhw) {
        if ($hour_in_day>=21.5 && $hour_in_day<(21.5+($shower_duration/3600))) {
            // If cylinder temperature is hotter than desired shower temperature, mix in cold water and so reduce flow rate from cylinder.
            if ($cylinder_temperature>=$shower_temperature) {
                $cylinder_flowrate = $shower_flowrate * (($shower_temperature-$inlet_temperature)/($cylinder_temperature-$inlet_temperature));
            } else {
            // If cylinder temperature is the same as or less than shower temperature, flow rate is the same but topup may be required.
                $cylinder_flowrate = $shower_flowrate;
                $immersion_topup = 4184 * $cylinder_flowrate * ($shower_temperature-$cylinder_temperature);
            }
            $dhw_heat = 4184 * $cylinder_flowrate * ($cylinder_temperature-$inlet_temperature);
        }
        
        $tank_heat_loss = 0.88 * 0.667 * ($tank_litres/120) * ($cylinder_temperature - 22.0);
        
        
        if ($balanceA>50.0 && $cylinder_temperature<60.0) {
            $immersion_power = 1.0*$balanceA;
        }
        
        $cylinder_power = $immersion_power - $dhw_heat - $tank_heat_loss;
        $cylinder_deltaT = ($cylinder_power * $timestep) / (4184 * $tank_litres);
        $cylinder_temperature += $cylinder_deltaT;
    }
    
    // -------------------------------------------------------------------------------
    // Final balance
    // -------------------------------------------------------------------------------
    $use = $use_before_hw + $immersion_power + $immersion_topup;
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
    $data->write("model:cylinderT",$cylinder_temperature);
    $data->write("model:battery_energy",$battery_energy);
    // Keep track of time and model end
    $itterations ++;
    $time += $timestep;
    if ($time>$model_end) break;
}
$data->save_all();

$solar_self_use = ($solar_kwh - $excess_kwh);
$solar_self_use_prc = $solar_self_use / $solar_kwh;
$use_from_solar_prc = ($use_kwh - $unmet_kwh) / $use_kwh;

// Final results
$average = $solar_sum / $itterations;
print "Solar:\t\t".number_format($average,1)."W\t".round($solar_kwh)." kWh ".round($solar_kwh/$solar_kw)." kWh/kWp\n";
print "Use:\t\t\t".round($use_kwh)." kWh ".number_format($use_kwh/365,1)." kWh/d\n";
print "Excess:\t\t\t".round($excess_kwh)." kWh\n";
print "Unmet:\t\t\t".round($unmet_kwh)." kWh\n";
print "Solar self consumption:\t".round($solar_self_use_prc*100)." %\n";
print "Demand supplied from solar:\t".round($use_from_solar_prc*100)." %\n";
// Save output feeds

// -------------------------------------------------------------------------
// Cost model
// -------------------------------------------------------------------------
print "\n";

// Feed in tariff and import 
$import_unitcost = 0.152;
$generation_tariff = 0.0411;
$export_tariff = 0.049;

// Solar cost model
$solar_system_cost = (2500 * pow($solar_kw,-0.368))*$solar_kw;
// $solar_system_cost = (983 * pow($solar_kw,-0.095))*$solar_kw;
$inverter_replacement_20y = $solar_kw * 250 * 0.4;              // Assuming 60% reduction in 4kW inverter costs by 2037

$battery_cost_y0 = $battery_capacity * 500.0;    // Price today 2017 after 14% year on year reduction in cost between 2007 and 2014
$battery_cost_y15 = $battery_capacity * 114.0;   // 10% year on year reduction in cost from y0 to y15
$battery_cost_y30 = $battery_capacity * 56.0;    // 5% year on year reduction in cost from y15 through to y30

$pvdiverter = 0;
if ($dhw) $pvdiverter = 365;

$full_20y_system_cost = $solar_system_cost + $battery_cost_y0 + ($battery_cost_y15*0.25) + $pvdiverter;
$full_35y_system_cost = $solar_system_cost + $battery_cost_y0 + ($battery_cost_y15*1.00) + $inverter_replacement_20y + ($battery_cost_y30*0.25) + $pvdiverter;

print "System cost 20y: £".round($full_20y_system_cost)."\n";
print "System cost 35y: £".round($full_35y_system_cost)."\n";

$self_consumption_value_1y = $solar_self_use*$import_unitcost;
$self_consumption_value_20y = $solar_self_use*$import_unitcost*20;
print "Value of solar self consumption @ ".number_format($import_unitcost*100,1)." p/kWh: £".number_format($self_consumption_value_1y,2)."/y (over 20y: £".number_format($self_consumption_value_20y,0).")\n";

$genexport_value_1y = ($solar_kwh*$generation_tariff) + ($solar_kwh*0.5*$export_tariff);
$genexport_value_20y = $genexport_value_1y * 20;
print "Value of generation + 50% export tariff £".number_format($genexport_value_1y,2)."/y (over 20y: £".number_format($genexport_value_20y,0).")\n";

$payback = $full_20y_system_cost / ($self_consumption_value_1y+$genexport_value_1y);

if ($payback<=20) {
    print "Subsidised system payback ".number_format($payback,1)."/y\n";
} else {
    $payback = ($full_35y_system_cost - $genexport_value_20y) / $self_consumption_value_1y;
    print "Subsidised system payback ".number_format($payback,1)."/y\n";
}

$payback = $full_20y_system_cost / ($self_consumption_value_1y);

if ($payback<=20) {
    print "Unsubsidised system payback ".number_format($payback,1)."/y\n";
} else {
    $payback = ($full_35y_system_cost) / $self_consumption_value_1y;
    print "Unsubsidised system payback ".number_format($payback,1)."/y\n";
}
print "\n";

$unsubsidised_20y_unitcost = ($full_20y_system_cost - $genexport_value_20y) / ($solar_self_use * 20);
print "Subsidised 20 year solar unit cost: ".number_format($unsubsidised_20y_unitcost*100,1)." p/kWh\n";
$unsubsidised_35y_unitcost = ($full_35y_system_cost - $genexport_value_20y) / ($solar_self_use * 35);
print "Subsidised 35 year solar unit cost: ".number_format($unsubsidised_35y_unitcost*100,1)." p/kWh\n";
$unsubsidised_35y_unitcost = $full_35y_system_cost / ($solar_self_use * 35);
print "Unsubsidised 35 year solar unit cost: ".number_format($unsubsidised_35y_unitcost*100,1)." p/kWh\n";

