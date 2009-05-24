<?php
/**
 * "�����������" ����
 * ����� �� ��������, �������� ������� ��� �� �������� �� ��������� ($field->defaultValue)
 * ��� ����� ����������� � PXOutputHandler'��
 *
 * displayType STATIC
 * storageType virtual
 */
class PXStorageTypeVirtual extends PXStorageType {
	function normalize($field, $object, &$param) {
		return (!is_null($field->defaultValue)) ? $field->defaultValue : NULL;
	}

	function notInDb($field, $object, $param=NULL) {
		return true;
	}
}

?>