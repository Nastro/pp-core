<?php
/**
 * �������
 *
 * storageType serialized
 * displayType TABLE|col1width-..-colNwidth|height
 *             col1width-..-colNwidth ������������� ������ ��������, �������� 1-2-1-1
 *             ���� height = 0, �� � ������ ������ ������� ����� �������������� <input type="text">
 *             ���� height > 0, �� � ������ ������ ������� ����� �������������� <textarea> � height ��� �� ������ � ��������
 */
class PXDisplayTypeTable extends PXDisplayType {
	function buildInput($field, $object, $param=NULL) {
		$value  = isset($object[$field->name]) ? htmlspecialcharsArray($object[$field->name]) : array();
		$height = isset($field->displayTypeArgs[1]) ? $field->displayTypeArgs[1] : 0;

		return NLAbstractHTMLForm::BuildTableByCols($field->name, $value, explode("-", $field->displayTypeArgs[0]), $height);
	}

	function getFromRequest($field, $object, $param=NULL) {
		return $param['request']->postData->_GetArrayVar($field->name);
	}
}
?>