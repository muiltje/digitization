<?php

/*
 *Gather the files per book after OCR.
* Step 1: read booknumbers.txt.
* Step 2: for each book, check which pages should be ocred.
* Step 3: if ocr for the book has finished, put meta tag in htms for the book and write them to Ready
* Step 4: if htmfiles have been moved to Ready (or if no pages had to be ocred), gather all tifs for the book and put them in Ready as well
* Step 5: move everything from Ready to sipbijzcoll
* Step 6: do some cleaning: remove subdirectories for books that are ready and update booknumbers.txt
* Step 7: send the results to the workflow webservice for logging
*/

/**
* Logging:
* We want to send logdata to the webservice, so package them into strings we can send.
* itemnumbers: string with itemnumbers, separated by ;
* incomplete ocr: string with itemnumbers, separated by ;
* error messages: string with "itemnumber: message", separated by ;
* clean up: simple string with message
*/

require_once 'init.php';

if (!is_dir($sSipBijzColl)) {
	$lfh = fopen($sLogFile, 'a');
	fwrite($lfh, 'Geen verbinding met de SIP');
	fclose($lfh);
	exit();
}

$aDirectories = array(
	'htm' => $sOcrOutDirectory,
	'tif' => $sOcrInDirectory,
	'skipped' => $sSkipOcrDirectory,
	'original' => $sStartDirectory,
	'postocr' =>$sTifPostOcrDirectory,
	'ready' => $sReadyDirectory,
	'sip' => $sSipBijzColl,
);
$sWebserviceURL = WEBSERVICE;
$sOcrWebserviceURL = WEBSERVICE . '/ocr';

$sLogItemNumbers = '';
$sLogMessages = '';
$sLogCleanup = '';
$sLogGeneral = '';

$aBookNumbers = array();
//Step 1: read booknumbers.txt.
$aBookNumbers = getBookNumbers($sBookNumberFile);

$aBooksDone = array();
$aBooksLeft = array();


/**
* Process each book in turn
*/
foreach ($aBookNumbers as $itemnumber) {
    $sLogItemNumbers .= $itemnumber . ';';

    $templine = "\n" . date("Y-m-d H:i:s") . ' Ik doe nu ' . $itemnumber . "\n";
    $lfh = fopen($sLogFile, 'a');
    fwrite($lfh, $templine);
    fclose($lfh);

    //Step 2: for each book, check which pages should be ocred.
    $aOcrDataInformation = getOCRData($itemnumber, $sWebserviceURL);
    $aOcrData = $aOcrDataInformation['OcrData'];
    if ($aOcrDataInformation['errors'] != '') {
		$lfh = fopen($sLogFile, 'a');
		$errorline = date("Y-m-d H") . ' kon geen OCR gegevens vinden ' . var_export($aOcrDataInformation['errors'], true) . "\n";
		fwrite($lfh, $errorline);
		fclose($lfh);
		$sLogMessages .= $itemnumber . ':Er is iets mis in getOCRdata';
    }

    if (count($aOcrData) > 0) {
		// For each file, check if it should be ocred and if yes, check if the htm is there.
		// As soon as a htm file is missing, stop and write to log.
		// If all htms are there, proceed.
		// Please note: this also checks pages and books that do not have to be OCRed.
		$aHtmCheckResults = checkHTM($aOcrData, $itemnumber, $sOcrOutDirectory);
		$bHtmCheck = $aHtmCheckResults['result'];

		//Step 3: if ocr for the book has finished, put meta tag in htms for the book and write them to Ready
		//Step 4: gather all tifs for the book and put them in Ready as well
		//these steps are done in processFiles
		if ($bHtmCheck == '1') {
           // add meta tag to each htm for this book and write it to Ready
           // get the tifs for this book and move them to Ready
           // get any ignored tifs for this book and move them to Ready
            $aProcessResult = processFiles($itemnumber, $aOcrData, $aDirectories, $sLogFile);
            if (isset($aProcessResult['error'])) {
				$sLogMessages .= $itemnumber . ':' . $aProcessResult['error'] . ';';
				$aBooksLeft[] = $itemnumber;
            }
            else {
				$aBooksDone[] = $itemnumber;
				$sLogMessages .= $itemnumber . ':klaar voor DSpace;';
            }
		}
		else {
            $aBooksLeft[] = $itemnumber;
            $sLogMessages .= $itemnumber . ':' . $aHtmCheckResults['message'] . ';';
       	}
    }
    else {
		$lfh = fopen($sLogFile, 'a');
		$errorline = date("Y-m-d H:i:s") . ' ' . $itemnumber .  " geen gegevens over de OCR gevonden; verwerking afgebroken \n";
		fwrite($lfh, $errorline);
		fclose($lfh);
		$sLogMessages .= $itemnumber . ':geen gegevens over de OCR gevonden; verwerking afgebroken;';
    }
}

/**
* Step 5: move everything from Ready to sipbijzcoll
*/
$movetosip = moveFinalDirectories($aBooksDone, $aDirectories);
if ($movetosip != 1) {
	$sLogMessages .= 'xxxxxx: bestanden zijn niet verplaatst naar sipbijzcoll;';
}

/**
* Step 6: do some cleaning: remove subdirectories for books that are ready and update booknumbers.txt
* remove empty directories
* remove WeGaanOCRen file
* update booknumbers.txt to hold only the books that haven't been processed
*/
$cleanup = cleanDirectories($aBooksDone, $aDirectories);

$startocrfile = $sScriptDirectory . '\WeGaanOCRen.txt';

while (is_file($startocrfile) == TRUE) {
    chmod($startocrfile, 0666);
    unlink($startocrfile);
}

$finalcleanup = newBookNumbers($aBooksLeft, $sBookNumberFile);
if ($cleanup == 1) {
    $sLogCleanup = 'directories opgeruimd';
}
else {
    $sLogCleanup = 'kon directories niet opruimen';
}




/**
* Step 7: send the results to the workflow webservice for logging
*/
$url = $sWebserviceURL . '/logdata';
$data_array = array(
	'itemnumbers' => $sLogItemNumbers,
	'messages' => $sLogMessages,
	'cleanup' => $sLogCleanup,
	'general' => $sLogGeneral,
);
$data = http_build_query($data_array);
//print_r($data);
$params = array('http' => array('method' => 'POST', 'content' => $data, 'header'=> 'Content-Type: application/x-www-form-urlencoded'));
$ctx = stream_context_create($params);
try {
    $fp = fopen($url, 'rb', false, $ctx);
    $answer = stream_get_contents($fp);
    echo $answer;
}
catch (Exception $e) {
	$lfh = fopen($sLogFile, 'a');
	$errorline = date("Y m d H:m") . " kon geen log data versturen \n";
	fwrite($lfh, $errorline);
	fclose($lfh);
}

echo 'done';
exit;


/**
* Get the itemnumbers from the file that was made by the getTifForOcr script
*/
function getBookNumbers($sBookNumberFile)
{
   $booknumbers = array();
   if (file_exists($sBookNumberFile)) {
        $fh = fopen($sBookNumberFile, "r");
        $contents = fread($fh, filesize($sBookNumberFile));

        //throw away last comma if it isn't followed by a digit
        $contents = preg_replace('/,$/', '', $contents);

        //extract booknumbers and put booknumbers into array
        $booknumbers = explode(',', $contents);

        fclose($fh);
    }
    else {

    }


    return $booknumbers;
}

/**
* Contact the workflow webservice for data about the OCR.
* This data tells us which pages there are for this item
* and which of them should be OCRed.
*/
function getOCRData($itemnumber, $sWebserviceURL)
{
	$aOcrDataInfo = array();
	$aOcrData = array();
	$sErrorMessages = '';

	//$aOcrData['itemnumber'] = $itemnumber;

	$serviceurl = $sWebserviceURL . '/ocr/' .  $itemnumber;
	$incomingdata = file_get_contents($serviceurl);
	$incomingdatafixed = str_replace( array('&lt;','&gt;') ,array('<','>'),$incomingdata);  //nasty fix for the new restler which now encodes the xml tags. We choose to fix it here and transform the response body to xml again

	try {
		$xml = new SimpleXMLElement($incomingdatafixed);
		if (is_object($xml)) {

			//total number of scans
			$totalfield = $xml->xpath('//totalscans');
			$totalscans = (int) $totalfield[0];

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
		} //end of if_object($xml)
		else {
			$sErrorMessages .= 'Kon geen contact maken met de webservice';
		}
	} //end of try
	catch (Exception $e) {
		$sErrorMessages .= 'Er is iets mis in getOCRdata ' . $e->getMessage();
	}
	$aOcrDataInfo['errors'] = $sErrorMessages;
	$aOcrDataInfo['OcrData'] = $aOcrData;

	return $aOcrDataInfo;
}


/**
* Check if all pages that should have been OCRed have indeed been.
* If a page does not have to be OCRed, it is skipped.
*/
function checkHTM($aOcrData, $itemnumber, $sOcrHtmDirectory)
{
	$aResults = array();
	//in $aOCRData the key is the number of the scan, thus the filename
	//the value is 1 if the scan should be ocred, otherwise 0
	foreach ($aOcrData as $sScannumber=>$bOcr) {
		//if $bOCR is 1, check if $sScannumber.htm exists
		if ($bOcr == '1') {
			$file = $sOcrHtmDirectory . "\\" . $itemnumber . sprintf("%04d", $sScannumber) . '.htm';
			//as soon as a htm file is missing, stop and return
			if (!(file_exists($file))) {
				//echo 'incomplete item ' . $itemnumber . "! \n";
				//$warning = 'van item ' . $itemnumber . " is de OCR nog niet af \n";
				//fwrite($lfh, $warning);
				$aResults['message'] = 'De OCR is nog niet af ik mis ' . $sScannumber;
				$aResults['result'] = '0';
				return $aResults;
			}
		}
	}
	//if no htm files are missing, return 1
	$aResults['result'] = 1;
	return $aResults;
}

/**
* Process all files for the item.
* Get all tif and htm files for the item and put them in the Ready directory.
* This is done for each item for which the OCR process has completed.
* Files are found in the htm-directory (for OCR results),
* the ocr-done directory (for tifs that have been OCRed)
* and the not-for-ocr directory (for tifs that don't have to be OCRed)
*/
function processFiles($itemnumber, $aOCRData, $aDirectories, $sLogFile)
{
	$aResults = array();
	$htmdirectory = $aDirectories['htm'];
	//$tifdirectory = $aDirectories['tif'];
	$ocrdonedirectory = $aDirectories['postocr'];
	$skippeddirectory = $aDirectories['skipped'];
	$readydirectory = $aDirectories['ready'];

	$tifdirectory = $ocrdonedirectory;

	//check if a directory for this itemnumber exists; if not, create it
	$itemdirectory = $readydirectory . '\\' . $itemnumber . '\\';
	echo "itemdir is $itemdirectory <br><br>\n";

	if (!(@opendir($itemdirectory))) {
		try {
			mkdir($itemdirectory, 0755);
		}
		catch (Exception $e) {
			echo 'could not create a directory for this item ' . "\n";
			$warning = 'Het systeem kon geen directory maken om de bestanden te plaatsen ' . "\n";
			$lfh = fopen($sLogFile, 'a');
			$errorline = date("Y m d H:m") . $warning;
			fwrite($lfh, $errorline);
			fclose($lfh);

			$aResults['error'] = $warning;
			return $aResults;
		}
	}

	//for each page in the item: process htm and tif file
	foreach ($aOCRData as $sScannumber=>$bOcr) {
		$filenamebase = $itemnumber . sprintf("%04d", $sScannumber);

                //process htm and tif file
		if ($bOcr == '1') {
			//update htm and write updated file to Ready

                        $htmfile = $htmdirectory . '\\' . $filenamebase . '.htm';
			$contents = file_get_contents($htmfile);
			$markedup = '';
        		$endhead = '/<\/head>/i';
        		$newmeta = '<meta name="bookid" content="' . $itemnumber . '">' . "\n" . '</head>';
        		$markedup = preg_replace($endhead, $newmeta, $contents);

			$newfile =  $itemdirectory. sprintf("%04d", $sScannumber) . '.htm';
			//echo 'updating text to ' . $newfile . "\n";
			$updateresult = file_put_contents($newfile, $markedup);
			//some checking on result?


			//remove old htm file
			//drastic way of removing file, as simple unlink will not always work
			while (is_file($htmfile) == TRUE) {
				chmod($htmfile, 0666);
				unlink($htmfile);
			}

			//move tif file to Ready
			$tiffile = $tifdirectory . '\\' . $filenamebase . '.tif';
			$destination = $itemdirectory . '\\' . $filenamebase . '.tif';
			//echo 'move ocred from ' . $tiffile . 'to ' . $destination . "<br>\n";
			$result = @rename($tiffile, $destination);
			//check on result
			if ($result != 1) {
				$lfh = fopen($sLogFile, 'a');
				$errorline = date("Y m d H:m ") . " kon niet schrijven naar $destination  <br>\n";
				fwrite($lfh, $errorline);
				fclose($lfh);
			}
		}
		else { //no htm, so just move tif file
                    $tiffile = $skippeddirectory . '\\' . $filenamebase . '.tif';
                    $destination = $itemdirectory . '\\' . $filenamebase . '.tif';
                    $result = 'b';

                    //make sure the tif file exists
                    if (!(file_exists($tiffile))) {
                        $aResults['message'] = 'Het boek is niet compleet ik mis ' . $filenamebase . '.tif';
			$aResults['result'] = '0';
                    }
                    else {
			$result = @rename($tiffile, $destination);
			//echo 'move from ' . $tiffile . ' to ' . $destination . ' - ' . $result . "<br>\n";

			//check on result
			if ($result != 1) {
                            $lfh = fopen($sLogFile, 'a');
                            $errorline = date("Y m d H:m ") . " kon niet schrijven naar $destination <br> \n";
                            fwrite($lfh, $errorline);
                            fclose($lfh);
			}
                    }
		}
	}

	return $aResults;
}

/**
* Move everything from Ready to the SIP
*/
function moveFinalDirectories($aBooksDone, $aDirectories)
{
	$readydir = $aDirectories['ready'];
	$sipdir = $aDirectories['sip'];
	$result = 0;

	foreach ($aBooksDone as $itemnumber) {
		$sourcedir = $readydir . '\\' . $itemnumber;
		$destinationdir = $sipdir . '\\' . $itemnumber;

		if (!(@opendir($destinationdir))) {
			$mk = mkdir($destinationdir);
			//if ($mk === FALSE) {
			//	$result = 0;
			//	return $result;
			//}
		}

		$dh = opendir($sourcedir);
                if ($dh) {
                    while (($file = @readdir($dh)) !== false) {
                        if ($file != "." && $file != "..") {
                            $source = $sourcedir . "\\" . $file;
                            $destination = $destinationdir . "\\" . $file;
                            //echo 'ik zou ' . $source . ' verplaatsen naar ' . $destination . '<br>';
                            $result = @rename($source, $destination);
                       }
                    }
 		}

		$result = rmdir($sourcedir);
	}

	return $result;
}



/**
* Clean up.
* The directories into which the tif files were first put still exist.
* Now that we know the item has been fully processed, we can remove these
* original directories.
*/
function cleanDirectories($aBooksDone, $aDirectories)
{
	$result = 0;

	$originaltifdirectory = $aDirectories['original'];
	foreach ($aBooksDone as $itemnumber) {
		$orgitemdirectory = $originaltifdirectory . '\\' . $itemnumber;

		//check if there is a Thumbs.db file and if so, delete it
		$thumbsfile = $orgitemdirectory. '\Thumbs.db';
		if (file_exists($thumbsfile)) {
			@unlink($thumbsfile);
		}
		$result = @rmdir($orgitemdirectory);

	}

	return $result;
}



/**
* If there are any items that have not been fully processed,
* put their number in the booknumbers file.
* That way we can be sure they will be seen again next time,
* even if no new items are added to the process queue.
* If there are no items to be written, just write an empty line
*/
function newBookNumbers($aBooksLeft, $sBookNumberFile)
{
	$newbookline = '';
	if (count($aBooksLeft) > 0) {
		foreach ($aBooksLeft as $itemnumber) {
			$newbookline .= $itemnumber . ',';
		}
	}

	$fh = fopen($sBookNumberFile, 'w');
	fwrite($fh, $newbookline);
	fclose($fh);

	return 1;
}