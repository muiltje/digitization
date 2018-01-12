<?php

require_once 'init.php';


$sWebserviceURL = WEBSERVICE;

$sFilesToOcr = '0';
$aTifDirs = array();
$sLogItemNumbers = '';
$sLogMessages = '';

$lfhs = fopen($sLogFile, 'a');
$line =  date("Y m d H:m") . " getTifs has started \n";
fwrite($lfhs, $line);
fclose($lfhs);


$aTifDirs = findTifDirs($sStartDirectory);
if (count($aTifDirs) == 0) {
	$lfh = fopen($sLogFile, 'a');
	$errorline = date("Y m d H:m") . " geen nieuwe tifs gevonden in " . $sStartDirectory ."\n";
	fwrite($lfh, $errorline);
	fclose($lfh);
}
elseif (isset($aTifDirs['error'])) {
	$lfh = fopen($sLogFile, 'a');
	$errorline = date("Y m d H:m") . $aTifDirs['error'] ."\n";
	fwrite($lfh, $errorline);
	fclose($lfh);
}
elseif (count($aTifDirs) > 0) {
	$nNumberOfDirs = count($aTifDirs);
	$line = date("Y m d H:m") . " Ik heb " . $nNumberOfDirs . " nieuwe mappen gevonden \n";
	$lfh = fopen($sLogFile, 'a');
	fwrite($lfh, $line);
	fclose($lfh);
}
else {
	$errorline = date("Y m d H:m") . " Er is iets mis, maar ik weet niet wat \n";
	$lfh = fopen($sLogFile, 'a');
	fwrite($lfh, $errorline);
	fclose($lfh);
}


//for each booknumber
//check files against ocr data (plus totalscans)
//and divide files over directories
foreach ($aTifDirs as $sBookNumber) {
   //echo $sBookNumber;
   $sLogItemNumbers .= $sBookNumber . ';';

   //check if book number is known in workflow
   $sBookNumberKnown = '';
   $sBookNumberKnown = checkBookNumber($sBookNumber, $sWebserviceURL);

   if ($sBookNumberKnown == 'y') {
	$sLogMessages .= $sBookNumber . ':Boek herkend;';

       //get totalscans as filled in
        $iTotalGiven = 0;
        $aTotalScanData = getTotalScans($sBookNumber, $sWebserviceURL);

        if ($aTotalScanData['error'] != '') {
            //echo ' heeft een probleem: ' . $aTotalScanData['error'];
            $sLogMessages .= $sBookNumber . ':' . $aTotalScanData['error'] . ';';
        }
        elseif ((int) $aTotalScanData['total'] < 1) {
            $sLogMessages .= $sBookNumber . ':heeft geen totaal aantal pagina\'s;';
        }
        else {
            $iTotalGiven = $aTotalScanData['total'];
        }

        //get number of files in directory
        $sBookDirectory = $sStartDirectory . '\\' .  $sBookNumber . "\\";
        //$iFileCount = countFiles($sBookNumber, $sBookDirectory);
		$iFileCount = countFiles($sBookDirectory);

        //get ocr data
        $aOcrData = getOcrData($sBookNumber, $sWebserviceURL);

        //if there is an error with ocrdata or if no page data have been filled in, we can not continue
        if ($aOcrData['errors'] == '' && $aOcrData['total'] > 0) {
            $iTotalFromOcrData = (int) $aOcrData['total'];

            $sPagesEqual = 'n';
            $aCountCompare = array();
            $aCountCompare = compareCounts($iTotalGiven, $iTotalFromOcrData, $iFileCount);
            $sPagesEqual = $aCountCompare['equal'];

            if ($sPagesEqual == 'y') {
                //check if all tifs are present and correctly named
                //$sPresenceCheck = '';
                $aPagesToCheck = $aOcrData['OcrData'];

                //by the time we get here, we already know that the number of tifs is correct
                //what we don't know if they all have a correct file name
                //that is what we want to check now
                $aPagesMisnamed = array();
                 $aPagesMisnamed = getTifPresence($sBookNumber, $sBookDirectory, $aPagesToCheck);
                if (count($aPagesMisnamed) < 1) {
                    // echo '<br>Alle scans zijn OK, we kunnen door <br>';
                    $sLogMessages .= $sBookNumber . ':Alle scans zijn OK, we kunnen door;';
                    //if all is well:
                    //write to booknumbers.txt
                    $fh = fopen($sBookNumberFile, "a");
                    $bookstring = $sBookNumber . ',';
                    fwrite($fh, $bookstring);

					$sFilesToOcr = '1';

                    //divide tifs over directories
                    $sMoveResult = moveTifs($sBookNumber, $sBookDirectory, $sOcrInDirectory, $sSkipOcrDirectory, $aPagesToCheck);
                    echo $sMoveResult;
                }
                else {
                    $sLogMessages .= $sBookNumber . ':' . 'De volgende scans kan ik niet vinden. Controleer de bestandsnamen van de tifs.<br>';
                    $sLogMessages .= '<ul>';
                    foreach ($aPagesMisnamed as $misnamedpage) {
                        $sLogMessages .= '<li>' . $misnamedpage . '</li>';
                    }
                    $sLogMessages .= '</ul>Ik ga niet verder.;';
                }

            } //end of if ($sPagesEqual == 'y')
            else {
                $sLogMessages .= $sBookNumber . ':' . $aCountCompare['note'] . ' - ik ga niet verder;';
            }
        } //end of if ($aOcrData['errors'] == '' && $aOcrData['total'] > 0)
        else {
            if ($aOcrData['errors'] != '') {
                $sLogMessages .= $sBookNumber . ':' . 'Geen OCR data, ik ga niet verder.;';
            }
            elseif ($aOcrData['total'] <1) {
                 $sLogMessages .= $sBookNumber . ':' . 'Het totaal aantal scans is niet ingevuld, ik ga niet verder.;';
            }
            $sLogMessages .= $sBookNumber . ':' . 'Ik zie dat er ' . $iFileCount . ' tifs zijn.;';
        }
   } //end of if ($sBookNumberKnown == 'y')
   else {
       $sLogMessages .= $sBookNumber . ':boeknummer komt niet voor in workflow;';
   }

}

//echo 'Files to OCR are ' . $sFilesToOcr . "\n";
//if there are files that have been moved to $sOcrInDirectory, write a text file there
if ($sFilesToOcr == '1') {
	$sStartFile = $sScriptDirectory . '\WeGaanOCRen.txt';
	$fhs = fopen($sStartFile, 'w');
	fwrite($fhs, '1');
	fclose($fhs);
}

/**
* Finally: send the results to the workflow webservice for logging
*/
$url = $sWebserviceURL . '/logdata';
$data_array = array(
	'itemnumbers' => $sLogItemNumbers,
	'messages' => $sLogMessages,
    );
$data = http_build_query($data_array);

//DEBUG
$temp = var_export($data_array, true);
$sTempFile = $sScriptDirectory. '\temp.txt';
file_put_contents($sTempFile, $temp);


$params = array('http' => array('method' => 'POST', 'content' => $data, 'header'=> 'Content-Type: application/x-www-form-urlencoded'));
$ctx = stream_context_create($params);
try {
    $fp = fopen($url, 'rb', false, $ctx);
    $answer = stream_get_contents($fp);
    //echo $answer;
}
catch (Exception $e) {
	$lfh = fopen($sLogFile, 'a');
	$errorline = date("Y m d H:m") . " kon geen log data versturen \n";
	fwrite($lfh, $errorline);
	fclose($lfh);
}





//log end
$lfhe = fopen($sLogFile, 'a');
$line =  date("Y m d H:m") . " getTifs has finished \n";
fwrite($lfhe, $line);
fclose($lfhe);




/**
* Get the names of the directories the tiffs have been put in.
* It does not matter whether there are actually tiffs in the directory.
* It is possible that an item's tiffs are already in the ocr in directory.
*/
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

function checkBookNumber($sBookNumber, $sWebserviceURL)
{
    $sNumberKnown = 'n';

    $sNumberCheckUrl = $sWebserviceURL . '/bookpresence/' . $sBookNumber;

    $checkdata = file_get_contents($sNumberCheckUrl);
    $checkdatafixed = str_replace( array('&lt;','&gt;') ,array('<','>'),$checkdata);

	try {
    	$xml = new SimpleXMLElement($checkdatafixed);

    	if (is_object($xml)) {
        	$itemcountfield = $xml->xpath('//itemcount');
        	$itemcount = (string) $itemcountfield[0];
        	if ($itemcount == 1) {
           		$sNumberKnown = 'y';
        	}
    	}
	}
	catch (Exception $e) {
		$sNumberKnown = 'n';
	}

    return $sNumberKnown;
}

/**
 * Get the total number of scans for this book
 * @param string $sBookNumber
 */
function getTotalScans($sBookNumber, $sWebserviceURL)
{
    $aTotalScans = array();

    $sTotalScansUrl = $sWebserviceURL . '/totalpages/' . $sBookNumber;

    $totalscansdata = file_get_contents($sTotalScansUrl);
    $totalfixed = str_replace( array('&lt;','&gt;') ,array('<','>'),$totalscansdata);

	try {
    	$xml = new SimpleXMLElement($totalfixed);
    	if (is_object($xml)) {
        	$totalpagesfield = $xml->xpath('//totalpages');
        	$totalpages = (int) $totalpagesfield[0];
        	$aTotalScans['total'] = $totalpages;
        	$aTotalScans['error'] = '';
    	}
    	else {
        	$aTotalScans['total'] = '';
        	$aTotalScans['error'] = 'Could not get total pages';
    	}
	}
	catch (Exception $e) {
		$aTotalScans['error'] = 'Could not get total pages';
	}

    return $aTotalScans;
}

/**
 * Get the OCR data for this book
 * @param string $sBookNumber
 */
function getOcrData($sBookNumber, $sWebserviceURL)
{
    $aOcrData = array();

    $aOcrDataInfo = array();
    $sErrorMessages = '';

    $serviceurl = $sWebserviceURL . '/ocr/' . $sBookNumber;
    $incomingdata = file_get_contents($serviceurl);
    $incomingdatafixed = str_replace( array('&lt;','&gt;') ,array('<','>'),$incomingdata);  //nasty fix for the new restler which now encodes the xml tags. We choose to fix it here and transform the response body to xml again

	try {
    	$xml = new SimpleXMLElement($incomingdatafixed);
    	if (is_object($xml)) {
			//total number of scans
			$totalfield = $xml->xpath('//totalscans');
			$totalscans = (int) $totalfield[0];
        	$aOcrDataInfo['total'] = $totalscans;

			//whether this is a book with special OCR requirements
			$ocrwantedfield = $xml->xpath('//ocrwanted');
			$ocrwanted = (string) $ocrwantedfield[0];

			//ocr start page
			$ocrstartfield = $xml->xpath('//ocrstartscan');
			$ocrstart = (int) $ocrstartfield[0];

			//ocr end page
			$ocrendfield = $xml->xpath('//ocrendscan');
			$ocrend = (int) $ocrendfield[0];

			//pages to be skipped
			$ocrskipfield = $xml->xpath('//donotocr');
			$ocrskip = (string) $ocrskipfield[0];

			//from this we should be able to work out for each page if it should be ocred
			//what we want to return is an array with 'scannumber'=>'ocr_or_not'
			//if only a part of the book should be ocred
			if ($ocrwanted == 'noplates') {
            	$skippedpages = array();
            	if ($ocrskip != '') {
					$skippedpages = explode(',', $ocrskip);
					for ($count=1; $count <= $totalscans; $count++) {
                    	if (in_array($count, $skippedpages)) {
							$aOcrData[$count] = '0';
                    	}
                    	else {
							$aOcrData[$count] = '1';
                    	}
					}
            	}
			}
			elseif ($ocrwanted == 'part') {
            	for ($count=1; $count <= $totalscans; $count++) {
					if ($count < $ocrstart || $count > $ocrend) {
                    	$aOcrData[$count] = '0';
					}
					else {
                    	$aOcrData[$count] = '1';
					}
            	}
			}
			//if nothing should be ocred
			elseif ($ocrwanted == 'none') {
            	for ($count=1; $count <= $totalscans; $count++) {
					$aOcrData[$count] = '0';
            	}
			}
			else {
            	for ($count=1; $count <= $totalscans; $count++) {
					$aOcrData[$count] = '1';
            	}
			}
    	}
    	else {
			$sErrorMessages .= 'Kon geen contact maken met de webservice';
    	}
	}
	catch (Exception $e) {
		$sErrorMessages .= 'Kon geen contact maken met de webservice';
	}

    $aOcrDataInfo['errors'] = $sErrorMessages;
    $aOcrDataInfo['OcrData'] = $aOcrData;

    //return $aOcrData;
    return $aOcrDataInfo;
}

/**
 * Count the number of files in the book directory
 * @param string $sBookDirectory
 * @return int
 */
function countFiles($sBookDirectory)
{
    $iCount = 0;

    $dh = opendir($sBookDirectory);
	if ($dh !== false) {
    	while (false !== ($node = readdir($dh))) {
			//skip parent directories and any thumbs.db files
        	if ($node != "." && $node != ".." && !preg_match('/db/', $node)) {
            	$iCount++;
        	}
    	}
	}

    closedir($dh);

    return $iCount;
}


function compareCounts($iTotalGiven, $iTotalFromOcr, $iFileCount)
{
    $aCountCompare = array();

    //possible scenarios:
    //1. total pages were filled in as N but from other data workflow calculated N+x pages
    //2. total pages were filled in as N but there are N-x files
    //3. total pages were filled in as N but there are N+x files
    //4. workflow calculated N pages but there are N-x files
    //5. workflow calculated N pages but there are N+x files

    if ($iTotalGiven == $iTotalFromOcr && $iTotalGiven == $iFileCount && $iTotalFromOcr == $iFileCount) {
        $aCountCompare['equal'] = 'y';
        $aCountCompare['note'] = '';
    }
    elseif ($iTotalGiven != $iTotalFromOcr) {
        $notestring = 'Totaal aantal scans zoals ingevuld (' . $iTotalGiven . ') ';
        $notestring .= 'is niet gelijk aan het aantal scans zoals berekend (' . $iTotalFromOcr . ')';
        $aCountCompare['note'] = $notestring;
        $aCountCompare['equal'] = 'n';
    }
    elseif ($iTotalGiven > $iFileCount) {
        $notestring = 'Totaal aantal scans zoals ingevuld (' . $iTotalGiven . ') ';
        $notestring .= 'is groter dan het aantal tifs (' . $iFileCount . ')';
        $aCountCompare['note'] = $notestring;
        $aCountCompare['equal'] = 'n';
    }
    elseif ($iTotalGiven < $iFileCount) {
        $notestring = 'Totaal aantal scans zoals ingevuld (' . $iTotalGiven . ') ';
        $notestring .= 'is kleiner dan het aantal tifs (' . $iFileCount . ')';
        $aCountCompare['note'] = $notestring;
        $aCountCompare['equal'] = 'n';
    }
    elseif ($iTotalFromOcr < $iFileCount) {
        $notestring = 'Er zijn meer bestanden (' . $iFileCount . ') ';
        $notestring .= 'dan er volgens de paginering (' . $iTotalFromOcr . ') moeten zijn';
        $aCountCompare['note'] = $notestring;
        $aCountCompare['equal'] = 'n';
    }
    elseif ($iTotalFromOcr > $iFileCount) {
        $notestring = 'Er zijn minder bestanden (' . $iFileCount . ') ';
        $notestring .= 'dan er volgens de paginering (' . $iTotalFromOcr . ') moeten zijn';
        $aCountCompare['note'] = $notestring;
        $aCountCompare['equal'] = 'n';
    }
    else {
        $aCountCompare['equal'] = 'n';
        $aCountCompare['note'] = 'In dit geval had ik niet voorzien';
    }

    return $aCountCompare;
}

/**
 * Check if all pages are present
 * If everything is OK: return array with 'OK'
 * Else return array with missing or misnamed files
 * @param string $sTifDirectory
 * @param array $aOcrData
 */
function getTifPresence($sBookNumber, $sBookDirectory, $aPagesToCheck)
{
    $aPagesMissing = array();
    foreach ($aPagesToCheck as $pagenumber => $tobeocred) {
        $filename = $sBookNumber . sprintf("%04d", $pagenumber) . '.tif';
        $path = $sBookDirectory . $filename;
        if (file_exists($path)) {

        }
        else {
            $aPagesMissing[] = $filename;
        }
    }



    return $aPagesMissing;
}



/**
 * Move tifs from start directory to correct directory for processing
 * @param type $sTifDirectory
 * @param type $sOcrIn
 * @param type $sSkipOcr
 * @param type $aOcrData
 */
function moveTifs($sBookNumber, $sBookDirectory, $sOcrInDirectory, $sSkipOcrDirectory, $aPagesToCheck)
{
    $sMoveResult = '';

    foreach ($aPagesToCheck as $pagenumber => $ocrvalue) {
        $filename = $sBookNumber . sprintf("%04d", $pagenumber) . '.tif';
        $inpath = $sBookDirectory . $filename;
        $outpath = '';

        if ($ocrvalue == 1) {
            $outpath = $sOcrInDirectory . '\\' . $filename;
        }
        else {
            $outpath = $sSkipOcrDirectory . '\\' . $filename;
        }


        $result = rename($inpath, $outpath);
	$sMoveResult = 'I would move ' . $inpath . ' to ' . $outpath . "\n";
    }

    return $sMoveResult;
}

