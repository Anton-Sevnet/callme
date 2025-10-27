#!/usr/bin/php
<?php
/**
* CallMe events listener for incoming calls
* PHP Version 8.2+
*/

// проверка на запуск из браузера
(PHP_SAPI !== 'cli' || isset($_SERVER['HTTP_USER_AGENT'])) && die('access error');

require __DIR__ . '/vendor/autoload.php';

/*
* start: for events listener
*/
use PAMI\Listener\IEventListener;
use PAMI\Message\Event\EventMessage;
use PAMI\Message\Event;
use PAMI\Message\Event\HoldEvent;
use PAMI\Message\Event\DialBeginEvent;
use PAMI\Message\Event\DialEndEvent;
use PAMI\Message\Event\NewchannelEvent;
use PAMI\Message\Event\VarSetEvent;
use PAMI\Message\Event\HangupEvent;
use PAMI\Message\Action\ActionMessage;
use PAMI\Message\Action\SetVarAction;
/*
* end: for events listener
*/

$helper = new HelperFuncs();
$callami = new CallAMI();

//объект с глобальными массивами
$globalsObj = Globals::getInstance();

//массив внешних номеров
$globalsObj->extentions = $helper->getConfig('extentions');

$globalsObj->user_show_cards = $helper->getConfig('user_show_cards');

//создаем экземпляр класса PAMI
$pamiClient = $callami->NewPAMIClient();
$pamiClient->open();
echo 'Start';
echo "\n\r";


$helper->writeToLog(NULL,
    'Start CallMeIn');

//обрабатываем NewchannelEventIncoming события 
//1. Создание лидов
//2. Запись звонков
//3. Всплытие карточки
//NewchannelEvent incoming
$pamiClient->registerEventListener(
            function (EventMessage $event) use ($helper,$callami,$globalsObj){
                //выгребаем параметры звонка

                $callLinkedid = $event->getKey("Uniqueid");
                $extNum = $event->getCallerIdNum();
                if (strlen($extNum) < 6) {
                    return "";
                }
                $exten = $event->getExtension();
                $CallChannel = $event->getChannel();
                echo 'NewchannelEventIncoming'."\n";
                echo $event->getRawContent();

                //добавляем звонок в массив, для обработки в других ивентах
                $globalsObj->uniqueids[] = $callLinkedid;
                $globalsObj->Dispositions[$callLinkedid] = 'NO ANSWER';
                //берем Exten из ивента

                //логируем параметры звонка
                $helper->writeToLog(array('extNum' => $extNum,
                                        'callUniqueid' => $callLinkedid,
                                        'Exten' => $exten),
                                    'New NewchannelEvent call');

                //выбираем из битрикса данные CRM-сущности (имя + ответственный) по номеру телефона
                $crmData = $helper->getCrmEntityDataByPhone($extNum);
                $CallMeCallerIDName = $crmData['name'];
                $responsibleUserId = $crmData['responsible_user_id'];
                
                $helper->writeToLog(array(
                    'CallMeCallerIDName' => $CallMeCallerIDName,
                    'responsibleUserId' => $responsibleUserId
                ), 'CRM entity data by phone');
                                               
                // выставим CallerID 
                $callami->SetVar("CALLERID(name)", $CallMeCallerIDName, $CallChannel);
                
                // Получаем внутренний номер из маппинга как fallback
                $bx24 = $helper->getConfig('bx24');
                // Проверка совместимости с PHP 8.2: array_key_exists требует массив
                if (!is_array($bx24)) {
                    $bx24 = array('default_user_number' => '100');
                }
                $fallbackIntNum = array_key_exists($exten, $bx24) ? $bx24[$exten] : $bx24["default_user_number"];
                
                // Определяем внутренний номер ДО регистрации звонка
                $intNum = $fallbackIntNum; // По умолчанию используем fallback
                
                // Если нашли ответственного в CRM - получаем его внутренний номер
                if ($responsibleUserId) {
                    $responsibleIntNum = $helper->getIntNumByUSER_ID($responsibleUserId);
                    if ($responsibleIntNum) {
                        $intNum = $responsibleIntNum;
                        $helper->writeToLog(array(
                            'responsibleUserId' => $responsibleUserId,
                            'responsibleIntNum' => $responsibleIntNum
                        ), 'Found responsible internal number from CRM');
                    } else {
                        $helper->writeToLog("Responsible user $responsibleUserId has no internal number, using fallback: $fallbackIntNum", 
                            'Responsible determination');
                    }
                } else {
                    $helper->writeToLog("No responsible found in CRM, using fallback: $fallbackIntNum", 
                        'Responsible determination');
                }
                
                $bx24_source = $helper->getConfig('bx24_crm_source');
                // Проверка совместимости с PHP 8.2: array_key_exists требует массив
                if (!is_array($bx24_source)) {
                    $bx24_source = array('default_crm_source' => 'CALL');
                }
                $srmSource = array_key_exists($exten, $bx24_source) ? $bx24_source[$exten] : $bx24_source["default_crm_source"];
                
                // Регистрируем звонок в Битрикс24 с ПРАВИЛЬНЫМ внутренним номером
                $callResult = $helper->runInputCall($intNum, $extNum, $exten, $srmSource);
                
                if (!$callResult) {
                    echo "Failed to register call in Bitrix24\n";
                    return "";
                }
                
                $call_id = $callResult['CALL_ID'];
                
                $helper->writeToLog(array(
                    'fallbackIntNum' => $fallbackIntNum,
                    'selectedIntNum' => $intNum,
                    'responsibleUserId' => $responsibleUserId ?? 'none',
                    'CRM_ENTITY_TYPE' => $callResult['CRM_ENTITY_TYPE'] ?? 'none',
                    'CRM_ENTITY_ID' => $callResult['CRM_ENTITY_ID'] ?? 'none',
                    'CALL_ID' => $call_id
                ), 'Call registered with responsible');
                
                // Показываем карточку ответственному
                $result = $helper->showInputCall($intNum, $call_id);
                $helper->writeToLog(var_export($result, true), "show input card to $intNum (responsible) from $exten");
                echo "callid = ".$call_id." \n";
                echo "responsible intNum = ".$intNum." \n";
                
                $globalsObj->calls[$callLinkedid] = $call_id;
                $globalsObj->intNums[$callLinkedid] = $intNum;
                $globalsObj->Durations[$callLinkedid] = 0;
                $globalsObj->Dispositions[$callLinkedid] = "NO ANSWER";
                echo "\n-------------------------------------------------------------------\n\r";
                echo "\n\r";

            }, function (EventMessage $event) use ($globalsObj){
                    //для фильтра берем только указанные внешние номера

                    return
                        ($event instanceof NewchannelEvent)
                        && ($event->getExtension() != "s")
                        && (strpos($event->getContext(), "trunk") != -1)
                        && ($event->getName() == "Newchannel")
                        //проверяем на вхождение в массив
                        && in_array($event->getExtension(), $globalsObj->extentions)
                        ;
                }
        );

//обрабатываем NewchannelEventOutgoing события
//NewchannelEvent outgoing
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper,$callami,$globalsObj){
        //выгребаем параметры звонка
        $callLinkedid = $event->getKey("Uniqueid");
        $extNum = $event->getExtension();
        $intNum = $event->getCallerIdNum();
        if (strlen($extNum) < 6) {
            echo "Local call, not reg $extNum \n";
            echo "\n-------------------------------------------------------------------\n\r";
            echo "\n\r";

            return "";
        }
        $exten = '';
        echo 'NewchannelEventOutgoing'."\n";
        echo $event->getRawContent()."\n";

        echo "intNum ".$intNum." extNum ".$extNum." \n";

        $call_id = $helper->runOutputCall($intNum,$extNum, "");
        $result = $helper->showOutputCall($intNum, $call_id);
        $helper->writeToLog($event->getRawContent()."\n");
        $helper->writeToLog(var_export($result, true), "show output card to $intNum ");

        if ($call_id == false) {
            echo "\n-------------------------------------------------------------------\n\r";
            echo "\n\r";
            return "";
        }

        echo "call_id ".$call_id." strlen ".strlen($call_id)." \n";
        //логируем параметры звонка
        $helper->writeToLog(array('extNum' => $extNum,
            'callUniqueid' => $callLinkedid,
            'Exten' => $exten),
            'New NewchannelEvent Outgoing call');

        //добавляем звонок в массив, для обработки в других ивентах
        $globalsObj->calls[$callLinkedid] = $call_id;
        $globalsObj->uniqueids[] = $callLinkedid;
        $globalsObj->Dispositions[$callLinkedid] = 'NO ANSWER';
        $globalsObj->intNums[$callLinkedid] = $intNum;
        $globalsObj->Durations[$callLinkedid] = 0;
        echo "-------------------------------------------------------------------\n\r";
        echo "\n\r";

    },function (EventMessage $event) use ($globalsObj){

        return
            ($event instanceof NewchannelEvent)
            && ($event->getExtension() !== 's')
//            && ($event->getContext() === 'E1' || $event->getContext() == 'office')
            // Если user_show_cards пуст - показываем всем (Битрикс сам определит ответственного)
            // Если заполнен - фильтруем по списку внутренних номеров
            && (empty($globalsObj->user_show_cards) || in_array($event->getCallerIdNum(), $globalsObj->user_show_cards))
            ;
}
);

//обрабатываем VarSetEvent события, получаем url записи звонка
//VarSetEvent
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper,$globalsObj) {
        echo 'VarSetEvent'."\n";
        echo $event->getRawContent();
        $callLinkedid = $event->getKey("Uniqueid");

        if ($event->getVariableName() === 'CallMeFULLFNAME' and
            !isset($globalsObj->FullFnameUrls[$callLinkedid])) {
            // Получаем относительный путь (YYYY/MM/DD/call-XXXXX.mp3) и формируем полный URL
            $relativePath = $event->getValue();
            $globalsObj->FullFnameUrls[$callLinkedid] = "http://195.98.170.206/continuous/" . $relativePath;
        }

        if (($event->getVariableName()  === 'ANSWER' or $event->getVariableName()  === "DIALSTATUS")
            and strlen($event->getValue()) > 1) {
            $globalsObj->Dispositions[$callLinkedid] = "ANSWERED";
        } else if ($event->getVariableName()  === 'ANSWER' and strlen($event->getValue()) == 0) {
            $globalsObj->Dispositions[$callLinkedid] = "NO ANSWER";
        }

        if(preg_match('/^\d+$/',$event->getValue())) $globalsObj->Durations[$callLinkedid] = $event->getValue();
        if(preg_match('/^[A-Z\ ]+$/',$event->getValue())) $globalsObj->Dispositions[$callLinkedid] = $event->getValue();

        //логируем параметры звонка
        $helper->writeToLog(array('FullFnameUrls'=>$globalsObj->FullFnameUrls,
                                  'Durations'=>$globalsObj->Durations,
                                  'Dispositions'=>$globalsObj->Dispositions),
            'New VarSetEvent - get FullFname,CallMeDURATION,CallMeDISPOSITION');
        echo "\n-------------------------------------------------------------------\n\r";
        echo "\n\r";
        },function (EventMessage $event) use ($globalsObj) {
            return
                $event instanceof VarSetEvent
                //проверяем что это именно нужная нам переменная
                && ($event->getVariableName() === 'CallMeFULLFNAME'
                    || $event->getVariableName() === 'DIALSTATUS'
                    || $event->getVariableName()  === 'CallMeDURATION'
                    || $event->getVariableName()  === 'ANSWER')

                //проверяем на вхождение в массив
                && in_array($event->getKey("Uniqueid"), $globalsObj->uniqueids);
        }
);

//обрабатываем HoldEvent события
$pamiClient->registerEventListener(
            function (EventMessage $event) use ($helper,$globalsObj, $callami) {
                //выгребаем параметры звонка

                echo "HoldEvent\n\r";
                echo $event->getRawContent()."\n\r";
                $channel = $event->getChannel();
                if (substr($channel, 7,1) === "-" ) {
                    $globalsObj->Onhold[$channel] = array("channel" =>$channel, "time"=>time());
                }
                echo "\n-------------------------------------------------------------------\n\r";
                echo "\n\r";
            },function (EventMessage $event) use ($globalsObj) {
                    return
                        $event instanceof Event\MusicOnHoldStartEvent
                        ;
                }
        );

//обрабатываем DialBeginEvent события
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper,$globalsObj, $callami) {
        //выгребаем параметры звонка
	    echo "Dial Begin ";
        echo $event->getRawContent()."\n\r";
        $helper->writeToLog("Dial Begin ");
        $helper->writeToLog($event->getRawContent());
        $callUniqueid = $event->getKey("Uniqueid");
        $exten = $event->getKey("DialString");

        // Если user_show_cards пуст - показываем всем (Битрикс сам определит ответственного)
        // Если заполнен - фильтруем по списку внутренних номеров
        if ($globalsObj->calls[$callUniqueid] !== 'undefined' 
            && (empty($globalsObj->user_show_cards) || in_array($exten, $globalsObj->user_show_cards))) {
            $result = $helper->showInputCall($exten, $globalsObj->calls[$callUniqueid]);
            $helper->writeToLog(var_export($result, true), "show input card to $exten ");
            $helper->writeToLog("show input call to ".$exten);
            echo "\n-------------------------------------------------------------------\n\r";
            echo "\n\r";
        }

    },function (EventMessage $event) use ($globalsObj) {
    return
        $event instanceof DialBeginEvent || $event->getKey("Event") == "Dial"
        ;
}
);

//обрабатываем UnHoldEvent события
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper,$globalsObj, $callami) {
        //выгребаем параметры звонка

        echo "EVENT \n\r";
        echo $event->getRawContent()."\n\r";
        echo "\n-------------------------------------------------------------------\n\r";
        echo "\n\r";
    },function (EventMessage $event) use ($globalsObj) {

    return
        in_array($event->getKey("Uniqueid"), $globalsObj->uniqueids);
}
);

//обрабатываем UnHoldEvent события
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper,$globalsObj, $callami) {
        //выгребаем параметры звонка

        echo "UnholdEvent\n\r";
        echo $event->getRawContent()."\n\r";
        $channel = $event->getkey("Channel");
        $helper->removeItemFromArray($globalsObj->Onhold, $channel,'key');
        echo "\n-------------------------------------------------------------------\n\r";
        echo "\n\r";
    },function (EventMessage $event) use ($globalsObj) {

    return
        $event->getKey("Event") === 'Unhold';
}
);

//обрабатываем DialEndEvent события
$pamiClient->registerEventListener(
            function (EventMessage $event) use ($helper,$globalsObj) {
                echo "DialEndEvent\n\r";
                echo $event->getRawContent()."\n\r";
                //выгребаем параметры звонка
                $callLinkedid = $event->getKey("Uniqueid");

                if ($event->getContext() === 'office' and !strpos($event->getKey("Channel"), "ocal/") and $event->getDialStatus() === "ANSWER") {
                    $globalsObj->intNums[$callLinkedid] = is_numeric($event->getCallerIDNum()) ? $event->getCallerIDNum() : $event->getCallerIDName();
                    $globalsObj->intNums[$callLinkedid] = substr($globalsObj->intNums[$callLinkedid], 0, 4);
                    $extNum = $event->getKey("Exten");
                } else if ($event->getContext() === 'from-trunk' and $event->getDialStatus() === "ANSWER") {
                    $intNum = $globalsObj->intNums[$callLinkedid];
                    $globalsObj->intNums[$callLinkedid] = is_numeric($event->getDestCallerIDNum()) ? $event->getDestCallerIDNum() : $event->getDestCallerIDName();
                    if (strlen($globalsObj->intNums[$callLinkedid]) > 3) {
                        $globalsObj->intNums[$callLinkedid] = $intNum;
                    }
                    $extNum = $event->getCallerIDNum();
                    $globalsObj->Dispositions[$callLinkedid] = $event->getDialStatus();
                    $globalsObj->Answers[$callLinkedid] = time();
                } else if ($event->getContext() === 'office' and strpos($event->getKey("Channel"), "ocal/") and $event->getDialStatus() === "ANSWER") {
                    $globalsObj->intNums[$callLinkedid] = is_numeric($event->getDestCallerIDNum()) ? $event->getDestCallerIDNum() : $event->getDestCallerIDName();
                    $globalsObj->intNums[$callLinkedid] = substr($globalsObj->intNums[$callLinkedid], 0, 4);
                    $extNum = $event->getCallerIDNum();
                }
                $helper->writeToLog($event->getRawContent()."\n\r");
                switch ($event->getDialStatus()) {
                    case 'ANSWER': //кто-то отвечает на звонок
                        $helper->writeToLog(array('intNum'=>$globalsObj->intNums[$callLinkedid],
                                                    'extNum'=>$extNum,
                                                    'callUniqueid'=>$callLinkedid,
                                                    'CALL_ID'=>$globalsObj->calls[$callLinkedid]),
                                                'incoming call ANSWER');
                        //для всех, кроме отвечающего, скрываем карточку

                        $helper->hideInputCallExcept($globalsObj->intNums[$callLinkedid], $globalsObj->calls[$callLinkedid]);
                        break;
                    case 'BUSY': //занято
                        $helper->writeToLog(array('intNum'=>$globalsObj->intNums[$callLinkedid],
                                                    'callUniqueid'=>$callLinkedid,
                                                    'CALL_ID'=>$globalsObj->calls[$callLinkedid]),
                                                'incoming call BUSY');
                        //скрываем карточку для юзера
                        $helper->hideInputCall($globalsObj->intNums[$callLinkedid], $globalsObj->calls[$callLinkedid]);
                        break;
                    case 'CANCEL': //звонивший бросил трубку
                        $helper->writeToLog(array('intNum'=>$globalsObj->intNums[$callLinkedid],
                                                    'callUniqueid'=>$callLinkedid,
                                                    'CALL_ID'=>$globalsObj->calls[$callLinkedid]),
                                                'incoming call CANCEL');
                        //скрываем карточку для юзера
                        $helper->hideInputCall($globalsObj->intNums[$callLinkedid], $globalsObj->calls[$callLinkedid]);
                        break;            
                    default:
                        break;
                }

                if ($globalsObj->Dispositions[$callLinkedid] === 'ANSWER') {
                    $globalsObj->Dispositions[$callLinkedid] = "ANSWERED";
                }
                echo "\n-------------------------------------------------------------------\n\r";
                echo "\n\r";
            },
            function (EventMessage $event) use ($globalsObj) {
                    return
                        $event instanceof DialEndEvent
                        //проверяем входит ли событие в массив с uniqueid внешних звонков
                        && in_array($event->getKey("Uniqueid"), $globalsObj->uniqueids);

                }
        );

//обрабатываем HangupEvent события, отдаем информацию о звонке и url его записи в битрикс
$pamiClient->registerEventListener(
            function (EventMessage $event) use ($callami, $helper, $globalsObj) {
                $helper->writeToLog($event->getRawContent()."\n\r");
                echo "HangupEvent\n\r";
                echo $event->getRawContent()."\n\r";
//                $CoreShowChannels = $callami->GetVar($event->getChannel(), "ANSWER")->getRawContent();
//
//
//                if (!strpos($CoreShowChannels, "No such channel")) {
//                    echo "-----------------------------------------------------\n\r";
//                    return "";
//                }
//
//                if (!($globalsObj->intNums[$callLinkedid] == $event->getCallerIDNum() or
//                    $globalsObj->intNums[$callLinkedid] == $event->getCallerIDName())) {
//                    return "";
//                }


                $callLinkedid = $event->getKey("Uniqueid");
//                if ($callLinkedid != $event->getKey("Uniqueid")) {
//                    echo $callLinkedid." ".$event->getKey("Uniqueid");
//                    $helper->writeToLog ("-----------------------------------------------------");
//                    $helper->writeToLog($callLinkedid != $event->getKey("Uniqueid"));
//                    return "";
//                }


                $FullFname = $globalsObj->FullFnameUrls[$callLinkedid];
//                $FullFname = "";
//              Длинна разговора, пусть будет всегда не меньше 1
                $CallDuration = $globalsObj->Durations[$callLinkedid];
                if ($globalsObj->Answers[$callLinkedid]) {
                    $CallDuration = time() - $globalsObj->Answers[$callLinkedid];
                }
//                $CallDuration = $CallDuration ? $CallDuration : 1;

                $CallDisposition = $globalsObj->Dispositions[$callLinkedid];
                $call_id = $globalsObj->calls[$callLinkedid];
                $CallIntNum = $globalsObj->intNums[$callLinkedid];

                // логируем $callUniqueid, $FullFnameUrls, $calls, $Durations, $Dispositions
                $helper->writeToLog(array($callLinkedid,$globalsObj->FullFnameUrls,$globalsObj->calls,$globalsObj->Durations,$globalsObj->Dispositions),
                    'New HangupEvent Zero step - params');
                // логируем то, что мы собрались отдать битриксу
                $helper->writeToLog(
                    array('FullFname'=>$FullFname,
                          'call_id'=>$call_id,
                          'intNum'=>$CallIntNum,
                          'Duration'=>$CallDuration,
                          'Disposition'=>$CallDisposition),
                    'New HangupEvent First step - recording filename URL, intNum, Duration, Disposition -----');
                echo "try to send in bx24 \n";
                echo var_export(array('FullFname'=>$FullFname,
                    'call_id'=>$call_id,
                    'intNum'=>$CallIntNum,
                    'Duration'=>$CallDuration,
                    'Disposition'=>$CallDisposition), true);
                
                // НЕМЕДЛЕННО завершаем звонок в Битрикс (БЕЗ записи)
                $statusCode = $helper->getStatusCodeFromDisposition($CallDisposition);
                $finishResult = $helper->finishCall($call_id, $CallIntNum, $CallDuration, $statusCode);
                $helper->writeToLog($finishResult, 'Call finished immediately (without record)');
                echo "call finished immediately in B24, status: $statusCode\n";
                
                // Upload with async background process to avoid blocking main CallMeIn process
                $uploadCmd = sprintf(
                    'php %s/upload_recording_async.php %s %s %s %s %s > /dev/null 2>&1 &',
                    __DIR__,
                    escapeshellarg($call_id),
                    escapeshellarg($FullFname),
                    escapeshellarg($CallIntNum),
                    escapeshellarg($CallDuration),
                    escapeshellarg($CallDisposition)
                );
                exec($uploadCmd);
                
                $helper->writeToLog(array(
                    'call_id' => $call_id,
                    'url' => $FullFname,
                    'intNum' => $CallIntNum,
                    'duration' => $CallDuration
                ), 'HangupEvent: Started async upload');
                
                echo "async upload started \n";

                // удаляем из массивов тот вызов, который завершился
                $helper->removeItemFromArray($globalsObj->uniqueids,$callLinkedid,'value');
                $helper->removeItemFromArray($globalsObj->intNums,$callLinkedid,'key');
                $helper->removeItemFromArray($globalsObj->FullFnameUrls,$callLinkedid,'key');
                $helper->removeItemFromArray($globalsObj->Durations,$callLinkedid,'key');
                $helper->removeItemFromArray($globalsObj->Dispositions,$callLinkedid,'key');
                $helper->removeItemFromArray($globalsObj->calls,$callLinkedid,'key');
                $helper->removeItemFromArray($globalsObj->Onhold,$event->getChannel(),'key');
                echo "\n-------------------------------------------------------------------\n\r";
                echo "\n\r";
            },function (EventMessage $event) use ($globalsObj) {
                    return
                        $event instanceof HangupEvent
                        //проверяем на вхождение в массив
                        && in_array($event->getKey("Uniqueid"), $globalsObj->uniqueids)
                        ;
                }
        );

$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper,$globalsObj, $callami) {
        $log = "\n------------------------\n";
        $log .= date("Y.m.d G:i:s") . "\n";
        $log .= print_r($event->getRawContent()."\n\r", 1);
        $log .= "\n------------------------\n";
        file_put_contents(getcwd() . '/logs/full.log', $log, FILE_APPEND);
    },function (EventMessage $event) use ($globalsObj) {

    return False;
}
);

function check_to_remove_bu_holdtimeout($globalsObj) {
    global $callami, $helper;
    foreach ($globalsObj->Onhold as $a) {
        if (time() - $a["time"] > 1) {
            $callami->Hangup($a["channel"]);
            $helper->removeItemFromArray($globalsObj->Onhold, $a["channel"],'key');
        }
    }
}

while(true) {
    $pamiClient->process();
    check_to_remove_bu_holdtimeout($globalsObj);
    usleep($helper->getConfig('listener_timeout'));
}
$pamiClient->ClosePAMIClient($pamiClient);

