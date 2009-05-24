<?php
/**
 * Checkbox
 *
 * storageType boolean
 * displayType CHECKBOX
 */
class PXDisplayTypeCheckbox extends PXDisplayType {
	function buildInput($field, $object, $param=NULL) {
		$value = (isset($object[$field->name])  && $object[$field->name] == true) ? true : false;
		return NLAbstractHTMLForm::BuildInputCheckBox($field->name, $value);
	}

	function buildCell($field, $object, $param=NULL) {
		$value = $object[$field->name];
		return $value ? '<div class="truecheck" title="��"></div>' : '';
	}

	function getFromRequest($field, $object, $param=NULL) {
		return $param['request']->postData->_GetCheckBoxVar($field->name);
	}
}
?>