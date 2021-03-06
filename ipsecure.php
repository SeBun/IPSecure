<?php
/**
 * IPSecure
 *
 * @author     Сергей Бунин
 * @copyright  Copyright (C) 2017 Sergey Bunin.
 * @license    GNU General Public License v3.0
 * @version    1.01
 *
 * IPSecure - скрипт защиты сайта посредством фильтрации IP-адресов. Его назначение - 
 * защита сайта от ботов, брута, сканирования и прочих действий, которые могут нанести вред
 * или замедлить работу путем создания неоправданно высокой нагрузки на ресурсы сервера.
 * 
 * Для использования этого скрипта его нужно подключить к вашему сайту так, что бы при
 * запросе он вызывался самым первым. Это можно сделать путем размещения файла скрипта 
 * в каталоге и добавлении директивы auto_prepend_file в php.ini, либо другим способом.
 *
 * Перед использованием скрипта установите необходимые настройки (после описания класса),
 * а так же задайте нужное действие, выполняемое при обнаружении прокси в методе action.
 */


/**
 * Проверяем на совместимость с используемой версией PHP. Версия должна быть не ниже 5.3.10
 */
if (version_compare(PHP_VERSION, '5.3.10', '<'))
{
    die('Your host needs to use PHP 5.3.10 or higher to run this IPSecure!');
}


class CPSS_IPSecure {
    
    /**
     * Текущий IP-адрес клиента. Устанавливается при первом вызове метода получения IP _getIp().
     * Впоследствии IP адрес больше не расчитывается, а берется из этой переменной.
     * @var string
     */
    protected $ip = NULL;
    
    /**
     * Свойство содержит время задержки выполнения скрипта при обнаружении проксти. По умолчанию 0.
     * @var integer 
     */
    public $sleep = 0; 
    
    /**
     * Свойство определяет, следует ли выполнять дополнительную проверку путем прозвона порта возможного прокси-сервера.
     * @var boolean
     */
    public $checkport = 0;

    
    /**
     * Список DNSBL хостов для проверки IP
     * @var array
     */
    public $dnsbl = array();

    
    
    /**
     * Конструктор класса проверяет параметр sleep (макс. 30сек.).
     */
    function __construct()
    {
        // Проверка диапазона времени задержки выполнения (не более 30 сек.)
        if ($this->sleep <= 0) { $this->sleep = 0;  }
        if ($this->sleep > 30) { $this->sleep = 30; }
        
        //Получение и установка IP клиента
        $this->ip = $this->_getIp();
    }
    
    
    
    /**
     * Метод получения текущего ip-адреса клиента из переменных сервера.
     * Выполняет запись полученного адреса в свойство ip.
     */
    private function _getIp() {

        if (!empty($_SERVER['HTTP_CLIENT_IP']))
	{
            $this->ip=$_SERVER['HTTP_CLIENT_IP'];
	}
	elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
	{
            $this->ip=$_SERVER['HTTP_X_FORWARDED_FOR'];
	}
	else
	{
            $this->ip=$_SERVER['REMOTE_ADDR'];
	}
    
    } // End function _getIp()
    

    /**
     * Если посетитель пришел с прокси-сервера, то в переменной $_SERVER['HTTP_VIA']
     * может храниться имя, версия программного обеспечения и номер порта.
     * 
     * @return boolean Возвращает TRUE, если есть признак принадлежности к прокси.
     */
    private function _detectVIA() 
    {
        if ($this->ip == '127.0.0.1' || empty($this->ip)) {
            return FALSE; // отключить для localhost
        }
        
        if (!empty($_SERVER['HTTP_VIA'])) {
          return true;
        }
        
        return FALSE; //строка пуста.
    
    } // End function _detectVIA()
    
    
    /**
     * Проверяет IP на наличие в черных списках DNSBL-серверов.
     * @return boolean При обнаружении возвращает результат проверки
     */
    private function _checkDNSBL() {
        
        if ($this->ip == '127.0.0.1' || empty($this->ip)) {
            return FALSE; // отключить для localhost
        }
        
        if(!is_array($this->dnsbl) || !count($this->dnsbl)) {
            return FALSE; // нет списков DNSBL-серверов
        }
        
        // Подготавливаем массив для результатов проверки
        $result = array('dnsbl_hosts' => array(), 'inblack' => 0);
        
        $reverse_ip = implode(".", array_reverse(explode(".", $this->ip)));
        
        foreach($this->dnsbl as $dnsbl_host) {
            $is_listed = checkdnsrr($reverse_ip.".".$dnsbl_host.".", "A") ? 1 : 0;
            $result['dnsbl_hosts'][$dnsbl_host] = $is_listed;
            
            if($is_listed) {
                $result['inblack']++;
            }                
        }
        
        return $result;
        
    } // End function _checkDNSBL()
    
    
    /**
     * Мы можем попробовать подключиться к ip клиента как к прокси (на часто используемые для прокси порты).
     * Если соединение установлено, значит за этим ip адресом прокси сервер, если нет, то скорее всего это настоящий
     * клиент (или у этого прокси сервера не популярный порт для подключения).
     * 
     * Основными портами открытых прокси-серверов являются следующие:
     *   80
     *   81
     *   8000
     *   8080 (т. н. HTTP CONNECT-прокси)
     *   1080 (SOCKS-прокси)
     *   3128 (стандартный порт для squid, WinGate, WinRoute и многих других)
     *   6588 (AnalogX)
     * 
     * @return boolean Возвращает TRUE, если есть признак принадлежности к прокси.
     */
    private function _detectPort()
    {
        if ($this->ip == '127.0.0.1' || empty($this->ip)) {
            return FALSE; // отключить для localhost
        }

        $ports = array(8080,80,81,1080,6588,8000,3128,553,554,4480);
        
        foreach($ports as $port) {
            if (@fsockopen($this->ip, $port, $errno, $errstr, 5)) {
                return TRUE;
            } 
        }
        
    } // End function _detectPort()
    
    
    /**
     * Запуск процесса обнаружения PROXY.
     * @return boolean Если обнаружено, что пользователь зашел с прокси-сервера, возвращается TRUE.
     */
    public function detect() 
    {

        if ($this->_detectVIA()) {
            return TRUE; // обнаружен признак прокси (HTTP_VIA заполнено).
        }

        // Если разрешена проверка порта, выполнить дополнительную проверку
        if ($this->checkport) {
            if (self::_detectPort()) {
                return TRUE; // обнаружен признак прокси (получен ответ от прокси).
            }
        }
        
        // Прокси не определен, запускаем проверку DNSBL
        if ($this->_checkDNSBL()) {
            return TRUE; // адрес числится в черных списках
        }

        return FALSE;
        
    } // End function detect() 
    
    
    /**
     * Выполнение действия при обнаружении входа через прокси.
     * 
     * @todo Пока это лишь банальная задержка времени выполнения скрипта.
     * @todo Здесь лучше выводить капчу по аналогии с популярными механизмами защиты.
     */
    public function action()
    {
        sleep($this->sleep); // пауза
       
    } // End function action()
    
} // End class



/**
 * Выполняем проверку IP клиента.
 * Проверка проходит в несколько этапов. Если обнаружено подключение через прокси, выполняется задержка
 * выполнения скрипта или прерывание работы в зависимости от конфигурации. Следующий шаг - проверка
 * адреса на наличие в черных списках DNSBL-систем.
 */


// Создаем объект класса и задаем предварительные настройки.
$ips = new CPSS_IPSecure();


// НАСТРОЙКИ СКРИПТА
$ips->sleep = 5;     // Задержка выполнения скрипта при обнаружении прокси в секундах (от 0 до 30).
$ips->checkport = 1; // Включить или выключить проверку портов (может замедлить работу).
$ips->dnsbl = array( // Список серверов, на которых проходит проверка DNSBL
    'b.barracudacentral.org', 
    'xbl.spamhaus.org', 
    'zen.spamhaus.org',
    'cbl.spamhaus.org',
    'pbl.spamhaus.org',
    'sbl.spamhaus.org'
);   


// Запускаем проверку IP и выполняем действие при обнаружении прокси
if ($ips->detect()) {
    $ips->action();
}

 
    
