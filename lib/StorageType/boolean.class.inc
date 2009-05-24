<?php
/**
 * ���������� ����, ����
 * � ���� ����� ��������� ��� BOOL
 *
 * displayType HIDDEN, CHECKBOX, STATIC
 * storageType boolean
 */
class PXStorageTypeBoolean extends PXStorageType {
	function getFromRequest($field, $object, &$param) {
		return (bool)$object[$field->name];
	}

	function objectsSortOrder($field, $object, &$param) {
		return $this->objectsSortOrderString($field, $object, $param);
	}

	function normalizeObjectAttribute($field, $object, &$param) {
		$k = $field->name;
		$v = (bool)$object[$k] ? '1' : '0';

		if(is_array($param['dbFields'])) {
			$param['dbFields'][] = $k;
		}

		if(is_array($param['dbValues'])) {
			$param['dbValues'][] = $v;
		}

		return array($v, $param['dbFields'], $param['dbValues']);
	}

	function normalize($field, $object, &$param) {
		return $param['db']->importBoolean($object[$field->name]);
	}
}
?>