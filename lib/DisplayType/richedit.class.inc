<?php
/**
 * ���������� ��������
 *
 * storageType string
 * displayType RICHEDIT|width|height
 *             width �� ������������
 *             height ��� ������ � ��������
 */
class PXDisplayTypeRichedit extends PXDisplayTypeText {
	function buildInput($field, $object, $param=NULL) {
		$value = isset($object[$field->name]) ? htmlspecialchars($object[$field->name]) : NULL;
		return NLAbstractHTMLForm::BuildRichEdit($field->name, $value, $field->displayTypeArgs[1]);
	}
}
?>