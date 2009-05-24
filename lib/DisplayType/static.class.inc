<?php
/**
 * ��������������� ����
 *
 * storageType ����� ���� �����, �� ��� ������� integer ��� string
 * displayType STATIC
 */
class PXDisplayTypeStatic extends PXDisplayType {
	function buildInput($field, $object, $param=NULL) {
		$k = $field->name;
		$value = isset($object[$k]) ? htmlspecialchars(is_array($object[$k]) ? print_r($object[$k], true) : $object[$k]) : NULL;

		$values = isset($field->values) ? $field->values->GetList() : array();
		return NLAbstractHTMLForm::BuildHidden($field->name, $value).(isset($values[$value]) ? $values[$value] : $value);
	}

	function buildCell($field, $object, $param=NULL) {
		$value = $object[$field->name];
		return isset($field->values->values[$value]) && isset($field->values->values[$value]['title']) ? $field->values->values[$value]['title'] : $value;
	}

	function preModifyObject($field, $object, $param=NULL) {
		return (bool)$param['skipHidden'];
	}
}
?>