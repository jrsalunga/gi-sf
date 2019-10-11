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