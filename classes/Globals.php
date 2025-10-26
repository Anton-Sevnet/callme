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

    public $user_show_cards = array();

    public $Onhold = array();

    public $Answers = array();

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
