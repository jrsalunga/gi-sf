<?php

if (!function_exists('pad')) {
	function pad($val, $len=2, $char='0', $direction=STR_PAD_LEFT){
		return str_pad($val, $len, $char, $direction);
	}
}

if (!function_exists('lpad')) {
  function lpad($val, $len=2, $char=' ') {
      return str_pad($val, $len, $char, STR_PAD_LEFT);
  }
}

if (!function_exists('rpad')) {
  function rpad($val, $len=2, $char=' ') {
      return str_pad($val, $len, $char, STR_PAD_RIGHT);
  }
}

if (!function_exists('bpad')) {
  function bpad($val, $len=2, $char=' ') {
      return str_pad($val, $len, $char, STR_PAD_BOTH);
  }
}

if (!function_exists('nf')) {
  function nf($x='0.00', $d=2, $zero_print=false) {
    if ($x==0 && $zero_print==false)
      return '';
    return number_format($x, $d);
  }
}