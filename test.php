<?php

echo 'hi this is a test' . "\n";

require_once 'init.php';

echo 'My context is ' . CONTEXT . "\n";
echo 'My prefix is ' . PREFIX . "\n";
echo 'my files are in ' . FILEBASE . "\n";
echo 'Files start in ' . $sStartDirectory . "\n";
echo 'OCR in is ' . $sOcrInDirectory . "\n";
echo 'Images go to ' . $sSkipOcrDirectory . "\n";
echo 'Files to be OCRed go to ' . $sOcrOutDirectory . "\n";
echo 'After OCR they go to ' . $sTifPostOcrDirectory . "\n";
echo 'When all is done they go to ' . $sReadyDirectory . "\n";
echo 'And finally to ' . $sSipBijzColl . "\n";
echo 'Logs are written to ' . $sLogFile . "\n";




//C:\PHP\php.exe -f "D:\NabewerkingScripts\test.php"



function findTifDirs($sStartDirectory)
{
    $aTifDirs = array();


    $dh = opendir($sStartDirectory);
    if ($dh !== false) {
    	while (false !== ($node = readdir($dh))) {
            if ($node != "." && $node != "..") {
            	$aTifDirs[] = $node;
            }
    	}
    }
    else {
		$aTifDirs['error'] = 'I tried ' . $sStartDirectory;
    }

    closedir($dh);

    return $aTifDirs;
}
