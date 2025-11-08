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
    //Отложенные регистрации по номеру внешнего абонента
    public $pendingCallsByCaller = array(); // [callerNumber => [linkedid => true]]
    //Отложенные регистрации (когда нет ответственного)
    public $pendingCalls = array(); // [linkedid => [...]]
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
    
    // Для отслеживания Transfer звонков (переключение между внутренними абонентами)
    public $transferHistory = array();     // [externalUniqueid => ['call_id'=>..., 'externalChannel'=>..., 'currentIntNum'=>..., 'history'=>[]]]

    // Состояние health-check AMI
    public $amiState = array();

    static public function getInstance(){
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}
	private function __clone() {}
	private function __wakeup() {}

}
