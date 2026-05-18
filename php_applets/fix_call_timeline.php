<?php
/**
 * Исправление записи звонка в CRM-таймлайне (ядро Б24, не REST).
 *
 * CLI:
 *   php fix_call_timeline.php --call-id=externalCall.xxx --duration=60 --record-url=http://...
 *   php fix_call_timeline.php --activity-id=147550 --duration=60 --record-url=http://...
 *   php fix_call_timeline.php --contact-id=42607 --date=2026-05-18 --time=18:11 --duration=60 --record-url=http://...
 */

define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);

$docRoot = realpath(__DIR__ . '/../../../..');
if (!$docRoot || !is_file($docRoot . '/bitrix/modules/main/include/prolog_before.php')) {
    $docRoot = realpath(__DIR__ . '/../../..');
}
$_SERVER['DOCUMENT_ROOT'] = $docRoot;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Crm\Activity\Provider\Call as CallProvider;
use Bitrix\Crm\Activity\StatisticsStream;
use Bitrix\Main\Loader;
use Bitrix\Voximplant\Rest\Helper as ViRestHelper;
use Bitrix\Voximplant\StatisticTable;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$options = getopt('', [
    'call-id:',
    'activity-id:',
    'contact-id:',
    'date:',
    'time:',
    'duration:',
    'status-code:',
    'record-url:',
    'user-id:',
    'dry-run',
]);

$duration = isset($options['duration']) ? (int)$options['duration'] : 60;
$statusCode = isset($options['status-code']) ? (string)$options['status-code'] : '200';
$recordUrl = isset($options['record-url']) ? trim((string)$options['record-url']) : '';
$dryRun = array_key_exists('dry-run', $options);

if (!Loader::includeModule('crm') || !Loader::includeModule('voximplant')) {
    fwrite(STDERR, "crm or voximplant module not loaded\n");
    exit(1);
}

$callId = isset($options['call-id']) ? trim((string)$options['call-id']) : '';
$activityId = isset($options['activity-id']) ? (int)$options['activity-id'] : 0;

if ($activityId > 0 && $callId === '') {
    $origin = (string)(\CCrmActivity::GetByID($activityId, false)['ORIGIN_ID'] ?? '');
    if (strpos($origin, 'VI_') === 0) {
        $callId = substr($origin, 3);
    }
}

if ($callId === '' && !empty($options['contact-id']) && !empty($options['date']) && !empty($options['time'])) {
    $contactId = (int)$options['contact-id'];
    $from = $options['date'] . ' ' . $options['time'] . ':00';
    $to = $options['date'] . ' ' . $options['time'] . ':59';

    $res = \CCrmActivity::GetList(
        ['ID' => 'DESC'],
        [
            'PROVIDER_ID' => CallProvider::ACTIVITY_PROVIDER_ID,
            'PROVIDER_TYPE_ID' => CallProvider::ACTIVITY_PROVIDER_TYPE_CALL,
            'BINDINGS' => [
                ['OWNER_TYPE_ID' => \CCrmOwnerType::Contact, 'OWNER_ID' => $contactId],
            ],
            '>=START_TIME' => $from,
            '<=START_TIME' => $to,
        ],
        false,
        false,
        ['ID', 'ORIGIN_ID', 'START_TIME', 'SUBJECT', 'COMPLETED', 'SETTINGS']
    );

    while ($row = $res->Fetch()) {
        $activityId = (int)$row['ID'];
        $origin = (string)($row['ORIGIN_ID'] ?? '');
        if ($origin !== '' && strpos($origin, 'VI_') === 0) {
            $callId = substr($origin, 3);
        } elseif ($origin === '') {
            $stat = StatisticTable::getList([
                'filter' => [
                    '=CRM_ACTIVITY_ID' => $activityId,
                ],
                'limit' => 1,
            ])->fetch();
            if ($stat) {
                $callId = (string)$stat['CALL_ID'];
            }
        }
        if ($callId !== '') {
            break;
        }
    }
}

if ($callId === '') {
    $stat = StatisticTable::getList([
        'filter' => [
            '=CRM_ENTITY_TYPE' => 'CONTACT',
            '=CRM_ENTITY_ID' => (int)($options['contact-id'] ?? 0),
            '>=CALL_START_DATE' => ($options['date'] ?? '') . ' 18:11:00',
            '<=CALL_START_DATE' => ($options['date'] ?? '') . ' 18:13:00',
        ],
        'order' => ['ID' => 'DESC'],
        'limit' => 5,
    ]);
    while ($row = $stat->fetch()) {
        if ((int)($row['CALL_FAILED_CODE'] ?? 0) === 304 || (int)($row['CALL_DURATION'] ?? 0) === 0) {
            $callId = (string)$row['CALL_ID'];
            $activityId = (int)($row['CRM_ACTIVITY_ID'] ?? 0);
            break;
        }
    }
}

if ($callId === '') {
    fwrite(STDERR, "Call not found. Use --call-id or --activity-id or --contact-id+--date+--time\n");
    exit(1);
}

$stat = StatisticTable::getByCallId($callId);
if (!$stat) {
    fwrite(STDERR, "Statistic record not found for CALL_ID={$callId}\n");
    exit(1);
}

if ($activityId <= 0) {
    $activityId = (int)($stat['CRM_ACTIVITY_ID'] ?? 0);
}
if ($activityId <= 0) {
    $activityId = (int)\CCrmActivity::GetIDByOrigin('VI_' . $callId);
}

$portalUserId = isset($options['user-id'])
    ? (int)$options['user-id']
    : (int)($stat['PORTAL_USER_ID'] ?? 0);

echo "CALL_ID: {$callId}\n";
echo "STATISTIC_ID: {$stat['ID']}\n";
echo "ACTIVITY_ID: {$activityId}\n";
echo "Before: CODE={$stat['CALL_FAILED_CODE']} DURATION={$stat['CALL_DURATION']} RECORD=" . ($stat['CALL_RECORD_ID'] ?: 'none') . "\n";

if ($dryRun) {
    echo "DRY RUN — no changes\n";
    exit(0);
}

$startDate = $stat['CALL_START_DATE'];
$endTime = $startDate;
if ($duration > 0 && $startDate instanceof \Bitrix\Main\Type\DateTime) {
    $endTime = clone $startDate;
    $endTime->add('+' . $duration . ' seconds');
}

$statUpdate = [
    'CALL_FAILED_CODE' => $statusCode,
    'CALL_DURATION' => $duration,
    'CALL_STATUS' => $duration > 0 ? 1 : 0,
    'PORTAL_USER_ID' => $portalUserId > 0 ? $portalUserId : $stat['PORTAL_USER_ID'],
];
StatisticTable::update($stat['ID'], $statUpdate);

$stat = array_merge($stat, $statUpdate);

if ($activityId > 0) {
    $settings = \CCrmActivity::GetByID($activityId, false)['SETTINGS'] ?? [];
    if (is_string($settings)) {
        $settings = unserialize($settings, ['allowed_classes' => false]) ?: [];
    }
    if (!is_array($settings)) {
        $settings = [];
    }
    unset($settings['MISSED_CALL']);

    $activityUpdate = [
        'ORIGIN_ID' => 'VI_' . $callId,
        'COMPLETED' => 'Y',
        'END_TIME' => $endTime,
        'DEADLINE' => $endTime,
        'RESULT_STREAM' => ($statusCode === '200')
            ? StatisticsStream::Outgoing
            : StatisticsStream::Missing,
        'SETTINGS' => $settings,
    ];

    if (!\CCrmActivity::Update($activityId, $activityUpdate, false)) {
        fwrite(STDERR, 'CCrmActivity::Update failed: ' . \CCrmActivity::GetLastErrorMessage() . "\n");
    }

    $statForAttach = $stat;
    $statForAttach['INCOMING'] = CVoxImplantMain::CALL_OUTGOING;
    $statForAttach['CALL_FAILED_CODE'] = $statusCode;
    $statForAttach['CALL_DURATION'] = $duration;
    \CVoxImplantCrmHelper::attachCallToActivity($statForAttach, $activityId);
}

if ($recordUrl !== '') {
    $attach = ViRestHelper::attachRecordWithUrl($callId, $recordUrl);
    if (!$attach->isSuccess()) {
        fwrite(STDERR, 'attachRecordWithUrl: ' . implode('; ', $attach->getErrorMessages()) . "\n");
        exit(1);
    }
    echo "Record attached, FILE_ID=" . ($attach->getData()['FILE_ID'] ?? '?') . "\n";
}

$statAfter = StatisticTable::getByCallId($callId);
echo "After: CODE={$statAfter['CALL_FAILED_CODE']} DURATION={$statAfter['CALL_DURATION']} RECORD_ID=" . ($statAfter['CALL_RECORD_ID'] ?? 'none') . "\n";
echo "Done.\n";
