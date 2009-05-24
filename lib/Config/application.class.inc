<?php
require_once 'Description/db.class.inc';

require_once 'Description/type.class.inc';
require_once 'Description/field.class.inc';

require_once 'Description/directory.class.inc';
require_once 'Description/ref.class.inc';

require_once 'Description/module.class.inc';
require_once 'bindingsQueue.class.inc';

/**
 * PXApplication - ������� �����.
 *
 * @subpackage Application
 */
class PXApplication {

	/**
	 * ������ ����������� � ���� ������
	 * @var Array
	 */
	var $dbDescription;

	/**
	 * ������ �������� datatypes {@link PXTypeDescription}
	 * @var Array
	 */
	var $types;

	/**
	 * ������ ������ ����� datatypes {@link PXRefDescription}
	 * @var Array
	 */
	var $references;

	/**
	 * ������ ������� {@link PXModuleDescription}
	 * @var Array
	 */
	var $modules;

	/**
	 * ������ �������� �� etc/properties.ini
	 * @var Array
	 */
	var $properties;

	/**
	 * ������ ������ ����������� �� etc/auth.ini
	 * @var Array
	 */
	var $authrules;

	/**
	 * ������ �������� ��������� �� etc/datatypes.xml
	 * @var Array
	 */
	var $directory;

	/**
	 * �������� ������� �� etc/modules.xml modules/bindings
	 * @var Array
	 */
	var $bindingsQueue;

	/**
	 * Singleton array with caching.
	 *
	 */
	public static function getInstance($engineClass) {
		static $instance = array();

		if (isset($instance[$engineClass])) {
			return $instance[$engineClass];
		}

		/* try to get config from cache */
		//$instance[$engineClass] = self::loadFromCache($engineClass);

		/* create new instance, if not cached */
		if (!isset($instance[$engineClass])) {
			$instance[$engineClass] = new PXApplication($engineClass);
		}

		return $instance[$engineClass];
	}

	/**
	 * ����������� ������
	 *
	 * @param String $engineClass - ��� ������ Engine, ������������ � ����� ����� ����
	 */
	private function __construct($engineClass) {
		$this->types = array();
		$this->directory = array();

		$this->FillDBDescription(self::parseIniFile($this->findConfigFile('database.ini')));
		$this->FillModules($this->findConfigFile('modules.xml'));
		$this->FillDataTypes($this->findConfigFile('datatypes.xml'));
		$this->FillDirectories($this->findConfigFile('datatypes.xml'));

		$this->properties = self::parseIniFiles($this->findConfigFiles('properties.ini'));
		$this->authrules  = self::parseIniFiles($this->findConfigFiles('auth.ini'));

		//todo: one file or many lang files?
		$this->fillLang($this->findConfigFile('lang.yaml'));

		$this->saveToCache($engineClass);
	}

	private static function parseIniFile($file) {
		return parse_ini_file($file, true);
	}

	private static function parseIniFiles($files) {
		/* we must merge array in reverse order: libpp + local + site,
		 * so local can redefine libpp settings, and site can override local */

		$result = self::parseIniFile(array_pop($files));

		while(count($files)) {
			$tmp = self::parseIniFile(array_pop($files));

			foreach($tmp as $k => $v) {
				if (is_array($v) and isset($result[$k]) and is_array($result[$k])) {
					$result[$k] = array_merge($result[$k], $v);
				} else {
					$result[$k] = $v;
				}
			}
		}
		return $result;
	}

	/**
	 *  Searches for the first file in order - site, local, libpp
	 */
	private static function findConfigFile($filename) {
		$files = self::findFilesUnless(
			$filename,
			create_function('$files', 'return count($files) > 0;')
		);

		if ($files) {
			return $files[0];
		}

		FatalError('����������� ���� �������� ' . $filename);
	}

	/**
	 *  Searches for all files at site, local, libpp dirs.
	 */
	private static function findConfigFiles($filename) {
		if ($files = self::findFilesUnless($filename)) {
			return $files;
		}

		FatalError('����������� ���� �������� '.$filename);
	}

	/*
	 * function is_enought($files_array) { <return TRUE when enought files collected> }
	 */
	private static function findFilesUnless($filename, $is_enough = NULL) {
		$files = array();

		foreach(self::configDirs() as $configDir) {
			$path = $configDir.$filename;

			if (file_exists($path)) {
				$files[] = $path;
			}

			if ($is_enough && $is_enough($files)) {
				return $files;
			}
		}

		return $files;
	}

	private static function configDirs() {
		return array(
			BASEPATH.'/site/etc/',
			BASEPATH.'/local/etc/',
			BASEPATH.'/libpp/etc/'
		);
	}

	//TODO:FIXME: REWRITE ME!
	private function fillLang ($filename) {
		function __ReplaceIncludeWhenParseLangYaml($replacement, $indent, $fileName) {
			$string = '';

			if(file_exists($fileName)) {
				$tmp = @file($fileName);

			} elseif(file_exists(BASEPATH.$fileName)) {
				$tmp = @file(BASEPATH.$fileName);

			} else {
				FatalError('�� ���� ����� '.$fileName);
			}

			if(is_array($tmp)) {
				foreach($tmp as $s) {
					if(!strlen(trim($s))) {
						continue;
					}

					$string .= $indent.$s;
				}
			}

			return $string;
		}

		$source = ReadFileToString($filename);

		$source = preg_replace('|^((\s*)include\s+(.+?))$|ime', "__ReplaceIncludeWhenParseLangYaml('$1', '$2', '$3');", $source);   // ������ include
		$source = preg_replace('/\t/',          ' ' ,         $source);   // � ����� ���� ��� �������������
		$source = preg_replace('/(\r?\n){2,}/', "\n",         $source);   // � ������ �������� �����

		$tmp = tempnam(BASEPATH.'tmp/lang/', 'lang');
		WriteStringToFile($tmp, $source);

		// Spyc'� �� �������� ��� �������, ������� �� ����� ������� ����������
		include_once 'spyc/spyc.php';
		$spyc = new Spyc();
		$this->langTree = $spyc->load($tmp);
		unlink($tmp);
	}


	/**
	 * ��������� ������ ����������� � ����� ������.
	 *
	 * @see NLDBDescription, $dbDescription
	 *
	 * @param Array $array - ������ � ����������� ����������� � ���� ������
	 */
	private function FillDBDescription($array) {
		$this->dbDescription = array();

		foreach ($array as $k=>$v) {
			$this->dbDescription[$k] = new NLDBDescription($v);
		}
	}

	/**
	 * ���������� ������� �� ({@link properties}) �� ����� ��� �������� �� ���������.
	 *
	 * @param String $k ����
	 * @param String $defaultValue - ������������ �������� �� ���������
	 */
	public function getProperty($k, $defaultValue=NULL) {
		return (isset($this->properties[$k])) ? $this->properties[$k] : $defaultValue;
	}

	/**
	 * ���������� ������������� ������ ��������� �������.
	 *
	 * @param Integer $leve  �������� �������� �������
	 * @return Array         ������ �� ��������� 'module_name' => $module
	 */
	public function getAvailableModules($level) {
		return array_filter($this->modules, create_function('$module', "return \$module->access <= $level;"));
	}

	/**
	 * ���������� ������������� ������ ����������� �����.
	 *
	 * @param NULL $formatName      �� ������������
	 * @param Object|Array $object  ������ ��� �������� ������ �����
	 * @return Array                ������ ����������� ����� ��� ������ ������
	 */
	public function getAllowedChilds($formatName, &$object) {
		if(!isset($this->types[$formatName])) {
			return array();
		}

		return $this->types[$formatName]->allowedChilds($object);
	}

	/**
	 * ���������� ������ ������������ ����������� �������� �������� ��������.
	 *
	 * @uses getAllowedChilds
	 *
	 * @param NULL			�� ������������
	 * @param Object|Array	������ ��� �������� ������ �����
	 * @return Array		������ ������������ ����������� ����� ��� ������ ������
	 */
	public function getAllowedChildsKeys($formatName, &$object) {
		return array_keys($this->getAllowedChilds($formatName, $object));
	}


	/*
	 * todo: two methods do the same?
	 */
	public function initContentObject($formatName) {
		$object = array();

		foreach ($this->types[$formatName]->fields as $k=>$v) {
			$object[$k] = $v->defaultValue;
		}

		return $object;
	}

	public function InitObject($formatName) {
		return $this->InitContentObject($formatName);
	}

	/**
	 * ��������� {@link PXApplication::$types} � {@link PXApplication::$references} ������� �� �����.
	 *
	 * � �������� ��������� ������ ������������ ���� etc/datatypes.xml. <br>
	 * XPath /model/datatypes/datatype � /model/references/reference.
	 *
	 * @uses PXFieldDescription
	 * @uses PXDirectoryDescription
	 * @uses PXRefDescription
	 * @uses PXTypeDescription
	 *
	 * @todo ��� ������ ��������� �� ������� ������������ debug �-��� d2(), ��� ��������� ?
	 *
	 * @param String $fileName	��� �����
	 */
	private function FillDataTypes($fileName) {
		$dom = PXML::load($fileName);
		if (!$dom) {
			FatalError("������ � ����� �������� <EM>$fileName</EM>");
		}
		$domDatatypes = $dom->xpath("/model/datatypes/datatype");
		if (!is_array($domDatatypes) || count($domDatatypes) == 0) {
			FatalError("�� ���������� ��������� ������ �������");
		}

		foreach ($domDatatypes as $domDatatype) {
			$type = new PXTypeDescription();

			$attrs = $domDatatype->attributes();
			$nodes = $domDatatype->childNodes();

			foreach ($attrs as $attr) {
				switch ($attr->name) {
					case 'name':          $type->id            = $attr->value;                       break;
					case 'description':   $type->title         = utf8_decode($attr->value);          break;
					case 'orderby':       $type->order         = $attr->value;                       break;
					case 'parent':        $type->parent        = $attr->value;                       break;
					case 'bydefault':     $type->byDefault     = self::_mapByDefaults($attr->value); break;
					case 'struct':        $type->struct        = $attr->value;                       break;
					case 'outputhandler': $type->outputHandler = $attr->value;                       break;

					default:              d2("Unknown attr name: " . $attr->name . __LINE__);        break;
				}
			}

			if (!isset($type->order)) {
				$type->order = 'sys_order';
			}

			foreach ($nodes as $node) {
				switch ($node->nodeName()) {
					case 'attribute':
						$nodeattrs = $node->attributes();
						$field = new PXFieldDescription($nodeattrs, $this, $type);

						if(is_null($field->listed)) {
							$field->listed = (bool) ($field->name === 'title');
						}

						$type->addField($field);
						break;
					default:
						d2("Unknown node name: " . $node->nodeName());
						break;
				}
			}

			$this->types[$type->id] = $type;
		}

		foreach ($this->types as $k=>$v) {
			if (!empty($v->parent)) {
				if (isset($this->types[$v->parent])) {
					$this->types[$v->parent]->childs[] = $k;
				} else {
					FatalError("������ ������: {$v->id} �� ����� ���� �������� �� ��������� � {$v->parent}, �������� {$v->parent} �����������");
				}
			}
		}

		$domReferences = $dom->xpath("/model/references/reference");

		if (!is_array($domReferences) || count($domReferences) == 0) {
			return;
		}

		foreach ($domReferences as $domReference) {
			$attrs = $domReference->attributes();
			$ref = new PXRefDescription();
			$nodes = $domReference->childNodes();

			foreach ($attrs as $attr) {
				switch (trim($attr->name)) {
					case 'name':              $ref->name             = $attr->value; break;
					case 'from':              $ref->from             = $attr->value; break;
					case 'to':                $ref->to               = $attr->value; break;
					case 'restrictby':        $ref->restrictBy       = $attr->value; break;
					case 'filter-to':         $ref->filterTo         = $attr->value; break;
					case 'filter-from':       $ref->filterFrom       = $attr->value; break;
					case 'load':              $ref->load             = $attr->value; break;
					case 'multiple':          $ref->multiple         = true;         break;
					case 'bydefault':         $ref->byDefault        = $this->_mapRefByDefaults($attr->value); break; //all or linked
					case 'hidden':            $ref->hidden           = true;         break;
				}
			}

			foreach ($nodes as $node) {
				switch ($node->nodeName()) {
					case 'attribute':
						$nodeattrs = $node->attributes();
						d($nodeattrs);
						$field = new PXFieldDescription($nodeattrs, $this);

						$ref->addField($field);
						break;
					default:
						d2("Unknown node name in reference: ".$node->nodeName());
						break;
				}
			}
			$this->references[$ref->name] = $ref;
			if (!isset($this->types[$ref->from]) || !isset($this->types[$ref->to])) {
				FatalError("������ ������: ����� <em>{$ref->name}</em> ����������, ����������� ���� �� �����");
			}
			$this->types[$ref->from]->references[$ref->to] = &$this->references[$ref->name];
			$this->types[$ref->to]->references[$ref->from] = &$this->references[$ref->name];
		}
	}

	/**
	 * ��������� {@link PXApplication::$directory} ������� �� �����.
	 *
	 * � �������� ��������� ������ ������������ ���� etc/datatypes.xml. <br>
	 * XPath /model/directories/directory.
	 *
	 * @uses PXDirectoryDescription
	 *
	 * @param String $fileName	��� �����
	 */
	private function FillDirectories($fileName) {
		$dom = PXML::load($fileName);

		if (!$dom) {
			FatalError("������ � ����� �������� <EM>$fileName</EM>");
		}
		$domDirectories = $dom->xpath("/model/directories/directory");
		foreach ($domDirectories as $domDirectory) {
			$name = $domDirectory->getAttribute('name');

			if (!isset($this->directory[$name])) {
				$this->directory[$name] = new PXDirectoryDescription($name);
			}

			$curDir = &$this->directory[$name];
			$curDir->load         = $domDirectory->getAttribute('load');
			$curDir->schema       = $domDirectory->getAttribute('schema');
			$curDir->source       = $domDirectory->getAttribute('source');
			$curDir->sourceDir    = dirname($fileName).DIRECTORY_SEPARATOR;

			$displayfield = trim($domDirectory->getAttribute('displayfield'));
			$curDir->displayField = strlen($displayfield) ? $displayfield : 'title';

			$location = $domDirectory->getAttribute('location');
			$curDir->location = ($location != '') ? $location : NULL;

			if ($curDir->load == 'automatic' && $curDir->schema == 'xml') {
				$this->LoadXMLDirectory($curDir, false);
			}
		}
	}

	/**
	 * ��������� ���������� (directory) �� ������
	 * ���������� {@link PXDatabase::_GetData()} ��� �������� ��������� �� ���� ������
	 *
	 * @param PXDirectoryDescription $directory
	 */
	public function LoadXMLDirectory(&$directory, $addDefault = true) {
		$file          = $directory->sourceDir.$directory->source;
		$xpathSelector = $directory->location;

		$dom2 = PXML::load($file);

		if (!$dom2){
			FatalError("������ � ����� ����������� <EM>$file</EM>");
		}
		$results = $dom2->xpath($xpathSelector);
		if ($results) {
			$nodes = $results[0]->childNodes();

			foreach ($nodes as $node) {
				$id = $node->getAttribute('id');

				$directory->values[] = array(
					'id'    => $id,
					'title' => utf8_decode($node->nodeValue())
				);

				$attrs = $node->attributes();
				foreach ($attrs as $name => $value) {
					if ($name == 'id') {
						continue;
					}

					$directory->values[$id][$name] = utf8_decode($value->value);
				}
			}
			$directory->SetLoaded();
		}

		$directory->SetLoaded($addDefault);
	}

	/**
	 * ��������� {@link PXApplication::$modules} ������� �� �����.
	 *
	 * � �������� ��������� ������ ������������ ���� etc/modules.xml. <br>
	 * XPath /modules/module
	 *
	 * @uses PXModuleDescription
	 *
	 * @param String $fileName ��� �����
	 */
	private function FillModules($fileName) {
		$dom = PXML::load($fileName);

		if (!$dom) {
			FatalError("������ � ����� �������� <em>$fileName</em>");
		}

		$domModules = $dom->xpath("/modules//module");

		if (!$domModules || empty($domModules)) {
			FatalError("�� ���������� ������ �������");
		}

		foreach($domModules as $domModule) {
			$module = new PXModuleDescription();
			$module->setAttrs($domModule->attributes());
			$module->lookPackage($dom);

			$nodes = $domModule->childNodes();
			foreach ($nodes as $node) {
				$module->settings[$node->nodeName()][] = utf8_decode($node->nodeValue());
			}

			foreach ($module->settings as $k=>$v) {
				if (count($v) == 1) {
					$module->settings[$k] = $v[0];
				}
			}

			$this->setModules($module);
		}


		$domBindings = $dom->xpath('/modules/bindings');
		$this->bindingsQueue = new PXBindingsQueue();

		foreach($domBindings as $domBinding) {
			$nodes = $domBinding->childNodes();

			foreach ($nodes as $node) {
				$domRules = $node->childNodes();

				foreach($domRules as $domRule) {
					$this->bindingsQueue->add($domRule, $node->nodeName());
				}
			}
		}

		$this->bindingsQueue->sort();
	}

	function setModules($module) {
		$this->modules[$module->getName()] = $module; 
	}

	/**
	 * ����������� 'all', 'selected', 'none' � �������� ��������.
	 *
	 * @see PP_CHILDREN_FETCH_ALL, PP_CHILDREN_FETCH_SELECTED, PP_CHILDREN_FETCH_NONE
	 * @access protected
	 * @param String $string
	 * @return Integer
	 */

	/* FIXME - this is private, check external usage!!! i.e. objectform.class.inc:266 */
	public static function _mapByDefaults($string) {
		if ($string == 'all') {
			return PP_CHILDREN_FETCH_ALL;
		}
		if ($string == 'selected') {
			return PP_CHILDREN_FETCH_SELECTED;
		}
		if ($string == 'none') {
			return PP_CHILDREN_FETCH_NONE;
		}
		return NULL;
	}

	function _mapRefByDefaults($string){
		switch($string){
			default:
			case 'all':
				return false;
			case 'linked':
				return true;
		}
	}

	/**
	 * ��������� ������� ������������� ����� �������� � ��������� ���.
	 *
	 * ���� ���������� ���������� tmp/cache/config ���� �� �� ��������������� � this.
	 * ������������ ����������� �� ������� ���������� ��������� �������� � ������ �������.
	 *
	 * @param  String   $engineClass ������� � �������
	 * @return Boolean  true ���� ���� ����������
	 */
	private static function loadFromCache($engineClass) {
		$cacheFile = self::_cacheFilename($engineClass);

		if (!file_exists($cacheFile)) {
			return null;
		}

		$tmp = stat($cacheFile);
		$cacheMtime = $tmp['mtime'];

		foreach(self::configDirs() as $configDir) {
			$d = new NLDir($configDir);

			while ($entry = $d->ReadFull()) {
				$tmp = stat($entry);

				if ($tmp['mtime'] >= $cacheMtime) {
					return null;
				}
			}
		}

		return unserialize(ReadFileToString($cacheFile));
	}

	/**
	 * �������� ��������� PXApplication � ����.
	 *
	 * ���� PXApplication ������������� � tmp/cache/config ����.
	 */
	private function saveToCache($engineClass) {
		$cacheFile = self::_cacheFilename($engineClass);

		MakeDirIfnotExists(dirname($cacheFile));
		WriteStringToFile($cacheFile, serialize($this));
	}

	private static function _cacheFilename($name){
		return BASEPATH.'/tmp/cache/config/'.strtolower($name);
	}
}

?>