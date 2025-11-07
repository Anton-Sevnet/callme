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
use PAMI\Message\Event\BridgeEvent;
use PAMI\Message\Action\ActionMessage;
use PAMI\Message\Action\SetVarAction;
use PAMI\Message\Action\PingAction;
use PAMI\Client\Exception\ClientException;
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

if (!is_array($globalsObj->amiState) || empty($globalsObj->amiState)) {
    $globalsObj->amiState = array(
        'lastEventTs' => microtime(true),
        'lastHash' => null,
        'hashStableSince' => null,
        'lastPingTs' => 0,
        'lastPingStatus' => null,
    );
}

$healthCheckTimeoutSec = (int) $helper->getConfig('healthCheckTimeout');
if ($healthCheckTimeoutSec <= 0) {
    $healthCheckTimeoutSec = 5;
}
$pingIdleTimeoutSec = (int) $helper->getConfig('pingIdleTimeout');
if ($pingIdleTimeoutSec <= 0) {
    $pingIdleTimeoutSec = 30;
}
$listenerTimeoutMicro = (int) $helper->getConfig('listener_timeout');
if ($listenerTimeoutMicro <= 0) {
    $listenerTimeoutMicro = 50000;
}
$healthCheckCycleThreshold = max(1, (int) ceil($healthCheckTimeoutSec / ($listenerTimeoutMicro / 1000000)));
$healthCheckCycleCounter = 0;

function ami_touch_activity($globalsObj)
{
    $globalsObj->amiState['lastEventTs'] = microtime(true);
}

function ami_update_originate_activity(EventMessage $event, $globalsObj)
{
    $candidates = array(
        $event->getKey('Uniqueid'),
        $event->getKey('UniqueID'),
        $event->getKey('Uniqueid1'),
        $event->getKey('Uniqueid2'),
    );
    foreach ($candidates as $uniqueId) {
        if (!$uniqueId) {
            continue;
        }
        $linkedId = $globalsObj->uniqueidToLinkedid[$uniqueId] ?? null;
        if ($linkedId && isset($globalsObj->originateCalls[$linkedId])) {
            $globalsObj->originateCalls[$linkedId]['last_activity'] = time();
        }
    }
}

function compute_active_calls_hash($globalsObj)
{
    if (empty($globalsObj->calls) && empty($globalsObj->originateCalls) && empty($globalsObj->transferHistory) && empty($globalsObj->Onhold)) {
        return null;
    }

    $snapshot = array(
        'calls' => array_keys($globalsObj->calls),
        'originate' => array(),
        'transfer' => array_keys($globalsObj->transferHistory),
        'onhold' => array_keys($globalsObj->Onhold)
    );

    foreach ($globalsObj->originateCalls as $linkedId => $data) {
        $snapshot['originate'][$linkedId] = array(
            'call_id' => $data['call_id'] ?? null,
            'answered' => $data['answered'] ?? false,
            'channels' => isset($data['channels']) ? array_keys($data['channels']) : array(),
            'last_activity' => $data['last_activity'] ?? null,
        );
    }

    $encoded = json_encode($snapshot);
    if ($encoded === false) {
        $encoded = serialize($snapshot);
    }

    return md5($encoded);
}

function ami_attempt_reconnect($pamiClient, $helper, $globalsObj)
{
    $helper->logAmiHealth('reconnect', 'NOTICE', 'Попытка переподключения к AMI после сбоя.');
    try {
        $pamiClient->close();
    } catch (\Throwable $closeError) {
        $helper->logAmiHealth('reconnect', 'DEBUG', 'Ошибка при закрытии соединения AMI', array('error' => $closeError->getMessage()));
    }

    usleep(250000); // 250 мс перед повторным подключением

    try {
        $pamiClient->open();
        ami_touch_activity($globalsObj);
        $globalsObj->amiState['lastHash'] = null;
        $globalsObj->amiState['hashStableSince'] = null;
        $globalsObj->amiState['lastPingStatus'] = 'reconnected';
        $globalsObj->amiState['lastPingTs'] = microtime(true);
        $helper->logAmiHealth('reconnect', 'NOTICE', 'Соединение с AMI восстановлено.');
        return true;
    } catch (ClientException $reconnectError) {
        $helper->logAmiHealth('reconnect', 'NOTICE', 'Не удалось переподключиться к AMI', array('error' => $reconnectError->getMessage()));
        return false;
    }
}

function ami_perform_idle_ping_if_needed($pamiClient, $helper, $globalsObj, $pingIdleTimeoutSec)
{
    $now = microtime(true);
    $stats = $pamiClient->getLastReadStats();
    $lastReadTs = isset($stats['timestamp']) ? (float) $stats['timestamp'] : 0.0;
    $timeSinceData = $lastReadTs > 0 ? $now - $lastReadTs : $pingIdleTimeoutSec + 1;
    $timeSinceEvent = $now - ($globalsObj->amiState['lastEventTs'] ?? 0);

    $currentHash = compute_active_calls_hash($globalsObj);
    if ($currentHash === null) {
        $globalsObj->amiState['lastHash'] = null;
        $globalsObj->amiState['hashStableSince'] = null;
        return;
    }

    if ($globalsObj->amiState['lastHash'] !== $currentHash) {
        $globalsObj->amiState['lastHash'] = $currentHash;
        $globalsObj->amiState['hashStableSince'] = $now;
        return;
    }

    if (empty($globalsObj->amiState['hashStableSince'])) {
        $globalsObj->amiState['hashStableSince'] = $now;
        return;
    }

    $hashStableDuration = $now - $globalsObj->amiState['hashStableSince'];

    if ($hashStableDuration < $pingIdleTimeoutSec) {
        return;
    }

    if ($timeSinceData < $pingIdleTimeoutSec || $timeSinceEvent < $pingIdleTimeoutSec) {
        return;
    }

    if (!empty($globalsObj->amiState['lastPingTs']) && ($now - $globalsObj->amiState['lastPingTs']) < 5) {
        return;
    }

    $helper->logAmiHealth('ping', 'NOTICE', 'Инициирован пинг AMI: нет активности и изменения массивов.', array(
        'time_since_data' => $timeSinceData,
        'time_since_event' => $timeSinceEvent,
        'hash_stable_duration' => $hashStableDuration,
    ));

    try {
        $response = $pamiClient->send(new PingAction());
        $globalsObj->amiState['lastPingTs'] = microtime(true);
        $globalsObj->amiState['hashStableSince'] = microtime(true);
        $globalsObj->amiState['lastPingStatus'] = $response->isSuccess() ? 'success' : 'failure';
        $helper->logAmiHealth(
            'ping',
            $response->isSuccess() ? 'DEBUG' : 'NOTICE',
            $response->isSuccess() ? 'AMI ping успешен.' : 'AMI ping вернул ошибку.',
            array('response' => $response->getMessage())
        );
        if (!$response->isSuccess()) {
            ami_attempt_reconnect($pamiClient, $helper, $globalsObj);
        }
    } catch (ClientException $pingError) {
        $globalsObj->amiState['lastPingTs'] = microtime(true);
        $globalsObj->amiState['lastPingStatus'] = 'error';
        $helper->logAmiHealth('ping', 'NOTICE', 'Исключение при выполнении AMI ping.', array('error' => $pingError->getMessage()));
        ami_attempt_reconnect($pamiClient, $helper, $globalsObj);
    }
}

//обрабатываем NewchannelEventIncoming события 
//1. Создание лидов
//2. Запись звонков
//3. Всплытие карточки
//NewchannelEvent incoming
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($globalsObj) {
        ami_touch_activity($globalsObj);
        ami_update_originate_activity($event, $globalsObj);
    },
    function () {
        return true;
    }
);

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
        $globalsObj->callsByCallId[$call_id] = $callLinkedid; // Обратная связка для fallback
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

//обрабатываем VarSetEvent для BRIDGEPEER - отслеживание transfer через изменение BRIDGEPEER
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper, $globalsObj) {
        if ($event->getVariableName() !== 'BRIDGEPEER') {
            return;
        }
        
        $channel = $event->getChannel();
        $bridgedPeer = $event->getValue(); // Канал с которым соединён
        $uniqueid = $event->getKey("Uniqueid");
        
        // Если канал внешний (IAX2) - пропускаем (обрабатываем только внутренние)
        if (strpos($channel, 'IAX2') !== false) {
            return;
        }
        
        // Определяем является ли текущий канал внутренним через анализ канала
        // Внутренние каналы имеют формат SIP/XXX-YYYY (где XXX - внутренний номер)
        if (strpos($channel, 'SIP/') !== 0) {
            return; // Не SIP канал, пропускаем
        }
        
        // Извлекаем внутренний номер из канала (например SIP/220-000026bd -> 220)
        $intNum = null;
        if (preg_match('/SIP\/(\d+)-/', $channel, $matches)) {
            $intNum = substr($matches[1], 0, 4);
        }
        
        if (!$intNum) {
            return;
        }
        
        // КРИТИЧЕСКОЕ: Игнорируем события BRIDGEPEER между внутренними каналами
        // Если bridgedPeer - это внутренний SIP канал (не внешний), это просто bridge между внутренними
        // Такие события приходят при звонке одного внутреннего на другого, но это НЕ transfer внешнего звонка
        if (strpos($bridgedPeer, 'SIP/') === 0) {
            // Проверяем, не является ли это внешним каналом из transferHistory
            $isExternalChannel = false;
            foreach ($globalsObj->transferHistory as $transferData) {
                $externalChannelBase = preg_replace('/-[^-]+$/', '', $transferData['externalChannel']);
                $bridgedPeerBase = preg_replace('/-[^-]+$/', '', $bridgedPeer);
                if ($externalChannelBase === $bridgedPeerBase || $transferData['externalChannel'] === $bridgedPeer) {
                    $isExternalChannel = true;
                    break;
                }
            }
            
            // Если bridgedPeer - внутренний SIP канал и НЕ внешний канал, пропускаем
            if (!$isExternalChannel) {
                return; // Это bridge между внутренними (например SIP/219 <-> SIP/220), не transfer
            }
        }
        
        // Проверяем что bridgedPeer указывает на внешний канал из transferHistory
        foreach ($globalsObj->transferHistory as $externalUniqueid => $transferData) {
            // Проверяем что звонок ещё активен
            if (!isset($globalsObj->calls[$externalUniqueid])) {
                continue;
            }
            
            // Проверяем что bridgedPeer указывает на внешний канал
            $externalChannelBase = preg_replace('/-[^-]+$/', '', $transferData['externalChannel']);
            $bridgedPeerBase = preg_replace('/-[^-]+$/', '', $bridgedPeer);
            if ($externalChannelBase !== $bridgedPeerBase && $transferData['externalChannel'] !== $bridgedPeer) {
                continue; // Это не внешний канал для данного звонка
            }
            
            // Если новый внутренний отличается от текущего - это transfer
            if ($transferData['currentIntNum'] != $intNum) {
                $oldIntNum = $transferData['currentIntNum'];
                $call_id = $transferData['call_id'];
                
                // Скрываем карточку у старого абонента
                $hideResult = $helper->hideInputCall($oldIntNum, $call_id);
                
                // Показываем карточку новому абоненту
                $showResult = $helper->showInputCall($intNum, $call_id);
                
                // Обновляем transferHistory
                $globalsObj->transferHistory[$externalUniqueid]['currentIntNum'] = $intNum;
                $globalsObj->transferHistory[$externalUniqueid]['history'][] = [
                    'from' => $oldIntNum,
                    'to' => $intNum,
                    'timestamp' => time()
                ];
                
                // Обновляем маппинг
                $globalsObj->intNums[$externalUniqueid] = $intNum;
                
                $helper->writeToLog([
                    'event' => 'VarSetEvent',
                    'variable' => 'BRIDGEPEER',
                    'type' => 'transfer_detected',
                    'externalUniqueid' => $externalUniqueid,
                    'externalChannel' => $transferData['externalChannel'],
                    'bridgedPeer' => $bridgedPeer,
                    'channel' => $channel,
                    'fromIntNum' => $oldIntNum,
                    'toIntNum' => $intNum,
                    'call_id' => $call_id,
                    'action' => 'card moved via BRIDGEPEER',
                    'hideResult' => $hideResult,
                    'showResult' => $showResult
                ], 'TRANSFER: Card moved between users (detected via BRIDGEPEER)');
                
                echo "TRANSFER: Card moved from $oldIntNum to $intNum via BRIDGEPEER for call_id: $call_id\n";
                
                // Нашли transfer - прекращаем поиск
                return;
            }
        }
    },
    function (EventMessage $event) {
        // Фильтр: только VarSetEvent для BRIDGEPEER
        return $event instanceof VarSetEvent && $event->getVariableName() === 'BRIDGEPEER';
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
    $uniqueid = $event->getKey("UniqueID");
    
    // Проверяем что это НЕ реальный Originate-вызов
    $linkedid = $globalsObj->uniqueidToLinkedid[$uniqueid] ?? null;
    $isRealOriginate = false;
    
    if ($linkedid && isset($globalsObj->originateCalls[$linkedid])) {
        $isRealOriginate = $globalsObj->originateCalls[$linkedid]['is_originate'] ?? false;
    }
    
    return
        ($event instanceof DialBeginEvent || $event->getKey("Event") == "Dial")
        // НЕ Originate-вызов (те обрабатываются отдельно)
        && !$isRealOriginate
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
                    $uniqueid = $event->getKey("UniqueID");
                    
                    // Проверяем что это НЕ реальный Originate-вызов
                    $linkedid = $globalsObj->uniqueidToLinkedid[$uniqueid] ?? null;
                    $isRealOriginate = false;
                    
                    if ($linkedid && isset($globalsObj->originateCalls[$linkedid])) {
                        $isRealOriginate = $globalsObj->originateCalls[$linkedid]['is_originate'] ?? false;
                    }
                    
                    return
                        $event instanceof DialEndEvent
                        //проверяем входит ли событие в массив с uniqueid внешних звонков
                        && in_array($event->getKey("Uniqueid"), $globalsObj->uniqueids)
                        // НЕ Originate-вызов (те обрабатываются отдельно)
                        && !$isRealOriginate;

                }
        );

//обрабатываем BridgeEvent события для отслеживания transfer звонков
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper, $globalsObj) {
        // Проверяем что это BridgeEvent со статусом Link
        if (!($event instanceof BridgeEvent) || $event->getBridgeState() !== 'Link') {
            return;
        }
        
        // Игнорируем Bridge между внутренними абонентами (промежуточный этап transfer)
        if ($helper->isInternalToInternalBridge($event)) {
            return; // Не логируем, просто пропускаем
        }
        
        // Проверяем что это Bridge внешний ↔ внутренний
        if (!$helper->isExternalToInternalBridge($event)) {
            return; // Не логируем, просто пропускаем
        }
        
        // Извлекаем данные Bridge
        $bridgeData = $helper->extractBridgeData($event);
        if (!$bridgeData) {
            return; // Не удалось извлечь данные
        }
        
        $externalUniqueid = $bridgeData['externalUniqueid'];
        $internalCallerID = $bridgeData['internalCallerID'];
        
        // ЗАЩИТА: Проверяем что звонок ещё не завершен
        if (!isset($globalsObj->calls[$externalUniqueid])) {
            // Звонок уже завершен (HangupEvent уже обработан), пропускаем
            return; // Не логируем
        }
        
        $call_id = $globalsObj->calls[$externalUniqueid];
        $newIntNum = substr($internalCallerID, 0, 4); // Обрезаем до 4 цифр как внутренний номер
        
        // Проверяем есть ли уже запись в transferHistory
        if (!isset($globalsObj->transferHistory[$externalUniqueid])) {
            // Первое соединение - создаём запись
            $globalsObj->transferHistory[$externalUniqueid] = [
                'call_id' => $call_id,
                'externalChannel' => $bridgeData['externalChannel'],
                'currentIntNum' => $newIntNum,
                'history' => []
            ];
            
            $helper->writeToLog([
                'event' => 'Bridge',
                'type' => 'initial_connection',
                'externalUniqueid' => $externalUniqueid,
                'internalIntNum' => $newIntNum,
                'call_id' => $call_id,
                'action' => 'transferHistory created'
            ], 'TRANSFER: Initial connection tracked');
            
        } else {
            // Transfer на нового абонента (новый Bridge с внешним каналом)
            $oldIntNum = $globalsObj->transferHistory[$externalUniqueid]['currentIntNum'];
            
            // Проверяем что действительно transfer (новый абонент отличается)
            if ($newIntNum == $oldIntNum) {
                return; // Не transfer, просто повторное событие
            }
            
            // Скрываем карточку у старого абонента
            $hideResult = $helper->hideInputCall($oldIntNum, $call_id);
            
            // Показываем карточку новому абоненту
            $showResult = $helper->showInputCall($newIntNum, $call_id);
            
            // Обновляем transferHistory
            $globalsObj->transferHistory[$externalUniqueid]['currentIntNum'] = $newIntNum;
            $globalsObj->transferHistory[$externalUniqueid]['history'][] = [
                'from' => $oldIntNum,
                'to' => $newIntNum,
                'timestamp' => time()
            ];
            
            // Обновляем маппинг для корректного завершения звонка
            $globalsObj->intNums[$externalUniqueid] = $newIntNum;
            
            $helper->writeToLog([
                'event' => 'Bridge',
                'type' => 'transfer',
                'externalUniqueid' => $externalUniqueid,
                'fromIntNum' => $oldIntNum,
                'toIntNum' => $newIntNum,
                'call_id' => $call_id,
                'action' => 'card moved',
                'hideResult' => $hideResult,
                'showResult' => $showResult,
                'transferCount' => count($globalsObj->transferHistory[$externalUniqueid]['history'])
            ], 'TRANSFER: Card moved between users');
            
            echo "TRANSFER: Card moved from $oldIntNum to $newIntNum for call_id: $call_id\n";
        }
    },
    function (EventMessage $event) {
        // Фильтр: только BridgeEvent со статусом Link
        return $event instanceof BridgeEvent && $event->getBridgeState() === 'Link';
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
                $call_id = $globalsObj->calls[$callLinkedid] ?? null;
                
                // FALLBACK: Если не нашли call_id - пропускаем обработку
                if (empty($call_id)) {
                    $helper->writeToLog("No call_id for Uniqueid $callLinkedid, skipping HangupEvent", 'HangupEvent FALLBACK');
                    return;
                }
                
                // Определяем ответственного: если был transfer - берём ПОСЛЕДНЕГО абонента
                if (isset($globalsObj->transferHistory[$callLinkedid])) {
                    // Был transfer - используем ПОСЛЕДНЕГО абонента (currentIntNum)
                    $CallIntNum = $globalsObj->transferHistory[$callLinkedid]['currentIntNum'];
                    
                    $helper->writeToLog([
                        'call_id' => $call_id,
                        'originalIntNum' => isset($globalsObj->transferHistory[$callLinkedid]['history'][0]) 
                            ? $globalsObj->transferHistory[$callLinkedid]['history'][0]['from'] 
                            : $CallIntNum,
                        'finalIntNum' => $CallIntNum,
                        'transferCount' => count($globalsObj->transferHistory[$callLinkedid]['history']),
                        'history' => $globalsObj->transferHistory[$callLinkedid]['history']
                    ], 'HangupEvent: Final responsible user (last transfer) - "Кто последний тот и папа"');
                } else {
                    // Обычный звонок без transfer
                    $CallIntNum = $globalsObj->intNums[$callLinkedid];
                }

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
                
                // СКРЫВАЕМ карточку звонка для пользователя
                $hideResult = $helper->hideInputCall($CallIntNum, $call_id);
                $helper->writeToLog(array(
                    'intNum' => $CallIntNum,
                    'call_id' => $call_id,
                    'hideResult' => $hideResult
                ), 'HangupEvent: Card hidden for user');
                echo "card hidden for intNum: $CallIntNum\n";
                
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

                // Очищаем transferHistory если был transfer
                if (isset($globalsObj->transferHistory[$callLinkedid])) {
                    unset($globalsObj->transferHistory[$callLinkedid]);
                }

                // удаляем из массивов тот вызов, который завершился
                $helper->removeItemFromArray($globalsObj->uniqueids,$callLinkedid,'value');
                $helper->removeItemFromArray($globalsObj->intNums,$callLinkedid,'key');
                $helper->removeItemFromArray($globalsObj->FullFnameUrls,$callLinkedid,'key');
                $helper->removeItemFromArray($globalsObj->Durations,$callLinkedid,'key');
                $helper->removeItemFromArray($globalsObj->Dispositions,$callLinkedid,'key');
                $helper->removeItemFromArray($globalsObj->calls,$callLinkedid,'key');
                $helper->removeItemFromArray($globalsObj->Onhold,$event->getChannel(),'key');
                
                // Очищаем маппинг linkedid если он был создан для обычного звонка
                if (isset($globalsObj->uniqueidToLinkedid[$callLinkedid])) {
                    unset($globalsObj->uniqueidToLinkedid[$callLinkedid]);
                }
                
                echo "\n-------------------------------------------------------------------\n\r";
                echo "\n\r";
            },function (EventMessage $event) use ($globalsObj) {
                    $uniqueid = $event->getKey("Uniqueid");
                    
                    // Проверяем что это НЕ реальный Originate-вызов
                    $linkedid = $globalsObj->uniqueidToLinkedid[$uniqueid] ?? null;
                    $isRealOriginate = false;
                    
                    if ($linkedid && isset($globalsObj->originateCalls[$linkedid])) {
                        $isRealOriginate = $globalsObj->originateCalls[$linkedid]['is_originate'] ?? false;
                    }
                    
                    return
                        $event instanceof HangupEvent
                        //проверяем на вхождение в массив
                        && in_array($uniqueid, $globalsObj->uniqueids)
                        // НЕ Originate-вызов (те обрабатываются отдельно)
                        && !$isRealOriginate
                        ;
                }
        );

// ==========================================
// ОБРАБОТЧИК: Originate-вызовы (с использованием Linkedid)
// ==========================================

// 1. VarSetEvent (CallMeLINKEDID) - ТОЛЬКО создаём маппинг, НЕ создаём структуру
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper, $globalsObj) {
        $uniqueid = $event->getKey("Uniqueid");
        $linkedid = $event->getValue(); // Значение переменной = Linkedid
        $channel = $event->getChannel();
        
        // ТОЛЬКО создаём маппинг для быстрого поиска
        // Структура originateCalls создаётся ТОЛЬКО при получении IS_CALLME_ORIGINATE
        $globalsObj->uniqueidToLinkedid[$uniqueid] = $linkedid;
        
        $helper->writeToLog([
            'uniqueid' => $uniqueid,
            'linkedid' => $linkedid,
            'channel' => $channel
        ], 'LINKEDID: Mapping created (waiting for Originate marker)');
    },
    function (EventMessage $event) {
        return $event instanceof VarSetEvent 
            && $event->getVariableName() === 'CallMeLINKEDID';
    }
);

// 2. VarSetEvent (IS_CALLME_ORIGINATE) - маркер Originate-вызова, ЗДЕСЬ создаём структуру
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper, $globalsObj) {
        $uniqueid = $event->getKey("Uniqueid");
        $channel = $event->getChannel();
        
        // Находим linkedid через маппинг, или используем uniqueid как linkedid
        $linkedid = $globalsObj->uniqueidToLinkedid[$uniqueid] ?? $uniqueid;
        
        // СОЗДАЁМ структуру originateCalls ТОЛЬКО для реальных Originate-звонков
        if (!isset($globalsObj->originateCalls[$linkedid])) {
            $globalsObj->originateCalls[$linkedid] = [
                'channels' => [],
                'call_id' => null,
                'intNum' => null,
                'is_originate' => true,
                'answered' => false,
                'answer_time' => null,
                'last_dialstatus' => null,
                'last_hangup_cause' => null,
                'created_at' => time(),
                'last_activity' => time()
            ];
            
            // Создаём маппинг если его нет
            if (!isset($globalsObj->uniqueidToLinkedid[$uniqueid])) {
                $globalsObj->uniqueidToLinkedid[$uniqueid] = $linkedid;
            }
        } else {
            // Если структура уже есть - просто помечаем как Originate
            $globalsObj->originateCalls[$linkedid]['is_originate'] = true;
        }
        
        // Добавляем канал в список
        $globalsObj->originateCalls[$linkedid]['channels'][$uniqueid] = [
            'channel' => $channel,
            'added_at' => time()
        ];
        $globalsObj->originateCalls[$linkedid]['last_activity'] = time();
        
        $helper->writeToLog([
            'uniqueid' => $uniqueid,
            'linkedid' => $linkedid,
            'channel' => $channel,
            'total_channels' => count($globalsObj->originateCalls[$linkedid]['channels'])
        ], 'ORIGINATE: Marked as Originate call and registered');
    },
    function (EventMessage $event) {
        return $event instanceof VarSetEvent 
            && $event->getVariableName() === 'IS_CALLME_ORIGINATE';
    }
);

// 3. VarSetEvent (CallMeCALL_ID) - получение call_id от Bitrix24 (ТОЛЬКО для Originate!)
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper, $globalsObj) {
        $uniqueid = $event->getKey("Uniqueid");
        $call_id = $event->getValue();
        $channel = $event->getChannel();
        
        // Находим linkedid через маппинг
        $linkedid = $globalsObj->uniqueidToLinkedid[$uniqueid] ?? $uniqueid;
        
        // КРИТИЧНО: Работаем ТОЛЬКО если это реальный Originate-звонок
        if (!isset($globalsObj->originateCalls[$linkedid])) {
            $helper->writeToLog([
                'uniqueid' => $uniqueid,
                'linkedid' => $linkedid,
                'call_id' => $call_id,
                'reason' => 'Not an Originate call - skipping'
            ], 'ORIGINATE: CallMeCALL_ID received but NOT Originate - ignored');
            return;
        }
        
        // Извлекаем внутренний номер из канала (SIP/219 → 219)
        $intNum = null;
        if (preg_match('/^SIP\/(\d+)/', $channel, $matches)) {
            $intNum = $matches[1];
        }
        
        $globalsObj->originateCalls[$linkedid]['call_id'] = $call_id;
        $globalsObj->originateCalls[$linkedid]['intNum'] = $intNum;
        $globalsObj->originateCalls[$linkedid]['last_activity'] = time();
        
        $helper->writeToLog([
            'uniqueid' => $uniqueid,
            'linkedid' => $linkedid,
            'call_id' => $call_id,
            'intNum' => $intNum,
            'channel' => $channel
        ], 'ORIGINATE: Tracking started with call_id');
    },
    function (EventMessage $event) {
        return $event instanceof VarSetEvent 
            && $event->getVariableName() === 'CallMeCALL_ID';
    }
);

// 4. DialEndEvent - результат набора внешнего номера
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper, $globalsObj) {
        $uniqueid = $event->getKey("UniqueID");
        $dialStatus = $event->getDialStatus();
        
        // Находим linkedid через маппинг
        $linkedid = $globalsObj->uniqueidToLinkedid[$uniqueid] ?? null;
        
        if ($linkedid && isset($globalsObj->originateCalls[$linkedid])) {
            $data = $globalsObj->originateCalls[$linkedid];
            
            $globalsObj->originateCalls[$linkedid]['last_dialstatus'] = $dialStatus;
            $globalsObj->originateCalls[$linkedid]['last_activity'] = time();
            
            if ($dialStatus === 'ANSWER') {
                // УСПЕХ - помечаем что звонок отвечен
                $globalsObj->originateCalls[$linkedid]['answered'] = true;
                $globalsObj->originateCalls[$linkedid]['answer_time'] = time();
                
                $helper->writeToLog([
                    'uniqueid' => $uniqueid,
                    'linkedid' => $linkedid,
                    'call_id' => $data['call_id'],
                    'dialStatus' => $dialStatus,
                    'total_channels' => count($data['channels'])
                ], 'ORIGINATE: External dial SUCCESS - call answered');
                
            } else {
                // ОШИБКА - завершаем звонок СРАЗУ
                $statusCode = $helper->getStatusCodeFromDialStatus($dialStatus);
                $finishResult = $helper->finishCall($data['call_id'], $data['intNum'], 0, $statusCode);
                
                $helper->writeToLog([
                    'uniqueid' => $uniqueid,
                    'linkedid' => $linkedid,
                    'call_id' => $data['call_id'],
                    'dialStatus' => $dialStatus,
                    'statusCode' => $statusCode,
                    'finishResult' => $finishResult
                ], 'ORIGINATE: External dial FAILED - finishing call immediately');
                
                // СКРЫВАЕМ карточку звонка для пользователя
                if (!empty($data['intNum']) && !empty($data['call_id'])) {
                    $hideResult = $helper->hideInputCall($data['intNum'], $data['call_id']);
                    $helper->writeToLog([
                        'intNum' => $data['intNum'],
                        'call_id' => $data['call_id'],
                        'hideResult' => $hideResult
                    ], 'ORIGINATE: Card hidden (dial failed)');
                    echo "ORIGINATE: card hidden for intNum: {$data['intNum']} (dial failed)\n";
                }
                
                // Очистка всех маппингов
                foreach ($data['channels'] as $uid => $channelData) {
                    unset($globalsObj->uniqueidToLinkedid[$uid]);
                }
                unset($globalsObj->originateCalls[$linkedid]);
            }
        }
    },
    function (EventMessage $event) use ($globalsObj) {
        $uniqueid = $event->getKey("UniqueID");
        return $event instanceof DialEndEvent 
            && isset($globalsObj->uniqueidToLinkedid[$uniqueid]);
    }
);

// 5. VarSetEvent (CallMeFULLFNAME) - получение URL записи для Originate
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper, $globalsObj) {
        $uniqueid = $event->getKey("Uniqueid");
        $relativePath = $event->getValue();
        
        // Находим linkedid через маппинг
        $linkedid = $globalsObj->uniqueidToLinkedid[$uniqueid] ?? null;
        
        if ($linkedid && isset($globalsObj->originateCalls[$linkedid])) {
            // Сохраняем URL записи (если еще не сохранен)
            if (empty($globalsObj->originateCalls[$linkedid]['record_url'])) {
                $globalsObj->originateCalls[$linkedid]['record_url'] = "http://195.98.170.206/continuous/" . $relativePath;
                $globalsObj->originateCalls[$linkedid]['last_activity'] = time();
                
                $helper->writeToLog([
                    'uniqueid' => $uniqueid,
                    'linkedid' => $linkedid,
                    'record_url' => $globalsObj->originateCalls[$linkedid]['record_url']
                ], 'ORIGINATE: Recording URL received');
            }
        }
    },
    function (EventMessage $event) use ($globalsObj) {
        $uniqueid = $event->getKey("Uniqueid");
        return $event instanceof VarSetEvent 
            && $event->getVariableName() === 'CallMeFULLFNAME'
            && isset($globalsObj->uniqueidToLinkedid[$uniqueid]);
    }
);

// 6. HangupEvent - завершение канала (ГЛАВНЫЙ обработчик завершения звонка)
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper, $globalsObj) {
        $uniqueid = $event->getKey("Uniqueid");
        $cause = $event->getKey("Cause");
        
        // Находим linkedid через маппинг
        $linkedid = $globalsObj->uniqueidToLinkedid[$uniqueid] ?? null;
        
        if ($linkedid && isset($globalsObj->originateCalls[$linkedid])) {
            $data = $globalsObj->originateCalls[$linkedid];
            
            // Сохраняем последний HangupCause
            $globalsObj->originateCalls[$linkedid]['last_hangup_cause'] = $cause;
            $globalsObj->originateCalls[$linkedid]['last_activity'] = time();
            
            // Удаляем ЭТОТ канал из списка
            unset($globalsObj->originateCalls[$linkedid]['channels'][$uniqueid]);
            unset($globalsObj->uniqueidToLinkedid[$uniqueid]);
            
            $remainingChannels = count($globalsObj->originateCalls[$linkedid]['channels']);
            
            $helper->writeToLog([
                'uniqueid' => $uniqueid,
                'linkedid' => $linkedid,
                'cause' => $cause,
                'causeText' => $helper->getHangupCauseText($cause),
                'remaining_channels' => $remainingChannels
            ], 'ORIGINATE: Channel hangup');
            
            // Если ВСЕ каналы завершены - финишируем звонок
            if (empty($globalsObj->originateCalls[$linkedid]['channels'])) {
                $statusCode = $helper->determineOriginateStatusCode($data);
                $duration = $helper->calculateOriginateDuration($data);
                
                // FALLBACK: Если call_id/intNum пустые - пробуем найти в обычных массивах
                $call_id = $data['call_id'];
                $intNum = $data['intNum'];
                
                if (empty($call_id) && isset($globalsObj->calls[$linkedid])) {
                    $call_id = $globalsObj->calls[$linkedid];
                    $helper->writeToLog(['linkedid' => $linkedid, 'call_id' => $call_id], 
                        'ORIGINATE FALLBACK: Found call_id in regular calls array');
                }
                
                if (empty($intNum) && isset($globalsObj->intNums[$linkedid])) {
                    $intNum = $globalsObj->intNums[$linkedid];
                    $helper->writeToLog(['linkedid' => $linkedid, 'intNum' => $intNum], 
                        'ORIGINATE FALLBACK: Found intNum in regular intNums array');
                }
                
                $finishResult = $helper->finishCall($call_id, $intNum, $duration, $statusCode);
                
                $helper->writeToLog([
                    'linkedid' => $linkedid,
                    'call_id' => $call_id,
                    'intNum' => $intNum,
                    'duration' => $duration,
                    'statusCode' => $statusCode,
                    'answered' => $data['answered'],
                    'finishResult' => $finishResult,
                    'used_fallback' => (empty($data['call_id']) || empty($data['intNum']))
                ], 'ORIGINATE: All channels closed - finishing call in Bitrix24');
                
                // СКРЫВАЕМ карточку звонка для пользователя (используем fallback значения)
                if (!empty($intNum) && !empty($call_id)) {
                    $hideResult = $helper->hideInputCall($intNum, $call_id);
                    $helper->writeToLog([
                        'intNum' => $intNum,
                        'call_id' => $call_id,
                        'hideResult' => $hideResult
                    ], 'ORIGINATE: Card hidden for user');
                    echo "ORIGINATE: card hidden for intNum: $intNum\n";
                }
                
                // Асинхронная загрузка записи если есть
                if (!empty($data['record_url'])) {
                    $uploadCmd = sprintf(
                        'php %s/upload_recording_async.php %s %s %s %s %s > /dev/null 2>&1 &',
                        __DIR__,
                        escapeshellarg($data['call_id']),
                        escapeshellarg($data['record_url']),
                        escapeshellarg($data['intNum']),
                        escapeshellarg($duration),
                        escapeshellarg('ANSWERED')
                    );
                    exec($uploadCmd);
                    
                    $helper->writeToLog([
                        'call_id' => $data['call_id'],
                        'url' => $data['record_url']
                    ], 'ORIGINATE: Started async upload');
                }
                
                unset($globalsObj->originateCalls[$linkedid]);
            }
        }
    },
    function (EventMessage $event) use ($globalsObj) {
        $uniqueid = $event->getKey("Uniqueid");
        return $event instanceof HangupEvent 
            && isset($globalsObj->uniqueidToLinkedid[$uniqueid]);
    }
);

// Full event log registration (controlled by config)
$enableFullLog = $helper->getConfig('enable_full_log');
if ($enableFullLog) {
    $pamiClient->registerEventListener(
        function (EventMessage $event) use ($helper,$globalsObj, $callami) {
            $log = "\n------------------------\n";
            $log .= date("Y.m.d G:i:s") . "\n";
            $log .= print_r($event->getRawContent()."\n\r", 1);
            $log .= "\n------------------------\n";
            file_put_contents(getcwd() . '/logs/full.log', $log, FILE_APPEND);
        },
        function (EventMessage $event) {
            // No filter - log all events when enabled
            return true;
        }
    );
}

function check_to_remove_bu_holdtimeout($globalsObj) {
    global $callami, $helper;
    $holdTimeout = (int) $helper->getConfig('hold_timeout');
    if ($holdTimeout <= 0) {
        $holdTimeout = 60;
    }
    foreach ($globalsObj->Onhold as $a) {
        if (time() - $a["time"] > $holdTimeout) {
            $callami->Hangup($a["channel"]);
            $helper->removeItemFromArray($globalsObj->Onhold, $a["channel"],'key');
        }
    }
}

/**
 * Fallback-механизм: проверка "зависших" Originate-вызовов
 * Завершает вызовы для которых не пришли события от Asterisk
 */
function checkOriginateHealthy($globalsObj, $helper) {
    $now = time();
    
    foreach ($globalsObj->originateCalls as $linkedId => $data) {
        // Пропускаем активные звонки (события приходят)
        if ($now - $data['last_activity'] < 30) {
            continue;
        }
        
        // Неотвеченный вызов висит более 10 минут
        if (!$data['answered'] && ($now - $data['created_at']) > 600) {
            $helper->logAmiHealth('watchdog', 'NOTICE', 'Originate-вызов завершён по таймауту 10 минут без ответа.', array(
                'linkedid' => $linkedId,
                'call_id' => $data['call_id'] ?? null,
                'age_seconds' => $now - $data['created_at']
            ));
            $helper->writeToLog([
                'linkedid' => $linkedId,
                'call_id' => $data['call_id'],
                'age_seconds' => $now - $data['created_at'],
                'reason' => 'Unanswered call timeout (10 minutes)'
            ], 'FALLBACK: Force finishing timeout call');
            
            $helper->finishCall($data['call_id'], $data['intNum'], 0, 304);
            
            // СКРЫВАЕМ карточку
            if (!empty($data['intNum']) && !empty($data['call_id'])) {
                $helper->hideInputCall($data['intNum'], $data['call_id']);
                $helper->writeToLog(['intNum' => $data['intNum'], 'call_id' => $data['call_id']], 
                    'FALLBACK: Card hidden (timeout)');
            }
            
            // Очистка маппингов
            foreach ($data['channels'] as $uid => $channelData) {
                unset($globalsObj->uniqueidToLinkedid[$uid]);
            }
            unset($globalsObj->originateCalls[$linkedId]);
            continue;
        }
        
        // Нет активности более 30 секунд (события должны приходить постоянно)
        $helper->logAmiHealth('watchdog', 'NOTICE', 'Originate-вызов завершён из-за отсутствия активности >30 секунд.', array(
            'linkedid' => $linkedId,
            'call_id' => $data['call_id'] ?? null,
            'inactive_seconds' => $now - $data['last_activity'],
            'remaining_channels' => count($data['channels'])
        ));
        $helper->writeToLog([
            'linkedid' => $linkedId,
            'call_id' => $data['call_id'],
            'inactive_seconds' => $now - $data['last_activity'],
            'remaining_channels' => count($data['channels']),
            'reason' => 'No activity for 30+ seconds - finishing call'
        ], 'FALLBACK: Force finishing inactive call');
        
        $statusCode = $helper->determineOriginateStatusCode($data);
        $duration = $helper->calculateOriginateDuration($data);
        
        $helper->finishCall($data['call_id'], $data['intNum'], $duration, $statusCode);
        
        // СКРЫВАЕМ карточку
        if (!empty($data['intNum']) && !empty($data['call_id'])) {
            $helper->hideInputCall($data['intNum'], $data['call_id']);
            $helper->writeToLog(['intNum' => $data['intNum'], 'call_id' => $data['call_id']], 
                'FALLBACK: Card hidden (inactive)');
        }
        
        // Очистка маппингов
        foreach ($data['channels'] as $uid => $channelData) {
            unset($globalsObj->uniqueidToLinkedid[$uid]);
        }
        unset($globalsObj->originateCalls[$linkedId]);
    }
}

// Переменная для отслеживания времени последней проверки Originate
$lastOriginateHealthCheck = 0;

while(true) {
    try {
        $pamiClient->process();
    } catch (ClientException $processError) {
        $helper->logAmiHealth('reconnect', 'NOTICE', 'Ошибка чтения AMI в основном цикле.', array('error' => $processError->getMessage()));
        ami_attempt_reconnect($pamiClient, $helper, $globalsObj);
        usleep($listenerTimeoutMicro);
        continue;
    }

    $healthCheckCycleCounter++;
    if ($healthCheckCycleCounter >= $healthCheckCycleThreshold) {
        $healthCheckCycleCounter = 0;
        ami_perform_idle_ping_if_needed($pamiClient, $helper, $globalsObj, $pingIdleTimeoutSec);
    }

    check_to_remove_bu_holdtimeout($globalsObj);

    // Проверка "зависших" Originate-вызовов каждые 15 секунд
    if (time() - $lastOriginateHealthCheck > 15) {
        checkOriginateHealthy($globalsObj, $helper);
        $lastOriginateHealthCheck = time();
    }

    usleep($listenerTimeoutMicro);
}
$pamiClient->ClosePAMIClient($pamiClient);

