<?php
class PXModuleProperties extends PXAbstractModule {
	var $_settings;
	var $_pseudoDatatype;

	function PXModuleProperties($area, $settings) {
		parent::PXAbstractModule($area, $settings);
		
		$this->app       = & PXRegistry::getApp();
		$this->_settings = $settings;
	}

	function _fillPseudoDatatype(){
		$this->_pseudoDatatype         = & new PXTypeDescription();
		$this->_pseudoDatatype->id     = 'properties';
		$this->_pseudoDatatype->title  = '��������� �����';

		$arToObject = create_function('&$val, $key', '$val = (object)array("name" => $key, "value" => $val);');

		foreach((array) $this->_settings['attribute'] as $formatString){
			$formatString = str_replace('|','&', $formatString);
			$dataDesc     = array();
			parse_str($formatString, $dataDesc);
			if(!isset($dataDesc['name'])){
				FatalError("� ������� ������ ��� ������ �� ����� ����������� ������������ �������� name!");
			}
			$dataDesc['description'] = isset($dataDesc['description']) ? utf8_encode($dataDesc['description']) : $dataDesc['name'];
			$dataDesc['storagetype'] = isset($dataDesc['storagetype']) ? $dataDesc['storagetype'] : 'string';
			$dataDesc['displaytype'] = isset($dataDesc['displaytype']) ? $dataDesc['displaytype'] : 'TEXT';
			if(isset($dataDesc['source']) && isset($this->app->directory[$dataDesc['source']])){
				//all directories already filled
				$_tmpSource = $dataDesc['source'];
				unset($dataDesc['source']);
			}
			str_replace(',','|', $dataDesc['displaytype']);
			array_walk($dataDesc, $arToObject);
			$field         = new PXFieldDescription(array_values($dataDesc), $this->app, $this->_pseudoDatatype);
			$field->listed = false;
			if(isset($_tmpSource)){
				$field->source = $_tmpSource;
				$field->values = & $this->app->directory[$_tmpSource];
				unset($_tmpSource);
			}
			$this->_pseudoDatatype->addField($field);
		}
	}
	
	function _checkForErrors(){
		if(!isset($this->_settings['attribute']) || !sizeof($this->_settings['attribute'])){
			return "���� �� ��������������� ���������������� ��������.";
		}

		if(!is_writable(BASEPATH . 'etc/properties.ini')){
			return "���� properties.ini �� �������� ��� ������ ��� �� ����������";
		}
		
		return false;
	}


	function adminIndex(&$app, &$request, &$user, &$db, &$layout) {
		$layout->setOneColumn();

		if(($error = $this->_checkForErrors()) !== false){
			$layout->assignError("INNER.0.0", $error);
			return;
		}
		
		$this->_fillPseudoDatatype();
		$propObject = PXModuleProperties::loadModel(array_keys($this->_pseudoDatatype->fields));
		
		$db->loadDirectoriesByType($this->_pseudoDatatype, $propObject);
		$objectForm = & new PXAdminObjectForm($this->_pseudoDatatype, $propObject);
		
		$formToHtml = $objectForm->MainForm($objectForm->format);
		$formToHtml = preg_replace("#<input .*?(?>name\s*=\s*('|\")area\\1).*?>#", '', $formToHtml); //FIXME: it's a hack, waiting for Widgets interface

		$html = $this->_editArea($formToHtml);
		
		$layout->Assign("INNER.0.0", $html);
	}

	function adminAction(&$app, &$request, &$user, &$db) {
		if(($error = $this->_checkForErrors()) !== false){
			FatalError($error);
		}
		
		$this->_fillPseudoDatatype();
		$propObject = $request->getContentObject($this->_pseudoDatatype);
		PXModuleProperties::_saveModel($this->_pseudoDatatype, $propObject);
	}

	function _editArea($objectForm) {
		return <<<HTML
			<h2>{$this->_pseudoDatatype->title}</h2>

			<form action="/admin/action.phtml" method="POST" enctype="multipart/form-data" class="autoheight">
				<input type="hidden" name="area" value="properties">
				{$objectForm}
				<input type="submit" class="button" value="���������">
			</form>
HTML;
	}

	function loadModel($filter = array()){
		$object = array();
		static $mapper;
		if(!isset($mapper)){
			$mapper = create_function('$search, $key, $outerScope','if(!in_array($key, $outerScope["filter"])) unset($outerScope["self"][$key]);');
		}
		if(file_exists($store = BASEPATH . 'etc/properties.ini')){
			$object = $this->app->properties;

			if(sizeof($filter) && $object){
				array_walk($object, $mapper, array('self' => &$object, 'filter' => $filter));
			}
		}
		
		return $object;
	}

	function _saveModel($format, $object){
		if(is_writable($store = BASEPATH . 'etc/properties.ini')){
			$arStrings = file($store);
			$newData = array();
			foreach($format->fields as $field){
				if($field->storageType->notInDB(NULL, NULL)) {
					continue;
				}
				
				$updated     = false;
				$param       = array('dbFields' => null, 'dbValues' => null);
				list($_ ,,)  = $field->storageType->normalizeObjectAttribute($field, $object, $param);
				$_           = trim(str_replace(array("\r","\n",'"'), '', $_)); //sanitize input

				$paramString = "{$field->name} = \"{$_}\"\n";
				
				foreach($arStrings as $line => $data){
					if(preg_match("#^(?>\s*{$field->name}\s*=)#iS", $data)){
						$arStrings[$line] = $paramString; //Update existing
						$updated = true;
					}
				}
				
				if(!$updated){
					$newData[] = $paramString; //Add new
				}
			}
			$arStrings = array_merge($arStrings, $newData);
			WriteStringToFile($store, join('', $arStrings));
			
			return true;
		}

		return false;
	}

}

?>