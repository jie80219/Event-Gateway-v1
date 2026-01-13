<?php

require_once __DIR__ . '/../init.php';

use App\ServiceDiscovery\LoadBalance\EntropyScoring;

$interval = (int) (getenv('SERVICEDISCOVERY_RECALC_INTERVAL') ?: 5);
$interval = max(1, $interval);

$scoring = new EntropyScoring();

while (true) {
    $scoring->recalculate();
    sleep($interval);
}
