<?php
require_once('HttpVars/classes.inc');

class PXRequest {
	var $httpMethod;
	var $host;
	var $path;
	var $remoteAddr;
	var $getData;
	var $postData;
	var $cookieData;

	function __construct() {
		$this->httpMethod = $this->GetHttpMethod();
		$this->host       = $this->GetHttpHost();
		$this->remoteAddr = $this->GetRemoteAddr();
		$this->path       = $this->GetOriginalPath();

		$this->getData    = new NLGetData();
		$this->postData   = new NLPostData();
		$this->cookieData = new NLCookieData();
	}

	static function GetHttpMethod() {
		return (isset($_SERVER['REQUEST_METHOD'])) ? $_SERVER['REQUEST_METHOD'] : 'CLI';
	}

	static function GetHttpReferer() {
		return (isset($_SERVER['HTTP_REFERER'])) ? $_SERVER['HTTP_REFERER'] : NULL;
	}

	static function GetRequestUri() {
		return (isset($_SERVER['REQUEST_URI'])) ? $_SERVER['REQUEST_URI'] : NULL;
	}

	function GetReferer() {
		if (isset($_SERVER['HTTP_REFERER'])) {
			return $_SERVER['HTTP_REFERER'];
		} elseif ($this->getData->_GetVar('referer')) {
			return $this->getData->_GetVar('referer');
		} else {
			return NULL;
		}
	}

	static function GetOriginalPath() {
		return $_SERVER['SCRIPT_NAME'];
	}

	function GetPath() {
		$tmpArray = explode("/", $this->path);
		$path = array();
		foreach ($tmpArray as $tmp) {
			if (!strlen($tmp) || 
				in_array(
					$tmp, 
					array(
						'index.html',
						'index.shtml',
						'index.phtml',
						'index.php',
						'index.php3',
						'index.php4',
						'default.htm',
						'default.asp',
						'index.jsp'
					),
					true
				)
			){
				continue;
			}
			
			$path[] = $tmp;
		}
		return $path;
	}

	static function GetHttpHost() {
		if (isset($_SERVER['HTTP_X_HOST'])) {
			if (strstr($_SERVER['HTTP_X_HOST'], ",")) {
				$host = trim(end(explode(",", $_SERVER['HTTP_X_HOST'])));
			} else {
				$host = $_SERVER['HTTP_X_HOST'];
			}
		} elseif (isset($_SERVER['HTTP_HOST'])) {
			$host = $_SERVER['HTTP_HOST'];

		} else {
			return NULL;
		}

		return preg_replace('/:.+$/', '', $host);
	}

	static function GetRemoteAddr() {
		if (isset($_SERVER['HTTP_X_REAL_IP'])) {
			if (strstr($_SERVER['HTTP_X_REAL_IP'], ",")) {
				return trim(end(explode(",", $_SERVER['HTTP_X_REAL_IP'])));
			} else {
				return $_SERVER['HTTP_X_REAL_IP'];
			}
		} elseif (isset($_SERVER['REMOTE_ADDR'])) {
			return $_SERVER['REMOTE_ADDR'];
		} else {
			return NULL;
		}
	}

	function GetUserAgent(){
		return isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
	}

	function GetGetVar($varName) {
		return $this->getData->_GetVar($varName);
	}

	function GetPostVar($varName) {
		return $this->postData->_GetVar($varName);
	}

	function GetCookieVar($varName) {
		return $this->cookieData->_GetVar($varName);
	}

	function SetCookieVar($varName, $value='', $expire=NULL) {
		$this->cookieData->_SetVar('charcheck', '������', $expire);

		return $this->cookieData->_SetVar($varName, $value, $expire);
	}

	function GetAllGetData() {
		return $this->getData->GetAll();
	}

	function GetAllPostData() {
		return $this->postData->GetAll();
	}

	function GetAllCookieData() {
		return $this->cookieData->GetAll();
	}

	function _GetGetPostVar($varName) {
		switch ($this->httpMethod) {
			case 'POST': return $this->GetPostVar($varName);
			default:     return $this->GetGetVar($varName);
		}
	}

	function _SetGetPostVar($varName, $value) {
		switch ($this->httpMethod) {
			case 'POST': return $this->postData->_SetVar($varName, $value);
			default:     return $this->getData->_SetVar($varName, $value);
		}
	}

	function SetVar($varName, $value) {
		$functionName = 'Set'.strtoupper($varName{0}).substr($varName, 1);

		if (method_exists($this, $functionName)) {
			$value = call_user_func(array(&$this, $functionName), $value);
		} else {
			$value = $this->_SetGetPostVar($varName, $value);
		}
		return $value;
	}

	function GetVar($varName, $default = NULL) {
		$functionName = 'Get'.ucfirst($varName);

		if (method_exists($this, $functionName)) {
			$value = call_user_func(array(&$this, $functionName));
		} else {
			$value = $this->_GetGetPostVar($varName);
		}
		return ($value !== NULL && $value !== '') ? $value : $default;
	}

	function isSetVar($varName) {
		switch ($this->httpMethod) {
			case 'POST': return $this->postData->_isSetVar($varName);
			default:     return $this->getData->_isSetVar($varName);
		}
	}

	function GetUploadFile($varName, $isArray=FALSE) {
		return $this->postData->_GetFileVar($varName, $isArray);
	}

	/**
	 * ��������� ��������� ip-������ � �������.
	 *
	 * @param String $ip        - ip-�����, �������� 192.168.0.1
	 * @param String $subnet    - �������, �������� 192.168.0.0/24
	 * @return Int
	 */
	static function IsIpInSubnet($ip, $subnet) {
		list($net,$mask) = split("/",$subnet);

		$lnet   = ip2long($net);
		$lip    = ip2long($ip);
		$binnet = str_pad(decbin($lnet), 32, "0", STR_PAD_LEFT);

		$firstpart = substr($binnet, 0, $mask);
		$binip     = str_pad(decbin($lip), 32, "0", STR_PAD_LEFT);
		$firstip   = substr($binip, 0, $mask);

		return (int)(strcmp($firstpart, $firstip) === 0);
	}

	/**
	 * ��������� ��������� ip-������ � ������ ��������.
	 *
	 * @param String $ip        - ip-�����, �������� 192.168.0.1
	 * @param Array  $subnet    - ������ ��������
	 * @return Int
	 */
	static function IsIpInSubnets($ip, $subnets) {
		$isIn = 0;

		foreach($subnets as $s) {
			$isIn += PXRequest::IsIpInSubnet($ip, $s);
		}

		return $isIn;
	}
	
	// ������� ����������� ������ "��������" � ������������ ���������� ��������� ������
	function getLeafStatus() {
		$leafStatus = $this->cookieData->_getVar('leafStatus');
		$openLeaf   = array();
		if ($leafStatus) {
			foreach ($leafStatus as $l=>$s) {
				if ($s == 'open') {
					$openLeaf[] = substr($l, 6);
				}
			}
		}
		return $openLeaf;
	}

	// ���������� ������� ������ ������ "struct"
	function getSid() {
		return $this->_getGetPostVar('sid');
	}

	// ���������� ������� ������ ������ "content"
	function getCid() {
		return $this->_getGetPostVar('cid');
	}

	// ����������� id �������������� �������
	function getId() {
		return $this->_getGetPostVar('id');
	}

	// ����������� format ��������������/������������ �������
	function getFormat() {
		return $this->_getGetPostVar('format');
	}

	// ����������� parent ��������������/������������ �������
	function getParent() {
		return $this->_getGetPostVar('parent');
	}

	// ����������� �������� ������������ ��� �������������/����������� ��������
	function getAction() {
		return $this->_getGetPostVar('action');
	}
	
	// ����������� ��� �������� �������
	function getCtype() {
		return $this->_getGetPostVar('ctype');
	}

	// POST
	function getLinks($reference) {
		return $this->postData->_getArrayVar($reference->name, false);
	}

	function include_handler($handler) {
		$file = strtolower($handler).".inputhandler.inc";

		if (file_exists(BASEPATH.'/local/handlers/'.$file)) {
			include_once BASEPATH.'/local/handlers/'.$file;
		} elseif (file_exists(BASEPATH.'/libpp/handlers/'.$file)) {
			include_once BASEPATH.'/libpp/handlers/'.$file;
		}
	}	

	function doHandler(&$field, &$object_field, $handler, $extraArgs) {
		$function = 'PXInputHandler'.$handler;

		if (function_exists($function)) {
			$object_field = $function($field, $object_field, $extraArgs);
		}
	}

	// POST
	function getContentObject($format) {
		$object = array();
		$p      = array('request' => $this);

		foreach ($format->fields as $k=>$v) {
			$object[$k] = $v->displayType->getFromRequest($v, $object, $p);

			if (!empty($v->handler)) {
				$extraArgs = explode('|', $v->handler);
				$handler   = array_shift($extraArgs);

				$this->include_handler($handler);
				$this->doHandler($v, $object[$k], $handler, $extraArgs);
			}

			$object[$k] = $v->storageType->getFromRequest($v, $object, $p);
		}

		return $object;
	}

	function getObjectSysVars($format, $neededVars = array()) {
		$object['id'] = $this->postData->_getNumericVar('id');

		if ($this->postData->_IsSet(OBJ_FIELD_CHILDREN) || in_array(OBJ_FIELD_CHILDREN, $neededVars)) {
			$object[OBJ_FIELD_CHILDREN] = $this->postData->_getArrayVar(OBJ_FIELD_CHILDREN);
		}

		if ($this->postData->_IsSet('sys_accessmod')) {
			$object['sys_accessmod'] = $this->postData->_getNumericVar('sys_accessmod');
		}

		if ($this->postData->_IsSet('sys_accessput')) {
			$object['sys_accessput'] = $this->postData->_getNumericVar('sys_accessput');
		}

		return $object;
	}

	// GET/POST
	function getAck() {
		switch ($this->httpMethod) {
			case 'POST':    return $this->postData->_getCheckBoxVar('ack');
			default:        return $this->getData->_getCheckBoxVar('ack');
		}
	}

	// POST
	function getIniFileSettings() {
		return $this->postData->getIniFileSettings();
	}

	// POST
	function getAfterActionDeal() {
		return $this->postData->_IsSetVar('close') ? 'close' : 'back';
	}
	
	// ������� ���������� "������" ������������� ����������
	function getArea($default=NULL) {
		$area = $this->_getGetPostVar('area') ? $this->_getGetPostVar('area') : $this->getGetVar('area');
		return ($area == NULL) ? $default : $area;
	}
	
	// clean the fields for admin system
	function getOrderVar($format, $default_sort, $fields){
		$currentOrder = $this->GetVar($format.'_order');
		if(!$currentOrder) {
			return $default_sort;
		}

		$order = explode(" ", $currentOrder, 2);

		if(isset($order[1]) && strtoupper($order[1]) != 'DESC') unset($order[1]);
		if(!in_array($order[0], array_keys($fields))) $order[0] = null;

		return is_null($order[0]) || !$fields[$order[0]]->listed ? $default_sort : trim(implode(" ", $order));
	}

	/* From PXUserRequest */
	function getHostAndDir() {
		$a = $this->getPath();
		array_unshift($a, $this->getHttpHost());
		if (sizeof($a) > 1 && strstr($a[sizeof($a)-1], ".")) {
			array_pop($a);
		}
		return $a;
	}

	function getFile() {
		$a = $this->getPath();
		if (count($a)) {
			if (strstr($a[sizeof($a)-1], ".")) {
				return $a[sizeof($a)-1];
			}
		}

		return 'index.html';
	}
	
	function isIndexFile() {
		return $this->getFile() == 'index.html';
	}

	function getPart() {
		$part = $this->_getGetPostVar('part');
		return is_null($part) ? 'index.html' : $part;
	}
}
?>