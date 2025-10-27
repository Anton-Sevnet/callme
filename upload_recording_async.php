#!/usr/bin/php
<?php
/**
* Async recording upload worker
* Runs in background to upload call recordings without blocking main CallMeIn process
* PHP Version 8.2+
*/

// проверка на запуск из браузера
(PHP_SAPI !== 'cli' || isset($_SERVER['HTTP_USER_AGENT'])) && die('access error');

require __DIR__ . '/vendor/autoload.php';

$helper = new HelperFuncs();

// Получаем параметры из командной строки
if ($argc < 6) {
    $helper->writeToLog("Invalid arguments count: $argc", 'ASYNC UPLOAD ERROR');
    exit(1);
}

$call_id = $argv[1];
$recordedfile = $argv[2];
$intNum = $argv[3];
$duration = $argv[4];
$disposition = $argv[5];

$helper->writeToLog(array(
    'call_id' => $call_id,
    'url' => $recordedfile,
    'intNum' => $intNum,
    'duration' => $duration,
    'disposition' => $disposition
), 'ASYNC UPLOAD START');

// Retry logic: 5 attempts with increasing delays
$maxAttempts = 5;
$initialDelay = 5;
$success = false;

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $delay = $initialDelay * $attempt;
    
    $helper->writeToLog("Attempt $attempt/$maxAttempts, wait {$delay}s", 'ASYNC UPLOAD');
    sleep($delay);
    
    // Check if file is available
    if (empty($recordedfile)) {
        $helper->writeToLog("Empty URL, skip", 'ASYNC UPLOAD');
        break;
    }
    
    $ch = curl_init($recordedfile);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $helper->writeToLog("File OK, attaching to call", 'ASYNC UPLOAD');
        
        // Attach recording to already finished call
        $result = $helper->uploadRecorderedFileTruth($call_id, $recordedfile, $recordedfile);
        
        $helper->writeToLog(array(
            'call_id' => $call_id,
            'attempt' => $attempt,
            'attach' => $result ? 'OK' : 'FAIL'
        ), 'ASYNC UPLOAD SUCCESS');
        
        $success = true;
        break;
    } else {
        $helper->writeToLog("HTTP $httpCode, file not ready", 'ASYNC UPLOAD');
        
        if ($attempt === $maxAttempts) {
            $helper->writeToLog(array(
                'call_id' => $call_id,
                'attempts' => $maxAttempts,
                'note' => 'Call already finished without recording'
            ), 'ASYNC UPLOAD FAILED');
            
            // finish уже вызван в основном процессе, здесь ничего не делаем
        }
    }
}

exit($success ? 0 : 1);

