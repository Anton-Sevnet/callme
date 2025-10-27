<?php
/**
* Helpers class for working with API  
* @author Автор: ViStep.RU
* @version 1.0
* @copyright: ViStep.RU (admin@vistep.ru)
* PHP Version 8.2+
**/

class HelperFuncs {

	/**
	 * Get Internal number by using USER_ID.
	 *
	 * @param int $userid
	 *
	 * @return int internal user number
	 */
	public function getIntNumByUSER_ID($userid){
        $this->writeToLog(NULL, 'getIntNumByUSER_ID');
	    $result = $this->getBitrixApi(array("ID" => $userid), 'user.get');
        $this->writeToLog($result, 'getIntNumByUSER_ID');
	    if ($result){

	        return $result['result'][0]['UF_PHONE_INNER'];
	    } else {
	        return false;
	    }

	}

	/**
	 * Get USER_ID by Internal number.
	 *
	 * @param int $intNum
	 *
	 * @return int user id
	 */
	public function getUSER_IDByIntNum($intNum){ 
	    $result = $this->getBitrixApi(array('FILTER' => array ('UF_PHONE_INNER' => $intNum,),), 'user.get');
	    if ($result){
	        return $result['result'][0]['ID'];
	    } else {
	        return false;
	    }
	}

	/**
	 * Upload recorded file to Bitrix24.
	 *
	 * @param string $call_id
	 * @param string $recordingfile
	 * @param string $duration
	 * @param string $intNum
	 *
	 * @return int internal user number
	 */
	public function uploadRecordedFile($call_id, $recordedfile, $intNum, $duration, $disposition){
		switch ($disposition) {
            case 'ANSWER':
            case 'ANSWERED':
		 		$sipcode = 200; // успешный звонок
		 		break;
            case 'NO ANSWER':
		 		$sipcode = 304; // нет ответа
		 		break;
		 	case 'BUSY':
				$sipcode =  486; //  занято
		 		break;		 	
		 	default:
		 		if(empty($disposition)) $sipcode = 304; //если пустой пришел, то поставим неотвечено
				else $sipcode = 603; // отклонено, когда все остальное
		 		break;
		}

	    $result = $this->getBitrixApi(array(
			    	'USER_PHONE_INNER' => $intNum,
					'CALL_ID' => $call_id, //идентификатор звонка из результатов вызова метода telephony.externalCall.register
					'STATUS_CODE' => $sipcode, 
//					'CALL_START_DATE' => date("Y-m-d H:i:s"),
					'DURATION' => $duration, //длительность звонка в секундах
					'RECORD_URL' => $recordedfile //url на запись звонка для сохранения в Битрикс24
					), 'telephony.externalcall.finish');
	    if ($result){
	        return $result;
	    } else {
	        return false;
	    }
    
	}

	/**
	 * Upload recorded file with retry logic
	 * Waits for MP3 conversion and retries up to 5 times with increasing delays
	 *
	 * @param string $call_id
	 * @param string $recordedfile URL of the recording
	 * @param string $intNum internal number
	 * @param string $duration call duration
	 * @param string $disposition call disposition
	 *
	 * @return array|false Result from Bitrix24 API or false
	 */
	public function uploadRecordedFileWithRetry($call_id, $recordedfile, $intNum, $duration, $disposition){
		$maxAttempts = 5;
		$initialDelay = 5;
		
		for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
			$delay = $initialDelay * $attempt;
			
			$this->writeToLog(array(
				'attempt' => $attempt,
				'delay' => $delay,
				'url' => $recordedfile
			), "Recording upload attempt $attempt/$maxAttempts, waiting {$delay}s");
			
			// Wait before checking
			sleep($delay);
			
			// Check if file is available via HTTP HEAD request
			$fileAvailable = $this->checkFileAvailability($recordedfile);
			
			if ($fileAvailable) {
				$this->writeToLog("File available, uploading to Bitrix24", "Recording upload attempt $attempt");
				
				// Upload using both methods as in original code
				$result1 = $this->uploadRecordedFile($call_id, $recordedfile, $intNum, $duration, $disposition);
				$result2 = $this->uploadRecorderedFileTruth($call_id, $recordedfile, $recordedfile);
				
				$this->writeToLog(array(
					'finish_result' => $result1,
					'attach_result' => $result2
				), "Recording uploaded successfully on attempt $attempt");
				
				return $result1;
			} else {
				$this->writeToLog("File not available yet", "Recording upload attempt $attempt/$maxAttempts");
				
				if ($attempt === $maxAttempts) {
					$this->writeToLog("Failed to upload recording after $maxAttempts attempts", "Recording upload FAILED");
					// Still try to finish the call even without recording
					return $this->uploadRecordedFile($call_id, '', $intNum, $duration, $disposition);
				}
			}
		}
		
		return false;
	}

	/**
	 * Check if file is available via HTTP HEAD request
	 *
	 * @param string $url
	 *
	 * @return bool
	 */
	private function checkFileAvailability($url){
		if (empty($url)) {
			return false;
		}
		
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		
		return ($httpCode === 200);
	}

//    загрузка аудиофайла
	public function uploadRecorderedFileTruth($call_id, $recordedfile, $recordUrl){
        $result = $this->getBitrixApi(array(
            'CALL_ID' => $call_id, //идентификатор звонка из результатов вызова метода telephony.externalCall.register
            'RECORD_URL' => $recordUrl, //url на запись звонка для сохранения в Битрикс24
            'FILENAME' => $recordedfile
        ), 'telephony.externalCall.attachRecord');
        if ($result){
            return $result;
        } else {
            return false;
        }
    }

	/**
	 * Run Bitrix24 REST API method telephony.externalcall.register.json  
	 *
	 * @param int $exten (${EXTEN} from the Asterisk server, i.e. internal number)
	 * @param int $callerid (${CALLERID(num)} from the Asterisk server, i.e. number which called us)
	 *
	 * @return array  like this:
	 * Array
	 *	(
	 *	    [result] => Array
	 *	        (
	 *	            [CALL_ID] => externalCall.cf1649fa0f4479870b76a0686f4a7058.1513888745
	 *	            [CRM_CREATED_LEAD] => 
	 *	            [CRM_ENTITY_TYPE] => LEAD
	 *	            [CRM_ENTITY_ID] => 24
	 *	        )
	 *	)
	 * We need CALL_ID and CRM data
	 */
	public function runInputCall($exten, $callerid, $line, $crm_source=null){
	    if (substr($callerid,0,1) == "9" and !(strlen($callerid) == 10)){
            $callerid = substr($callerid, 1);
        }
	    if (strlen($callerid) == 7){
            $callerid = "8342".$callerid;
        }
	    $data = array(
            'USER_PHONE_INNER' => $exten,
            //'USER_ID' => $argv[1],
            'PHONE_NUMBER' => "+7".substr($callerid, -10),
            'LINE_NUMBER' => $line,
            'TYPE' => 2,
            'CRM_CREATE' => 0,
            'SHOW' => 1,
        );
	    if ($crm_source !== null) {
	        $data['CRM_SOURCE'] = $crm_source;
        }
	    $result = $this->getBitrixApi($data, 'telephony.externalcall.register');
	    $this->writeToLog($result, 'runInputCall result');
	    echo var_dump($result);
	    if ($result && isset($result['result'])){
	        return $result['result']; // Возвращаем полный результат, а не только CALL_ID
	    } else {
	        return false;
	    }
    
	}

    /**
     * Run Bitrix24 REST API method telephony.externalcall.register.json
     *
     * @param int $exten (${EXTEN} from the Asterisk server, i.e. internal number)
     * @param int $callerid (${CALLERID(num)} from the Asterisk server, i.e. number which called us)
     *
     * @return array  like this:
     * Array
     *	(
     *	    [result] => Array
     *	        (
     *	            [CALL_ID] => externalCall.cf1649fa0f4479870b76a0686f4a7058.1513888745
     *	            [CRM_CREATED_LEAD] =>
     *	            [CRM_ENTITY_TYPE] => LEAD
     *	            [CRM_ENTITY_ID] => 24
     *	        )
     *	)
     * We need only CALL_ID
     */
    public function runOutputCall($exten, $callerid, $line){
        if (substr($callerid,0,1) == "9" and !(strlen($callerid) == 10)){
            $callerid = substr($callerid, 1);
        }
        if (strlen($callerid) == 7){
            $callerid = "8342".$callerid;
        }
        $result = $this->getBitrixApi(array(
            'USER_PHONE_INNER' => $exten,
            //'USER_ID' => $argv[1],
            'PHONE_NUMBER' => "+7".substr($callerid, -10),
            'LINE_NUMBER' => $line,
            'TYPE' => 1,
//            'CALL_START_DATE' => date("Y-m-d H:i:s"),
            'CRM_CREATE' => 0,
            'SHOW' => 1,
        ), 'telephony.externalcall.register');
        echo var_dump($result);
        $this->writeToLog($result, 'runOutputCall result');
        if ($result){
            return $result['result']['CALL_ID'];
        } else {
            return false;
        }

    }

	/**
	 * Run Bitrix24 REST API method user.get.json return only online users array
	 *
	 *
	 * @return array  like this:
	 *	Array
	 *	(
	 *	    [result] => Array
	 *	        (
	 *	            [0] => Array
	 *	                (
	 *	                    [ID] => 1
	 *	                    [ACTIVE] => 1
	 *	                    [EMAIL] => admin@your-admin.pro
	 *	                    [NAME] => 
	 *	                    [LAST_NAME] => 
	 *	                    [SECOND_NAME] => 
	 *	                    [PERSONAL_GENDER] => 
	 *	                    [PERSONAL_PROFESSION] => 
	 *	                    [PERSONAL_WWW] => 
	 *	                    [PERSONAL_BIRTHDAY] => 
	 *	                    [PERSONAL_PHOTO] => 
	 *	                    [PERSONAL_ICQ] => 
	 *	                    [PERSONAL_PHONE] => 
	 *	                    [PERSONAL_FAX] => 
	 *	                    [PERSONAL_MOBILE] => 
	 *	                    [PERSONAL_PAGER] => 
	 *	                    [PERSONAL_STREET] => 
	 *	                    [PERSONAL_CITY] => 
	 *	                    [PERSONAL_STATE] => 
	 *	                    [PERSONAL_ZIP] => 
	 *	                    [PERSONAL_COUNTRY] => 
	 *	                    [WORK_COMPANY] => 
	 *	                    [WORK_POSITION] => 
	 *	                    [WORK_PHONE] => 
	 *	                    [UF_DEPARTMENT] => Array
	 *	                        (
	 *	                            [0] => 1
	 *	                        )
     *
	 *	                    [UF_INTERESTS] => 
	 *	                    [UF_SKILLS] => 
	 *	                    [UF_WEB_SITES] => 
	 *	                    [UF_XING] => 
	 *	                    [UF_LINKEDIN] => 
	 *	                    [UF_FACEBOOK] => 
	 *	                    [UF_TWITTER] => 
	 *	                    [UF_SKYPE] => 
	 *	                    [UF_DISTRICT] => 
	 *	                    [UF_PHONE_INNER] => 555
	 *	                )
 	 *
	 *		        )
     *
	 *	    [total] => 1
	 *	)
	 */
	public function getUsersOnline(){
	    $result = $this->getBitrixApi(array(
			'FILTER' => array ('IS_ONLINE' => 'Y',),
			), 'user.get');

	    if ($result){
	    	if (isset($result['total']) && $result['total']>0) 
	    		return $result['result'];
	    	else return false;
	    } else {
	        return false;
	    }
    
	}

	/**
	 * Get CRM entity data by phone (Contact/Company/Lead)
	 * Uses crm.duplicate.findbycomm to search across all CRM entities
	 * Priority: 1) Contact, 2) Company, 3) Lead
	 *
	 * @param string $phone
	 *
	 * @return array Array with 'name' and 'responsible_user_id' keys, or null values on fail
	 */
	public function getCrmEntityDataByPhone($phone){
		// Ищем все связанные CRM-сущности по телефону
		$duplicates = $this->getBitrixApi(array(
			'TYPE' => 'PHONE',
			'VALUES' => array($phone),
		), 'crm.duplicate.findbycomm');

		$result = array(
			'name' => $phone, // По умолчанию возвращаем номер телефона
			'responsible_user_id' => null
		);

		if ($duplicates && isset($duplicates['result']) && !empty($duplicates['result'])) {
			// Обрабатываем найденные сущности по приоритетам
			$entities = $duplicates['result'];
			
			// Приоритет №1: Контакт (CONTACT)
			if (isset($entities['CONTACT']) && !empty($entities['CONTACT'])) {
				$contactId = $entities['CONTACT'][0]; // Берем первый найденный контакт
				$contact = $this->getBitrixApi(array('ID' => $contactId), 'crm.contact.get');
				if ($contact && isset($contact['result'])) {
					$name = $contact['result']['NAME'] ?? '';
					$lastName = $contact['result']['LAST_NAME'] ?? '';
					$result['name'] = $this->translit(trim($name . '_' . $lastName));
					$result['responsible_user_id'] = $contact['result']['ASSIGNED_BY_ID'] ?? null;
				}
			}
			// Приоритет №2: Компания (COMPANY)
			elseif (isset($entities['COMPANY']) && !empty($entities['COMPANY'])) {
				$companyId = $entities['COMPANY'][0]; // Берем первую найденную компанию
				$company = $this->getBitrixApi(array('ID' => $companyId), 'crm.company.get');
				if ($company && isset($company['result']['TITLE'])) {
					$result['name'] = $this->translit($company['result']['TITLE']);
					$result['responsible_user_id'] = $company['result']['ASSIGNED_BY_ID'] ?? null;
				}
			}
			// Приоритет №3: Лид (LEAD)
			elseif (isset($entities['LEAD']) && !empty($entities['LEAD'])) {
				$leadId = $entities['LEAD'][0]; // Берем первый найденный лид
				$lead = $this->getBitrixApi(array('ID' => $leadId), 'crm.lead.get');
				$result['name'] = "Lead_ID_" . $leadId . "_" . $phone;
				if ($lead && isset($lead['result']['ASSIGNED_BY_ID'])) {
					$result['responsible_user_id'] = $lead['result']['ASSIGNED_BY_ID'];
				}
			}
		}

		return $result;
	}

	/**
	 * Get CRM entity name by phone (Contact/Company/Lead)
	 * Wrapper for backward compatibility
	 *
	 * @param string $phone
	 *
	 * @return string Formatted name or phone number on fail 
	 */
	public function getCrmEntityNameByPhone($phone){
		$data = $this->getCrmEntityDataByPhone($phone);
		return $data['name'];
	}

	/**
	 * Get responsible user ID from CRM entity
	 *
	 * @param string $entityType (LEAD, CONTACT, COMPANY, DEAL)
	 * @param int $entityId
	 *
	 * @return int|false User ID or false on error
	 */
	public function getResponsibleFromCrmEntity($entityType, $entityId){
		if (empty($entityType) || empty($entityId)) {
			return false;
		}

		$this->writeToLog(array('entityType' => $entityType, 'entityId' => $entityId), 
			'Getting responsible from CRM entity');

		$method = '';
		switch (strtoupper($entityType)) {
			case 'LEAD':
				$method = 'crm.lead.get';
				break;
			case 'CONTACT':
				$method = 'crm.contact.get';
				break;
			case 'COMPANY':
				$method = 'crm.company.get';
				break;
			case 'DEAL':
				$method = 'crm.deal.get';
				break;
			default:
				$this->writeToLog("Unknown entity type: $entityType", 'getResponsibleFromCrmEntity ERROR');
				return false;
		}

		$result = $this->getBitrixApi(array('ID' => $entityId), $method);
		$this->writeToLog($result, "getResponsibleFromCrmEntity result for $entityType:$entityId");

		if ($result && isset($result['result']['ASSIGNED_BY_ID'])) {
			return $result['result']['ASSIGNED_BY_ID'];
		}

		return false;
	}

	/**
	 * Get internal phone number from CRM entity responsible
	 * Returns responsible's internal number or fallback number
	 *
	 * @param array $callResult Result from telephony.externalcall.register
	 * @param string $fallbackIntNum Fallback internal number from mapping
	 *
	 * @return string Internal phone number
	 */
	public function getResponsibleIntNum($callResult, $fallbackIntNum){
		// Проверяем режим работы
		$responsibleMode = $this->getConfig('responsible_mode');
		
		// Если режим статического маппинга - всегда возвращаем fallback
		if ($responsibleMode === 'static_mapping') {
			$this->writeToLog("Static mapping mode enabled, using fallback: $fallbackIntNum", 
				'getResponsibleIntNum');
			return $fallbackIntNum;
		}
		
		// Режим CRM responsible (по умолчанию)
		// Если нет данных о CRM сущности - возвращаем fallback
		if (empty($callResult['CRM_ENTITY_TYPE']) || empty($callResult['CRM_ENTITY_ID'])) {
			$this->writeToLog("No CRM entity found, using fallback: $fallbackIntNum", 
				'getResponsibleIntNum');
			return $fallbackIntNum;
		}

		// Получаем ID ответственного из CRM
		$responsibleUserId = $this->getResponsibleFromCrmEntity(
			$callResult['CRM_ENTITY_TYPE'], 
			$callResult['CRM_ENTITY_ID']
		);

		if (!$responsibleUserId) {
			$this->writeToLog("Cannot get responsible user ID, using fallback: $fallbackIntNum", 
				'getResponsibleIntNum');
			return $fallbackIntNum;
		}

		// Получаем внутренний номер ответственного
		$responsibleIntNum = $this->getIntNumByUSER_ID($responsibleUserId);

		if (!$responsibleIntNum) {
			$this->writeToLog("Responsible user $responsibleUserId has no internal number, using fallback: $fallbackIntNum", 
				'getResponsibleIntNum');
			return $fallbackIntNum;
		}

		$this->writeToLog(array(
			'mode' => 'crm_responsible',
			'responsibleUserId' => $responsibleUserId,
			'responsibleIntNum' => $responsibleIntNum,
			'crmEntityType' => $callResult['CRM_ENTITY_TYPE'],
			'crmEntityId' => $callResult['CRM_ENTITY_ID']
		), 'Found responsible from CRM');

		return $responsibleIntNum;
	}

	/**
	 * Show input call data for online users
	 *
	 * @param string $call_id
	 *
	 * @return bool 
	 */
	public function showInputCallForOnline($call_id){
		$online_users = $this->getUsersOnline();
		if ($online_users){
			foreach ($online_users as $user) {
				$result = $this->getBitrixApi(array(
					'CALL_ID' => $call_id,
					'USER_ID' => $user['ID'],
					), 'telephony.externalcall.show');
			}
			return true;
		} else 
			return false;
	}

	/**
	 * Show input call data for user with internal number
	 *
	 * @param int $intNum (user internal number)
	 * @param int $call_id 
	 *
	 * @return bool 
	 */
	public function showInputCall($intNum, $call_id){
		$user_id = $this->getUSER_IDByIntNum($intNum);
		if ($user_id){
			$result = $this->getBitrixApi(array(
						'CALL_ID' => $call_id,
						'USER_ID' => $user_id,
						), 'telephony.externalcall.show');
			return $result;
		} else 
			return false;
	}

    /**
     * Show input call data for user with internal number
     *
     * @param int $intNum (user internal number)
     * @param int $call_id
     *
     * @return bool
     */
    public function showOutputCall($intNum, $call_id){
        $user_id = $this->getUSER_IDByIntNum($intNum);
        if ($user_id){
            $result = $this->getBitrixApi(array(
                'CALL_ID' => $call_id,
                'USER_ID' => $user_id,
            ), 'telephony.externalcall.show');
            return $result;
        } else
            return false;
    }

	/**
	 * Hide input call data for all except user with internal number.
	 *
	 * @param int $intNum (user internal number)
	 * @param int $call_id 
	 *
	 * @return bool 
	 */
	public function hideInputCallExcept($intNum, $call_id){
		$user_id = $this->getUSER_IDByIntNum($intNum);
		$online_users = $this->getUsersOnline();
		if (($user_id) && ($online_users)){
			foreach ($online_users as $user) {
				if ($user['ID']!=$user_id){
					$result = $this->getBitrixApi(array(
						'CALL_ID' => $call_id,
						'USER_ID' => $user['ID'],
						), 'telephony.externalcall.hide');
				}
			}
			return true;
		} else 
			return false;
	}

	/**
	 * Hide input call data for user with internal number
	 *
	 * @param int $intNum (user internal number)
	 * @param int $call_id 
	 *
	 * @return bool 
	 */
	public function hideInputCall($intNum, $call_id){
		$user_id = $this->getUSER_IDByIntNum($intNum);
		if ($user_id){
			$result = $this->getBitrixApi(array(
						'CALL_ID' => $call_id,
						'USER_ID' => $user_id,
						), 'telephony.externalcall.hide');
			return $result;
		} else 
			return false;
	}

    public function crmStatusList(){
        $result = $this->getBitrixApi(array(
            '' => '',
        ), 'crm.status.list');
        return $result;
    }

    public function crmStatusEntityTypes(){
        $result = $this->getBitrixApi(array(
            '' => '',
        ), 'crm.status.entity.types');
        return $result;
    }

	/**
	 * Check string for json data.
	 *
	 * @param string $string
	 *
	 * @return bool 
	 */
	public function isJson($string) {
	    json_decode($string);
	    return (json_last_error() == JSON_ERROR_NONE);
	}

	/**
	 * Api requests to Bitrix24 
	 *
	 * @param array $data
	 * @param string $method
	 * @param string $url
	 *
	 * @return array or false 
	 */

	public function getBitrixApi($data, $method){
		$url = $this->getConfig('bitrixApiUrl');
		if (!$url) return false;
	    $queryUrl = $url.$method.'.json';
	    $queryData = http_build_query($data);
	    
	    // Логирование запроса к Битрикс24 в режиме дебаг
	    $debug = $this->getConfig('CallMeDEBUG');
	    if($debug){
	        $logData = array(
	            'URL' => $queryUrl,
	            'METHOD' => $method,
	            'PARAMS' => $data,
	            'PARAMS_JSON' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
	        );
	        $this->writeToLog($logData, 'Bitrix24 API Request');
	    }
	    
	    $curl = curl_init();
	    curl_setopt_array($curl, array(
	    CURLOPT_SSL_VERIFYPEER => 0,
	    CURLOPT_POST => 1,
	    CURLOPT_HEADER => 0,
	    CURLOPT_RETURNTRANSFER => 1,
	    CURLOPT_URL => $queryUrl,
	    CURLOPT_POSTFIELDS => $queryData,
	        ));
	    $result = curl_exec($curl);
	    curl_close($curl);
	    
	    if ($this->isJson($result)){
	        $result = json_decode($result, true);
	        // Логирование ответа от Битрикс24 в режиме дебаг
	        if($debug){
	            $this->writeToLog($result, 'Bitrix24 API Response');
	        }
	        return $result;
	    } else {
	        // Логирование ошибки парсинга JSON
	        if($debug){
	            $this->writeToLog($result, 'Bitrix24 API Response ERROR (not JSON)');
	        }
	        return false;
	    }
	}

	/**
	 * Write data to log file.
	 *
	 * @param mixed  $data
	 * @param string $title
	 *
	 * @return bool
	 */
	public function writeToLog($data, $title = '') {
		$debug = $this->getConfig('CallMeDEBUG');
		if($debug){
		    $log = "\n------------------------\n";
		    $log .= date("Y.m.d G:i:s") . "\n";
		    $log .= (strlen($title) > 0 ? $title : 'DEBUG') . "\n";
		    $log .= print_r($data, 1);
		    $log .= "\n------------------------\n";
		    file_put_contents(getcwd() . '/logs/CallMe.log', $log, FILE_APPEND);
		    return true;
	    }
	    else return;
	}

	/**
	 * Remove item from array.
	 *
	 * @param array $data
	 * @param mixed $needle
	 *
	 * @return array
	 */
	public function removeItemFromArray(&$data,$needle,$what) {

		if($what === 'value') {
			if (($key = array_search($needle, $data)) !== false) {
       	 		unset($data[$key]);
       		}
    	}

    	elseif($what === 'key') {
    		if (array_key_exists($needle, $data)) {
       	 		unset($data[$needle]);
       		}
       	}

        //return $data;
	}



	/**
	 * Return config value.
	 *
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function getConfig($key) {
        $config = require(__DIR__.'/../config.php');
		if (is_array($config)){
			// PHP 8.2 совместимость: проверяем наличие ключа перед возвратом
			return isset($config[$key]) ? $config[$key] : null;
		} else return false;
	}

	/**
	 * Translit string.
	 *
	 * @param string $string
	 *
	 * @return string
	 */
  	public function translit($string) {
	    $converter = array(
	        'а' => 'a',   'б' => 'b',   'в' => 'v',
	        'г' => 'g',   'д' => 'd',   'е' => 'e',
	        'ё' => 'e',   'ж' => 'zh',  'з' => 'z',
	        'и' => 'i',   'й' => 'y',   'к' => 'k',
	        'л' => 'l',   'м' => 'm',   'н' => 'n',
	        'о' => 'o',   'п' => 'p',   'р' => 'r',
	        'с' => 's',   'т' => 't',   'у' => 'u',
	        'ф' => 'f',   'х' => 'h',   'ц' => 'c',
	        'ч' => 'ch',  'ш' => 'sh',  'щ' => 'sch',
	        'ь' => '\'',  'ы' => 'y',   'ъ' => '\'',
	        'э' => 'e',   'ю' => 'yu',  'я' => 'ya',
	        
	        'А' => 'A',   'Б' => 'B',   'В' => 'V',
	        'Г' => 'G',   'Д' => 'D',   'Е' => 'E',
	        'Ё' => 'E',   'Ж' => 'Zh',  'З' => 'Z',
	        'И' => 'I',   'Й' => 'Y',   'К' => 'K',
	        'Л' => 'L',   'М' => 'M',   'Н' => 'N',
	        'О' => 'O',   'П' => 'P',   'Р' => 'R',
	        'С' => 'S',   'Т' => 'T',   'У' => 'U',
	        'Ф' => 'F',   'Х' => 'H',   'Ц' => 'C',
	        'Ч' => 'Ch',  'Ш' => 'Sh',  'Щ' => 'Sch',
	        'Ь' => '\'',  'Ы' => 'Y',   'Ъ' => '\'',
	        'Э' => 'E',   'Ю' => 'Yu',  'Я' => 'Ya',
	    );
	    return strtr($string, $converter);
  	}

}