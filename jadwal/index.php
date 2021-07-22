<?php
require_once('PrayerTimes.php');
use IslamicNetwork\PrayerTimes\PrayerTimes;

date_default_timezone_set("Asia/Jakarta");

$latitude='-6.5258968';
$longitude='107.0392306';
$timezone=intval(7);
$pt=new PrayerTimes('SINGAPORE');
$date=new DateTime(date('Y-m-d'));
$times=$pt->getTimes($date, $latitude, $longitude, $timezone, null);
echo json_encode($times);
