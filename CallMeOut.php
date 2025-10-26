#!/usr/bin/php
<?php
/**
* CallMe outgoing calls handler
* PHP Version 8.2+
**/

require __DIR__ . '/vendor/autoload.php';

$helper = new HelperFuncs();
$callami = new CallAMI();

//настройки
$tech = $helper->getConfig('tech');
$authToken = $helper->getConfig('authToken');
$context = $helper->getConfig('context');


//данные в запросе
$request = $_REQUEST;

 $helper->writeToLog(var_dump($_REQUEST), "");

//проверяем не пустой ли request
if(!empty($request)){
    //логируем запрос
    echo "----------------------------------------------- \n";
    $helper->writeToLog($request,'request');
    if (!empty($request['action'])){ //есть ли action
        switch ($request['action']) {
            case 'sendcall2b24': //отправляем инфу о звонке в битрикс
                $helper->writeToLog($request,'sendcall2b24 action');
                if (empty($request['call_id']) || empty($request['FullFname']) || empty($request['CallIntNum']) || empty($request['CallDuration']) || empty($request['CallDisposition'])){
                    $helper->writeToLog(null,'sendcall2b24 error in params');
                    exit('error in params');
                }
                $resultFromB24 = $helper->uploadRecordedFile($request['call_id'], $request['FullFname'],$request['CallIntNum'],$request['CallDuration'],$request['CallDisposition']);
                //логируем, что нам рассказал битрикс в ответ на наш реквест
                $helper->writeToLog($resultFromB24,'sendcall2b24 upload call status');
            break;
            default:
                $helper->writeToLog($request['event'] ?? 'no event','action wrong');
                break;
            }
    } else {
        //проверяем авторизацию по токену
        if ($request['auth']['application_token'] === $authToken) {
            $intNum = $helper->getIntNumByUSER_ID($request['data']['USER_ID']);
            $helper->writeToLog($request['data']['USER_ID'],'USER_ID');
            $helper->writeToLog($intNum,'intnum');
            $CalledNumber = $request['data']['PHONE_NUMBER_INTERNATIONAL'];
            if (substr($CalledNumber,0,1) == "7") {
                $CalledNumber = "8".substr($CalledNumber,1);
            } else if (substr($CalledNumber,0,1) == "+"){
                $CalledNumber = "8".substr($CalledNumber,2);
            }
            $helper->writeToLog($CalledNumber,'CalledNumber');
            $CallID = $request['data']['CALL_ID'];
            $helper->writeToLog($CallID,'CALL_ID');
            //дергаем ивен, переданный из битрикса
            switch ($request['event']) {
                case 'ONEXTERNALCALLSTART': //внешний звонок
                    $helper->writeToLog($request['event'],'ONEXTERNALCALLSTART');
                    //отправляем на астериск
                    $response = $callami->OriginateCall($intNum, $CalledNumber, $tech, $CallID, $context);
                    $helper->writeToLog($response,'PAMI response');
                    break; 
                default:
                    $helper->writeToLog($request['event'],'f');
                    break;
                }
            } else $helper->writeToLog($request,'Not authorized!!!');
    }
} else exit('error in params'); //если пустой request то отдаем ошибку в параметрах
