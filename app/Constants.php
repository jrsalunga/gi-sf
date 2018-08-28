<?php

defined('DS') ? null : define('DS', DIRECTORY_SEPARATOR);

function vfpdate_to_carbon($f){
	$m = substr($f, 4, 2);
	$d = substr($f, 6, 2);
	$y = substr($f, 0, 4);
	return Carbon\Carbon::parse($y.'-'.$m.'-'.$d);
}

function is_iso_date($date){
    return preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/",$date);
}

function is_time($time){
	return preg_match("/^(?:(?:([01]?\d|2[0-3]):)?([0-5]?\d):)?([0-5]?\d)$/",$time);
}

function alog($log, $logfile=NULL) {
  $logfile = !is_null($logfile) 
    ? $logfile
    : 'C:\\EODFILES'.DS.'logs'.DS.'activity.log';

  $dir = pathinfo($logfile, PATHINFO_DIRNAME);

  if(!is_dir($dir))
    mkdir($dir, 0777, true);

  $new = file_exists($logfile) ? false : true;
  if($new){
    $handle = fopen($logfile, 'w+');
    chmod($logfile, 0777);
  } else
    $handle = fopen($logfile, 'a');

  //$ip = clientIP();
  //$brw = $_SERVER['HTTP_USER_AGENT'];
  $content = date('r')." | {$log} \n";
  //$content = "{$log}\n";
  fwrite($handle, $content);
  fclose($handle);
}



function mdir($dir) {
  if (!is_dir($dir))
    return mkdir($dir, 0775, true);
}