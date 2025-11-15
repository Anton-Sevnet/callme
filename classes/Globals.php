<?php
/**
* Globals class for working with 'globals' variables 
* @author Автор: ViStep.RU
* @version 1.0
* @copyright: ViStep.RU (admin@vistep.ru)
* PHP Version 8.2+
*/

class Globals {

    static private $instance = null;
    //массив с CALL_ID из битрикса, ключ - Uniqueid из asterisk
    public $calls = array();
    //маппинг linkedid -> CALL_ID для быстрого доступа
    public $callIdByLinkedid = array();
    //Детали CRM объекта, возвращённого при регистрации звонка
    public $callCrmData = array(); // [linkedid => ['entity_type'=>..., 'entity_id'=>..., 'created'=>bool]]
    //Список внутренних номеров, у которых показана карточка для звонка
    public $callShownCards = array(); // [linkedid => ['100'=>true]]
    //Список внутренних номеров, где в данный момент звонит телефон
    public $ringingIntNums = array(); // [linkedid => ['100' => ['user_id'=>123, 'shown'=>bool, 'state'=>'RING']]]
    //Порядок появления внутренних номеров в состоянии RING
    public $ringOrder = array(); // [linkedid => ['100','101']]
    //Направление звонка: inbound/outbound
    public $callDirections = array(); // [linkedid => 'inbound']
    //Тип маршрута вызова (direct/multi)
    public $callRouteTypes = array(); // [linkedid => 'direct']
    //Текущий Call ID, закреплённый за внутренними номерами (для быстрого доступа при событиях AMI)
    public $callIdByInt = array(); // ['100' => CALL_ID]
    //Обратный маппинг CALL_ID -> Uniqueid (fallback при переносах)
    public $callsByCallId = array();
	//массив с uniqueid внешних звонкнов
    public $uniqueids = array();
	//массив FullFname (url'ы записей разговоров), ключ - Uniqueid из asterisk
    public $FullFnameUrls = array();
	//массив внутренних номеров, ключ - Uniqueid из asterisk
	public $intNums = array();
	//массив duration звонков, ключ - Uniqueid из asterisk
    public $Durations = array();
	//массив disposition звонков, ключ - Uniqueid из asterisk
    public $Dispositions = array();
    //массив extensions - внешние номера, звонки на которые мы отслеживаем
    public $extensions = array();
    
    //массив extentions - внешние номера (альтернативное название для совместимости)
    public $extentions = array();

    public $user_show_cards = array();

    public $Onhold = array();

    public $Answers = array();
    
    // Для отслеживания Originate-звонков (исходящие через Bitrix24) - ВЕРСИЯ С LINKEDID
    public $originateCalls = array();      // [linkedId => ['call_id'=>..., 'intNum'=>..., 'channels'=>[], 'answered'=>bool]]
    public $uniqueidToLinkedid = array();  // [uniqueid => linkedId] - маппинг для быстрого поиска
    // Для отслеживания transfer-ов (перемещение карточек между операторами)
    public $transferHistory = array();     // [externalUniqueid => ['call_id'=>..., 'externalChannel'=>..., 'currentIntNum'=>..., 'history'=>[]]]
    // Состояние health-check AMI
    public $amiState = array();
    // Отложенные данные по локальным ногам очередей до получения linkedid
    public $pendingDialBegin = array(); // [uniqueid => ['event'=>EventMessage,'exten'=>'220', ...]]
    // Маркеры, что fallback DialBegin уже обработан для uniqueid
    public $dialBeginProcessed = array(); // [uniqueid => true]

    static public function getInstance(){
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}
	private function __clone() {}
	private function __wakeup() {}

    /**
     * Возвращает срез ключевых массивов состояния для логирования.
     *
     * @param array|null $onlyKeys
     * @return array<string,mixed>
     */
    public function exportStateSnapshot(array $onlyKeys = null) {
        $state = array(
            'calls' => $this->calls,
            'callIdByLinkedid' => $this->callIdByLinkedid,
            'callsByCallId' => $this->callsByCallId,
            'callCrmData' => $this->callCrmData,
            'callShownCards' => $this->callShownCards,
            'ringingIntNums' => $this->ringingIntNums,
            'ringOrder' => $this->ringOrder,
            'callDirections' => $this->callDirections,
            'callRouteTypes' => $this->callRouteTypes,
            'callIdByInt' => $this->callIdByInt,
            'callsExternal' => $this->uniqueids,
            'intNums' => $this->intNums,
            'Durations' => $this->Durations,
            'Dispositions' => $this->Dispositions,
            'originateCalls' => $this->originateCalls,
            'uniqueidToLinkedid' => $this->uniqueidToLinkedid,
            'transferHistory' => $this->transferHistory,
            'Onhold' => $this->Onhold,
            'Answers' => $this->Answers,
            'extensions' => $this->extensions,
            'extentions' => $this->extentions,
            'user_show_cards' => $this->user_show_cards,
            'amiState' => $this->amiState,
        );

        if ($onlyKeys !== null && is_array($onlyKeys) && !empty($onlyKeys)) {
            $filtered = array();
            foreach ($onlyKeys as $key) {
                if (array_key_exists($key, $state)) {
                    $filtered[$key] = $state[$key];
                }
            }
            return $filtered;
        }

        return $state;
    }

}
