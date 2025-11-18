#!/usr/bin/php
<?php
/**
* CallMe events listener for incoming calls
* PHP Version 8.2+
*/

// проверка на запуск из браузера
(PHP_SAPI !== 'cli' || isset($_SERVER['HTTP_USER_AGENT'])) && die('access error');

require __DIR__ . '/vendor/autoload.php';

if (!function_exists('callme_normalize_phone')) {
    function callme_normalize_phone($number) {
        $digits = preg_replace('/\D+/', '', (string)$number);
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        } elseif (strlen($digits) === 11 && $digits[0] === '7') {
            // already correct
        } elseif (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        if (strlen($digits) === 11 && $digits[0] === '7') {
            return '+7' . substr($digits, 1);
        }
        if (strpos($digits, '+7') === 0) {
            return $digits;
        }
        return $digits === '' ? '' : $digits;
    }
}

if (!function_exists('callme_channel_base')) {
    /**
     * Возвращает канал без суффиксов ";1" и динамических хвостов "-0000000a".
     *
     * @param string $channel
     * @return string
     */
    function callme_channel_base($channel)
    {
        if ($channel === null) {
            return '';
        }

        $normalized = (string) $channel;
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/;.*$/', '', $normalized);
        $normalized = preg_replace('/-[^-]+$/', '', $normalized);

        return (string) $normalized;
    }
}

if (!function_exists('callme_normalize_route_type')) {
    /**
     * Нормализует строковое представление типа маршрута вызова.
     *
     * @param string|null $routeType
     * @return string 'direct'|'multi'
     */
    function callme_normalize_route_type($routeType)
    {
        $value = strtolower(trim((string)$routeType));
        if ($value === '') {
            return 'direct';
        }

        $multiAliases = array('queue', 'ring_group', 'ringgroup', 'multi', 'multiagent', 'group', 'multi_exten', 'multi-route');
        if (in_array($value, $multiAliases, true)) {
            return 'multi';
        }

        return 'direct';
    }
}

if (!function_exists('callme_maybe_unset_route_type')) {
    /**
     * Удаляет сохранённый тип маршрута для linkedid, когда нет активных карточек/звонков.
     *
     * @param string $linkedid
     * @param Globals $globalsObj
     * @return void
     */
    function callme_maybe_unset_route_type($linkedid, Globals $globalsObj)
    {
        if ($linkedid === '') {
            return;
        }
        if (!isset($globalsObj->ringingIntNums[$linkedid]) && !isset($globalsObj->callShownCards[$linkedid])) {
            unset($globalsObj->callRouteTypes[$linkedid]);
        }
    }
}

/*
* start: for events listener
*/
use PAMI\Listener\IEventListener;
use PAMI\Message\Event\EventMessage;
use PAMI\Message\Event;
use PAMI\Message\Event\HoldEvent;
use PAMI\Message\Event\DialBeginEvent;
use PAMI\Message\Event\DialEndEvent;
use PAMI\Message\Event\DialEvent;
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
    if (empty($globalsObj->calls) && empty($globalsObj->originateCalls) && empty($globalsObj->Onhold)) {
        return null;
    }

    $snapshot = array(
        'calls' => array_keys($globalsObj->calls),
        'originate' => array(),
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

/**
 * Показываем карточки звонка всем текущим абонентам в состоянии RING.
 *
 * @param string $linkedid
 * @param HelperFuncs $helper
 * @param Globals $globalsObj
 * @return void
 */
function callme_show_cards_for_ringing($linkedid, $helper, $globalsObj)
{
    if (empty($linkedid)) {
        return;
    }
    $call_id = $globalsObj->callIdByLinkedid[$linkedid] ?? null;
    if (!$call_id && !empty($globalsObj->transferHistory)) {
        foreach ($globalsObj->transferHistory as $transferData) {
            if (!is_array($transferData)) {
                continue;
            }
            if (empty($transferData['call_id'])) {
                continue;
            }
            $historyLinkedid = $transferData['linkedid'] ?? null;
            if ($historyLinkedid && $historyLinkedid === $linkedid) {
                $call_id = $transferData['call_id'];
                break;
            }
        }
    }
    if (!$call_id) {
        foreach ($globalsObj->ringingIntNums[$linkedid] ?? array() as $intNum => $ringData) {
            $candidate = $globalsObj->callIdByInt[$intNum] ?? null;
            if ($candidate) {
                $call_id = $candidate;
                break;
            }
        }
        if (!$call_id) {
            foreach ($globalsObj->calls as $uniq => $storedCallId) {
                if (($globalsObj->uniqueidToLinkedid[$uniq] ?? null) === $linkedid) {
                    $call_id = $storedCallId;
                    break;
                }
            }
        }
        if ($call_id) {
            $globalsObj->callIdByLinkedid[$linkedid] = $call_id;
            $globalsObj->callsByCallId[$call_id] = $linkedid;
        }
    }
    if (!$call_id) {
        foreach ($globalsObj->calls as $uniqueid => $candidateCallId) {
            if (($globalsObj->uniqueidToLinkedid[$uniqueid] ?? null) === $linkedid) {
                $call_id = $candidateCallId;
                break;
            }
        }
    }
    if (!$call_id) {
        return;
    }
    $globalsObj->callIdByLinkedid[$linkedid] = $call_id;
    $globalsObj->callsByCallId[$call_id] = $linkedid;
    if (empty($globalsObj->ringingIntNums[$linkedid]) || !is_array($globalsObj->ringingIntNums[$linkedid])) {
        return;
    }

    foreach ($globalsObj->ringingIntNums[$linkedid] as $intNum => $ringData) {
        $state = $ringData['state'] ?? 'RING';
        if ($state !== 'RING') {
            continue;
        }
        $shownEntry = $globalsObj->callShownCards[$linkedid][$intNum] ?? null;
        $alreadyShown = false;
        if (is_array($shownEntry)) {
            $alreadyShown = !empty($shownEntry['shown']);
        } else {
            $alreadyShown = !empty($shownEntry);
        }
        if ($alreadyShown) {
            continue;
        }
        callme_show_card_for_int($linkedid, $intNum, $helper, $globalsObj);
    }
}

/**
 * Показываем карточку звонка конкретному внутреннему номеру, если это ещё не сделано.
 *
 * @param string $linkedid
 * @param string $intNum
 * @param HelperFuncs $helper
 * @param Globals $globalsObj
 * @return void
 */
function callme_show_card_for_int($linkedid, $intNum, $helper, $globalsObj)
{
    if (empty($linkedid) || empty($intNum)) {
        return;
    }

    $call_id = $globalsObj->callIdByLinkedid[$linkedid] ?? null;
    if (!$call_id) {
        return;
    }

    $ringData = $globalsObj->ringingIntNums[$linkedid][$intNum] ?? array();
    $userId = $ringData['user_id'] ?? $helper->getUSER_IDByIntNum($intNum);
    if (!$userId) {
        return;
    }

    if (callme_is_card_marked_shown($linkedid, $intNum, $globalsObj)) {
        return;
    }

    $result = $helper->showInputCall($intNum, $call_id);
    $helper->writeToLog(array(
        'linkedid' => $linkedid,
        'intNum' => $intNum,
        'userId' => $userId,
        'call_id' => $call_id,
        'result' => $result,
    ), 'show input call single');

    if ($result) {
        $globalsObj->callIdByInt[$intNum] = $call_id;
        $globalsObj->callIdByLinkedid[$linkedid] = $call_id;
        $globalsObj->callsByCallId[$call_id] = $linkedid;
        if (!isset($globalsObj->callShownCards[$linkedid])) {
            $globalsObj->callShownCards[$linkedid] = array();
        }
        $globalsObj->callShownCards[$linkedid][$intNum] = array(
            'user_id' => $userId ? (int)$userId : null,
            'int_num' => (string)$intNum,
            'shown' => true,
            'shown_at' => time(),
        );
        if (!isset($globalsObj->ringingIntNums[$linkedid])) {
            $globalsObj->ringingIntNums[$linkedid] = array();
        }
        if (!isset($globalsObj->ringingIntNums[$linkedid][$intNum])) {
            $globalsObj->ringingIntNums[$linkedid][$intNum] = array();
        }
        $globalsObj->ringingIntNums[$linkedid][$intNum]['user_id'] = $userId;
        $globalsObj->ringingIntNums[$linkedid][$intNum]['state'] = $globalsObj->ringingIntNums[$linkedid][$intNum]['state'] ?? 'RING';
        $globalsObj->ringingIntNums[$linkedid][$intNum]['shown'] = true;
        $globalsObj->ringingIntNums[$linkedid][$intNum]['timestamp'] = time();
    }
}

/**
 * Проверяет, отмечена ли карточка как показанная для указанного внутреннего номера.
 *
 * @param string $linkedid
 * @param string $intNum
 * @param Globals $globalsObj
 * @return bool
 */
function callme_is_card_marked_shown($linkedid, $intNum, Globals $globalsObj)
{
    if (!isset($globalsObj->callShownCards[$linkedid][$intNum])) {
        return false;
    }
    $entry = $globalsObj->callShownCards[$linkedid][$intNum];
    if (is_array($entry)) {
        return !empty($entry['shown']);
    }
    return (bool)$entry;
}

/**
 * Пытается определить linkedid, связанный с указанным внутренним номером.
 *
 * @param string|null $intNum
 * @param Globals $globalsObj
 * @param string|null $fallbackLinkedid
 * @return string|null
 */
function callme_find_linkedid_for_int($intNum, Globals $globalsObj, $fallbackLinkedid = null)
{
    if ($intNum === null || $intNum === '') {
        return $fallbackLinkedid;
    }
    $intNum = (string)$intNum;

    foreach ($globalsObj->callShownCards as $linkedidCandidate => $cardMap) {
        if (isset($cardMap[$intNum])) {
            return $linkedidCandidate;
        }
    }

    foreach ($globalsObj->ringingIntNums as $linkedidCandidate => $ringMap) {
        if (isset($ringMap[$intNum])) {
            return $linkedidCandidate;
        }
    }

    if (isset($globalsObj->callIdByInt[$intNum])) {
        $callId = $globalsObj->callIdByInt[$intNum];
        if ($callId !== '' && isset($globalsObj->callsByCallId[$callId])) {
            return $globalsObj->callsByCallId[$callId];
        }
        foreach ($globalsObj->callIdByLinkedid as $linkedidCandidate => $storedCallId) {
            if ($storedCallId === $callId) {
                return $linkedidCandidate;
            }
        }
    }

    return $fallbackLinkedid;
}

/**
 * Клонирует контекст звонка (CALL_ID, CRM-данные, направление) между linkedid.
 *
 * @param string|null $sourceLinkedid
 * @param string $targetLinkedid
 * @param string $callId
 * @param Globals $globalsObj
 * @return void
 */
function callme_clone_call_context($sourceLinkedid, $targetLinkedid, $callId, Globals $globalsObj)
{
    if ($targetLinkedid === '' || $callId === '') {
        return;
    }

    $globalsObj->callIdByLinkedid[$targetLinkedid] = $callId;
    $globalsObj->callsByCallId[$callId] = $targetLinkedid;
    $globalsObj->calls[$targetLinkedid] = $callId;

    if ($sourceLinkedid && !isset($globalsObj->callCrmData[$targetLinkedid]) && isset($globalsObj->callCrmData[$sourceLinkedid])) {
        $globalsObj->callCrmData[$targetLinkedid] = $globalsObj->callCrmData[$sourceLinkedid];
    }
    if ($sourceLinkedid && !isset($globalsObj->callDirections[$targetLinkedid]) && isset($globalsObj->callDirections[$sourceLinkedid])) {
        $globalsObj->callDirections[$targetLinkedid] = $globalsObj->callDirections[$sourceLinkedid];
    }
    if ($sourceLinkedid && isset($globalsObj->callRouteTypes[$sourceLinkedid])) {
        if (!isset($globalsObj->callRouteTypes[$targetLinkedid]) || $globalsObj->callRouteTypes[$targetLinkedid] === '') {
            $globalsObj->callRouteTypes[$targetLinkedid] = $globalsObj->callRouteTypes[$sourceLinkedid];
        }
    }
}

/**
 * Собирает список целей (пользователь/внутренний номер), для которых карточка была показана.
 *
 * @param string $linkedid
 * @param HelperFuncs $helper
 * @param Globals $globalsObj
 * @param string|null $excludeIntNum
 * @return array<int,array{user_id:int,int_num:string}>
 */
function callme_collect_shown_card_targets($linkedid, HelperFuncs $helper, Globals $globalsObj, $excludeIntNum = null)
{
    $targets = array();
    if (empty($linkedid)) {
        return $targets;
    }
    if (empty($globalsObj->callShownCards[$linkedid]) || !is_array($globalsObj->callShownCards[$linkedid])) {
        return $targets;
    }

    foreach ($globalsObj->callShownCards[$linkedid] as $intNum => $cardInfo) {
        $intNumStr = (string)$intNum;
        if ($intNumStr === '') {
            continue;
        }
        if ($excludeIntNum !== null && (string)$excludeIntNum === $intNumStr) {
            continue;
        }

        $userId = null;
        if (is_array($cardInfo) && isset($cardInfo['user_id'])) {
            $userId = (int)$cardInfo['user_id'];
        }
        if (!$userId && isset($globalsObj->ringingIntNums[$linkedid][$intNum]['user_id'])) {
            $userId = (int)$globalsObj->ringingIntNums[$linkedid][$intNum]['user_id'];
        }
        if (!$userId) {
            $userId = (int)$helper->getUSER_IDByIntNum($intNumStr);
        }
        if ($userId <= 0) {
            continue;
        }

        if (!is_array($cardInfo)) {
            $globalsObj->callShownCards[$linkedid][$intNum] = array();
        }
        $globalsObj->callShownCards[$linkedid][$intNum]['user_id'] = $userId;
        $globalsObj->callShownCards[$linkedid][$intNum]['int_num'] = $intNumStr;
        $globalsObj->callShownCards[$linkedid][$intNum]['shown'] = true;
        $globalsObj->callShownCards[$linkedid][$intNum]['updated_at'] = time();

        $targets[] = array(
            'user_id' => $userId,
            'int_num' => $intNumStr,
        );
    }

    return $targets;
}

/**
 * Выполняет групповое скрытие карточек звонка для указанного linkedid.
 *
 * @param string $linkedid
 * @param string $call_id
 * @param HelperFuncs $helper
 * @param Globals $globalsObj
 * @param string|null $excludeIntNum
 * @return array{result:array|false,targets:array}
 */
function callme_hide_cards_batch($linkedid, $call_id, HelperFuncs $helper, Globals $globalsObj, $excludeIntNum = null)
{
    $targets = callme_collect_shown_card_targets($linkedid, $helper, $globalsObj, $excludeIntNum);
    if (empty($targets) || !$call_id) {
        return array('result' => false, 'targets' => array());
    }

    $hideResult = $helper->hideInputCallForTargets($call_id, $targets);

    foreach ($targets as $target) {
        $intNum = $target['int_num'];
        if (isset($globalsObj->callShownCards[$linkedid][$intNum])) {
            unset($globalsObj->callShownCards[$linkedid][$intNum]);
        }
    }
    if (isset($globalsObj->callShownCards[$linkedid]) && empty($globalsObj->callShownCards[$linkedid])) {
        unset($globalsObj->callShownCards[$linkedid]);
    }

    $helper->writeToLog(array(
        'linkedid' => $linkedid,
        'call_id' => $call_id,
        'targets' => $targets,
        'result' => $hideResult,
    ), 'Batch hide call cards');

    if ($linkedid !== '') {
        callme_maybe_unset_route_type($linkedid, $globalsObj);
    }

    return array('result' => $hideResult, 'targets' => $targets);
}

/**
 * Принудительно останавливает отображение карточки для конкретного внутреннего номера.
 *
 * @param string $linkedid
 * @param string $intNum
 * @param HelperFuncs $helper
 * @param Globals $globalsObj
 * @param array $context
 * @return array{call_id:?string,hidden:bool}|false
 */
function callme_force_ring_entry_cleanup($linkedid, $intNum, HelperFuncs $helper, Globals $globalsObj, array $context = array())
{
    if ($linkedid === '' || $intNum === '') {
        return false;
    }

    $callId = callme_resolve_call_id($linkedid, $intNum, $globalsObj, $helper);
    $ringStateBefore = $globalsObj->ringingIntNums[$linkedid][$intNum] ?? null;
    $routeType = 'direct';
    if (is_array($ringStateBefore) && !empty($ringStateBefore['route_type'])) {
        $routeType = callme_normalize_route_type($ringStateBefore['route_type']);
    } elseif (isset($globalsObj->callRouteTypes[$linkedid])) {
        $routeType = callme_normalize_route_type($globalsObj->callRouteTypes[$linkedid]);
    }
    $ringStateLabel = strtoupper((string)($ringStateBefore['state'] ?? ''));
    if ($routeType !== 'direct' && $ringStateLabel === 'RING' && empty($ringStateBefore['shown'])) {
        $helper->writeToLog(array_merge(array(
            'linkedid' => $linkedid,
            'intNum' => $intNum,
            'route_type' => $routeType,
            'ringState' => $ringStateLabel,
        ), $context), 'Skip force ring cleanup for multi-route leg');
        return false;
    }
    $hidden = false;

    if ($callId && callme_is_card_marked_shown($linkedid, $intNum, $globalsObj)) {
        $hidden = (bool)$helper->hideInputCall($intNum, $callId);
    }

    if (isset($globalsObj->callShownCards[$linkedid][$intNum])) {
        unset($globalsObj->callShownCards[$linkedid][$intNum]);
        if (empty($globalsObj->callShownCards[$linkedid])) {
            unset($globalsObj->callShownCards[$linkedid]);
        }
    }

    if (isset($globalsObj->ringingIntNums[$linkedid][$intNum])) {
        unset($globalsObj->ringingIntNums[$linkedid][$intNum]);
        if (empty($globalsObj->ringingIntNums[$linkedid])) {
            unset($globalsObj->ringingIntNums[$linkedid]);
        }
    }

    if (isset($globalsObj->ringOrder[$linkedid])) {
        $globalsObj->ringOrder[$linkedid] = array_values(array_filter(
            $globalsObj->ringOrder[$linkedid],
            function ($value) use ($intNum) {
                return (string)$value !== (string)$intNum;
            }
        ));
        if (empty($globalsObj->ringOrder[$linkedid])) {
            unset($globalsObj->ringOrder[$linkedid]);
        }
    }

    if (isset($globalsObj->callIdByInt[$intNum])) {
        unset($globalsObj->callIdByInt[$intNum]);
    }

    if (!empty($context['agent_uniqueid'])) {
        unset($globalsObj->uniqueidToLinkedid[$context['agent_uniqueid']]);
        unset($globalsObj->intNums[$context['agent_uniqueid']]);
    }

    $helper->writeToLog(array_merge(array(
        'linkedid' => $linkedid,
        'intNum' => $intNum,
        'call_id' => $callId,
        'route_type' => $routeType,
        'hidden' => $hidden,
    ), $context), 'Forced ring entry cleanup');

    if ($callId === null && $helper->isDebugEnabled()) {
        $helper->writeToLog(array(
            'linkedid' => $linkedid,
            'intNum' => $intNum,
            'context' => $context,
            'ringStateBefore' => $ringStateBefore,
            'activeCallsHash' => compute_active_calls_hash($globalsObj),
        ), 'Ring cleanup without CALL_ID snapshot');
    }

    callme_maybe_unset_route_type($linkedid, $globalsObj);

    return array(
        'call_id' => $callId,
        'hidden' => $hidden,
    );
}

/**
 * Переносит карточку звонка между внутренними номерами (attended/blind transfer, очередь).
 *
 * @param string $linkedid
 * @param string $callId
 * @param string|null $fromIntNum
 * @param string $toIntNum
 * @param HelperFuncs $helper
 * @param Globals $globalsObj
 * @param array $context
 * @return void
 */
function callme_transfer_move_card($linkedid, $callId, $fromIntNum, $toIntNum, HelperFuncs $helper, Globals $globalsObj, array $context = array())
{
    if ($callId === '' || $toIntNum === '') {
        return;
    }

    $toIntNum = (string)$toIntNum;
    $fromIntNum = $fromIntNum !== null ? (string)$fromIntNum : null;
    $fallbackLinkedid = $linkedid !== '' ? $linkedid : ($globalsObj->callsByCallId[$callId] ?? null);

    $targetLinkedid = callme_find_linkedid_for_int($toIntNum, $globalsObj, $fallbackLinkedid);
    $sourceLinkedid = $fallbackLinkedid;

    if ($fromIntNum !== null && $fromIntNum !== '' && $fromIntNum !== $toIntNum) {
        $sourceLinkedid = callme_find_linkedid_for_int($fromIntNum, $globalsObj, $fallbackLinkedid);
        $helper->hideInputCall($fromIntNum, $callId);

        if ($sourceLinkedid && isset($globalsObj->callShownCards[$sourceLinkedid][$fromIntNum])) {
            unset($globalsObj->callShownCards[$sourceLinkedid][$fromIntNum]);
            if (empty($globalsObj->callShownCards[$sourceLinkedid])) {
                unset($globalsObj->callShownCards[$sourceLinkedid]);
            }
        }

        if ($sourceLinkedid && isset($globalsObj->ringingIntNums[$sourceLinkedid][$fromIntNum])) {
            $globalsObj->ringingIntNums[$sourceLinkedid][$fromIntNum]['shown'] = false;
            $globalsObj->ringingIntNums[$sourceLinkedid][$fromIntNum]['state'] = 'TRANSFERRED';
        }

        if ($sourceLinkedid && isset($globalsObj->ringOrder[$sourceLinkedid])) {
            $globalsObj->ringOrder[$sourceLinkedid] = array_values(array_filter(
                $globalsObj->ringOrder[$sourceLinkedid],
                function ($value) use ($fromIntNum) {
                    return (string)$value !== (string)$fromIntNum;
                }
            ));
            if (empty($globalsObj->ringOrder[$sourceLinkedid])) {
                unset($globalsObj->ringOrder[$sourceLinkedid]);
            }
        }

        if (isset($globalsObj->callIdByInt[$fromIntNum]) && $globalsObj->callIdByInt[$fromIntNum] === $callId) {
            unset($globalsObj->callIdByInt[$fromIntNum]);
        }
    }

    if (!$targetLinkedid) {
        $targetLinkedid = $sourceLinkedid ?: $fallbackLinkedid;
    }
    if (!$targetLinkedid) {
        return;
    }

    if ($sourceLinkedid && $sourceLinkedid !== $targetLinkedid) {
        callme_clone_call_context($sourceLinkedid, $targetLinkedid, $callId, $globalsObj);
    } else {
        callme_clone_call_context($targetLinkedid, $targetLinkedid, $callId, $globalsObj);
    }

    if (!isset($globalsObj->ringOrder[$targetLinkedid])) {
        $globalsObj->ringOrder[$targetLinkedid] = array();
    }
    if (!in_array($toIntNum, $globalsObj->ringOrder[$targetLinkedid], true)) {
        $globalsObj->ringOrder[$targetLinkedid][] = $toIntNum;
    }

    if (!isset($globalsObj->ringingIntNums[$targetLinkedid])) {
        $globalsObj->ringingIntNums[$targetLinkedid] = array();
    }
    if (!isset($globalsObj->ringingIntNums[$targetLinkedid][$toIntNum])) {
        $globalsObj->ringingIntNums[$targetLinkedid][$toIntNum] = array();
    }

    $routeType = $globalsObj->callRouteTypes[$targetLinkedid] ?? null;
    if (!$routeType && $sourceLinkedid && isset($globalsObj->callRouteTypes[$sourceLinkedid])) {
        $routeType = $globalsObj->callRouteTypes[$sourceLinkedid];
    }
    if (!$routeType) {
        $routeType = 'direct';
    }
    $globalsObj->callRouteTypes[$targetLinkedid] = $routeType;
    $globalsObj->ringingIntNums[$targetLinkedid][$toIntNum]['route_type'] = $routeType;

    $globalsObj->ringingIntNums[$targetLinkedid][$toIntNum]['state'] = 'TRANSFER';
    $globalsObj->ringingIntNums[$targetLinkedid][$toIntNum]['timestamp'] = time();

    $userId = $globalsObj->ringingIntNums[$targetLinkedid][$toIntNum]['user_id'] ?? $helper->getUSER_IDByIntNum($toIntNum);
    if ($userId) {
        $globalsObj->ringingIntNums[$targetLinkedid][$toIntNum]['user_id'] = $userId;
    }

    $globalsObj->callIdByLinkedid[$targetLinkedid] = $callId;
    $globalsObj->callsByCallId[$callId] = $targetLinkedid;
    $globalsObj->callIdByInt[$toIntNum] = $callId;

    callme_show_card_for_int($targetLinkedid, $toIntNum, $helper, $globalsObj);

    if (!empty($context)) {
        $helper->writeToLog(array_merge($context, array(
            'linkedid' => $targetLinkedid,
            'sourceLinkedid' => $sourceLinkedid,
            'call_id' => $callId,
            'fromIntNum' => $fromIntNum,
            'toIntNum' => $toIntNum,
        )), 'TRANSFER: Card moved');
    }
}

$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper, $globalsObj) {
        if (!($event instanceof BridgeEvent) || $event->getBridgeState() !== 'Link') {
            return;
        }

        if ($helper->isInternalToInternalBridge($event)) {
            return;
        }

        if (!$helper->isExternalToInternalBridge($event)) {
            return;
        }

        $bridgeData = $helper->extractBridgeData($event);
        if (!$bridgeData) {
            return;
        }

        $externalUniqueid = $bridgeData['externalUniqueid'] ?? null;
        $originalExternalUniqueid = $externalUniqueid;
        $externalChannel = $bridgeData['externalChannel'] ?? null;
        $internalUniqueid = $bridgeData['internalUniqueid'] ?? null;
        $internalChannel = $bridgeData['internalChannel'] ?? null;
        $internalCallerID = $bridgeData['internalCallerID'] ?? '';

        if (!$externalUniqueid) {
            return;
        }

        $newIntNum = callme_extract_internal_number($internalCallerID, $internalChannel);
        if (!$newIntNum) {
            $newIntNum = substr(preg_replace('/\D+/', '', (string)$internalCallerID), 0, 4);
        }
        if (!$newIntNum) {
            return;
        }

        $callId = $globalsObj->calls[$externalUniqueid] ?? null;
        $externalLinkedid = $globalsObj->uniqueidToLinkedid[$externalUniqueid] ?? null;
        $internalLinkedid = $internalUniqueid ? ($globalsObj->uniqueidToLinkedid[$internalUniqueid] ?? null) : null;
        $linkedid = $internalLinkedid ?? $externalLinkedid;

        if (!$callId && $linkedid && isset($globalsObj->callIdByLinkedid[$linkedid])) {
            $callId = $globalsObj->callIdByLinkedid[$linkedid];
        }
        if (!$callId && $linkedid && isset($globalsObj->calls[$linkedid])) {
            $callId = $globalsObj->calls[$linkedid];
        }
        if (!$callId && $linkedid && isset($globalsObj->callsByCallId)) {
            foreach ($globalsObj->callsByCallId as $storedCallId => $storedLinkedid) {
                if ($storedLinkedid === $linkedid) {
                    $callId = $storedCallId;
                    break;
                }
            }
        }
        if (!$callId && $externalLinkedid && isset($globalsObj->callIdByLinkedid[$externalLinkedid])) {
            $callId = $globalsObj->callIdByLinkedid[$externalLinkedid];
            if (!$linkedid) {
                $linkedid = $externalLinkedid;
            }
        }
        if (!$callId && !empty($globalsObj->transferHistory)) {
            $externalChannelBase = callme_channel_base($externalChannel);
            foreach ($globalsObj->transferHistory as $historyUniqueid => $transferData) {
                if (!is_array($transferData)) {
                    continue;
                }
                if (empty($transferData['call_id'])) {
                    continue;
                }
                $historyChannelBase = callme_channel_base($transferData['externalChannel'] ?? '');
                if ($externalChannelBase === '' || $externalChannelBase !== $historyChannelBase) {
                    continue;
                }

                $callId = $transferData['call_id'];
                $historyLinkedid = $transferData['linkedid'] ?? null;
                if ($historyLinkedid) {
                    $linkedid = $historyLinkedid;
                }
                $externalUniqueid = $historyUniqueid;
                break;
            }
        }
        if (!$callId) {
            return;
        }

        if (!$linkedid) {
            $linkedid = $globalsObj->callsByCallId[$callId] ?? ($internalLinkedid ?? $externalUniqueid);
        }

        $globalsObj->callIdByLinkedid[$linkedid] = $callId;
        $globalsObj->callsByCallId[$callId] = $linkedid;
        $globalsObj->calls[$linkedid] = $callId;
        $globalsObj->calls[$externalUniqueid] = $callId;
        if ($internalUniqueid) {
            $globalsObj->calls[$internalUniqueid] = $callId;
            $globalsObj->uniqueidToLinkedid[$internalUniqueid] = $linkedid;
        }
        $globalsObj->uniqueidToLinkedid[$externalUniqueid] = $linkedid;
        if ($originalExternalUniqueid && $originalExternalUniqueid !== $externalUniqueid) {
            $globalsObj->calls[$originalExternalUniqueid] = $callId;
            $globalsObj->uniqueidToLinkedid[$originalExternalUniqueid] = $linkedid;
        }

        if (!isset($globalsObj->transferHistory[$externalUniqueid])) {
            $globalsObj->transferHistory[$externalUniqueid] = array(
                'call_id' => $callId,
                'externalChannel' => $externalChannel,
                'currentIntNum' => $newIntNum,
                'linkedid' => $linkedid,
                'history' => array(),
            );
            $globalsObj->intNums[$externalUniqueid] = $newIntNum;
            $answerTimestamp = time();
            $globalsObj->transferHistory[$externalUniqueid]['answer_timestamp'] = $answerTimestamp;
            $globalsObj->Dispositions[$externalUniqueid] = 'ANSWER';
            if (empty($globalsObj->Answers[$externalUniqueid])) {
                $globalsObj->Answers[$externalUniqueid] = $answerTimestamp;
            }
            if ($linkedid) {
                $globalsObj->callCrmData[$linkedid]['answer_int_num'] = $newIntNum;
            }

            callme_transfer_move_card($linkedid, $callId, null, $newIntNum, $helper, $globalsObj, array(
                'event' => 'BridgeEvent',
                'type' => 'initial_connection',
                'externalUniqueid' => $externalUniqueid,
                'externalChannel' => $externalChannel,
                'internalUniqueid' => $internalUniqueid,
            ));

            return;
        }

        $oldIntNum = $globalsObj->transferHistory[$externalUniqueid]['currentIntNum'] ?? null;
        $globalsObj->transferHistory[$externalUniqueid]['externalChannel'] = $externalChannel;
        $globalsObj->transferHistory[$externalUniqueid]['linkedid'] = $linkedid;
        if (empty($globalsObj->transferHistory[$externalUniqueid]['answer_timestamp'])) {
            $globalsObj->transferHistory[$externalUniqueid]['answer_timestamp'] = time();
        }
        if ($oldIntNum === $newIntNum) {
            return;
        }

        callme_transfer_move_card($linkedid, $callId, $oldIntNum, $newIntNum, $helper, $globalsObj, array(
            'event' => 'BridgeEvent',
            'type' => 'transfer',
            'externalUniqueid' => $externalUniqueid,
            'externalChannel' => $externalChannel,
            'internalUniqueid' => $internalUniqueid,
        ));

        $globalsObj->transferHistory[$externalUniqueid]['currentIntNum'] = $newIntNum;
        $globalsObj->transferHistory[$externalUniqueid]['history'][] = array(
            'from' => $oldIntNum,
            'to' => $newIntNum,
            'timestamp' => time(),
        );
        $globalsObj->intNums[$externalUniqueid] = $newIntNum;
        $globalsObj->Dispositions[$externalUniqueid] = 'ANSWER';
        if (empty($globalsObj->Answers[$externalUniqueid]) && !empty($globalsObj->transferHistory[$externalUniqueid]['answer_timestamp'])) {
            $globalsObj->Answers[$externalUniqueid] = $globalsObj->transferHistory[$externalUniqueid]['answer_timestamp'];
        }
    },
    function (EventMessage $event) {
        return $event instanceof BridgeEvent && $event->getBridgeState() === 'Link';
    }
);

$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper, $globalsObj) {
        $agentUniqueId = (string) ($event->getKey('Uniqueid') ?? '');
        if ($agentUniqueId === '') {
            return;
        }
        $linkedid = $globalsObj->uniqueidToLinkedid[$agentUniqueId] ?? null;
        $intNum = $globalsObj->intNums[$agentUniqueId] ?? null;
        if (!$linkedid || !$intNum) {
            return;
        }
        $ringEntry = $globalsObj->ringingIntNums[$linkedid][$intNum] ?? null;
        $ringState = strtoupper((string)($ringEntry['state'] ?? ''));
        $channel = (string)($event->getChannel() ?? '');
        $cause = (string)($event->getKey('Cause') ?? '');
        
        // Фильтруем технические Hangup для Local каналов в очередях
        // В Asterisk 1.8 при звонках в очереди могут быть технические Hangup для Local каналов,
        // которые не означают реальное завершение звонка
        if (strpos($channel, 'Local/') === 0) {
            // Local каналы в очередях - технические Hangup, игнорируем
            if (strpos($channel, 'from-queue') !== false || strpos($channel, 'from-ringgroups') !== false) {
                $helper->writeToLog(array(
                    'linkedid' => $linkedid,
                    'intNum' => $intNum,
                    'agent_uniqueid' => $agentUniqueId,
                    'channel' => $channel,
                    'cause' => $cause,
                    'reason' => 'technical_queue_local_hangup',
                ), 'Skip cleanup: Technical queue Local channel hangup');
                return;
            }
        }
        
        // Проверяем, является ли это Hangup канала Transfer
        // Если канал Transfer от другого номера (например, SIP/219-00000638 при intNum=220),
        // то это Hangup канала Transfer, и не нужно закрывать карточку у получателя
        $channelIntNum = callme_extract_internal_number($channel);
        if ($channelIntNum && $channelIntNum !== $intNum) {
            // Канал принадлежит другому номеру - это канал Transfer
            // Проверяем, является ли intNum получателем Transfer
            if (!empty($globalsObj->transferHistory)) {
                $callId = callme_resolve_call_id($linkedid, $intNum, $globalsObj, $helper);
                foreach ($globalsObj->transferHistory as $transferData) {
                    if (!is_array($transferData)) {
                        continue;
                    }
                    $currentIntNum = (string)($transferData['currentIntNum'] ?? '');
                    $transferLinkedid = (string)($transferData['linkedid'] ?? '');
                    $transferCallId = (string)($transferData['call_id'] ?? '');
                    
                    // Если это получатель Transfer (совпадает intNum), не закрываем карточку
                    if ($currentIntNum === $intNum) {
                        $linkedidMatches = ($transferLinkedid === $linkedid || $linkedid === '');
                        $callIdMatches = ($callId && $transferCallId && $transferCallId === $callId);
                        
                        if ($linkedidMatches || $callIdMatches) {
                            $helper->writeToLog(array(
                                'linkedid' => $linkedid,
                                'intNum' => $intNum,
                                'channelIntNum' => $channelIntNum,
                                'agent_uniqueid' => $agentUniqueId,
                                'ringState' => $ringState,
                                'transferLinkedid' => $transferLinkedid,
                                'callId' => $callId,
                                'transferCallId' => $transferCallId,
                                'channel' => $channel,
                                'reason' => 'transfer_channel_hangup',
                            ), 'Skip cleanup: Transfer channel hangup, recipient active');
                            return;
                        }
                    }
                }
            }
        }
        
        // Проверяем, является ли получатель Transfer текущим получателем в transferHistory
        if (!empty($globalsObj->transferHistory)) {
            $callId = callme_resolve_call_id($linkedid, $intNum, $globalsObj, $helper);
            foreach ($globalsObj->transferHistory as $transferData) {
                if (!is_array($transferData)) {
                    continue;
                }
                $currentIntNum = (string)($transferData['currentIntNum'] ?? '');
                $transferLinkedid = (string)($transferData['linkedid'] ?? '');
                $transferCallId = (string)($transferData['call_id'] ?? '');
                
                // Если это получатель Transfer (совпадает intNum), не закрываем карточку
                $isTransferRecipient = ($currentIntNum === $intNum);
                $linkedidMatches = ($transferLinkedid === $linkedid || $linkedid === '');
                $callIdMatches = ($callId && $transferCallId && $transferCallId === $callId);
                
                if ($isTransferRecipient && ($linkedidMatches || $callIdMatches)) {
                    $helper->writeToLog(array(
                        'linkedid' => $linkedid,
                        'intNum' => $intNum,
                        'agent_uniqueid' => $agentUniqueId,
                        'ringState' => $ringState,
                        'transferLinkedid' => $transferLinkedid,
                        'callId' => $callId,
                        'transferCallId' => $transferCallId,
                        'channel' => $channel,
                        'reason' => 'transfer_recipient_active',
                    ), 'Skip cleanup: Transfer recipient is active');
                    return;
                }
            }
        }
        
        // Не закрываем карточку, если звонок уже ответил
        if (!$ringEntry || $ringState === 'ANSWER') {
            return;
        }

        callme_force_ring_entry_cleanup($linkedid, $intNum, $helper, $globalsObj, array(
            'event' => 'HangupEvent',
            'reason' => 'agent_leg_hangup',
            'agent_uniqueid' => $agentUniqueId,
            'channel' => $channel,
            'cause' => $cause,
            'cause_txt' => $event->getKey('Cause-txt'),
        ));
    },
    function (EventMessage $event) use ($globalsObj) {
        if (!($event instanceof HangupEvent)) {
            return false;
        }
        $uniqueid = (string) ($event->getKey('Uniqueid') ?? '');
        if ($uniqueid === '') {
            return false;
        }
        
        // Фильтруем технические Hangup для Local каналов в очередях
        // В Asterisk 1.8 при звонках в очереди могут быть технические Hangup для Local каналов,
        // которые не означают реальное завершение звонка
        $channel = (string)($event->getChannel() ?? '');
        if (strpos($channel, 'Local/') === 0) {
            // Local каналы в очередях - технические Hangup, игнорируем
            if (strpos($channel, 'from-queue') !== false || strpos($channel, 'from-ringgroups') !== false) {
                return false;
            }
        }
        
        if (!isset($globalsObj->intNums[$uniqueid])) {
            return false;
        }
        $linkedid = $globalsObj->uniqueidToLinkedid[$uniqueid] ?? null;
        $intNum = $globalsObj->intNums[$uniqueid] ?? null;
        if (!$linkedid || !$intNum) {
            return false;
        }
        return isset($globalsObj->ringingIntNums[$linkedid][$intNum]);
    }
);

/**
 * Обработка пользовательского события начала дозвона по внутреннему номеру.
 *
 * @param EventMessage $event
 * @param HelperFuncs $helper
 * @param Globals $globalsObj
 * @return void
 */
function callme_handle_user_event_ringing_start(EventMessage $event, HelperFuncs $helper, Globals $globalsObj)
{
    $linkedid = (string) ($event->getKey('Linkedid') ?? $event->getKey('LinkedID') ?? '');
    $linkedid = trim($linkedid);
    
    $intNum = trim((string) ($event->getKey('Target') ?? ''));
    if ($intNum === '') {
        return;
    }

    $agentUniqueId = (string) ($event->getKey('AgentUniqueid') ?? '');
    $direction = (string) ($event->getKey('Direction') ?? 'inbound');
    $routeTypeRaw = (string) ($event->getKey('RouteType') ?? '');
    $routeType = callme_normalize_route_type($routeTypeRaw);
    
    // Fallback: если Linkedid пустой, пытаемся найти его по AgentUniqueid, call_id или intNum
    if ($linkedid === '') {
        $eventCallId = trim((string) ($event->getKey('CallId') ?? $event->getKey('CALLID') ?? ''));
        $resolvedBy = 'unknown';
        
        // Пробуем найти linkedid по AgentUniqueid
        if ($agentUniqueId !== '') {
            $linkedid = $globalsObj->uniqueidToLinkedid[$agentUniqueId] ?? null;
            if ($linkedid) {
                $resolvedBy = 'agentUniqueid';
            }
        }
        
        // Если не нашли, пробуем найти по call_id из события
        if (!$linkedid && $eventCallId !== '') {
            $linkedid = $globalsObj->callsByCallId[$eventCallId] ?? null;
            if ($linkedid) {
                $resolvedBy = 'eventCallId';
            }
        }
        
        // Если не нашли, пробуем найти по intNum через callIdByInt
        if (!$linkedid && $intNum !== '') {
            $callId = $globalsObj->callIdByInt[$intNum] ?? null;
            if ($callId) {
                $linkedid = $globalsObj->callsByCallId[$callId] ?? null;
                if ($linkedid) {
                    $resolvedBy = 'intNum_callId';
                }
            }
        }
        
        // Если все еще не нашли, пробуем найти по всем callIdByLinkedid, которые связаны с intNum
        if (!$linkedid && $intNum !== '') {
            $callId = $globalsObj->callIdByInt[$intNum] ?? null;
            if ($callId) {
                // Ищем linkedid, который имеет этот call_id
                foreach ($globalsObj->callIdByLinkedid as $candidateLinkedid => $candidateCallId) {
                    if ($candidateCallId === $callId) {
                        $linkedid = $candidateLinkedid;
                        $resolvedBy = 'callIdByLinkedid';
                        break;
                    }
                }
            }
        }
        
        // Если все еще не нашли, пробуем найти по всем linkedid, которые имеют звонки для этого intNum
        if (!$linkedid && $intNum !== '') {
            foreach ($globalsObj->ringingIntNums as $candidateLinkedid => $ringingData) {
                if (isset($ringingData[$intNum])) {
                    $linkedid = $candidateLinkedid;
                    $resolvedBy = 'ringingIntNums';
                    break;
                }
            }
        }
        
        // Если все еще не нашли, пробуем найти по всем calls, которые имеют call_id для этого intNum
        if (!$linkedid && $intNum !== '') {
            $callId = $globalsObj->callIdByInt[$intNum] ?? null;
            if ($callId) {
                // Ищем linkedid в calls, который имеет этот call_id
                foreach ($globalsObj->calls as $candidateUniqueid => $candidateCallId) {
                    if ($candidateCallId === $callId) {
                        $linkedid = $globalsObj->uniqueidToLinkedid[$candidateUniqueid] ?? $candidateUniqueid;
                        if ($linkedid) {
                            $resolvedBy = 'calls_uniqueid';
                            break;
                        }
                    }
                }
            }
        }
        
        // Если все еще не нашли, пробуем найти по всем callIdByLinkedid (последний fallback)
        if (!$linkedid && $intNum !== '') {
            $callId = $globalsObj->callIdByInt[$intNum] ?? null;
            if ($callId) {
                // Ищем linkedid, который имеет этот call_id в callIdByLinkedid
                foreach ($globalsObj->callIdByLinkedid as $candidateLinkedid => $candidateCallId) {
                    if ($candidateCallId === $callId) {
                        $linkedid = $candidateLinkedid;
                        $resolvedBy = 'callIdByLinkedid_fallback';
                        break;
                    }
                }
            }
        }
        
        // Если все еще не нашли, пробуем найти по всем callIdByLinkedid напрямую (для случаев, когда callIdByInt еще не установлен)
        // Это важно для звонков в очередь, когда UserEvent приходит до установки callIdByInt
        if (!$linkedid && $intNum !== '') {
            // Ищем linkedid, который имеет call_id, связанный с этим intNum через другие механизмы
            // Пробуем найти через callsByCallId, если есть call_id для intNum в других местах
            foreach ($globalsObj->callIdByLinkedid as $candidateLinkedid => $candidateCallId) {
                // Проверяем, есть ли этот linkedid в ringingIntNums для этого intNum
                if (isset($globalsObj->ringingIntNums[$candidateLinkedid][$intNum])) {
                    $linkedid = $candidateLinkedid;
                    $resolvedBy = 'callIdByLinkedid_ringingIntNums';
                    break;
                }
            }
        }
        
        // Если все еще не нашли, пробуем найти по всем callsByCallId, если есть call_id
        // Это последний fallback - ищем linkedid по любому call_id, который может быть связан с intNum
        if (!$linkedid && $intNum !== '') {
            // Пробуем найти через все callIdByLinkedid, если есть хотя бы один call_id
            if (!empty($globalsObj->callIdByLinkedid)) {
                // Берем первый linkedid, который имеет call_id (для multi звонков это может быть основной linkedid)
                // Это рискованный fallback, но лучше показать карточку, чем не показать
                $firstLinkedid = array_key_first($globalsObj->callIdByLinkedid);
                if ($firstLinkedid && isset($globalsObj->ringingIntNums[$firstLinkedid])) {
                    // Проверяем, есть ли этот intNum в ringingIntNums для этого linkedid
                    if (isset($globalsObj->ringingIntNums[$firstLinkedid][$intNum])) {
                        $linkedid = $firstLinkedid;
                        $resolvedBy = 'callIdByLinkedid_first_match';
                    }
                }
            }
        }
        
        if ($linkedid === '' || $linkedid === null) {
            $helper->writeToLog(array(
                'event' => 'CallMeRingingStart',
                'intNum' => $intNum,
                'agentUniqueid' => $agentUniqueId,
                'callId' => $eventCallId !== '' ? $eventCallId : null,
                'callIdByInt' => $globalsObj->callIdByInt[$intNum] ?? null,
                'message' => 'Linkedid is empty and cannot be resolved',
            ), 'CallMeRingingStart: Linkedid cannot be resolved');
            return;
        }
        
        $helper->writeToLog(array(
            'event' => 'CallMeRingingStart',
            'intNum' => $intNum,
            'agentUniqueid' => $agentUniqueId,
            'resolvedLinkedid' => $linkedid,
            'resolvedBy' => $resolvedBy,
            'callId' => $eventCallId !== '' ? $eventCallId : ($globalsObj->callIdByInt[$intNum] ?? null),
        ), 'CallMeRingingStart: Linkedid resolved from empty');
    }
    if ($routeTypeRaw !== '') {
        $globalsObj->callRouteTypes[$linkedid] = $routeType;
    } elseif (isset($globalsObj->callRouteTypes[$linkedid])) {
        $routeType = callme_normalize_route_type($globalsObj->callRouteTypes[$linkedid]);
    }

    if (!isset($globalsObj->ringingIntNums[$linkedid])) {
        $globalsObj->ringingIntNums[$linkedid] = array();
    }

    $ringEntry = $globalsObj->ringingIntNums[$linkedid][$intNum] ?? array();
    if (empty($ringEntry['user_id'])) {
        $ringEntry['user_id'] = $helper->getUSER_IDByIntNum($intNum);
    }
    $ringEntry['agent_uniqueid'] = $agentUniqueId ?: ($ringEntry['agent_uniqueid'] ?? null);
    $ringEntry['direction'] = $direction ?: ($ringEntry['direction'] ?? 'inbound');
    $ringEntry['route_type'] = $routeType;
    if ($routeTypeRaw !== '') {
        $ringEntry['route_type_raw'] = $routeTypeRaw;
    }
    $ringEntry['state'] = 'RING';
    $ringEntry['timestamp'] = time();
    $ringEntry['shown'] = callme_is_card_marked_shown($linkedid, $intNum, $globalsObj);

    $globalsObj->ringingIntNums[$linkedid][$intNum] = $ringEntry;

    if (!isset($globalsObj->ringOrder[$linkedid])) {
        $globalsObj->ringOrder[$linkedid] = array();
    }
    if (!in_array($intNum, $globalsObj->ringOrder[$linkedid], true)) {
        $globalsObj->ringOrder[$linkedid][] = $intNum;
    }

    if ($agentUniqueId !== '') {
        $globalsObj->uniqueidToLinkedid[$agentUniqueId] = $linkedid;
        $globalsObj->intNums[$agentUniqueId] = $intNum;
    }

    $eventCallId = trim((string) ($event->getKey('CallId') ?? $event->getKey('CALLID') ?? ''));
    $callId = $eventCallId !== '' ? $eventCallId : null;
    if (!$callId) {
        $callId = $globalsObj->callIdByLinkedid[$linkedid] ?? ($globalsObj->callIdByInt[$intNum] ?? null);
    }
    if (!$callId && $agentUniqueId !== '' && isset($globalsObj->calls[$agentUniqueId])) {
        $callId = $globalsObj->calls[$agentUniqueId];
    }
    if (!$callId) {
        $callId = $helper->findCallIdByIntNum($intNum, $globalsObj);
    }
    if (!$callId && isset($globalsObj->calls[$linkedid])) {
        $callId = $globalsObj->calls[$linkedid];
    }
    
    // КРИТИЧНО: Для звонков в очередь linkedid из UserEvent (оригинальный) может не совпадать
    // с linkedid, для которого зарегистрирован call_id (uniqueid основного канала).
    // Ищем call_id по всем связанным linkedid через AgentUniqueid или базовый linkedid.
    if (!$callId && $agentUniqueId !== '') {
        // Ищем linkedid, связанный с AgentUniqueid
        $agentLinkedid = $globalsObj->uniqueidToLinkedid[$agentUniqueId] ?? null;
        if ($agentLinkedid && $agentLinkedid !== $linkedid) {
            // Пробуем найти call_id по linkedid агента
            $callId = $globalsObj->callIdByLinkedid[$agentLinkedid] ?? ($globalsObj->calls[$agentLinkedid] ?? null);
            if ($callId) {
                // Создаем маппинг для оригинального linkedid
                $globalsObj->callIdByLinkedid[$linkedid] = $callId;
                $globalsObj->callsByCallId[$callId] = $linkedid;
            }
        }
    }
    
    // Если все еще не нашли, ищем по базовому linkedid (без суффикса .X)
    if (!$callId && $linkedid !== '') {
        $linkedidBase = explode('.', (string)$linkedid)[0] ?? '';
        if ($linkedidBase !== '' && $linkedidBase !== $linkedid) {
            // Ищем call_id по базовому linkedid
            $callId = $globalsObj->callIdByLinkedid[$linkedidBase] ?? ($globalsObj->calls[$linkedidBase] ?? null);
            if ($callId) {
                // Создаем маппинг для оригинального linkedid
                $globalsObj->callIdByLinkedid[$linkedid] = $callId;
                $globalsObj->callsByCallId[$callId] = $linkedid;
            }
        }
    }
    
    // Если все еще не нашли, ищем по всем linkedid в callIdByLinkedid, которые могут быть связаны
    // с этим звонком через intNum или через другие механизмы
    if (!$callId && $intNum !== '') {
        // Ищем call_id по всем linkedid, которые имеют этот intNum в ringingIntNums
        foreach ($globalsObj->ringingIntNums as $candidateLinkedid => $ringingData) {
            if (isset($ringingData[$intNum])) {
                $candidateCallId = $globalsObj->callIdByLinkedid[$candidateLinkedid] ?? null;
                if ($candidateCallId) {
                    $callId = $candidateCallId;
                    // Создаем маппинг для оригинального linkedid
                    $globalsObj->callIdByLinkedid[$linkedid] = $callId;
                    $globalsObj->callsByCallId[$callId] = $linkedid;
                    break;
                }
            }
        }
    }
    
    if (!$callId && !empty($globalsObj->transferHistory)) {
        foreach ($globalsObj->transferHistory as $transferData) {
            if (!is_array($transferData)) {
                continue;
            }
            if (($transferData['currentIntNum'] ?? null) !== $intNum) {
                continue;
            }
            if (empty($transferData['call_id'])) {
                continue;
            }

            $callId = $transferData['call_id'];
            $historyLinkedid = $transferData['linkedid'] ?? null;
            if ($historyLinkedid && $historyLinkedid !== $linkedid) {
                $globalsObj->callIdByLinkedid[$historyLinkedid] = $callId;
                $globalsObj->callsByCallId[$callId] = $historyLinkedid;
            }
            break;
        }
    }
    if ($callId) {
        $globalsObj->callIdByLinkedid[$linkedid] = $callId;
        $globalsObj->callIdByInt[$intNum] = $callId;
        if ($agentUniqueId !== '') {
            $globalsObj->calls[$agentUniqueId] = $callId;
        }
        $globalsObj->callsByCallId[$callId] = $linkedid;
    } else {
        $helper->writeToLog(array(
            'event' => 'CallMeRingingStart',
            'linkedid' => $linkedid,
            'intNum' => $intNum,
            'agentUniqueid' => $agentUniqueId,
            'message' => 'CALL_ID not resolved, fallback cleanup may trigger',
        ), 'CallMeRingingStart unresolved CALL_ID');
    }

    $helper->writeToLog(array(
        'event' => 'CallMeRingingStart',
        'linkedid' => $linkedid,
        'intNum' => $intNum,
        'agentUniqueid' => $agentUniqueId,
        'direction' => $direction,
        'call_id' => $callId,
        'route_type' => $routeType,
        'ringOrder' => $globalsObj->ringOrder[$linkedid],
        'alreadyShown' => $ringEntry['shown'],
    ), 'UserEvent CallMeRingingStart');

    callme_show_cards_for_ringing($linkedid, $helper, $globalsObj);
}

/**
 * Обработка пользовательского события об ответе внутреннего номера.
 *
 * @param EventMessage $event
 * @param HelperFuncs $helper
 * @param Globals $globalsObj
 * @return void
 */
function callme_handle_user_event_ringing_answer(EventMessage $event, HelperFuncs $helper, Globals $globalsObj)
{
    $linkedid = (string) ($event->getKey('Linkedid') ?? $event->getKey('LinkedID') ?? '');
    $linkedid = trim($linkedid);
    if ($linkedid === '') {
        return;
    }

    $intNum = trim((string) ($event->getKey('Target') ?? ''));
    if ($intNum === '') {
        return;
    }

    $agentUniqueId = (string) ($event->getKey('AgentUniqueid') ?? '');
    $direction = (string) ($event->getKey('Direction') ?? 'inbound');
    $routeTypeRaw = (string) ($event->getKey('RouteType') ?? '');
    $routeType = callme_normalize_route_type($routeTypeRaw);
    if ($routeTypeRaw !== '') {
        $globalsObj->callRouteTypes[$linkedid] = $routeType;
    } elseif (isset($globalsObj->callRouteTypes[$linkedid])) {
        $routeType = callme_normalize_route_type($globalsObj->callRouteTypes[$linkedid]);
    }

    if (!isset($globalsObj->ringingIntNums[$linkedid])) {
        $globalsObj->ringingIntNums[$linkedid] = array();
    }
    if (!isset($globalsObj->ringingIntNums[$linkedid][$intNum])) {
        $globalsObj->ringingIntNums[$linkedid][$intNum] = array(
            'shown' => callme_is_card_marked_shown($linkedid, $intNum, $globalsObj),
        );
    }

    $entry =& $globalsObj->ringingIntNums[$linkedid][$intNum];
    if (empty($entry['user_id'])) {
        $entry['user_id'] = $helper->getUSER_IDByIntNum($intNum);
    }
    $entry['agent_uniqueid'] = $agentUniqueId ?: ($entry['agent_uniqueid'] ?? null);
    $entry['direction'] = $direction ?: ($entry['direction'] ?? 'inbound');
    $entry['route_type'] = $routeType;
    if ($routeTypeRaw !== '') {
        $entry['route_type_raw'] = $routeTypeRaw;
    }
    $entry['state'] = 'ANSWER';
    $entry['timestamp'] = time();
    $entry['answered'] = true;

    if ($agentUniqueId !== '') {
        $globalsObj->uniqueidToLinkedid[$agentUniqueId] = $linkedid;
        $globalsObj->intNums[$agentUniqueId] = $intNum;
    }

    $eventCallId = trim((string) ($event->getKey('CallId') ?? $event->getKey('CALLID') ?? ''));
    $callId = $eventCallId !== '' ? $eventCallId : null;
    if (!$callId) {
        $callId = $globalsObj->callIdByLinkedid[$linkedid] ?? ($globalsObj->callIdByInt[$intNum] ?? null);
    }
    if (!$callId && isset($globalsObj->calls[$linkedid])) {
        $callId = $globalsObj->calls[$linkedid];
    }
    if (!$callId && isset($globalsObj->calls[$agentUniqueId])) {
        $callId = $globalsObj->calls[$agentUniqueId];
    }
    if (!$callId) {
        $callId = $helper->findCallIdByIntNum($intNum, $globalsObj);
    }
    if (!$callId && !empty($globalsObj->transferHistory)) {
        foreach ($globalsObj->transferHistory as $transferData) {
            if (!is_array($transferData)) {
                continue;
            }
            if (($transferData['currentIntNum'] ?? null) !== $intNum) {
                continue;
            }
            if (empty($transferData['call_id'])) {
                continue;
            }

            $callId = $transferData['call_id'];
            $historyLinkedid = $transferData['linkedid'] ?? null;
            if ($historyLinkedid && $historyLinkedid !== $linkedid) {
                $globalsObj->callIdByLinkedid[$historyLinkedid] = $callId;
                $globalsObj->callsByCallId[$callId] = $historyLinkedid;
            }
            break;
        }
    }
    if ($callId) {
        $globalsObj->callIdByLinkedid[$linkedid] = $callId;
        $globalsObj->callIdByInt[$intNum] = $callId;
        if ($agentUniqueId !== '') {
            $globalsObj->calls[$agentUniqueId] = $callId;
        }
        $globalsObj->callsByCallId[$callId] = $linkedid;
    }

    if (!$callId && $helper->isDebugEnabled()) {
        $helper->writeToLog(array(
            'linkedid' => $linkedid,
            'intNum' => $intNum,
            'agentUniqueid' => $agentUniqueId,
            'ringingEntry' => $entry,
            'knownByLinkedid' => isset($globalsObj->callIdByLinkedid[$linkedid]),
            'knownByInt' => isset($globalsObj->callIdByInt[$intNum]),
        ), 'CallMeRingingAnswer unresolved CALL_ID');
    }

    $helper->writeToLog(array(
        'event' => 'CallMeRingingAnswer',
        'linkedid' => $linkedid,
        'intNum' => $intNum,
        'agentUniqueid' => $agentUniqueId,
        'direction' => $direction,
        'call_id' => $callId,
        'route_type' => $routeType,
    ), 'UserEvent CallMeRingingAnswer');

    if ($callId) {
        callme_hide_cards_batch($linkedid, $callId, $helper, $globalsObj, $intNum);
        if (!isset($globalsObj->callShownCards[$linkedid])) {
            $globalsObj->callShownCards[$linkedid] = array();
        }
        $currentUserId = $entry['user_id'] ?? $helper->getUSER_IDByIntNum($intNum);
        $globalsObj->callShownCards[$linkedid][$intNum] = array(
            'user_id' => $currentUserId ? (int)$currentUserId : null,
            'int_num' => (string)$intNum,
            'shown' => true,
            'shown_at' => time(),
        );
    }

    if (isset($globalsObj->ringingIntNums[$linkedid])) {
        foreach (array_keys($globalsObj->ringingIntNums[$linkedid]) as $otherInt) {
            if ($otherInt !== $intNum) {
                unset($globalsObj->ringingIntNums[$linkedid][$otherInt]);
            }
        }
    }
    if (isset($globalsObj->ringOrder[$linkedid])) {
        $globalsObj->ringOrder[$linkedid] = array_values(array_filter(
            $globalsObj->ringOrder[$linkedid],
            function ($value) use ($intNum) {
                return (string) $value === $intNum;
            }
        ));
        if (empty($globalsObj->ringOrder[$linkedid])) {
            unset($globalsObj->ringOrder[$linkedid]);
        }
    }
}

/**
 * Обработка пользовательского события завершения дозвона для внутреннего номера.
 *
 * @param EventMessage $event
 * @param HelperFuncs $helper
 * @param Globals $globalsObj
 * @return void
 */
function callme_handle_user_event_ringing_stop(EventMessage $event, HelperFuncs $helper, Globals $globalsObj)
{
    $linkedid = (string) ($event->getKey('Linkedid') ?? $event->getKey('LinkedID') ?? '');
    $linkedid = trim($linkedid);
    if ($linkedid === '') {
        return;
    }

    $intNum = trim((string) ($event->getKey('Target') ?? ''));
    if ($intNum === '') {
        return;
    }

    $dialStatusRaw = (string) ($event->getKey('DialStatus') ?? '');
    $dialStatus = strtoupper(trim($dialStatusRaw));
    $cleanupOnStop = !in_array($dialStatus, array('ANSWER', 'ANSWERED'), true);
    $routeTypeRaw = (string) ($event->getKey('RouteType') ?? '');
    $routeType = callme_normalize_route_type($routeTypeRaw);
    if ($routeTypeRaw !== '') {
        $globalsObj->callRouteTypes[$linkedid] = $routeType;
    } elseif (isset($globalsObj->callRouteTypes[$linkedid])) {
        $routeType = callme_normalize_route_type($globalsObj->callRouteTypes[$linkedid]);
    }

    $eventCallId = trim((string) ($event->getKey('CallId') ?? $event->getKey('CALLID') ?? ''));
    $callId = $eventCallId !== '' ? $eventCallId : null;
    if (!$callId) {
        $callId = $globalsObj->callIdByLinkedid[$linkedid] ?? null;
    }
    if (!$callId && isset($globalsObj->callIdByInt[$intNum])) {
        $callId = $globalsObj->callIdByInt[$intNum];
    }
    if (!$callId && isset($globalsObj->calls[$linkedid])) {
        $callId = $globalsObj->calls[$linkedid];
    }
    if (!$callId && isset($globalsObj->calls[$intNum])) {
        $callId = $globalsObj->calls[$intNum];
    }
    if (!$callId) {
        $callId = $helper->findCallIdByIntNum($intNum, $globalsObj);
    }
    if (!$callId && !empty($globalsObj->transferHistory)) {
        foreach ($globalsObj->transferHistory as $transferData) {
            if (!is_array($transferData)) {
                continue;
            }
            if (($transferData['currentIntNum'] ?? null) !== $intNum) {
                continue;
            }
            if (empty($transferData['call_id'])) {
                continue;
            }

            $callId = $transferData['call_id'];
            $historyLinkedid = $transferData['linkedid'] ?? null;
            if ($historyLinkedid && $historyLinkedid !== $linkedid) {
                $globalsObj->callIdByLinkedid[$historyLinkedid] = $callId;
                $globalsObj->callsByCallId[$callId] = $historyLinkedid;
            }
            break;
        }
    }

    if (!$callId && $helper->isDebugEnabled()) {
        $helper->writeToLog(array(
            'linkedid' => $linkedid,
            'intNum' => $intNum,
            'dialStatus' => $dialStatus,
            'cleanupOnStop' => $cleanupOnStop,
        ), 'CallMeRingingStop unresolved CALL_ID');
    }

    if ($callId && callme_is_card_marked_shown($linkedid, $intNum, $globalsObj) && $cleanupOnStop) {
        $helper->hideInputCall($intNum, $callId);
    }

    $agentUniqueId = (string) ($event->getKey('AgentUniqueid') ?? '');
    if ($cleanupOnStop) {
        if (isset($globalsObj->callShownCards[$linkedid][$intNum])) {
            unset($globalsObj->callShownCards[$linkedid][$intNum]);
            if (empty($globalsObj->callShownCards[$linkedid])) {
                unset($globalsObj->callShownCards[$linkedid]);
            }
        }

        if ($agentUniqueId !== '') {
            unset($globalsObj->uniqueidToLinkedid[$agentUniqueId]);
            unset($globalsObj->intNums[$agentUniqueId]);
        }

        if (isset($globalsObj->ringingIntNums[$linkedid][$intNum])) {
            unset($globalsObj->ringingIntNums[$linkedid][$intNum]);
            if (empty($globalsObj->ringingIntNums[$linkedid])) {
                unset($globalsObj->ringingIntNums[$linkedid]);
            }
        }

        if (isset($globalsObj->ringOrder[$linkedid])) {
            $globalsObj->ringOrder[$linkedid] = array_values(array_filter(
                $globalsObj->ringOrder[$linkedid],
                function ($value) use ($intNum) {
                    return (string) $value !== $intNum;
                }
            ));
            if (empty($globalsObj->ringOrder[$linkedid])) {
                unset($globalsObj->ringOrder[$linkedid]);
            }
        }

        if (isset($globalsObj->callIdByInt[$intNum])) {
            unset($globalsObj->callIdByInt[$intNum]);
        }
    } else {
        if (!isset($globalsObj->ringingIntNums[$linkedid])) {
            $globalsObj->ringingIntNums[$linkedid] = array();
        }
        if (!isset($globalsObj->ringingIntNums[$linkedid][$intNum])) {
            $globalsObj->ringingIntNums[$linkedid][$intNum] = array();
        }
        $globalsObj->ringingIntNums[$linkedid][$intNum]['state'] = 'STOP_DEFERRED';
        $globalsObj->ringingIntNums[$linkedid][$intNum]['timestamp'] = time();
        $globalsObj->ringingIntNums[$linkedid][$intNum]['route_type'] = $routeType;
    }

    callme_maybe_unset_route_type($linkedid, $globalsObj);

    if (isset($globalsObj->ringingIntNums[$linkedid][$intNum])) {
        unset($globalsObj->ringingIntNums[$linkedid][$intNum]['route_type_raw']);
    }

    $helper->writeToLog(array(
        'event' => 'CallMeRingingStop',
        'linkedid' => $linkedid,
        'intNum' => $intNum,
        'dialStatus' => $dialStatusRaw,
        'call_id' => $callId,
        'agentUniqueid' => $agentUniqueId,
        'route_type' => $routeType,
        'cleanupOnStop' => $cleanupOnStop,
    ), 'UserEvent CallMeRingingStop');
}

/**
 * Извлекает внутренний номер (2–6 цифр) из представления канала/строки дозвона.
 *
 * @param string ...$sources
 * @return string|null
 */
function callme_extract_internal_number(...$sources)
{
    $patterns = array(
        '/Local\/(\d+)(?=@)/',
        '/SIP\/(\d+)(?=[\-\/]|$)/',
        '/PJSIP\/(\d+)(?=[\-\/@]|$)/',
        '/^(\d{2,6})$/'
    );

    foreach ($sources as $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }

        $parts = preg_split('/[&,\s]+/', $value);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $part, $matches)) {
                    $number = $matches[1];
                    if (strlen($number) >= 2 && strlen($number) <= 6) {
                        return $number;
                    }
                }
            }
        }
    }

    return null;
}

/**
 * Пытается определить CALL_ID, связанный с указанным linkedid или внутренним номером.
 *
 * @param string|null $linkedid
 * @param string|null $intNum
 * @param Globals $globalsObj
 * @param HelperFuncs $helper
 * @return string|null
 */
function callme_resolve_call_id($linkedid, $intNum, Globals $globalsObj, HelperFuncs $helper)
{
    $candidateLinkedid = $linkedid !== null ? (string)$linkedid : '';
    $candidateInt = $intNum !== null ? (string)$intNum : '';

    if ($candidateLinkedid !== '' && isset($globalsObj->callIdByLinkedid[$candidateLinkedid])) {
        return $globalsObj->callIdByLinkedid[$candidateLinkedid];
    }

    if ($candidateInt !== '' && isset($globalsObj->callIdByInt[$candidateInt])) {
        return $globalsObj->callIdByInt[$candidateInt];
    }

    if (!empty($globalsObj->transferHistory) && is_array($globalsObj->transferHistory)) {
        foreach ($globalsObj->transferHistory as $transferData) {
            if (!is_array($transferData) || empty($transferData['call_id'])) {
                continue;
            }
            if ($candidateLinkedid !== '' && ($transferData['linkedid'] ?? null) === $candidateLinkedid) {
                return $transferData['call_id'];
            }
            if ($candidateInt !== '' && (string)($transferData['currentIntNum'] ?? '') === $candidateInt) {
                return $transferData['call_id'];
            }
        }
    }

    if ($candidateInt !== '') {
        $fallback = $helper->findCallIdByIntNum($candidateInt, $globalsObj);
        if ($fallback) {
            return $fallback;
        }
    }

    if ($candidateLinkedid !== '' && isset($globalsObj->calls[$candidateLinkedid])) {
        return $globalsObj->calls[$candidateLinkedid];
    }

    if ($helper->isDebugEnabled()) {
        $helper->writeToLog(array(
            'linkedid' => $candidateLinkedid,
            'intNum' => $candidateInt,
            'hasLinkedMap' => isset($globalsObj->callIdByLinkedid[$candidateLinkedid]),
            'hasIntMap' => isset($globalsObj->callIdByInt[$candidateInt]),
        ), 'callme_resolve_call_id unresolved');
    }

    return null;
}

/**
 * Универсальная функция поиска URL записи звонка.
 * Ищет URL по linkedid (приоритет) или uniqueid (fallback).
 *
 * @param string|null $uniqueid
 * @param string|null $linkedid
 * @param Globals $globalsObj
 * @param HelperFuncs $helper
 * @return string|null URL записи или null если не найден
 */
function callme_resolve_recording_url($uniqueid, $linkedid, Globals $globalsObj, HelperFuncs $helper)
{
    $candidateUniqueid = $uniqueid !== null ? (string)$uniqueid : '';
    $candidateLinkedid = $linkedid !== null ? (string)$linkedid : '';
    
    // Если linkedid не передан, пытаемся найти через маппинг
    if ($candidateLinkedid === '' && $candidateUniqueid !== '') {
        $candidateLinkedid = (string)($globalsObj->uniqueidToLinkedid[$candidateUniqueid] ?? '');
    }
    
    $foundUrl = null;
    $foundBy = null;
    
    // ПРИОРИТЕТ 1: Поиск по linkedid (основной способ)
    if ($candidateLinkedid !== '' && isset($globalsObj->FullFnameUrls[$candidateLinkedid])) {
        $foundUrl = $globalsObj->FullFnameUrls[$candidateLinkedid];
        $foundBy = 'linkedid';
    }
    
    // ПРИОРИТЕТ 2: Поиск по uniqueid (fallback для обратной совместимости)
    if ($foundUrl === null && $candidateUniqueid !== '' && isset($globalsObj->FullFnameUrls[$candidateUniqueid])) {
        $foundUrl = $globalsObj->FullFnameUrls[$candidateUniqueid];
        $foundBy = 'uniqueid';
    }
    
    // ПРИОРИТЕТ 3: Глубокий поиск - ищем по всем uniqueid, связанным с linkedid
    if ($foundUrl === null && $candidateLinkedid !== '') {
        foreach ($globalsObj->uniqueidToLinkedid as $uid => $mappedLinkedid) {
            if ($mappedLinkedid === $candidateLinkedid && isset($globalsObj->FullFnameUrls[$uid])) {
                $foundUrl = $globalsObj->FullFnameUrls[$uid];
                $foundBy = 'deep_search_uniqueid';
                break;
            }
        }
    }
    
    // Логирование результата поиска
    if ($foundUrl !== null) {
        $helper->writeToLog(array(
            'uniqueid' => $candidateUniqueid,
            'linkedid' => $candidateLinkedid,
            'found_by' => $foundBy,
            'url' => $foundUrl,
        ), 'Recording URL resolved');
    } else {
        $helper->writeToLog(array(
            'uniqueid' => $candidateUniqueid,
            'linkedid' => $candidateLinkedid,
            'has_linkedid_mapping' => $candidateLinkedid !== '' && isset($globalsObj->uniqueidToLinkedid[$candidateUniqueid]),
            'available_keys' => array_keys($globalsObj->FullFnameUrls ?? array()),
        ), 'Recording URL NOT found');
    }
    
    return $foundUrl;
}

/**
 * Общий обработчик начала дозвона по внутреннему номеру.
 *
 * @param EventMessage $event
 * @param string $callUniqueid
 * @param string|null $destUniqueId
 * @param string|null $linkedid
 * @param string $exten
 * @param string $rawDialString
 * @param string $callerNumberRaw
 * @param HelperFuncs $helper
 * @param Globals $globalsObj
 * @return void
 */
function callme_handle_dial_begin_common(
    EventMessage $event,
    $callUniqueid,
    $destUniqueId,
    $linkedid,
    $exten,
    $rawDialString,
    $callerNumberRaw,
    HelperFuncs $helper,
    Globals $globalsObj
) {
    $linkedidOriginal = $linkedid;
    if (empty($linkedidOriginal)) {
        $linkedidOriginal = $callUniqueid;
    }

    $mappedLinkedid = $globalsObj->uniqueidToLinkedid[$callUniqueid] ?? null;
    if (!empty($mappedLinkedid)) {
        $linkedid = $mappedLinkedid;
    } elseif (!empty($destUniqueId) && isset($globalsObj->uniqueidToLinkedid[$destUniqueId])) {
        $linkedid = $globalsObj->uniqueidToLinkedid[$destUniqueId];
    } else {
        $linkedid = $linkedidOriginal;
    }

    if (!isset($globalsObj->callIdByLinkedid[$linkedid]) && strpos((string)$linkedid, '.') !== false) {
        $linkedidBase = explode('.', (string)$linkedid)[0];
        foreach ($globalsObj->callIdByLinkedid as $knownLinkedid => $knownCallId) {
            $knownBase = explode('.', (string)$knownLinkedid)[0] ?? '';
            if ($knownBase === $linkedidBase) {
                $linkedid = $knownLinkedid;
                break;
            }
        }
    }

    $globalsObj->uniqueidToLinkedid[$callUniqueid] = $linkedid;
    if (!empty($destUniqueId)) {
        $globalsObj->uniqueidToLinkedid[$destUniqueId] = $linkedid;
    }
    if (!isset($globalsObj->uniqueidToLinkedid[$linkedidOriginal])) {
        $globalsObj->uniqueidToLinkedid[$linkedidOriginal] = $linkedid;
    }
    if (!isset($globalsObj->callDirections[$linkedid])) {
        $globalsObj->callDirections[$linkedid] = 'inbound';
    }
    if (!isset($globalsObj->callDirections[$linkedidOriginal])) {
        $globalsObj->callDirections[$linkedidOriginal] = 'inbound';
    }

    $normalizedCaller = callme_normalize_phone($callerNumberRaw);

    $call_id = $globalsObj->callIdByLinkedid[$linkedid] ?? null;
    $sourceLinkedid = null;
    
    if (!$call_id) {
        $sourceInternal = callme_extract_internal_number(
            $event->getKey('CallerIDNum'),
            $event->getKey('CallerIDName'),
            $event->getKey('Channel')
        );
        if ($sourceInternal) {
            $sourceInternal = (string)$sourceInternal;
            $call_id = $globalsObj->callIdByInt[$sourceInternal] ?? null;
            if (!$call_id) {
                $sourceLinkedid = callme_find_linkedid_for_int($sourceInternal, $globalsObj, null);
                if ($sourceLinkedid && isset($globalsObj->callIdByLinkedid[$sourceLinkedid])) {
                    $call_id = $globalsObj->callIdByLinkedid[$sourceLinkedid];
                }
            }
            if ($call_id) {
                if (!$sourceLinkedid) {
                    $sourceLinkedid = $globalsObj->callsByCallId[$call_id] ?? null;
                }
                callme_clone_call_context($sourceLinkedid, $linkedid, $call_id, $globalsObj);
            }
        }
    }
    
    // КРИТИЧНО: Для каналов Local/ из очереди определяем тип маршрута и ищем call_id из основного звонка (NewChannelEvent)
    // В Asterisk 1.8 UserEvent НЕ передаются через AMI для очередей, поэтому определяем начало дозвона по DialBegin
    $channel = (string)($event->getKey('Channel') ?? $event->getKey('DestChannel') ?? '');
    $isQueueChannel = (strpos($channel, 'Local/') === 0 && (strpos($channel, 'from-queue') !== false || strpos($channel, 'from-ringgroups') !== false));
    
    // Устанавливаем тип маршрута как 'multi' для очередей СРАЗУ, чтобы логика работала правильно
    if ($isQueueChannel && !isset($globalsObj->callRouteTypes[$linkedid]) && !isset($globalsObj->callRouteTypes[$linkedidOriginal])) {
        $globalsObj->callRouteTypes[$linkedid] = 'multi';
        if ($linkedidOriginal !== $linkedid) {
            $globalsObj->callRouteTypes[$linkedidOriginal] = 'multi';
        }
    }
    
    if (!$call_id && $isQueueChannel) {
            // ПРИОРИТЕТ 1: Ищем call_id по базовому linkedid (основной канал из NewChannelEvent)
            // Для очередей linkedid могут быть 1234567890, 1234567890.1, 1234567890.2 и т.д.
            // Основной канал имеет linkedid без .X суффикса
            $linkedidBase = explode('.', (string)$linkedid)[0] ?? '';
            if ($linkedidBase !== '' && $linkedidBase !== $linkedid) {
                // Ищем по базовому linkedid в callIdByLinkedid
                if (isset($globalsObj->callIdByLinkedid[$linkedidBase])) {
                    $call_id = $globalsObj->callIdByLinkedid[$linkedidBase];
                    $sourceLinkedid = $linkedidBase;
                    callme_clone_call_context($sourceLinkedid, $linkedid, $call_id, $globalsObj);
                    $helper->writeToLog(array(
                        'linkedid' => $linkedid,
                        'linkedidBase' => $linkedidBase,
                        'foundLinkedid' => $sourceLinkedid,
                        'call_id' => $call_id,
                        'channel' => $channel,
                        'exten' => $exten,
                    ), 'DialBegin: Found call_id for queue channel by base linkedid');
                }
                
                // ПРИОРИТЕТ 2: Ищем по базовому linkedid в массиве calls (из NewChannelEvent)
                if (!$call_id && isset($globalsObj->calls[$linkedidBase])) {
                    $call_id = $globalsObj->calls[$linkedidBase];
                    $sourceLinkedid = $linkedidBase;
                    // Создаем маппинги
                    $globalsObj->callIdByLinkedid[$linkedidBase] = $call_id;
                    $globalsObj->callIdByLinkedid[$linkedid] = $call_id;
                    $globalsObj->callsByCallId[$call_id] = $linkedidBase;
                    if (isset($globalsObj->callCrmData[$linkedidBase])) {
                        $globalsObj->callCrmData[$linkedid] = $globalsObj->callCrmData[$linkedidBase];
                    }
                    if (isset($globalsObj->callDirections[$linkedidBase])) {
                        $globalsObj->callDirections[$linkedid] = $globalsObj->callDirections[$linkedidBase];
                    }
                    $helper->writeToLog(array(
                        'linkedid' => $linkedid,
                        'linkedidBase' => $linkedidBase,
                        'foundLinkedid' => $sourceLinkedid,
                        'call_id' => $call_id,
                        'channel' => $channel,
                        'exten' => $exten,
                    ), 'DialBegin: Found call_id for queue channel from calls array by base linkedid');
                }
                
                // ПРИОРИТЕТ 3: Ищем по всем callIdByLinkedid с базовым linkedid (включая .X суффиксы)
                if (!$call_id) {
                    foreach ($globalsObj->callIdByLinkedid as $knownLinkedid => $knownCallId) {
                        if (!$knownCallId) {
                            continue;
                        }
                        $knownBase = explode('.', (string)$knownLinkedid)[0] ?? '';
                        if ($knownBase === $linkedidBase) {
                            $call_id = $knownCallId;
                            $sourceLinkedid = $knownLinkedid;
                            callme_clone_call_context($sourceLinkedid, $linkedid, $call_id, $globalsObj);
                            $helper->writeToLog(array(
                                'linkedid' => $linkedid,
                                'linkedidBase' => $linkedidBase,
                                'foundLinkedid' => $sourceLinkedid,
                                'call_id' => $call_id,
                                'channel' => $channel,
                                'exten' => $exten,
                            ), 'DialBegin: Found call_id for queue channel by linkedid base match in callIdByLinkedid');
                            break;
                        }
                    }
                }
                
                // ПРИОРИТЕТ 4: Ищем по всем calls с базовым linkedid (включая .X суффиксы)
                if (!$call_id) {
                    foreach ($globalsObj->calls as $knownUniqueid => $knownCallId) {
                        if (!$knownCallId) {
                            continue;
                        }
                        $knownBase = explode('.', (string)$knownUniqueid)[0] ?? '';
                        if ($knownBase === $linkedidBase) {
                            $call_id = $knownCallId;
                            $sourceLinkedid = $knownUniqueid;
                            // Создаем маппинги
                            if (!isset($globalsObj->callIdByLinkedid[$sourceLinkedid])) {
                                $globalsObj->callIdByLinkedid[$sourceLinkedid] = $call_id;
                            }
                            $globalsObj->callIdByLinkedid[$linkedid] = $call_id;
                            $globalsObj->callsByCallId[$call_id] = $sourceLinkedid;
                            if (isset($globalsObj->callCrmData[$sourceLinkedid])) {
                                $globalsObj->callCrmData[$linkedid] = $globalsObj->callCrmData[$sourceLinkedid];
                            }
                            if (isset($globalsObj->callDirections[$sourceLinkedid])) {
                                $globalsObj->callDirections[$linkedid] = $globalsObj->callDirections[$sourceLinkedid];
                            }
                            $helper->writeToLog(array(
                                'linkedid' => $linkedid,
                                'linkedidBase' => $linkedidBase,
                                'foundLinkedid' => $sourceLinkedid,
                                'call_id' => $call_id,
                                'channel' => $channel,
                                'exten' => $exten,
                            ), 'DialBegin: Found call_id for queue channel from calls array by base match');
                            break;
                        }
                    }
                }
            }
            
            // ПРИОРИТЕТ 5: Ищем по всем ringingIntNums с активными звонками (fallback для сложных случаев)
            if (!$call_id) {
                $candidateCallIds = array();
                foreach ($globalsObj->ringingIntNums as $ringLinkedid => $ringNums) {
                    if (!empty($ringNums) && isset($globalsObj->callIdByLinkedid[$ringLinkedid])) {
                        $candidateCallId = $globalsObj->callIdByLinkedid[$ringLinkedid];
                        if ($candidateCallId && isset($globalsObj->callDirections[$ringLinkedid]) && 
                            $globalsObj->callDirections[$ringLinkedid] === 'inbound') {
                            $candidateCallIds[$candidateCallId] = $ringLinkedid;
                        }
                    }
                }
                
                // Берем call_id с наибольшим количеством активных звонящих номеров
                if (!empty($candidateCallIds)) {
                    $bestCallId = null;
                    $bestLinkedid = null;
                    $maxRingingCount = 0;
                    foreach ($candidateCallIds as $candidateCallId => $candidateLinkedid) {
                        $ringingCount = 0;
                        foreach ($globalsObj->ringingIntNums as $ringLinkedid => $ringNums) {
                            if (isset($globalsObj->callIdByLinkedid[$ringLinkedid]) && 
                                $globalsObj->callIdByLinkedid[$ringLinkedid] === $candidateCallId) {
                                $ringingCount += count($ringNums);
                            }
                        }
                        if ($ringingCount > $maxRingingCount) {
                            $maxRingingCount = $ringingCount;
                            $bestCallId = $candidateCallId;
                            $bestLinkedid = $candidateLinkedid;
                        }
                    }
                    
                    if ($bestCallId && $bestLinkedid) {
                        $call_id = $bestCallId;
                        $sourceLinkedid = $bestLinkedid;
                        callme_clone_call_context($sourceLinkedid, $linkedid, $call_id, $globalsObj);
                        $helper->writeToLog(array(
                            'linkedid' => $linkedid,
                            'foundLinkedid' => $sourceLinkedid,
                            'call_id' => $call_id,
                            'channel' => $channel,
                            'exten' => $exten,
                            'ringingCount' => $maxRingingCount,
                        ), 'DialBegin: Found call_id for queue channel by multi call match (fallback)');
                    }
                }
            }
            
            // ПРИОРИТЕТ 6: Ищем call_id по всем зарегистрированным inbound звонкам (для случая, когда ORIGINAL_LINKEDID не совпадает с linkedid канала очереди)
            // Это критично для звонков в очередь, когда ORIGINAL_LINKEDID из b24_continuous_parallel.conf отличается от linkedid канала очереди
            if (!$call_id && !empty($globalsObj->callIdByLinkedid) && $normalizedCaller !== '') {
                // Ищем inbound звонок, который соответствует номеру вызывающего (caller ID)
                $bestCallId = null;
                $bestLinkedid = null;
                $maxTimestamp = 0;
                $callerNumberDigits = preg_replace('/\D+/', '', $normalizedCaller);
                
                foreach ($globalsObj->callIdByLinkedid as $candidateLinkedid => $candidateCallId) {
                    if (!$candidateCallId) {
                        continue;
                    }
                    
                    // Проверяем, что это inbound звонок
                    if (isset($globalsObj->callDirections[$candidateLinkedid]) && 
                        $globalsObj->callDirections[$candidateLinkedid] !== 'inbound') {
                        continue;
                    }
                    
                    // Проверяем совпадение номера вызывающего через callCrmData или через последние активные звонки
                    $matchesCaller = false;
                    if (isset($globalsObj->callCrmData[$candidateLinkedid])) {
                        // Проверяем через callCrmData, если есть информация о номере
                        // Для более точного поиска можно использовать номер из CRM, но пока используем timestamp
                        $matchesCaller = true;
                    }
                    
                    // Если нет callCrmData, проверяем по timestamp (самый свежий звонок может быть нашим)
                    if (!$matchesCaller) {
                        $matchesCaller = true; // Пока считаем все inbound звонки потенциальными кандидатами
                    }
                    
                    if (!$matchesCaller) {
                        continue;
                    }
                    
                    // Ищем максимальный timestamp из ringingIntNums для этого linkedid
                    $candidateTimestamp = 0;
                    if (isset($globalsObj->ringingIntNums[$candidateLinkedid])) {
                        foreach ($globalsObj->ringingIntNums[$candidateLinkedid] as $ringData) {
                            $ringTs = (int)($ringData['timestamp'] ?? 0);
                            if ($ringTs > $candidateTimestamp) {
                                $candidateTimestamp = $ringTs;
                            }
                        }
                    }
                    
                    // Если нет timestamp в ringingIntNums, проверяем наличие call_id (звонок зарегистрирован)
                    if ($candidateTimestamp === 0 && isset($globalsObj->callIdByLinkedid[$candidateLinkedid])) {
                        // Используем timestamp из callCrmData, если есть, иначе текущее время минус небольшое значение
                        // (чтобы не перебивать звонки с реальным timestamp)
                        $candidateTimestamp = isset($globalsObj->callCrmData[$candidateLinkedid]['timestamp']) 
                            ? (int)$globalsObj->callCrmData[$candidateLinkedid]['timestamp'] 
                            : (time() - 60); // 60 секунд назад для свежих звонков
                    }
                    
                    // Берем самый свежий inbound звонок
                    if ($candidateTimestamp > $maxTimestamp) {
                        $maxTimestamp = $candidateTimestamp;
                        $bestCallId = $candidateCallId;
                        $bestLinkedid = $candidateLinkedid;
                    }
                }
                
                if ($bestCallId && $bestLinkedid) {
                    $call_id = $bestCallId;
                    $sourceLinkedid = $bestLinkedid;
                    callme_clone_call_context($sourceLinkedid, $linkedid, $call_id, $globalsObj);
                    $helper->writeToLog(array(
                        'linkedid' => $linkedid,
                        'linkedidBase' => $linkedidBase ?? '',
                        'foundLinkedid' => $sourceLinkedid,
                        'call_id' => $call_id,
                        'channel' => $channel,
                        'exten' => $exten,
                        'callerNumber' => $normalizedCaller,
                        'timestamp' => $maxTimestamp,
                        'message' => 'Found by ORIGINAL_LINKEDID fallback (latest inbound call matching caller)',
                    ), 'DialBegin: Found call_id for queue channel by ORIGINAL_LINKEDID fallback');
                }
            }
        }

    if (!isset($globalsObj->ringingIntNums[$linkedid])) {
        $globalsObj->ringingIntNums[$linkedid] = array();
    }
    if (!isset($globalsObj->ringOrder[$linkedid])) {
        $globalsObj->ringOrder[$linkedid] = array();
    }
    if (!in_array($exten, $globalsObj->ringOrder[$linkedid], true)) {
        $globalsObj->ringOrder[$linkedid][] = $exten;
    }

    $existingEntry = $globalsObj->ringingIntNums[$linkedid][$exten] ?? array();
    $routeType = 'direct'; // По умолчанию direct
    if (isset($globalsObj->callRouteTypes[$linkedid])) {
        $routeType = callme_normalize_route_type($globalsObj->callRouteTypes[$linkedid]);
    } elseif (isset($globalsObj->callRouteTypes[$linkedidOriginal])) {
        $routeType = callme_normalize_route_type($globalsObj->callRouteTypes[$linkedidOriginal]);
        $globalsObj->callRouteTypes[$linkedid] = $routeType;
    } else {
        // Определяем тип звонка по каналу, если не установлен
        $channel = (string)($event->getKey('Channel') ?? $event->getKey('DestChannel') ?? '');
        $destChannel = (string)($event->getKey('DestChannel') ?? '');
        $dialString = (string)($event->getKey('Dialstring') ?? $rawDialString ?? '');
        
        // Проверяем наличие признаков multi звонка
        $isMultiChannel = (strpos($channel, 'from-queue') !== false || strpos($destChannel, 'from-queue') !== false ||
            strpos($channel, 'from-ringgroups') !== false || strpos($destChannel, 'from-ringgroups') !== false ||
            strpos($channel, 'Local/') === 0);
        
        // Для каналов Local/ из очереди всегда multi
        if ($isMultiChannel) {
            $routeType = 'multi';
            $globalsObj->callRouteTypes[$linkedid] = $routeType;
            if ($linkedidOriginal !== $linkedid) {
                $globalsObj->callRouteTypes[$linkedidOriginal] = $routeType;
            }
        }
    }
    if (empty($existingEntry['user_id'])) {
        $existingEntry['user_id'] = $helper->getUSER_IDByIntNum($exten);
    }
    $existingEntry['timestamp'] = time();
    $existingEntry['state'] = 'RING';
    if (!empty($routeType) && empty($existingEntry['route_type'])) {
        $existingEntry['route_type'] = $routeType;
    } elseif (empty($existingEntry['route_type'])) {
        $existingEntry['route_type'] = 'direct';
    }
    if (!empty($destUniqueId)) {
        $existingEntry['agent_uniqueid'] = $destUniqueId;
    }
    if (empty($existingEntry['direction'])) {
        $existingEntry['direction'] = 'inbound';
    }
    $existingEntry['shown'] = callme_is_card_marked_shown($linkedid, $exten, $globalsObj) || !empty($existingEntry['shown']);

    $globalsObj->ringingIntNums[$linkedid][$exten] = $existingEntry;
    $userId = $existingEntry['user_id'] ?? null;
    $helper->writeToLog(array(
        'linkedid' => $linkedid,
        'intNum' => $exten,
        'dialStringRaw' => $rawDialString,
        'userId' => $userId,
        'ringOrder' => $globalsObj->ringOrder[$linkedid],
        'activeRinging' => array_keys($globalsObj->ringingIntNums[$linkedid]),
        'callerRaw' => $callerNumberRaw,
        'callerNormalized' => $normalizedCaller,
    ), 'DialBegin: RING state updated');

    // КРИТИЧНО: Для multi-звонков (очередь) открываем карточки СРАЗУ при DialBegin БЕЗ ожидания UserEvent
    // В Asterisk 1.8 UserEvent НЕ передаются через AMI для очередей, поэтому определяем начало дозвона по DialBegin
    // Это основной механизм, а НЕ fallback!
    if ($routeType === 'multi') {
        // Показываем карточки сразу, если call_id уже найден
        if ($call_id) {
            $helper->writeToLog(array(
                'linkedid' => $linkedid,
                'intNum' => $exten,
                'route_type' => $routeType,
                'call_id' => $call_id,
                'ringingIntNums' => array_keys($globalsObj->ringingIntNums[$linkedid] ?? array()),
                'channel' => (string)($event->getKey('Channel') ?? ''),
                'source' => 'DialBegin - immediate show for multi call',
            ), 'DialBegin: Multi call detected, showing cards immediately (no UserEvent)');
            // Показываем карточки для ВСЕХ звонящих номеров в очереди
            callme_show_cards_for_ringing($linkedid, $helper, $globalsObj);
            // Дополнительно показываем карточку для текущего номера, если еще не показана
            $ringEntry = $globalsObj->ringingIntNums[$linkedid][$exten] ?? null;
            if ($ringEntry && empty($ringEntry['shown'])) {
                callme_show_card_for_int($linkedid, $exten, $helper, $globalsObj);
            }
        } else {
            // Если call_id еще не найден, запоминаем что нужно показать карточки когда найден
            // Это важно для случаев, когда DialBegin приходит до полной инициализации call_id
            $helper->writeToLog(array(
                'linkedid' => $linkedid,
                'intNum' => $exten,
                'route_type' => $routeType,
                'channel' => (string)($event->getKey('Channel') ?? ''),
                'message' => 'Multi call detected but call_id not found yet, will show when found',
            ), 'DialBegin: Multi call detected, waiting for call_id');
        }
    } else {
        // Для direct звонков показываем карточки только если есть не показанные
        $hasUnshownRinging = false;
        foreach ($globalsObj->ringingIntNums[$linkedid] as $ringInt => $ringInfo) {
            $state = $ringInfo['state'] ?? 'RING';
            $shown = $ringInfo['shown'] ?? false;
            if ($state === 'RING' && !$shown) {
                $hasUnshownRinging = true;
                break;
            }
        }
        if ($hasUnshownRinging && $call_id) {
            callme_show_cards_for_ringing($linkedid, $helper, $globalsObj);
        }
    }

    if ($call_id) {
        $globalsObj->calls[$callUniqueid] = $call_id;
        if ($linkedid !== $callUniqueid) {
            $globalsObj->calls[$linkedid] = $call_id;
        }
        if (!empty($destUniqueId)) {
            $globalsObj->calls[$destUniqueId] = $call_id;
        }
        $globalsObj->callIdByLinkedid[$linkedid] = $call_id;
        $globalsObj->callIdByInt[$exten] = $call_id;
        if (!isset($globalsObj->callIdByInt[$linkedidOriginal])) {
            $globalsObj->callIdByInt[$linkedidOriginal] = $call_id;
        }
        $globalsObj->callsByCallId[$call_id] = $linkedid;
    } else {
        $helper->writeToLog(array(
            'linkedid' => $linkedid,
            'intNum' => $exten,
            'uniqueid' => $callUniqueid,
        ), 'DialBegin without registered CALL_ID');
    }

    $globalsObj->intNums[$callUniqueid] = $exten;
    if ($linkedid && !isset($globalsObj->intNums[$linkedid]) && $call_id) {
        $globalsObj->intNums[$linkedid] = $exten;
    }
}

/**
 * Общий обработчик завершения дозвона по внутреннему номеру.
 *
 * @param EventMessage $event
 * @param string $callLinkedid
 * @param HelperFuncs $helper
 * @param Globals $globalsObj
 * @return void
 */
function callme_handle_dial_end_common(
    EventMessage $event,
    $callLinkedid,
    HelperFuncs $helper,
    Globals $globalsObj
) {
    $linkedid = $event->getKey("Linkedid") ?: ($globalsObj->uniqueidToLinkedid[$callLinkedid] ?? $callLinkedid);
    $globalsObj->uniqueidToLinkedid[$callLinkedid] = $linkedid;
    $callId = $globalsObj->callIdByLinkedid[$linkedid] ?? ($globalsObj->calls[$linkedid] ?? ($globalsObj->calls[$callLinkedid] ?? null));
    $currentIntNum = $globalsObj->intNums[$callLinkedid] ?? null;

    if (!$callId) {
        $callId = callme_resolve_call_id($linkedid, $currentIntNum, $globalsObj, $helper);
    }
    if ($callId) {
        if ($linkedid) {
            $globalsObj->callIdByLinkedid[$linkedid] = $callId;
        }
        if ($currentIntNum) {
            $globalsObj->callIdByInt[$currentIntNum] = $callId;
        }
    }
    $now = time();

    switch ($event->getDialStatus()) {
        case 'ANSWER':
            if ($linkedid && isset($globalsObj->ringingIntNums[$linkedid][$currentIntNum])) {
                $globalsObj->ringingIntNums[$linkedid][$currentIntNum]['state'] = 'ANSWER';
                $globalsObj->ringingIntNums[$linkedid][$currentIntNum]['timestamp'] = $now;
            }
            $helper->writeToLog(array('intNum'=>$currentIntNum,
                                        'extNum'=>$event->getKey("Exten"),
                                        'callUniqueid'=>$callLinkedid,
                                        'CALL_ID'=>$callId,
                                        'ringOrder' => $globalsObj->ringOrder[$linkedid] ?? array(),
                                        'activeBefore' => isset($globalsObj->ringingIntNums[$linkedid]) ? array_keys($globalsObj->ringingIntNums[$linkedid]) : array()),
                                    'incoming call ANSWER');

            if ($callId && $linkedid) {
                callme_hide_cards_batch($linkedid, $callId, $helper, $globalsObj, $currentIntNum);
            }
            if ($linkedid && $currentIntNum) {
                if (!isset($globalsObj->callShownCards[$linkedid])) {
                    $globalsObj->callShownCards[$linkedid] = array();
                }
                $currentUserId = $globalsObj->ringingIntNums[$linkedid][$currentIntNum]['user_id'] ?? $helper->getUSER_IDByIntNum($currentIntNum);
                $globalsObj->callShownCards[$linkedid][$currentIntNum] = array(
                    'user_id' => $currentUserId ? (int)$currentUserId : null,
                    'int_num' => (string)$currentIntNum,
                    'shown' => true,
                    'shown_at' => time(),
                );
            }
            if ($linkedid && isset($globalsObj->callCrmData[$linkedid])) {
                $globalsObj->callCrmData[$linkedid]['answer_int_num'] = $currentIntNum;
            }
            if ($linkedid && $currentIntNum && isset($globalsObj->ringingIntNums[$linkedid][$currentIntNum])) {
                unset($globalsObj->ringingIntNums[$linkedid][$currentIntNum]);
            }
            $globalsObj->Dispositions[$callLinkedid] = 'ANSWER';
            if (empty($globalsObj->Answers[$callLinkedid])) {
                $globalsObj->Answers[$callLinkedid] = $now;
            }
            if (!empty($globalsObj->transferHistory) && $currentIntNum) {
                foreach ($globalsObj->transferHistory as $externalUniqueid => $transferData) {
                    if (!is_array($transferData)) {
                        continue;
                    }
                    if ((string)($transferData['currentIntNum'] ?? '') !== (string)$currentIntNum) {
                        continue;
                    }
                    $globalsObj->Dispositions[$externalUniqueid] = 'ANSWER';
                    if (empty($globalsObj->Answers[$externalUniqueid])) {
                        $answerTs = $transferData['answer_timestamp'] ?? $now;
                        $globalsObj->Answers[$externalUniqueid] = $answerTs;
                        $globalsObj->transferHistory[$externalUniqueid]['answer_timestamp'] = $answerTs;
                    }
                    break;
                }
            }
            break;
        case 'BUSY':
            if ($linkedid && isset($globalsObj->ringingIntNums[$linkedid][$currentIntNum])) {
                $globalsObj->ringingIntNums[$linkedid][$currentIntNum]['state'] = 'BUSY';
                $globalsObj->ringingIntNums[$linkedid][$currentIntNum]['timestamp'] = $now;
            }
            $activeBefore = isset($globalsObj->ringingIntNums[$linkedid]) ? array_keys($globalsObj->ringingIntNums[$linkedid]) : array();
            $helper->writeToLog(array(
                'intNum' => $currentIntNum,
                'callUniqueid' => $callLinkedid,
                'CALL_ID' => $callId,
                'ringOrder' => $globalsObj->ringOrder[$linkedid] ?? array(),
                'activeBefore' => $activeBefore,
            ), 'incoming call BUSY');
            if ($callId && $currentIntNum && callme_is_card_marked_shown($linkedid, $currentIntNum, $globalsObj)) {
                $helper->hideInputCall($currentIntNum, $callId);
            }
            if ($linkedid && $currentIntNum) {
                unset($globalsObj->callShownCards[$linkedid][$currentIntNum]);
                unset($globalsObj->ringingIntNums[$linkedid][$currentIntNum]);
                $activeAfter = isset($globalsObj->ringingIntNums[$linkedid]) ? array_keys($globalsObj->ringingIntNums[$linkedid]) : array();
                $helper->writeToLog(array(
                    'intNum' => $currentIntNum,
                    'linkedid' => $linkedid,
                    'activeAfter' => $activeAfter,
                ), 'incoming call BUSY cleanup');
            }
            $globalsObj->Dispositions[$callLinkedid] = 'BUSY';
            break;
        case 'CANCEL':
            if ($linkedid && isset($globalsObj->ringingIntNums[$linkedid][$currentIntNum])) {
                $globalsObj->ringingIntNums[$linkedid][$currentIntNum]['state'] = 'CANCEL';
                $globalsObj->ringingIntNums[$linkedid][$currentIntNum]['timestamp'] = $now;
            }
            $activeBefore = isset($globalsObj->ringingIntNums[$linkedid]) ? array_keys($globalsObj->ringingIntNums[$linkedid]) : array();
            $helper->writeToLog(array(
                'intNum' => $currentIntNum,
                'callUniqueid' => $callLinkedid,
                'CALL_ID' => $callId,
                'ringOrder' => $globalsObj->ringOrder[$linkedid] ?? array(),
                'activeBefore' => $activeBefore,
            ), 'incoming call CANCEL');
            if ($callId && $currentIntNum && callme_is_card_marked_shown($linkedid, $currentIntNum, $globalsObj)) {
                $helper->hideInputCall($currentIntNum, $callId);
            }
            if ($linkedid && $currentIntNum) {
                unset($globalsObj->callShownCards[$linkedid][$currentIntNum]);
                unset($globalsObj->ringingIntNums[$linkedid][$currentIntNum]);
                $activeAfter = isset($globalsObj->ringingIntNums[$linkedid]) ? array_keys($globalsObj->ringingIntNums[$linkedid]) : array();
                $helper->writeToLog(array(
                    'intNum' => $currentIntNum,
                    'linkedid' => $linkedid,
                    'activeAfter' => $activeAfter,
                ), 'incoming call CANCEL cleanup');
            }
            $globalsObj->Dispositions[$callLinkedid] = 'CANCEL';
            break;
        default:
            if ($event->getDialStatus()) {
                $globalsObj->Dispositions[$callLinkedid] = $event->getDialStatus();
            }
            break;
    }

    if ($globalsObj->Dispositions[$callLinkedid] === 'ANSWER') {
        $globalsObj->Dispositions[$callLinkedid] = "ANSWERED";
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

// Диагностическое логирование событий Dial (Asterisk 1.8)
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
                $globalsObj->callDirections[$callLinkedid] = 'inbound';
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
                if (!empty($CallMeCallerIDName)) {
                    $callami->SetVar("__CALLME_CONNECTEDLINE_NAME", $CallMeCallerIDName, $CallChannel);
                    if (!empty($CallChannel)) {
                        $callami->SetVar(sprintf("SHARED(CALLME_CL_NAME,%s)", $CallChannel), $CallMeCallerIDName, $CallChannel);
                        $callami->SetVar("__CALLME_INGRESS_CHANNEL", $CallChannel, $CallChannel);
                    }
                    $helper->writeToLog(array(
                        'linkedid' => $callLinkedid,
                        'channel' => $CallChannel,
                        'connectedline_name' => $CallMeCallerIDName,
                        'shared_channel' => $CallChannel ?: null,
                    ), 'Prepared ConnectedLine name for agent');
                }
                
                $fallbackUserId = $helper->getFallbackResponsibleUserId();
                $fallbackUserInt = $fallbackUserId ? $helper->getIntNumByUSER_ID($fallbackUserId) : null;

                $selectedIntNum = null;
                $selectedUserId = null;
                if ($responsibleUserId) {
                    $responsibleIntNum = $helper->getIntNumByUSER_ID($responsibleUserId);
                    if ($responsibleIntNum) {
                        $selectedIntNum = $responsibleIntNum;
                        $selectedUserId = $responsibleUserId;
                        $helper->writeToLog(array(
                            'responsibleUserId' => $responsibleUserId,
                            'responsibleIntNum' => $responsibleIntNum
                        ), 'Found responsible internal number from CRM');
                    } else {
                        $helper->writeToLog("Responsible user $responsibleUserId has no internal number, will register later.", 
                            'Responsible determination');
                    }
                } else {
                    $helper->writeToLog("No responsible found in CRM, will wait for agent registration.", 
                        'Responsible determination');
                }

                $bx24_source = $helper->getConfig('bx24_crm_source');
                // Проверка совместимости с PHP 8.2: array_key_exists требует массив
                if (!is_array($bx24_source)) {
                    $bx24_source = array('default_crm_source' => 'CALL');
                }
                $srmSource = array_key_exists($exten, $bx24_source) ? $bx24_source[$exten] : $bx24_source["default_crm_source"];

                $roiSource = $helper->getRoiSourceByNumber($exten);
                if ($roiSource !== null) {
                    $srmSource = $roiSource;
                }

                $globalsObj->callShownCards[$callLinkedid] = array();
                $globalsObj->ringingIntNums[$callLinkedid] = array();
                $globalsObj->ringOrder[$callLinkedid] = array();

                $registerUserId = $selectedUserId ?: ($fallbackUserId ?: null);
                $registrationTarget = $helper->resolveRegistrationTarget(
                    $selectedIntNum,
                    $fallbackUserInt,
                    $exten
                );
                $registerIntNum = $registrationTarget['intNum'];
                $registerIntSource = $registrationTarget['source'];
                $registerIntAvailable = $registerIntNum !== null;

                $helper->writeToLog(array(
                    'linkedid' => $callLinkedid,
                    'extNum' => $extNum,
                    'line' => $exten,
                    'selectedIntNum' => $selectedIntNum,
                    'fallbackIntNum' => $fallbackUserInt,
                    'resolvedIntNum' => $registerIntNum,
                    'resolvedSource' => $registerIntSource,
                    'hasResolvedInt' => $registerIntAvailable,
                    'responsibleUserId' => $responsibleUserId,
                    'fallbackUserId' => $fallbackUserId,
                    'registerUserId' => $registerUserId,
                ), 'Resolved registration target');

                $callResult = $helper->runInputCall(
                    $registerIntNum,
                    $extNum,
                    $exten,
                    $srmSource,
                    $registerUserId
                );

                if ($callResult && isset($callResult['CALL_ID'])) {
                    $call_id = $callResult['CALL_ID'];
                    $globalsObj->calls[$callLinkedid] = $call_id;
                    $globalsObj->callIdByLinkedid[$callLinkedid] = $call_id;
                    $globalsObj->callsByCallId[$call_id] = $callLinkedid;
                    $globalsObj->uniqueidToLinkedid[$callLinkedid] = $callLinkedid;
                    if ($registerIntAvailable) {
                        $globalsObj->intNums[$callLinkedid] = $registerIntNum;
                        $globalsObj->callIdByInt[$registerIntNum] = $call_id;
                    }

                    $globalsObj->callCrmData[$callLinkedid] = array(
                        'entity_type' => $callResult['CRM_ENTITY_TYPE'] ?? ($crmData['entity_type'] ?? null),
                        'entity_id' => $callResult['CRM_ENTITY_ID'] ?? ($crmData['entity_id'] ?? null),
                        'created' => !empty($callResult['CRM_CREATED_LEAD']) || !empty($callResult['CRM_CREATED_ENTITIES']),
                        'initial_responsible_user_id' => $registerUserId,
                        'current_responsible_user_id' => $registerUserId,
                        'crm_responsible_user_id' => $responsibleUserId,
                    );

                    $helper->writeToLog(array(
                        'linkedid' => $callLinkedid,
                        'intNum' => $registerIntNum,
                        'userId' => $registerUserId,
                        'CALL_ID' => $call_id,
                        'crm_responsible_user_id' => $responsibleUserId,
                        'fallbackUserId' => $fallbackUserId,
                        'registerSource' => $registerIntSource,
                        'hasResolvedInt' => $registerIntAvailable,
                    ), 'Immediate call registered on agent');

                    if (!empty($call_id) && !empty($CallChannel)) {
                        $callami->SetVar('CALLME_CALL_ID', $call_id, $CallChannel);
                    }
                } else {
                    $helper->writeToLog(array(
                        'linkedid' => $callLinkedid,
                        'extNum' => $extNum,
                        'line' => $exten,
                        'responsibleUserId' => $responsibleUserId,
                        'selectedIntNum' => $selectedIntNum,
                        'fallbackIntNum' => $fallbackUserInt,
                        'registerSource' => $registerIntSource,
                    ), 'Immediate call registration failed');
                }

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

        $callerLen = strlen(preg_replace('/\D+/', '', (string)$intNum));
        $channel = $event->getChannel();
        if ($callerLen > 4 || strpos($channel ?? '', 'Local/') === 0) {
            return "";
        }

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

    if (!($event instanceof NewchannelEvent)) {
        return false;
    }

    $callerIdNum = (string)$event->getCallerIdNum();
    $callerLen = strlen(preg_replace('/\D+/', '', $callerIdNum));
    $channel = $event->getKey('Channel') ?? '';
    return
        ($event->getExtension() !== 's')
//            && ($event->getContext() === 'E1' || $event->getContext() == 'office')
            // Если user_show_cards пуст - показываем всем (Битрикс сам определит ответственного)
            // Если заполнен - фильтруем по списку внутренних номеров
            && (empty($globalsObj->user_show_cards) || in_array($callerIdNum, $globalsObj->user_show_cards))
            && ($callerLen <= 4)
            && (strpos($channel, 'Local/') !== 0)
            ;
}
);

$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper, $globalsObj) {
        ami_touch_activity($globalsObj);
        $userEventName = (string) ($event->getKey('UserEvent') ?? '');
        switch ($userEventName) {
            case 'CallMeRingingStart':
                callme_handle_user_event_ringing_start($event, $helper, $globalsObj);
                break;
            case 'CallMeRingingAnswer':
                callme_handle_user_event_ringing_answer($event, $helper, $globalsObj);
                break;
            case 'CallMeRingingStop':
                callme_handle_user_event_ringing_stop($event, $helper, $globalsObj);
                break;
            default:
                break;
        }
    },
    function (EventMessage $event) {
        if ($event->getName() !== 'UserEvent') {
            return false;
        }
        $userEventName = (string) ($event->getKey('UserEvent') ?? '');
        return in_array($userEventName, array('CallMeRingingStart', 'CallMeRingingAnswer', 'CallMeRingingStop'), true);
    }
);

//обрабатываем VarSetEvent события, получаем url записи звонка
//VarSetEvent
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper,$globalsObj) {
        echo 'VarSetEvent'."\n";
        echo $event->getRawContent();

        $variableName = $event->getVariableName();
        $rawValue = $event->getValue();
        $callUniqueid = $event->getKey("Uniqueid");
        $linkedidFromEvent = $event->getKey("Linkedid");
        $linkedid = $linkedidFromEvent ?: ($globalsObj->uniqueidToLinkedid[$callUniqueid] ?? null);

        if ($variableName === 'BRIDGEPEER') {
            $channel = (string)$event->getChannel();
            $bridgedPeer = (string)$event->getValue();
            if (strpos($channel, 'SIP/') !== 0) {
                return;
            }

            $intNum = null;
            if (preg_match('/SIP\/(\d+)-/', $channel, $matches)) {
                $intNum = substr($matches[1], 0, 4);
            }
            if (!$intNum) {
                $intNum = callme_extract_internal_number($channel);
            }
            if (!$intNum) {
                return;
            }

            foreach ($globalsObj->transferHistory as $externalUniqueid => $transferData) {
                $callId = $transferData['call_id'] ?? ($globalsObj->calls[$externalUniqueid] ?? null);
                if (!$callId) {
                    continue;
                }

                if ($linkedid && !empty($transferData['linkedid']) && $transferData['linkedid'] !== $linkedid) {
                    continue;
                }

                $externalChannel = $transferData['externalChannel'] ?? '';
                $externalChannelBase = preg_replace('/-[^-]+$/', '', (string)$externalChannel);
                $bridgedPeerBase = preg_replace('/-[^-]+$/', '', $bridgedPeer);
                if ($externalChannelBase !== $bridgedPeerBase && $externalChannel !== $bridgedPeer) {
                    continue;
                }

                $linkedidForTransfer = $globalsObj->uniqueidToLinkedid[$externalUniqueid]
                    ?? ($globalsObj->callsByCallId[$callId] ?? ($linkedid ?: $externalUniqueid));
                if ($linkedid && $linkedidForTransfer !== $linkedid) {
                    $linkedidForTransfer = $linkedid;
                }

                $expectedCallId = callme_resolve_call_id($linkedidForTransfer, $intNum, $globalsObj, $helper);
                if ($expectedCallId && $expectedCallId !== $callId) {
                    $callId = $expectedCallId;
                }

                $oldIntNum = $transferData['currentIntNum'] ?? null;
                if ($oldIntNum === $intNum) {
                    continue;
                }

                callme_transfer_move_card($linkedidForTransfer, $callId, $oldIntNum, $intNum, $helper, $globalsObj, array(
                    'event' => 'VarSetEvent',
                    'variable' => 'BRIDGEPEER',
                    'externalUniqueid' => $externalUniqueid,
                    'externalChannel' => $externalChannel,
                    'channel' => $channel,
                    'bridgedPeer' => $bridgedPeer,
                ));

                $globalsObj->transferHistory[$externalUniqueid]['currentIntNum'] = $intNum;
                $globalsObj->transferHistory[$externalUniqueid]['linkedid'] = $linkedidForTransfer;
                $globalsObj->transferHistory[$externalUniqueid]['history'][] = array(
                    'from' => $oldIntNum,
                    'to' => $intNum,
                    'timestamp' => time(),
                );
                $globalsObj->intNums[$externalUniqueid] = $intNum;
                $globalsObj->callIdByLinkedid[$linkedidForTransfer] = $callId;
                $globalsObj->callIdByInt[$intNum] = $callId;
                return;
            }

            return;
        } elseif ($variableName === 'CALLME_CARD_STATE') {
            $payload = trim((string)$rawValue);
            $helper->writeToLog(array(
                'variable' => 'CALLME_CARD_STATE',
                'payload' => $payload,
                'uniqueid' => $callUniqueid,
                'linkedid' => $linkedid,
                'channel' => (string)($event->getChannel() ?? ''),
            ), 'CALLME_CARD_STATE VarSetEvent received');
            
            if ($payload === '') {
                $helper->writeToLog(array(
                    'uniqueid' => $callUniqueid,
                    'linkedid' => $linkedid,
                ), 'CALLME_CARD_STATE empty payload');
                echo "\n-------------------------------------------------------------------\n\r";
                echo "\n\r";
                return;
            }

            $statePart = $payload;
            $targetPart = '';
            $payloadLinkedid = '';
            $payloadCallId = '';
            $routeTypeRaw = '';
            if (strpos($payload, '|') !== false) {
                $segments = explode('|', $payload);
                $statePart = $segments[0] ?? '';
                $payloadLinkedid = trim($segments[1] ?? '');
                $targetPart = $segments[2] ?? '';
                $payloadCallId = trim($segments[3] ?? '');
                $routeTypeRaw = trim($segments[4] ?? '');
            } elseif (strpos($payload, ':') !== false) {
                list($statePart, $targetPart) = explode(':', $payload, 2);
            }

            $state = strtoupper(trim($statePart));
            if ($targetPart === '') {
                $segments = preg_split('/\s+/', trim($payload));
                if (count($segments) >= 2) {
                    $state = strtoupper(trim($segments[0]));
                    $targetPart = $segments[1];
                }
            }
            $intNum = preg_replace('/\D+/', '', (string)$targetPart);

            if ($intNum === '') {
                $helper->writeToLog(array(
                    'payload' => $payload,
                    'uniqueid' => $callUniqueid,
                    'linkedid' => $linkedid,
                ), 'CALLME_CARD_STATE invalid intNum');
                echo "\n-------------------------------------------------------------------\n\r";
                echo "\n\r";
                return;
            }

            if ($linkedid === '' && $payloadLinkedid !== '') {
                $linkedid = $payloadLinkedid;
            }

            $routeType = $routeTypeRaw !== '' ? callme_normalize_route_type($routeTypeRaw) : null;
            if ($routeType && $linkedid !== '') {
                $globalsObj->callRouteTypes[$linkedid] = $routeType;
            } elseif ($linkedid !== '' && isset($globalsObj->callRouteTypes[$linkedid])) {
                $routeType = callme_normalize_route_type($globalsObj->callRouteTypes[$linkedid]);
            }

            $callId = null;
            if ($payloadCallId !== '') {
                $callId = $payloadCallId;
            }
            // КРИТИЧНО: Для очередей (multi) call_id может быть не в payload, ищем по linkedid
            // Приоритет 1: По linkedid из payload или события
            if (!$callId && $linkedid) {
                $callId = $globalsObj->callIdByLinkedid[$linkedid] ?? null;
            }
            // Приоритет 2: По базовому linkedid (для очередей linkedid может быть с суффиксом .X)
            if (!$callId && $linkedid && strpos((string)$linkedid, '.') !== false) {
                $linkedidBase = explode('.', (string)$linkedid)[0] ?? '';
                if ($linkedidBase !== '' && isset($globalsObj->callIdByLinkedid[$linkedidBase])) {
                    $callId = $globalsObj->callIdByLinkedid[$linkedidBase];
                    // Создаем маппинг для текущего linkedid
                    $globalsObj->callIdByLinkedid[$linkedid] = $callId;
                    $globalsObj->callsByCallId[$callId] = $linkedid;
                }
            }
            // Приоритет 3: По intNum
            if (!$callId && isset($globalsObj->callIdByInt[$intNum])) {
                $callId = $globalsObj->callIdByInt[$intNum];
                if (!$linkedid) {
                    $foundLinkedid = array_search($callId, $globalsObj->callIdByLinkedid, true);
                    if ($foundLinkedid !== false) {
                        $linkedid = $foundLinkedid;
                    }
                }
            }
            // Приоритет 4: По uniqueid из события
            if (!$callId && $callUniqueid && isset($globalsObj->calls[$callUniqueid])) {
                $callId = $globalsObj->calls[$callUniqueid];
                if ($linkedid) {
                    $globalsObj->callIdByLinkedid[$linkedid] = $callId;
                }
            }
            // Приоритет 5: По linkedid напрямую в calls
            if (!$callId && $linkedid && isset($globalsObj->calls[$linkedid])) {
                $callId = $globalsObj->calls[$linkedid];
            }
            // Приоритет 6: По базовому linkedid в calls (для очередей)
            if (!$callId && $linkedid && strpos((string)$linkedid, '.') !== false) {
                $linkedidBase = explode('.', (string)$linkedid)[0] ?? '';
                if ($linkedidBase !== '' && isset($globalsObj->calls[$linkedidBase])) {
                    $callId = $globalsObj->calls[$linkedidBase];
                    // Создаем маппинги
                    $globalsObj->callIdByLinkedid[$linkedid] = $callId;
                    $globalsObj->callIdByLinkedid[$linkedidBase] = $callId;
                    $globalsObj->callsByCallId[$callId] = $linkedidBase;
                }
            }
            // Приоритет 7: Поиск через helper
            if (!$callId) {
                $callId = $helper->findCallIdByIntNum($intNum, $globalsObj);
            }
            if (!$callId && !empty($globalsObj->transferHistory)) {
                foreach ($globalsObj->transferHistory as $transferData) {
                    if (!is_array($transferData)) {
                        continue;
                    }
                    if (($transferData['currentIntNum'] ?? null) !== $intNum) {
                        continue;
                    }
                    if (empty($transferData['call_id'])) {
                        continue;
                    }

                    $callId = $transferData['call_id'];
                    $historyLinkedid = $transferData['linkedid'] ?? null;
                    if ($historyLinkedid && !$linkedid) {
                        $linkedid = $historyLinkedid;
                    }
                    if ($historyLinkedid) {
                        $globalsObj->callIdByLinkedid[$historyLinkedid] = $callId;
                        $globalsObj->callsByCallId[$callId] = $historyLinkedid;
                    }
                    break;
                }
            }

            $resolvedLinkedid = callme_find_linkedid_for_int($intNum, $globalsObj, $linkedid);
            if ($resolvedLinkedid) {
                $linkedid = $resolvedLinkedid;
            }

            if (!$callId) {
                $helper->writeToLog(array(
                    'state' => $state,
                    'intNum' => $intNum,
                    'uniqueid' => $callUniqueid,
                    'linkedid' => $linkedid,
                ), 'CALLME_CARD_STATE without call_id');
                echo "\n-------------------------------------------------------------------\n\r";
                echo "\n\r";
                return;
            }

            if ($state === 'SHOW') {
                if ($linkedid) {
                    $globalsObj->callIdByLinkedid[$linkedid] = $callId;
                    $globalsObj->callsByCallId[$callId] = $linkedid;
                    $globalsObj->callIdByInt[$intNum] = $callId;
                    if ($routeType) {
                        $globalsObj->callRouteTypes[$linkedid] = $routeType;
                        if (!isset($globalsObj->ringingIntNums[$linkedid])) {
                            $globalsObj->ringingIntNums[$linkedid] = array();
                        }
                        if (!isset($globalsObj->ringingIntNums[$linkedid][$intNum])) {
                            $globalsObj->ringingIntNums[$linkedid][$intNum] = array();
                        }
                        $globalsObj->ringingIntNums[$linkedid][$intNum]['route_type'] = $routeType;
                    }
                    callme_show_card_for_int($linkedid, $intNum, $helper, $globalsObj);
                } else {
                    $helper->showInputCall($intNum, $callId);
                    $helper->writeToLog(array(
                        'intNum' => $intNum,
                        'call_id' => $callId,
                        'state' => $state,
                    ), 'CALLME_CARD_STATE show without linkedid');
                }
            } elseif ($state === 'HIDE') {
                if ($linkedid && isset($globalsObj->callShownCards[$linkedid][$intNum])) {
                    unset($globalsObj->callShownCards[$linkedid][$intNum]);
                    if (empty($globalsObj->callShownCards[$linkedid])) {
                        unset($globalsObj->callShownCards[$linkedid]);
                    }
                }
                if ($linkedid && isset($globalsObj->ringingIntNums[$linkedid][$intNum])) {
                    unset($globalsObj->ringingIntNums[$linkedid][$intNum]);
                    if (empty($globalsObj->ringingIntNums[$linkedid])) {
                        unset($globalsObj->ringingIntNums[$linkedid]);
                    }
                }
                $helper->hideInputCall($intNum, $callId);
                if (isset($globalsObj->callIdByInt[$intNum])) {
                    unset($globalsObj->callIdByInt[$intNum]);
                }
                if ($linkedid) {
                    callme_maybe_unset_route_type($linkedid, $globalsObj);
                }
            } else {
                $helper->writeToLog(array(
                    'state' => $state,
                    'intNum' => $intNum,
                    'payload' => $payload,
                ), 'CALLME_CARD_STATE unknown state');
            }

            echo "\n-------------------------------------------------------------------\n\r";
            echo "\n\r";
            return;
        }

        if ($variableName === 'CallMeFULLFNAME') {
            $relativePath = $rawValue;
            $fullUrl = "http://195.98.170.206/continuous/" . $relativePath;
            
            // Определяем linkedid для сохранения (приоритет: из маппинга, затем из события)
            $targetLinkedid = $linkedid ?: $callUniqueid;
            
            // ПРИОРИТЕТ 1: Сохраняем по linkedid (основной способ для transfer)
            if ($linkedid && $linkedid !== $callUniqueid) {
                // Если URL еще не сохранен по linkedid - сохраняем
                if (!isset($globalsObj->FullFnameUrls[$linkedid])) {
                    $globalsObj->FullFnameUrls[$linkedid] = $fullUrl;
                    $helper->writeToLog(array(
                        'uniqueid' => $callUniqueid,
                        'linkedid' => $linkedid,
                        'url' => $fullUrl,
                        'saved_by' => 'linkedid'
                    ), 'CallMeFULLFNAME saved by linkedid');
                } else {
                    // URL уже сохранен по linkedid - логируем, но не перезаписываем
                    $helper->writeToLog(array(
                        'uniqueid' => $callUniqueid,
                        'linkedid' => $linkedid,
                        'existing_url' => $globalsObj->FullFnameUrls[$linkedid],
                        'new_url' => $fullUrl,
                        'action' => 'skipped_duplicate'
                    ), 'CallMeFULLFNAME duplicate by linkedid - skipped');
                }
            }
            
            // ПРИОРИТЕТ 2: Сохраняем по uniqueid (fallback для обратной совместимости)
            if (!isset($globalsObj->FullFnameUrls[$callUniqueid])) {
                $globalsObj->FullFnameUrls[$callUniqueid] = $fullUrl;
                $helper->writeToLog(array(
                    'uniqueid' => $callUniqueid,
                    'linkedid' => $linkedid,
                    'url' => $fullUrl,
                    'saved_by' => 'uniqueid_fallback'
                ), 'CallMeFULLFNAME saved by uniqueid (fallback)');
            }
        }

        if (($variableName === 'ANSWER' || $variableName === 'DIALSTATUS')
            && strlen($rawValue) > 1) {
            $globalsObj->Dispositions[$callUniqueid] = "ANSWERED";
        } elseif ($variableName === 'ANSWER' && strlen($rawValue) == 0) {
            $globalsObj->Dispositions[$callUniqueid] = "NO ANSWER";
        }

        if (preg_match('/^\d+$/', $rawValue)) {
            $globalsObj->Durations[$callUniqueid] = $rawValue;
        }
        if (preg_match('/^[A-Z\ ]+$/', $rawValue)) {
            $globalsObj->Dispositions[$callUniqueid] = $rawValue;
        }

        $helper->writeToLog(array('FullFnameUrls'=>$globalsObj->FullFnameUrls,
                                  'Durations'=>$globalsObj->Durations,
                                  'Dispositions'=>$globalsObj->Dispositions),
            'New VarSetEvent - get FullFname,CallMeDURATION,CallMeDISPOSITION');
        echo "\n-------------------------------------------------------------------\n\r";
        echo "\n\r";
        },function (EventMessage $event) use ($globalsObj) {
        return
            $event instanceof VarSetEvent
            && (
                $event->getVariableName() === 'CALLME_CARD_STATE'
                || $event->getVariableName() === 'BRIDGEPEER'
                || (
                    ($event->getVariableName() === 'CallMeFULLFNAME'
                        || $event->getVariableName() === 'DIALSTATUS'
                        || $event->getVariableName()  === 'CallMeDURATION'
                        || $event->getVariableName()  === 'ANSWER')
                    && in_array($event->getKey("Uniqueid"), $globalsObj->uniqueids)
                )
            );
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
        $linkedid = $event->getKey("Linkedid");
        $destUniqueId = $event->getKey("DestUniqueID");
        $rawDialString = (string) $event->getKey("DialString");
        if ($rawDialString === '') {
            return;
        }

        $resolvedInt = callme_extract_internal_number(
            $rawDialString,
            $event->getKey("DestChannel"),
            $event->getKey("Channel"),
            $event->getKey("DestCallerIDNum"),
            $event->getKey("DestCallerIDName")
        );

        if (!$resolvedInt) {
            $helper->writeToLog(array(
                'uniqueid' => $callUniqueid,
                'linkedid_raw' => $linkedid,
                'destUniqueId' => $destUniqueId,
                'dialString' => $rawDialString,
                'channel' => $event->getKey("Channel"),
                'destChannel' => $event->getKey("DestChannel"),
                'destCallerIdNum' => $event->getKey("DestCallerIDNum"),
                'destCallerIdName' => $event->getKey("DestCallerIDName"),
            ), 'DialBegin: unable to resolve internal number');
            return;
        }

        $exten = $resolvedInt;

        $callerNumberRaw = $event->getCallerIdNum();

        callme_handle_dial_begin_common(
            $event,
            $callUniqueid,
            $destUniqueId,
            $linkedid,
            $exten,
            $rawDialString,
            $callerNumberRaw,
            $helper,
            $globalsObj
        );
        echo "\n-------------------------------------------------------------------\n\r";
        echo "\n\r";

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

$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper, $globalsObj) {
        if ($event->getName() !== 'Dial') {
            return;
        }

        $subEvent = $event->getKey('SubEvent');
        if ($subEvent === 'Begin') {
            $callUniqueid = $event->getKey('UniqueID') ?? $event->getKey('Uniqueid');
            if (!$callUniqueid) {
                return;
            }
            $destUniqueId = $event->getKey('DestUniqueID') ?? $event->getKey('DestUniqueid') ?? null;
            $linkedid = $event->getKey('Linkedid') ?? $event->getKey('LinkedID') ?? null;

            $rawDialString = (string) ($event->getKey('Dialstring') ?? $event->getKey('DialString') ?? '');
            if ($rawDialString === '') {
                return;
            }

            $resolvedInt = callme_extract_internal_number(
                $rawDialString,
                $event->getKey('Destination'),
                $event->getKey('Channel'),
                $event->getKey('DestCallerIDNum'),
                $event->getKey('DestCallerIDName')
            );

            if (!$resolvedInt) {
                $helper->writeToLog(array(
                    'uniqueid' => $callUniqueid,
                    'linkedid_raw' => $linkedid,
                    'destUniqueId' => $destUniqueId,
                    'dialString' => $rawDialString,
                    'channel' => $event->getKey("Channel"),
                    'destChannel' => $event->getKey("DestChannel"),
                    'destCallerIdNum' => $event->getKey("DestCallerIDNum"),
                    'destCallerIdName' => $event->getKey("DestCallerIDName"),
                ), 'Dial legacy: unable to resolve internal number');
                return;
            }

            if ($linkedid && isset($globalsObj->originateCalls[$linkedid])) {
                $isRealOriginate = $globalsObj->originateCalls[$linkedid]['is_originate'] ?? false;
                if ($isRealOriginate) {
                    return;
                }
            }

            callme_handle_dial_begin_common(
                $event,
                $callUniqueid,
                $destUniqueId,
                $linkedid,
                $resolvedInt,
                $rawDialString,
                $event->getKey('CallerIDNum'),
                $helper,
                $globalsObj
            );
        } elseif ($subEvent === 'End') {
            $callUniqueid = $event->getKey('UniqueID') ?? $event->getKey('Uniqueid');
            if (!$callUniqueid) {
                return;
            }
            if (!in_array($callUniqueid, $globalsObj->uniqueids)) {
                return;
            }
            callme_handle_dial_end_common($event, $callUniqueid, $helper, $globalsObj);
        }
    },
    function (EventMessage $event) {
        return $event->getName() === 'Dial';
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
                } else if ($event->getContext() === 'from-trunk' and $event->getDialStatus() === "ANSWER") {
                    $intNum = $globalsObj->intNums[$callLinkedid];
                    $globalsObj->intNums[$callLinkedid] = is_numeric($event->getDestCallerIDNum()) ? $event->getDestCallerIDNum() : $event->getDestCallerIDName();
                    if (strlen($globalsObj->intNums[$callLinkedid]) > 3) {
                        $globalsObj->intNums[$callLinkedid] = $intNum;
                    }
                    $globalsObj->Dispositions[$callLinkedid] = $event->getDialStatus();
                    $globalsObj->Answers[$callLinkedid] = time();
                } else if ($event->getContext() === 'office' and strpos($event->getKey("Channel"), "ocal/") and $event->getDialStatus() === "ANSWER") {
                    $globalsObj->intNums[$callLinkedid] = is_numeric($event->getDestCallerIDNum()) ? $event->getDestCallerIDNum() : $event->getDestCallerIDName();
                    $globalsObj->intNums[$callLinkedid] = substr($globalsObj->intNums[$callLinkedid], 0, 4);
                }
                $helper->writeToLog($event->getRawContent()."\n\r");

                callme_handle_dial_end_common($event, $callLinkedid, $helper, $globalsObj);
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

                // Определяем linkedid ДО поиска URL (нужен для правильного поиска)
                $linkedid = $globalsObj->uniqueidToLinkedid[$callLinkedid] ?? $callLinkedid;
                
                // Используем универсальную функцию поиска URL записи
                $FullFname = callme_resolve_recording_url($callLinkedid, $linkedid, $globalsObj, $helper);
                
//                $FullFname = "";
//              Длинна разговора, пусть будет всегда не меньше 1
                $CallDuration = $globalsObj->Durations[$callLinkedid];
                if (!empty($globalsObj->Answers[$callLinkedid])) {
                    $CallDuration = time() - $globalsObj->Answers[$callLinkedid];
                } elseif (!empty($globalsObj->transferHistory[$callLinkedid]['answer_timestamp'])) {
                    $answerTs = $globalsObj->transferHistory[$callLinkedid]['answer_timestamp'];
                    $CallDuration = max(0, time() - $answerTs);
                    $globalsObj->Answers[$callLinkedid] = $answerTs;
                }
//                $CallDuration = $CallDuration ? $CallDuration : 1;

                $CallDisposition = strtoupper((string)($globalsObj->Dispositions[$callLinkedid] ?? ''));
                $call_id = $globalsObj->calls[$callLinkedid] ?? ($globalsObj->callIdByLinkedid[$linkedid] ?? null);
                $direction = 'inbound';
                if ($linkedid && isset($globalsObj->callDirections[$linkedid])) {
                    $direction = $globalsObj->callDirections[$linkedid];
                }
                $isOriginate = false;
                if ($linkedid && isset($globalsObj->originateCalls[$linkedid])) {
                    $isOriginate = $globalsObj->originateCalls[$linkedid]['is_originate'] ?? false;
                    if ($isOriginate) {
                        $direction = 'outbound';
                    }
                }
                if ($direction === 'inbound' && $CallDisposition === 'CANCEL') {
                    $CallDisposition = 'NO ANSWER';
                    $globalsObj->Dispositions[$callLinkedid] = $CallDisposition;
                }
                if (!in_array($CallDisposition, array('ANSWER', 'ANSWERED'), true)) {
                    if (!empty($globalsObj->transferHistory[$callLinkedid]['answer_timestamp'])) {
                        $CallDisposition = 'ANSWERED';
                        $globalsObj->Dispositions[$callLinkedid] = $CallDisposition;
                    } elseif ($linkedid && !empty($globalsObj->callCrmData[$linkedid]['answer_int_num'])) {
                        $CallDisposition = 'ANSWERED';
                        $globalsObj->Dispositions[$callLinkedid] = $CallDisposition;
                    }
                }
                
                // FALLBACK: Если не нашли call_id - пропускаем обработку
                if (empty($call_id)) {
                    $helper->writeToLog("No call_id for Uniqueid $callLinkedid, skipping HangupEvent", 'HangupEvent FALLBACK');
                    unset($globalsObj->ringingIntNums[$linkedid]);
                    unset($globalsObj->callShownCards[$linkedid]);
                    return;
                }
                
                // Определяем ответственного: используем внутренний номер, закреплённый за каналом
                $CallIntNum = $globalsObj->intNums[$callLinkedid] ?? ($linkedid ? ($globalsObj->intNums[$linkedid] ?? null) : null);

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
                
                $statusCode = $helper->getStatusCodeFromDisposition($CallDisposition);

                $batchHide = array('result' => false, 'targets' => array());
                if ($linkedid) {
                    $batchHide = callme_hide_cards_batch($linkedid, $call_id, $helper, $globalsObj);
                }

                $primaryTarget = null;
                if (!empty($batchHide['targets'])) {
                    $primaryTarget = $batchHide['targets'][0];
                }

                $finishIntNum = $CallIntNum;
                $finishUserId = null;
                if (!$finishIntNum && isset($globalsObj->transferHistory[$callLinkedid]['currentIntNum'])) {
                    $finishIntNum = (string)$globalsObj->transferHistory[$callLinkedid]['currentIntNum'];
                }
                if (!$finishIntNum && $linkedid && !empty($globalsObj->callCrmData[$linkedid]['answer_int_num'])) {
                    $finishIntNum = (string)$globalsObj->callCrmData[$linkedid]['answer_int_num'];
                }
                if ($primaryTarget) {
                    $finishIntNum = $primaryTarget['int_num'] ?? $finishIntNum;
                    $finishUserId = $primaryTarget['user_id'] ?? null;
                }
                if ($finishIntNum && $finishUserId === null) {
                    $finishUserId = $helper->getUSER_IDByIntNum($finishIntNum);
                }

                $finishResult = false;
                if ($finishIntNum) {
                    $finishResult = $helper->finishCall($call_id, $finishIntNum, $CallDuration, $statusCode, $finishUserId);
                    $helper->writeToLog(array(
                        'finishIntNum' => $finishIntNum,
                        'finishUserId' => $finishUserId,
                        'statusCode' => $statusCode,
                        'result' => $finishResult,
                        'duration' => $CallDuration,
                        'batchTargets' => $batchHide['targets'],
                    ), 'Call finished after batch hide');
                    echo "call finished immediately in B24, status: $statusCode\n";
                } else {
                    $helper->writeToLog(array(
                        'call_id' => $call_id,
                        'statusCode' => $statusCode,
                        'duration' => $CallDuration,
                        'reason' => 'Finish skipped: no internal number determined',
                    ), 'Call finish skipped');
                }

                if ($linkedid) {
                    unset($globalsObj->callShownCards[$linkedid]);
                    unset($globalsObj->ringingIntNums[$linkedid]);
                    if (isset($globalsObj->ringOrder[$linkedid])) {
                        unset($globalsObj->ringOrder[$linkedid]);
                    }
                    callme_maybe_unset_route_type($linkedid, $globalsObj);
                }

                $isAnswered = in_array(strtoupper($CallDisposition), array('ANSWER', 'ANSWERED'), true);
                if ($isAnswered && $linkedid && !empty($globalsObj->callCrmData[$linkedid])) {
                    $crmInfo = $globalsObj->callCrmData[$linkedid];
                    $crmEntityType = $crmInfo['entity_type'] ?? null;
                    $crmEntityId = $crmInfo['entity_id'] ?? null;
                    $finalIntNum = $finishIntNum ?: ($crmInfo['answer_int_num'] ?? $CallIntNum);
                    $finalUserId = $finalIntNum ? $helper->getUSER_IDByIntNum($finalIntNum) : null;
                    if (!$finalUserId) {
                        $finalUserId = $helper->getFallbackResponsibleUserId();
                    }

                    if ($crmEntityType && $crmEntityId && $finalUserId) {
                        $updateResult = $helper->setCrmResponsible($crmEntityType, $crmEntityId, $finalUserId);
                        $globalsObj->callCrmData[$linkedid]['current_responsible_user_id'] = $finalUserId;
                        $globalsObj->callCrmData[$linkedid]['final_int_num'] = $finalIntNum;
                        $globalsObj->callCrmData[$linkedid]['crm_responsible_update'] = $updateResult;
                        $helper->writeToLog(array(
                            'entityType' => $crmEntityType,
                            'entityId' => $crmEntityId,
                            'finalIntNum' => $finalIntNum,
                            'finalUserId' => $finalUserId,
                            'updateResult' => $updateResult,
                        ), 'CRM responsible updated after call');
                    }
                }
                
                // Upload with async background process to avoid blocking main CallMeIn process
                $uploadCmd = sprintf(
                    'php %s/upload_recording_async.php %s %s %s %s %s > /dev/null 2>&1 &',
                    __DIR__,
                    escapeshellarg((string)($call_id ?? '')),
                    escapeshellarg((string)($FullFname ?? '')),
                    escapeshellarg((string)($CallIntNum ?? '')),
                    escapeshellarg((string)($CallDuration ?? '')),
                    escapeshellarg((string)($CallDisposition ?? ''))
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
                if ($linkedid && isset($globalsObj->callIdByLinkedid[$linkedid])) {
                    unset($globalsObj->callIdByLinkedid[$linkedid]);
                }
                if ($call_id && isset($globalsObj->callsByCallId[$call_id])) {
                    unset($globalsObj->callsByCallId[$call_id]);
                }
                if ($linkedid && isset($globalsObj->callCrmData[$linkedid])) {
                    unset($globalsObj->callCrmData[$linkedid]);
                }
                if ($linkedid && isset($globalsObj->callDirections[$linkedid])) {
                    unset($globalsObj->callDirections[$linkedid]);
                }
                if ($linkedid) {
                    callme_maybe_unset_route_type($linkedid, $globalsObj);
                }
                if (isset($globalsObj->transferHistory[$callLinkedid])) {
                    unset($globalsObj->transferHistory[$callLinkedid]);
                }
                foreach ($globalsObj->transferHistory as $transferUniqueid => $transferData) {
                    if (($transferData['call_id'] ?? null) === $call_id) {
                        unset($globalsObj->transferHistory[$transferUniqueid]);
                    }
                }
                
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

// 1. VarSetEvent (CallMeLINKEDID) - фиксируем маппинг UniqueID ↔ linkedid
$pamiClient->registerEventListener(
    function (EventMessage $event) use ($helper, $globalsObj) {
        $uniqueid = $event->getKey("Uniqueid");
        $linkedid = $event->getValue(); // Значение переменной = Linkedid
        $channel = $event->getChannel();
        $linkedidFromEvent = $event->getKey("Linkedid"); // Linkedid из события AMI

        if (empty($uniqueid) || empty($linkedid)) {
            $helper->writeToLog([
                'uniqueid' => $uniqueid,
                'linkedid' => $linkedid,
                'channel' => $channel,
                'reason' => 'Empty uniqueid or linkedid - skipping'
            ], 'LINKEDID: Invalid event - skipped');
            return;
        }

        $oldLinkedid = $globalsObj->uniqueidToLinkedid[$uniqueid] ?? null;
        $isUpdate = $oldLinkedid !== null && $oldLinkedid !== $linkedid;
        
        // Создаем/обновляем маппинг uniqueid → linkedid
        $globalsObj->uniqueidToLinkedid[$uniqueid] = $linkedid;
        if (!isset($globalsObj->uniqueidToLinkedid[$linkedid])) {
            $globalsObj->uniqueidToLinkedid[$linkedid] = $linkedid;
        }
        
        // СИНХРОНИЗАЦИЯ: Если call_id уже привязан к старому linkedid - переносим на новый
        if ($isUpdate && $oldLinkedid && isset($globalsObj->callIdByLinkedid[$oldLinkedid])) {
            $callId = $globalsObj->callIdByLinkedid[$oldLinkedid];
            $globalsObj->callIdByLinkedid[$linkedid] = $callId;
            $globalsObj->callsByCallId[$callId] = $linkedid;
            
            $helper->writeToLog([
                'uniqueid' => $uniqueid,
                'old_linkedid' => $oldLinkedid,
                'new_linkedid' => $linkedid,
                'call_id' => $callId,
                'action' => 'call_id_mapping_synced'
            ], 'LINKEDID: Call_id mapping synchronized to new linkedid');
        }
        
        // СИНХРОНИЗАЦИЯ: Если call_id уже привязан к uniqueid - связываем с linkedid
        if (isset($globalsObj->calls[$uniqueid])) {
            $callId = $globalsObj->calls[$uniqueid];
            if (!isset($globalsObj->callIdByLinkedid[$linkedid])) {
                $globalsObj->callIdByLinkedid[$linkedid] = $callId;
                $globalsObj->callsByCallId[$callId] = $linkedid;
            }
        }

        $helper->writeToLog([
            'uniqueid' => $uniqueid,
            'linkedid' => $linkedid,
            'linkedid_from_event' => $linkedidFromEvent,
            'channel' => $channel,
            'is_update' => $isUpdate,
            'old_linkedid' => $oldLinkedid,
            'has_call_id_mapping' => isset($globalsObj->callIdByLinkedid[$linkedid])
        ], 'LINKEDID: Mapping updated' . ($isUpdate ? ' (updated)' : ' (new)'));
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
        $globalsObj->callDirections[$linkedid] = 'outbound';
        
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
                if (isset($globalsObj->callDirections[$linkedid])) {
                    unset($globalsObj->callDirections[$linkedid]);
                }
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
            $fullUrl = "http://195.98.170.206/continuous/" . $relativePath;
            
            // Сохраняем URL записи (если еще не сохранен)
            if (empty($globalsObj->originateCalls[$linkedid]['record_url'])) {
                $globalsObj->originateCalls[$linkedid]['record_url'] = $fullUrl;
                $globalsObj->originateCalls[$linkedid]['last_activity'] = time();
                
                $helper->writeToLog([
                    'uniqueid' => $uniqueid,
                    'linkedid' => $linkedid,
                    'record_url' => $fullUrl,
                    'saved_by' => 'linkedid_originate'
                ], 'ORIGINATE: Recording URL received and saved');
            } else {
                // URL уже сохранен - логируем, но не перезаписываем
                $helper->writeToLog([
                    'uniqueid' => $uniqueid,
                    'linkedid' => $linkedid,
                    'existing_url' => $globalsObj->originateCalls[$linkedid]['record_url'],
                    'new_url' => $fullUrl,
                    'action' => 'skipped_duplicate'
                ], 'ORIGINATE: Recording URL duplicate - skipped');
            }
        } else {
            // Логируем если linkedid не найден или не Originate-вызов
            $helper->writeToLog([
                'uniqueid' => $uniqueid,
                'linkedid' => $linkedid,
                'is_originate' => $linkedid && isset($globalsObj->originateCalls[$linkedid]),
                'reason' => $linkedid ? 'Not an Originate call' : 'Linkedid not found'
            ], 'ORIGINATE: CallMeFULLFNAME received but not processed');
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
                        escapeshellarg((string)($data['call_id'] ?? '')),
                        escapeshellarg((string)($data['record_url'] ?? '')),
                        escapeshellarg((string)($data['intNum'] ?? '')),
                        escapeshellarg((string)($duration ?? '')),
                        escapeshellarg('ANSWERED')
                    );
                    exec($uploadCmd);
                    
                    $helper->writeToLog([
                        'call_id' => $data['call_id'],
                        'url' => $data['record_url']
                    ], 'ORIGINATE: Started async upload');
                }
                
                unset($globalsObj->originateCalls[$linkedid]);
                if (isset($globalsObj->callDirections[$linkedid])) {
                    unset($globalsObj->callDirections[$linkedid]);
                }
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

