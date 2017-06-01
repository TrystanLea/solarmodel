<?php

// -------------------------------------------------------------------------------
// Solar self-consumption model
// -------------------------------------------------------------------------------
// - using high resolution 10s solar generation data from an emoncms feed
// - creates household consumption model to experiment with load timing and types
// - simulates PV Diversion with hot water cylinder
// - simple EV charging profile option
// - saves modelled consumption feed and cylinder temperature to output emoncms feeds

// Author: Trystan Lea
// part of the OpenEnergyMonitor project

// -------------------------------------------------------------------------------
// How to use: Configuration
// -------------------------------------------------------------------------------
// 1. Enter your solar pv emoncms feedid (for best results at least 1 year of data is recommended with less than 10% missing data)
   $solar_feedid = "example";
// 2. Enter solar capacity of system solar data is based on:
   $solar_capacity = 2.4; // kW
// 3. Enter adjustment if you wish to model a different solar capacity: e.g: 4 kWp system
   $solar_kw = 4.0;
// 4. Create an two feeds in emoncms to hold modelled consumption and cylinder temperature data, enter their feedids:
// WARNING: These feeds are output feeds and will be written too:
   $consumption_feedid = 151070;
   $cylinder_temperature_feedid = 151071;
//
// 5. Adjust consumption model as required below
// 6. Adjust economic model as required
   $import_unitcost = 0.152;
   $generation_tariff = 0.0411;
   $export_tariff = 0.049;
   
   // Basic capacity based installation cost model
   // 4kW system £5000 for 4kW..
   // - panels: ~ £2200
   // - inverter: ~ £1000
   // - cables + misc: ~ £400
   // - installation, scaffold, misc: £1400
   // kits available for panels, inverters, misc components for £3000 to £3200
   // full installation average cost £6000 for 4kW.
   
   // Solar lifetimes are expected in 35 year +
   // Inverter lifetimes may now be up to 20-25 years
   
   $system_cost = (2500 * pow($solar_kw,-0.368))*$solar_kw;
   $system_cost = round($system_cost * 0.1) * 10;
   $system_cost = $system_cost * 0.8;
  
   $inverter_replacement_20y = $solar_kw * 250 * 0.4; // Assuming 60% reduction in 4kW inverter costs by 2037
  
   // Battery storage
   $battery_capacity = 2.5;
   $battery_energy = $battery_capacity * 0.5;
   
   $battery_cost_y0 = $battery_capacity * 500.0 * 0.8;    // Price today 2017 after 14% year on year reduction in cost between 2007 and 2014
   $battery_cost_y15 = $battery_capacity * 114.0;   // 10% year on year reduction in cost from y0 to y15
   $battery_cost_y30 = $battery_capacity * 56.0;    // 5% year on year reduction in cost from y15 through to y30
   
   $full_20y_system_cost = $system_cost + $battery_cost_y0 + ($battery_cost_y15 * 0.25);
   $full_35y_system_cost = $system_cost + $inverter_replacement_20y + $battery_cost_y0 + $battery_cost_y15 + ($battery_cost_y30*0.25);
// 6. RUN model from terminal:
//
//   $ sudo php solar_model.php
//

require ("common.php");
$wkdir = getcwd();

define('EMONCMS_EXEC', 1);
chdir("/var/www/emoncms");
require "process_settings.php";
$dir = $feed_settings["phpfina"]["datadir"];
$solardir = $dir;
if ($solar_feedid=="example") $solardir = $wkdir."/";

// Load solar feed meta data
$solar_meta = getmeta($solardir,$solar_feedid);
$start = $solar_meta->start_time;
$startday = floor($start / 86400);
$seconds_in_day = ($start - ($startday*86400));
$hour_in_day = floor($seconds_in_day/3600);

$date = new DateTime();
$date->setTimezone(new DateTimeZone("UTC"));
$date->setTimestamp($start);
$startdayofweek = $date->format("N");

if (!$fh = @fopen($solardir.$solar_feedid.".dat", 'rb')) {
    echo "ERROR: could not open $solardir $solar_feedid.dat\n";
    return false;
}

// Init
$solar = 0;
$use = 0;
$solar_total = 0;
$use_total = 0;
$excess_total = 0;
$unmet_total = 0;
$totaltime = 0;
$buffer = "";
$bufferT = "";
$np = 0;
$cylinder_temperature = 10;
$solar_normal = 1.0 / $solar_capacity;
$day = $startday;

// Main model loop
for ($n=0; $n<$solar_meta->npoints; $n++) {
    $time = $start + ($n*$solar_meta->interval);
    
    $tmp = unpack("f",fread($fh,4));
    if (!is_nan($tmp[1])) $solar = 1.0*$tmp[1]*$solar_normal*$solar_kw;
    
    $day = floor($time / 86400);
    $seconds_in_day = ($time - ($day*86400));
    $hour_in_day = $seconds_in_day/3600;
    $dayofweek = ($startdayofweek + ($day - $startday)) % 7;
    
    $excess = 0;
    $unmet = 0;

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
    
    $ev = true;
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
    $balanceA = $solar - $use_before_hw;
    
    // -------------------------------------------------------------------------------
    // 11. Battery storage
    // -------------------------------------------------------------------------------
    if ($balanceA>0) {
        $charge = $balanceA;
        $battery_delta = ($charge*0.95 * $solar_meta->interval)/3600000.0;
        
        if (($battery_energy+$battery_delta)<=$battery_capacity) {
            $battery_energy += $battery_delta;
        } else {
            $charge = 0;
        }
        
        $balanceA -= $charge;
        $use_before_hw += $charge;
    } else {
        $discharge = -$balanceA;
        $battery_delta = (($discharge/0.95) * $solar_meta->interval)/3600000.0;
        
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
    $shower_temperature = 38;
    $inlet_temperature = 10;
    $tank_litres = 168;
    $immersion_topup = 0;
    $immersion_power = 0;
    $dhw_heat = 0;
    $tank_heat_loss = 0;
    
    $dhw = true;
    if ($dhw) {
        if ($hour_in_day>=21.5 && $hour_in_day<(21.5+((10*60)/3600))) {
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
        
        $tank_heat_loss = 1.0 * ($cylinder_temperature - 18);
        
        
        if ($balanceA>50.0 && $cylinder_temperature<60.0) {
            $immersion_power = 1.0*$balanceA;
        }
        
        $cylinder_power = $immersion_power - $dhw_heat - $tank_heat_loss;
        $cylinder_deltaT = ($cylinder_power * $solar_meta->interval) / (4184 * $tank_litres);
        $cylinder_temperature += $cylinder_deltaT;
    }
    
    // -------------------------------------------------------------------------------
    // Final balance
    // -------------------------------------------------------------------------------
    $use = $use_before_hw + $immersion_power + $immersion_topup;
    
    $balance = $solar - $use;
    if ($balance>0.0) {
        $excess = $balance;
    } else {
        $unmet = -1 * $balance;
    }
    
    // Totals
    $use_total += $use * $solar_meta->interval / 3600;
    $solar_total += $solar * $solar_meta->interval / 3600;
    $excess_total += $excess * $solar_meta->interval / 3600;
    $unmet_total += $unmet * $solar_meta->interval / 3600;
    $totaltime += $solar_meta->interval;
    $np ++;
    
    // Output file buffers
    $buffer .= pack("f",$use+$discharge);
    $bufferT .= pack("f",$battery_energy);

    // Process only 1 year of solar data
    if ($totaltime>(3600*24*365)) break;
}

$number_of_days = $totaltime / (3600*24);
$solar_self_use = $solar_total - $excess_total;
$solar_self_use_prc = $solar_self_use / $solar_total;

print "------------------------------------------------------------------------\n";
print "Solar self consumption model\n";
print "------------------------------------------------------------------------\n";
print "Solar capacity: ".$solar_kw." kWp\n";
print "Use: ".round($use_total*0.001)." kWh\n";
print "Use (kWh/d): ".number_format($use_total*0.001/$number_of_days,1)." kWh\n";
print "Solar: ".round($solar_total*0.001)." kWh\n";
print "Solar (kWh/d): ".number_format($solar_total*0.001/$number_of_days,1)." kWh\n";
print "Excess: ".round($excess_total*0.001)." kWh, Unmet: ".round($unmet_total*0.001)." kWh\n";
print "Solar self consumption: ".number_format($solar_self_use_prc*100,1)."%\n";
print "\n";

// -------------------------------------------------------------------------------
// Finances
// -------------------------------------------------------------------------------

print "System cost 20y: £".$full_20y_system_cost."\n";
print "System cost 35y: £".$full_35y_system_cost."\n";

$self_consumption_value_1y = $solar_self_use*0.001*$import_unitcost;
$self_consumption_value_20y = $solar_self_use*0.001*$import_unitcost*20;
print "Value of solar self consumption @ ".number_format($import_unitcost*100,1)." p/kWh: £".number_format($self_consumption_value_1y,2)."/y (over 20y: £".number_format($self_consumption_value_20y,0).")\n";

$genexport_value_1y = ($solar_total*0.001*$generation_tariff) + ($solar_total*0.5*0.001*$export_tariff);
$genexport_value_20y = $genexport_value_1y * 20;
print "Value of generation + 50% export tariff £".number_format($genexport_value_1y,2)."/y (over 20y: £".number_format($genexport_value_20y,0).")\n";

$payback = 20 * ($full_20y_system_cost / ($self_consumption_value_20y+$genexport_value_20y));

if ($payback<=20) {
    print "System payback ".number_format($payback,1)."/y\n";
} else {
    $payback = ($full_35y_system_cost - $genexport_value_20y) / $self_consumption_value_1y;
    print "System payback ".number_format($payback,1)."/y\n";
}
print "\n";

$unsubsidised_35y_unitcost = $full_35y_system_cost / ($solar_self_use*0.001 * 35);
print "Unsubsidised 35 year solar unit cost: ".number_format($unsubsidised_35y_unitcost*100,1)." p/kWh\n";

$unsubsidised_35y_unitcost = ($full_35y_system_cost - $genexport_value_20y) / ($solar_self_use*0.001 * 35);
print "Subsidised 35 year solar unit cost: ".number_format($unsubsidised_35y_unitcost*100,1)." p/kWh\n";
print "\n";

$unsubsidised_20y_unitcost = ($full_20y_system_cost - $genexport_value_20y) / ($solar_self_use*0.001 * 20);
print "Subsidised 20 year solar unit cost: ".number_format($unsubsidised_20y_unitcost*100,1)." p/kWh\n";
print "\n";

createmeta($dir,$consumption_feedid,$solar_meta);
$fh = @fopen("/tmp/consumption.dat", 'wb');
fwrite($fh,$buffer); fclose($fh);
unlink($dir."$consumption_feedid.dat");
symlink("/tmp/consumption.dat",$dir.$consumption_feedid.".dat");

createmeta($dir,$cylinder_temperature_feedid,$solar_meta);
$fh = @fopen("/tmp/cylinder_temperature.dat", 'wb');
fwrite($fh,$bufferT); fclose($fh);
unlink($dir."$cylinder_temperature_feedid.dat");
symlink("/tmp/cylinder_temperature.dat",$dir."$cylinder_temperature_feedid.dat");
