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
     * Кэш соответствий USER_ID ↔ внутренний номер.
     *
     * @var array<string,int>
     */
    private static $userIdByIntCache = array();

    /**
     * Кэш соответствий USER_ID ↔ внутренний номер.
     *
     * @var array<int,string>
     */
    private static $intByUserIdCache = array();

    /**
     * Кэш соответствий номеров и источников ROI.
     *
     * @var array<string,string>|null
     */
    private static $roiSourceCache = null;

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
	    if ($result && isset($result['result'][0]['UF_PHONE_INNER'])){
            $intNum = (string)$result['result'][0]['UF_PHONE_INNER'];
            if ($intNum !== '') {
                self::$intByUserIdCache[(int)$userid] = $intNum;
                self::$userIdByIntCache[$intNum] = (int)$userid;
            }
	        return $intNum;
	    }
        return false;

	/**
	 * Get USER_ID by Internal number.
	 *
	 * @param int $intNum
	 *
	 * @return int user id
	 */
	public function getUSER_IDByIntNum($intNum){
        $intNum = (string)$intNum;
        if ($intNum === '') {
            return false;
        }
        if (isset(self::$userIdByIntCache[$intNum])) {
            return self::$userIdByIntCache[$intNum];
        }
	    $result = $this->getBitrixApi(array('FILTER' => array ('UF_PHONE_INNER' => $intNum,),), 'user.get');
	    if ($result && isset($result['result'][0]['ID'])){
            $userId = (int)$result['result'][0]['ID'];
            self::$userIdByIntCache[$intNum] = $userId;
            self::$intByUserIdCache[$userId] = $intNum;
	        return $userId;
	    }
        return false;
	}

	/**
	 * Get STATUS_CODE from call disposition (legacy method for compatibility)
	 *
	 * @param string $disposition
	 *
	 * @return int SIP status code
	 */
	public function getStatusCodeFromDisposition($disposition){
		switch (strtoupper($disposition)) {
			case 'ANSWER':
			case 'ANSWERED':
				return 200; // успешный звонок
			case 'NO ANSWER':
			case 'NOANSWER':
				return 304; // нет ответа
			case 'BUSY':
				return 486; // занято
			case 'CANCEL':
			case 'CANCELLED':
				return 603; // отклонено
			case 'CONGESTION':
				return 503; // перегрузка сети
			default:
				if(empty($disposition)) return 304; //если пустой пришел, то поставим неотвечено
				else return 603; // отклонено, когда все остальное
		}
	}

	/**
	 * Get STATUS_CODE for Bitrix24 from Asterisk DialStatus
	 *
	 * @param string $dialStatus DialStatus from DialEndEvent
	 *
	 * @return int Bitrix24 status code
	 */
	public function getStatusCodeFromDialStatus($dialStatus){
		switch (strtoupper($dialStatus)) {
			case 'ANSWER':
				return 200; // успешный звонок
			case 'BUSY':
				return 486; // занято
			case 'NOANSWER':
				return 304; // нет ответа
			case 'CANCEL':
			case 'CANCELLED':
				return 603; // отклонено
			case 'CONGESTION':
				return 503; // перегрузка сети
			case 'CHANUNAVAIL':
			case 'INVALIDNMBR':
			case 'CHANGED':
				return 404; // номер не найден
			default:
				return 603; // отклонено по умолчанию
		}
	}

	/**
	 * Get STATUS_CODE for Bitrix24 from Asterisk HangupCause
	 *
	 * @param string|int $cause HangupCause code from HangupEvent
	 *
	 * @return int Bitrix24 status code
	 */
	public function getStatusCodeFromCause($cause){
		$cause = intval($cause);
		
		switch ($cause) {
			case 16: // Normal Clearing
				return 200;
			case 17: // User Busy
				return 486;
			case 18: // No User Response
			case 19: // No Answer
				return 304;
			case 21: // Call Rejected
			case 31: // Normal Unspecified
				return 603;
			case 22: // Number Changed
			case 23: // Redirected
			case 28: // Invalid Number Format
				return 404;
			case 34: // Circuit Congestion
			case 38: // Network Out of Order
			case 41: // Temporary Failure
			case 42: // Switching Congestion
			case 47: // Resource Unavailable
				return 503;
			default:
				return 603; // отклонено по умолчанию
		}
	}

	/**
	 * Get human-readable text for Asterisk HangupCause (for debugging)
	 *
	 * @param string|int $cause HangupCause code
	 *
	 * @return string Readable cause text
	 */
	public function getHangupCauseText($cause){
		$cause = intval($cause);
		
		$causes = [
			0 => 'Unspecified',
			1 => 'Unallocated number',
			2 => 'No route to network',
			3 => 'No route to destination',
			6 => 'Channel unacceptable',
			7 => 'Call awarded',
			16 => 'Normal Clearing',
			17 => 'User busy',
			18 => 'No user response',
			19 => 'No answer',
			20 => 'Subscriber absent',
			21 => 'Call rejected',
			22 => 'Number changed',
			26 => 'Non-selected user clearing',
			27 => 'Destination out of order',
			28 => 'Invalid number format',
			29 => 'Facility rejected',
			31 => 'Normal unspecified',
			34 => 'Circuit congestion',
			38 => 'Network out of order',
			41 => 'Temporary failure',
			42 => 'Switching congestion',
			43 => 'Access info discarded',
			44 => 'Requested channel unavailable',
			47 => 'Resource unavailable',
			50 => 'Facility not subscribed',
			52 => 'Outgoing call barred',
			54 => 'Incoming call barred',
			57 => 'Bearer capability not authorized',
			58 => 'Bearer capability not available',
			63 => 'Service unavailable',
			65 => 'Bearer capability not implemented',
			66 => 'Channel type not implemented',
			69 => 'Facility not implemented',
			79 => 'Service not implemented',
			81 => 'Invalid call reference',
			88 => 'Incompatible destination',
			95 => 'Invalid message',
			96 => 'Mandatory IE missing',
			97 => 'Message type non-existent',
			98 => 'Wrong message',
			99 => 'IE non-existent',
			100 => 'Invalid IE contents',
			101 => 'Wrong call state',
			102 => 'Recovery on timer expiry',
			103 => 'Mandatory IE length error',
			111 => 'Protocol error',
			127 => 'Interworking'
		];
		
		return $causes[$cause] ?? "Unknown cause ($cause)";
	}

	/**
	 * Determine status code for Originate call based on call data
	 *
	 * @param array $callData Originate call data from originateCalls[linkedId]
	 *
	 * @return int Bitrix24 status code
	 */
	public function determineOriginateStatusCode($callData){
		// Если был ответ и есть время ответа
		if (!empty($callData['answered']) && !empty($callData['answer_time'])) {
			$duration = time() - $callData['answer_time'];
			if ($duration > 0) {
				return 200; // Успешный разговор
			}
		}
		
		// На основе последнего DialStatus
		if (!empty($callData['last_dialstatus'])) {
			return $this->getStatusCodeFromDialStatus($callData['last_dialstatus']);
		}
		
		// На основе последнего HangupCause
		if (!empty($callData['last_hangup_cause'])) {
			return $this->getStatusCodeFromCause($callData['last_hangup_cause']);
		}
		
		// По умолчанию - нет ответа
		return 304;
	}

	/**
	 * Calculate duration for Originate call
	 *
	 * @param array $callData Originate call data from originateCalls[linkedId]
	 *
	 * @return int Duration in seconds
	 */
	public function calculateOriginateDuration($callData){
		if (!empty($callData['answered']) && !empty($callData['answer_time'])) {
			return time() - $callData['answer_time'];
		}
		return 0;
	}

	/**
	 * Finish call in Bitrix24 without recording (immediate)
	 *
	 * @param string $call_id
	 * @param string $intNum
	 * @param string $duration
	 * @param int $statusCode
	 *
	 * @return array|false Result from API or false on error
	 */
    public function finishCall($call_id, $intNum, $duration, $statusCode, $userId = null){
        $payload = array(
            'USER_PHONE_INNER' => $intNum,
            'CALL_ID' => $call_id,
            'STATUS_CODE' => $statusCode,
            'DURATION' => $duration,
            'RECORD_URL' => '' // Пустая строка - запись будет прикреплена потом
        );

        if ($userId !== null) {
            $payload['USER_ID'] = (int)$userId;
        }

		$result = $this->getBitrixApi($payload, 'telephony.externalcall.finish');
		
		if ($result){
			return $result;
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
		$sipcode = $this->getStatusCodeFromDisposition($disposition);

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
	public function runInputCall($exten, $callerid, $line, $crm_source=null, $userId = null){
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
            'CRM_CREATE' => 1,
            'SHOW' => 0,
        );
        if ($userId !== null) {
            $data['USER_ID'] = (int)$userId;
        } else {
            $fallbackUserId = $this->getFallbackResponsibleUserId();
            if ($fallbackUserId !== null) {
                $data['USER_ID'] = $fallbackUserId;
            }
        }
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
     * Получить fallback USER_ID для назначения ответственного
     *
     * @return int|null
     */
    public function getFallbackResponsibleUserId(){
        $value = $this->getConfig('fallback_responsible_user_id');
        if (is_numeric($value)) {
            $value = (int)$value;
            return $value > 0 ? $value : null;
        }
        return null;
    }

    /**
     * Массово скрыть карточки звонка у списка внутренних номеров
     *
     * @param string $call_id
     * @param array $intNums
     * @param string|null $excludeIntNum
     * @return array<int|string,mixed>
     */
    public function hideInputCallList($call_id, array $intNums, $excludeIntNum = null){
        $targets = array();
        foreach ($intNums as $intNum) {
            $intNum = (string)$intNum;
            if ($intNum === '') {
                continue;
            }
            if ($excludeIntNum !== null && (string)$excludeIntNum === $intNum) {
                continue;
            }
            $userId = $this->getUSER_IDByIntNum($intNum);
            if (!$userId) {
                continue;
            }
            $targets[] = array(
                'user_id' => (int)$userId,
                'int_num' => $intNum,
            );
        }
        return $this->hideInputCallForTargets($call_id, $targets);
    }

    /**
     * Обновить ответственного для CRM сущности
     *
     * @param string $entityType
     * @param int $entityId
     * @param int $userId
     * @return array|false
     */
    public function setCrmResponsible($entityType, $entityId, $userId){
        if (empty($entityType) || empty($entityId) || empty($userId)) {
            return false;
        }

        $entityType = strtoupper($entityType);
        $methodMap = array(
            'LEAD' => 'crm.lead.update',
            'CONTACT' => 'crm.contact.update',
            'COMPANY' => 'crm.company.update',
            'DEAL' => 'crm.deal.update',
        );

        if (!isset($methodMap[$entityType])) {
            $this->writeToLog("Unknown CRM entity type for responsible update: {$entityType}", 'setCrmResponsible');
            return false;
        }

        $payload = array(
            'ID' => (int)$entityId,
            'FIELDS' => array(
                'ASSIGNED_BY_ID' => (int)$userId,
            ),
        );

        return $this->getBitrixApi($payload, $methodMap[$entityType]);
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
                        'USER_PHONE_INNER' => (string)$intNum,
						), 'telephony.externalcall.show');
			return $result;
		} else 
			return false;
	}

    /**
     * Показываем карточку звонка группе пользователей.
     *
     * @param string $call_id
     * @param array<int> $userIds
     * @return array|false
     */
    public function showInputCallForUsers($call_id, array $userIds) {
        $userIds = array_unique(array_filter(array_map('intval', $userIds), function ($value) {
            return $value > 0;
        }));
        if (empty($userIds)) {
            return false;
        }
        return $this->getBitrixApi(array(
            'CALL_ID' => $call_id,
            'USERS' => array_values($userIds),
        ), 'telephony.externalcall.show');
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

    /**
     * Hide call cards for a prepared list of targets in a single Bitrix24 request.
     *
     * @param string $call_id
     * @param array<int,array{user_id:int,int_num:string}> $targets
     * @return array|false
     */
    public function hideInputCallForTargets($call_id, array $targets){
        $userIds = array();
        $userPhones = array();

        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }
            $userId = isset($target['user_id']) ? (int)$target['user_id'] : 0;
            $intNum = isset($target['int_num']) ? (string)$target['int_num'] : '';
            if ($userId <= 0) {
                continue;
            }
            $userIds[] = $userId;
            if ($intNum !== '') {
                $userPhones[] = $intNum;
            }
        }

        if (empty($userIds)) {
            return false;
        }

        $payload = array(
            'CALL_ID' => $call_id,
            'USER_ID' => array_values(array_unique($userIds)),
        );

        if (!empty($userPhones)) {
            $payload['USER_PHONE_INNER'] = array_values(array_unique($userPhones));
        }

        return $this->getBitrixApi($payload, 'telephony.externalcall.hide');
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

	public function logAmiHealth($channel, $level, $message, array $context = array()) {
		$config = $this->getConfig('ami_healthcheck_log');
		if (!is_array($config)) {
			$config = array();
		}
		$channelKey = strtolower($channel);
		$channelConfig = $config[$channelKey] ?? $config[$channel] ?? array('NOTICE' => true);
		$level = strtoupper($level);
		$allowed = $channelConfig[$level] ?? ($level === 'NOTICE');
		if (!$allowed) {
			return;
		}
		$log = "\n------------------------\n";
		$log .= date("Y.m.d G:i:s") . "\n";
		$log .= sprintf('%s [%s]', strtoupper($channel), $level) . "\n";
		$log .= $message . "\n";
		if (!empty($context)) {
			$log .= print_r($context, true);
		}
		$log .= "\n------------------------\n";
		file_put_contents(getcwd() . '/logs/ami_healthcheck.log', $log, FILE_APPEND);
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

	/**
	 * Найти call_id по внутреннему номеру из существующих массивов
	 *
	 * @param string $intNum
	 * @param object $globalsObj
	 * @return string|null
	 */
	public function findCallIdByIntNum($intNum, $globalsObj) {
		// Простой поиск в существующих массивах
		foreach ($globalsObj->calls as $uniqueid => $call_id) {
			if (isset($globalsObj->intNums[$uniqueid]) && $globalsObj->intNums[$uniqueid] == $intNum) {
				return $call_id;
			}
		}
		if (!empty($globalsObj->transferHistory) && is_array($globalsObj->transferHistory)) {
			foreach ($globalsObj->transferHistory as $transferData) {
				if (!is_array($transferData)) {
					continue;
				}
				if (($transferData['currentIntNum'] ?? null) == $intNum && !empty($transferData['call_id'])) {
					return $transferData['call_id'];
				}
			}
		}
		return null;
	}

	/**
	 * Получить CRM_SOURCE для указанного внешнего номера по данным инфоблока.
	 *
	 * @param string $number
	 * @return string|null
	 */
	public function getRoiSourceByNumber($number) {
		$digits = preg_replace('/\D+/', '', (string)$number);
		if ($digits === '') {
			return null;
		}

		$map = $this->loadRoiSourceMap();
		return $map[$digits] ?? null;
	}

	/**
	 * Сбросить и пересчитать кэш источников.
	 *
	 * @return void
	 */
	public function refreshRoiSourceCache() {
		self::$roiSourceCache = null;
		$this->loadRoiSourceMap();
	}

	/**
	 * Загрузить карту соответствий транков и источников из инфоблока.
	 *
	 * @return array<string,string>
	 */
	private function loadRoiSourceMap() {
		if (is_array(self::$roiSourceCache)) {
			return self::$roiSourceCache;
		}

		$iblockId = (int)$this->getConfig('roi_source_iblock_id');
		if ($iblockId <= 0) {
			self::$roiSourceCache = array();
			return self::$roiSourceCache;
		}

		$propertyCode = 'ISTOCHNIK_DLYA_ANALITIKI_ROI';
		$response = $this->getBitrixApi(array(
			'IBLOCK_TYPE_ID' => 'lists',
			'IBLOCK_ID' => $iblockId,
			'select' => array('ID', 'NAME', 'PROPERTY_' . $propertyCode, 'PROPERTY_' . $propertyCode . '_VALUE'),
		), 'lists.element.get');

		$items = array();
		if ($response && isset($response['result'])) {
			if (isset($response['result']['items']) && is_array($response['result']['items'])) {
				$items = $response['result']['items'];
			} elseif (is_array($response['result'])) {
				$items = $response['result'];
			}
		}

		$map = array();
		if (!is_array($items)) {
			self::$roiSourceCache = $map;
			return self::$roiSourceCache;
		}

		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}
			$name = isset($item['NAME']) ? $item['NAME'] : '';
			$number = preg_replace('/\D+/', '', (string)$name);
			if ($number === '') {
				continue;
			}

			$propertyKey = 'PROPERTY_' . $propertyCode . '_VALUE';
			$value = $item[$propertyKey] ?? ($item['PROPERTY_' . $propertyCode] ?? null);
			if (is_array($value)) {
				$value = reset($value);
			}

			if (!is_string($value) || trim($value) === '') {
				continue;
			}

			$parts = array_map('trim', explode('|', $value));
			if (count($parts) < 2) {
				continue;
			}

			$statusId = $parts[1];
			if ($statusId === '') {
				continue;
			}

			$map[$number] = $statusId;
		}

		self::$roiSourceCache = $map;
		return self::$roiSourceCache;
	}

	/**
	 * Проверяет, соединяет ли BridgeEvent внешний канал с внутренним абонентом.
	 *
	 * @param \PAMI\Message\Event\BridgeEvent $bridgeEvent
	 * @return bool
	 */
	public function isExternalToInternalBridge($bridgeEvent) {
		$callerID1 = $bridgeEvent->getCallerID1();
		$callerID2 = $bridgeEvent->getCallerID2();
		$channel1 = $bridgeEvent->getChannel1();
		$channel2 = $bridgeEvent->getChannel2();

		$callerID1Length = strlen(preg_replace('/\D/', '', (string)$callerID1));
		$callerID2Length = strlen(preg_replace('/\D/', '', (string)$callerID2));

		$isChannel1External = (strpos((string)$channel1, 'IAX2') !== false) ||
			(strpos((string)$channel1, 'SIP/') !== false && $callerID1Length >= 6) ||
			(strpos((string)$channel1, 'Local/') !== false && $callerID1Length >= 6);

		$isChannel2External = (strpos((string)$channel2, 'IAX2') !== false) ||
			(strpos((string)$channel2, 'SIP/') !== false && $callerID2Length >= 6) ||
			(strpos((string)$channel2, 'Local/') !== false && $callerID2Length >= 6);

		return ($isChannel1External && !$isChannel2External && $callerID2Length <= 4)
			|| (!$isChannel1External && $isChannel2External && $callerID1Length <= 4);
	}

	/**
	 * Проверяет, соединяет ли BridgeEvent два внутренних канала.
	 *
	 * @param \PAMI\Message\Event\BridgeEvent $bridgeEvent
	 * @return bool
	 */
	public function isInternalToInternalBridge($bridgeEvent) {
		$callerID1 = $bridgeEvent->getCallerID1();
		$callerID2 = $bridgeEvent->getCallerID2();

		$callerID1Length = strlen(preg_replace('/\D/', '', (string)$callerID1));
		$callerID2Length = strlen(preg_replace('/\D/', '', (string)$callerID2));

		return $callerID1Length <= 4 && $callerID2Length <= 4;
	}

	/**
	 * Извлекает полезные данные из BridgeEvent для отслеживания transfer.
	 *
	 * @param \PAMI\Message\Event\BridgeEvent $bridgeEvent
	 * @return array|null
	 */
	public function extractBridgeData($bridgeEvent) {
		$callerID1 = $bridgeEvent->getCallerID1();
		$callerID2 = $bridgeEvent->getCallerID2();
		$channel1 = $bridgeEvent->getChannel1();
		$channel2 = $bridgeEvent->getChannel2();
		$uniqueID1 = $bridgeEvent->getUniqueID1();
		$uniqueID2 = $bridgeEvent->getUniqueID2();

		$callerID1Length = strlen(preg_replace('/\D/', '', (string)$callerID1));
		$callerID2Length = strlen(preg_replace('/\D/', '', (string)$callerID2));

		$isChannel1External = (strpos((string)$channel1, 'IAX2') !== false) ||
			(strpos((string)$channel1, 'SIP/') !== false && $callerID1Length >= 6) ||
			(strpos((string)$channel1, 'Local/') !== false && $callerID1Length >= 6);

		if ($isChannel1External && $callerID2Length <= 4) {
			return array(
				'externalChannel' => $channel1,
				'externalUniqueid' => $uniqueID1,
				'externalCallerID' => $callerID1,
				'internalChannel' => $channel2,
				'internalUniqueid' => $uniqueID2,
				'internalCallerID' => $callerID2,
			);
		}

		$isChannel2External = (strpos((string)$channel2, 'IAX2') !== false) ||
			(strpos((string)$channel2, 'SIP/') !== false && $callerID2Length >= 6) ||
			(strpos((string)$channel2, 'Local/') !== false && $callerID2Length >= 6);

		if ($isChannel2External && $callerID1Length <= 4) {
			return array(
				'externalChannel' => $channel2,
				'externalUniqueid' => $uniqueID2,
				'externalCallerID' => $callerID2,
				'internalChannel' => $channel1,
				'internalUniqueid' => $uniqueID1,
				'internalCallerID' => $callerID1,
			);
		}

		return null;
	}

}
