<?php
/**
 * ��������� ����
 *
 * storageType string, integer ��� float
 * displayType TEXT|width|height|cell_maxlength
 *             width �� ������������
 *             ���� height = 0, �� �������� <input type="text">
 *             ���� height > 0, �� �������� <textarea> � height ��� �� ������ � ��������
 *             cell_maxlength ���������� ������������ ����� ��������, ��������� � ������
 */
class PXDisplayTypeText extends PXDisplayType {
	function buildInput($field, $object, $param=NULL) {
		$value = isset($object[$field->name]) ? htmlspecialchars($object[$field->name]) : NULL;
		$width = isset($field->displayTypeArgs[1]) ? (int)$field->displayTypeArgs[1] : 0;
		return NLAbstractHTMLForm::BuildText($field->name, $value, $width);
	}

	function buildCell($field, $object, $param=NULL) {
		$trunc = isset($field->displayTypeArgs[2]) && (int)$field->displayTypeArgs[2] > 0 ? (int)$field->displayTypeArgs[2] : 50;
		$value = strip_tags($object[$field->name]);
		return strlen($value) > $trunc ? substr($value, 0, $trunc).'&hellip;' : $value;
	}
}
?>