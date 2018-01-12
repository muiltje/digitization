<?php
ini_set("display_errors", 0);
$is_production = 'no';
$context = '';

//todo: get environment based on ip address

if ($is_production == 'no') {
    error_reporting(E_ALL);
    ini_set("display_errors", 1);
    $context = 'development';
}
else {
    error_reporting(E_ERROR | E_PARSE);
	ini_set("log_errors", 1);
	ini_set("error_log", "errorlog.txt");
	$context = 'production';
}

$inifile = 'processing.ini';
$ini_array = parse_ini_file($inifile, true);

define('CONTEXT', $context);
define('FILEBASE', $ini_array[CONTEXT]['BasePath']);
define('SCRIPTBASE', $ini_array[CONTEXT]['ScriptPath']);
define('WEBSERVICE', $ini_array[CONTEXT]['Webservice']);
define('PREFIX', $ini_array[CONTEXT]['DirPrefix']);

$sScriptDirectory = SCRIPTBASE;
$sLogFile = FILEBASE . PREFIX . 'Logfiles\errorlog.txt';
$sStartDirectory = FILEBASE . PREFIX . 'NabewerkingStart';
$sOcrInDirectory = FILEBASE . PREFIX . 'Processing\OCR_In';
$sSkipOcrDirectory = FILEBASE .PREFIX .'Processing\Skip_OCR';
$sOcrOutDirectory = FILEBASE . PREFIX . 'Processing\OCR_HTMOut';
$sTifPostOcrDirectory = FILEBASE . PREFIX . 'Processing\Tifs_OCR_Done';
$sReadyDirectory = FILEBASE . PREFIX . 'Processing\Ready';
$sSipBijzColl = FILEBASE . PREFIX . 'SipBijzColl';

$sBookNumberFile = $sScriptDirectory . '\booknumbers.txt';