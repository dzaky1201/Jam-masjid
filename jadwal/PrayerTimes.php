<?php namespace IslamicNetwork\PrayerTimes;

require_once('DMath.php');require_once('Method.php');use DateTime;
use DateTimezone;
use IslamicNetwork\PrayerTimes\DMath;
use IslamicNetwork\PrayerTimes\Method;

class PrayerTimes
{
    const IMSAK='Imsak';
    const FAJR='Subuh';
    const SUNRISE='Terbit';
    const ZHUHR='Duhur';
    const ASR='Ashar';
    const SUNSET='Terbenam';
    const MAGHRIB='Maghrib';
    const ISHA='Isya';
    const MIDNIGHT='TengahMalam';
    const SCHOOL_STANDARD='STANDARD';
    const SCHOOL_HANAFI='HANAFI';
    const MIDNIGHT_MODE_STANDARD='STANDARD';
    const MIDNIGHT_MODE_JAFARI='JAFARI';
    const LATITUDE_ADJUSTMENT_METHOD_MOTN='MIDDLE_OF_THE_NIGHT';
    const LATITUDE_ADJUSTMENT_METHOD_ANGLE='ANGLE_BASED';
    const LATITUDE_ADJUSTMENT_METHOD_ONESEVENTH='ONE_SEVENTH';
    const LATITUDE_ADJUSTMENT_METHOD_NONE='NONE';
    const TIME_FORMAT_24H='24h';
    const TIME_FORMAT_12H='12h';
    const TIME_FORMAT_12hNS='12hNS';
    const TIME_FORMAT_FLOAT='Float';
    const INVALID_TIME='-----';
    public $methods;
    public $methodCodes;
    private $date;
    private $method;
    private $school=self::SCHOOL_STANDARD;
    private $midnightMode;
    private $latitudeAdjustmentMethod;
    private $timeFormat;
    private $latitude;
    private $longitude;
    private $elevation;
    private $asrShadowFactor=null;
    private $settings;
    private $offset=[];
    public function __construct($method=Method::METHOD_MWL, $school=self::SCHOOL_STANDARD, $asrShadowFactor=null)
    {
        $this->loadMethods();
        $this->setMethod($method);
        $this->setSchool($school);
        if ($asrShadowFactor!==null) {
            $this->asrShadowFactor=$asrShadowFactor;
        }
        $this->loadSettings();
    }
    public function setCustomMethod(Method $method)
    {
        $this->setMethod(Method::METHOD_CUSTOM);
        $this->methods[$this->method]=get_object_vars($method);
        $this->loadSettings();
    }
    private function loadSettings()
    {
        $this->settings=new \stdClass();
        $this->settings->{self::IMSAK}=isset($this->methods[$this->method]['params'][self::IMSAK])?$this->methods[$this->method]['params'][self::IMSAK]:'10 min';
        $this->settings->{self::FAJR}=isset($this->methods[$this->method]['params'][self::FAJR])?$this->methods[$this->method]['params'][self::FAJR]:0;
        $this->settings->{self::ZHUHR}=isset($this->methods[$this->method]['params'][self::ZHUHR])?$this->methods[$this->method]['params'][self::ZHUHR]:'0 min';
        $this->settings->{self::ISHA}=isset($this->methods[$this->method]['params'][self::ISHA])?$this->methods[$this->method]['params'][self::ISHA]:0;
        $this->settings->{self::MAGHRIB}=isset($this->methods[$this->method]['params'][self::MAGHRIB])?$this->methods[$this->method]['params'][self::MAGHRIB]:'0 min';
        if (isset($this->methods[$this->method]['params'][self::MIDNIGHT])&&$this->methods[$this->method]['params'][self::MIDNIGHT]==self::MIDNIGHT_MODE_JAFARI) {
            $this->setMidnightMode(self::MIDNIGHT_MODE_JAFARI);
        } else {
            $this->setMidnightMode(self::MIDNIGHT_MODE_STANDARD);
        }
    }
    public function getTimesForToday($latitude, $longitude, $timezone, $elevation=null, $latitudeAdjustmentMethod=self::LATITUDE_ADJUSTMENT_METHOD_ANGLE, $midnightMode=null, $format=self::TIME_FORMAT_24H)
    {
        $date=new DateTime(null, new DateTimezone($timezone));
        return $this->getTimes($date, $latitude, $longitude, $elevation, $latitudeAdjustmentMethod, $midnightMode, $format);
    }
    public function getTimes(DateTime $date, $latitude, $longitude, $elevation=null, $latitudeAdjustmentMethod=self::LATITUDE_ADJUSTMENT_METHOD_ANGLE, $midnightMode=null, $format=self::TIME_FORMAT_24H)
    {
        $this->latitude=1*$latitude;
        $this->longitude=1*$longitude;
        $this->elevation=$elevation===null?0:1*$elevation;
        $this->setTimeFormat($format);
        $this->setLatitudeAdjustmentMethod($latitudeAdjustmentMethod);
        if ($midnightMode!==null) {
            $this->setMidnightMode($midnightMode);
        }
        $this->date=$date;
        $dateku=$date->format('Y-m-d');
        return $this->computeTimes($dateku);
    }
    private function computeTimes($date)
    {
        $times=[self::IMSAK=>5,self::FAJR=>5,self::SUNRISE=>6,self::ZHUHR=>12,self::ASR=>13,self::SUNSET=>18,self::MAGHRIB=>18,self::ISHA=>18];
        $times=$this->computePrayerTimes($times);
        $times=$this->adjustTimes($times);
        $times[self::MIDNIGHT]=($this->midnightMode==self::MIDNIGHT_MODE_JAFARI)?$times[self::SUNSET]+$this->timeDiff($times[self::SUNSET], $times[self::FAJR])/2:$times[self::SUNSET]+$this->timeDiff($times[self::SUNSET], $times[self::SUNRISE])/2;
        $times=$this->tuneTimes($times);
        return $this->modifyFormats($times, $date);
    }
    private function modifyFormats($times, $date)
    {
        foreach ($times as $i=>$t) {
            $times[$i]=$this->getFormattedTime($t, $this->timeFormat);
        }
        $times=array('tanggal'=>$date,'jadwal'=>$times);
        return $times;
    }
    private function getFormattedTime($time, $format)
    {
        if (is_nan($time)) {
            return self::INVALID_TIME;
        }
        if ($format==self::TIME_FORMAT_FLOAT) {
            return $time;
        }
        $suffixes=['am','pm'];
        $time=DMath::fixHour($time+0.5/60);
        $hours=floor($time);
        $minutes=floor(($time-$hours)*60);
        $suffix=($this->timeFormat==self::TIME_FORMAT_12H)?$suffixes[$hours<12?0:1]:'';
        $hour=($format==self::TIME_FORMAT_24H)?$this->twoDigitsFormat($hours):(($hours+12-1)%12+1);
        return $hour.':'.$this->twoDigitsFormat($minutes).($suffix?' '.$suffix:'');
    }
    private function twoDigitsFormat($num)
    {
        return($num<10)?'0'.$num:$num;
    }
    private function tuneTimes($times)
    {
        if (!empty($this->offset)) {
            foreach ($times as $i=>$t) {
                if (isset($this->offset[$i])) {
                    $times[$i]+=$this->offset[$i]/60;
                }
            }
        }
        return $times;
    }
    private function evaluate($str)
    {
        return floatval($str);
    }
    private function adjustTimes($times)
    {
        $dateTimeZone=$this->date->getTimezone();
        foreach ($times as $i=>$t) {
            $times[$i]+=($dateTimeZone->getOffset($this->date)/3600)-$this->longitude/15;
        }
        if ($this->latitudeAdjustmentMethod!=self::LATITUDE_ADJUSTMENT_METHOD_NONE) {
            $times=$this->adjustHighLatitudes($times);
        }
        if ($this->isMin($this->settings->{self::IMSAK})) {
            $times[self::IMSAK]=$times[self::FAJR]-$this->evaluate($this->settings->{self::IMSAK})/60;
        }
        if ($this->isMin($this->settings->{self::MAGHRIB})) {
            $times[self::MAGHRIB]=$times[self::SUNSET]+$this->evaluate($this->settings->{self::MAGHRIB})/60;
        }
        if ($this->isMin($this->settings->{self::ISHA})) {
            $times[self::ISHA]=$times[self::MAGHRIB]+$this->evaluate($this->settings->{self::ISHA})/60;
        }
        $times[self::ZHUHR]+=$this->evaluate($this->settings->{self::ZHUHR})/60;
        return $times;
    }
    private function adjustHighLatitudes($times)
    {
        $nightTime=$this->timeDiff($times[self::SUNSET], $times[self::SUNRISE]);
        $times[self::IMSAK]=$this->adjustHLTime($times[self::IMSAK], $times[self::SUNRISE], $this->evaluate($this->settings->{self::IMSAK}), $nightTime, 'ccw');
        $times[self::FAJR]=$this->adjustHLTime($times[self::FAJR], $times[self::SUNRISE], $this->evaluate($this->settings->{self::FAJR}), $nightTime, 'ccw');
        $times[self::ISHA]=$this->adjustHLTime($times[self::ISHA], $times[self::SUNSET], $this->evaluate($this->settings->{self::ISHA}), $nightTime);
        $times[self::MAGHRIB]=$this->adjustHLTime($times[self::MAGHRIB], $times[self::SUNSET], $this->evaluate($this->settings->{self::MAGHRIB}), $nightTime);
        return $times;
    }
    private function isMin($str)
    {
        if (strpos($str, 'min')!==false) {
            return true;
        }
        return false;
    }
    private function adjustHLTime($time, $base, $angle, $night, $direction=null)
    {
        $portion=$this->nightPortion($angle, $night);
        $timeDiff=($direction=='ccw')?$this->timeDiff($time, $base):$this->timeDiff($base, $time);
        if (is_nan($time)||$timeDiff>$portion) {
            $time=$base+($direction=='ccw'?(-$portion):$portion);
        }
        return $time;
    }
    private function nightPortion($angle, $night)
    {
        $method=$this->latitudeAdjustmentMethod;
        $portion=1/2;
        if ($method==self::LATITUDE_ADJUSTMENT_METHOD_ANGLE) {
            $portion=1/60*$angle;
        }
        if ($method==self::LATITUDE_ADJUSTMENT_METHOD_ONESEVENTH) {
            $portion=1/7;
        }
        return $portion*$night;
    }
    private function timeDiff($t1, $t2)
    {
        return DMath::fixHour($t2-$t1);
    }
    private function computePrayerTimes($times)
    {
        $times=$this->dayPortion($times);
        $imsak=$this->sunAngleTime($this->evaluate($this->settings->{self::IMSAK}), $times[self::IMSAK], 'ccw');
        $fajr=$this->sunAngleTime($this->evaluate($this->settings->{self::FAJR}), $times[self::FAJR], 'ccw');
        $sunrise=$this->sunAngleTime($this->riseSetAngle(), $times[self::SUNRISE], 'ccw');
        $dhuhr=$this->midDay($times[self::ZHUHR]);
        $asr=$this->asrTime($this->asrFactor(), $times[self::ASR]);
        $sunset=$this->sunAngleTime($this->riseSetAngle(), $times[self::SUNSET]);
        $maghrib=$this->sunAngleTime($this->evaluate($this->settings->{self::MAGHRIB}), $times[self::MAGHRIB]);
        $isha=$this->sunAngleTime($this->evaluate($this->settings->{self::ISHA}), $times[self::ISHA]);
        return[self::FAJR=>$fajr,self::SUNRISE=>$sunrise,self::ZHUHR=>$dhuhr,self::ASR=>$asr,self::SUNSET=>$sunset,self::MAGHRIB=>$maghrib,self::ISHA=>$isha,self::IMSAK=>$imsak,];
    }
    private function asrTime($factor, $time)
    {
        $julianDate=GregorianToJD($this->date->format('n'), $this->date->format('d'), $this->date->format('Y'));
        $decl=$this->sunPosition($julianDate+$time)->declination;
        $angle=-DMath::arccot($factor+DMath::tan(abs($this->latitude-$decl)));
        return $this->sunAngleTime($angle, $time);
    }
    private function sunAngleTime($angle, $time, $direction=null)
    {
        $julianDate=$this->julianDate($this->date->format('Y'), $this->date->format('n'), $this->date->format('d'))-$this->longitude/(15*24);
        $decl=$this->sunPosition($julianDate+$time)->declination;
        $noon=$this->midDay($time);
        $p1=-DMath::sin($angle)-DMath::sin($decl)*DMath::sin($this->latitude);
        $p2=DMath::cos($decl)*DMath::cos($this->latitude);
        $cosRange=($p1/$p2);
        if ($cosRange>1) {
            $cosRange=1;
        }
        if ($cosRange<-1) {
            $cosRange=-1;
        }
        $t=1/15*DMath::arccos($cosRange);
        return $noon+($direction=='ccw'?-$t:$t);
    }
    private function asrFactor()
    {
        if ($this->asrShadowFactor!==null) {
            return $this->asrShadowFactor;
        }
        if ($this->school==self::SCHOOL_STANDARD) {
            return 1;
        } elseif ($this->school==self::SCHOOL_HANAFI) {
            return 2;
        } else {
            return 0;
        }
    }
    private function riseSetAngle()
    {
        $angle=0.0347*sqrt($this->elevation);
        return 0.833+$angle;
    }
    private function sunPosition($julianDate)
    {
        $D=$julianDate-2451545.0;
        $g=DMath::fixAngle(357.529+0.98560028*$D);
        $q=DMath::fixAngle(280.459+0.98564736*$D);
        $L=DMath::fixAngle($q+1.915*DMath::sin($g)+0.020*DMath::sin(2*$g));
        $R=1.00014-0.01671*DMath::cos($g)-0.00014*DMath::cos(2*$g);
        $e=23.439-0.00000036*$D;
        $RA=DMath::arctan2(DMath::cos($e)*DMath::sin($L), DMath::cos($L))/15;
        $eqt=$q/15-DMath::fixHour($RA);
        $decl=DMath::arcsin(DMath::sin($e)*DMath::sin($L));
        $res=new \stdClass();
        $res->declination=$decl;
        $res->equation=$eqt;
        return $res;
    }
    private function julianDate($year, $month, $day)
    {
        if ($month<=2) {
            $year-=1;
            $month+=12;
        }
        $A=floor($year/100);
        $B=2-$A+floor($A/4);
        $JD=floor(365.25*($year+4716))+floor(30.6001*($month+1))+$day+$B-1524.5;
        return $JD;
    }
    private function midDay($time)
    {
        $julianDate=$this->julianDate($this->date->format('Y'), $this->date->format('n'), $this->date->format('d'))-$this->longitude/(15*24);
        $eqt=$this->sunPosition($julianDate+$time)->equation;
        $noon=DMath::fixHour(12-$eqt);
        return $noon;
    }
    private function dayPortion($times)
    {
        foreach ($times as $i=>$time) {
            $times[$i]=$time/24;
        }
        return $times;
    }
    public function setMethod($method=Method::METHOD_MWL)
    {
        if (in_array($method, $this->methodCodes)) {
            $this->method=$method;
        } else {
            $this->method=Method::METHOD_MWL;
        }
    }
    public function setAsrJuristicMethod($method=self::SCHOOL_STANDARD)
    {
        if (in_array($method, [self::SCHOOL_HANAFI,self::SCHOOL_STANDARD])) {
            $this->school=$method;
        } else {
            $this->school=self::SCHOOL_STANDARD;
        }
    }
    public function setSchool($school=self::SCHOOL_STANDARD)
    {
        $this->setAsrJuristicMethod($school);
    }
    public function setMidnightMode($mode=self::MIDNIGHT_MODE_STANDARD)
    {
        if (in_array($mode, [self::MIDNIGHT_MODE_JAFARI,self::MIDNIGHT_MODE_STANDARD])) {
            $this->midnightMode=$mode;
        } else {
            $this->midnightMode=self::MIDNIGHT_MODE_STANDARD;
        }
    }
    public function setLatitudeAdjustmentMethod($method=self::LATITUDE_ADJUSTMENT_METHOD_ANGLE)
    {
        if (in_array($method, [self::LATITUDE_ADJUSTMENT_METHOD_MOTN,self::LATITUDE_ADJUSTMENT_METHOD_ANGLE,self::LATITUDE_ADJUSTMENT_METHOD_ONESEVENTH,self::LATITUDE_ADJUSTMENT_METHOD_NONE])) {
            $this->latitudeAdjustmentMethod=$method;
        } else {
            $this->latitudeAdjustmentMethod=self::LATITUDE_ADJUSTMENT_METHOD_ANGLE;
        }
    }
    public function setTimeFormat($format=self::TIME_FORMAT_24H)
    {
        if (in_array($format, [self::TIME_FORMAT_24H,self::TIME_FORMAT_FLOAT,self::TIME_FORMAT_12hNS,self::TIME_FORMAT_12H])) {
            $this->timeFormat=$format;
        } else {
            $this->timeFormat=self::TIME_FORMAT_24H;
        }
    }
    public function tune($imsak=0, $fajr=0, $sunrise=0, $dhuhr=0, $asr=0, $maghrib=0, $sunset=0, $isha=0, $midnight=0)
    {
        $this->offset=[self::IMSAK=>$imsak,self::FAJR=>$fajr,self::SUNRISE=>$sunrise,self::ZHUHR=>$dhuhr,self::ASR=>$asr,self::MAGHRIB=>$maghrib,self::SUNSET=>$sunset,self::ISHA=>$isha,self::MIDNIGHT=>$midnight];
    }
    public function loadMethods()
    {
        $this->methods=Method::getMethods();
        $this->methodCodes=Method::getMethodCodes();
    }
    public function getMethods()
    {
        return $this->methods;
    }
    public function getMethod()
    {
        return $this->method;
    }
    public function getMeta()
    {
        $result=['latitude'=>$this->latitude,'longitude'=>$this->longitude,'timezone'=>($this->date->getTimezone())->getName(),'method'=>$this->methods[$this->method],'latitudeAdjustmentMethod'=>$this->latitudeAdjustmentMethod,'midnightMode'=>$this->midnightMode,'school'=>$this->school,'offset'=>$this->offset,];
        if (isset($result['method']['offset'])) {
            unset($result['method']['offset']);
        }
        return $result;
    }
}
