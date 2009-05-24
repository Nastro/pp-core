<?php
/**
 * ���� � �����
 * � ���� ����� ��������� ��� TIMESTAMP
 *
 * displayType HIDDEN, STATIC, TIMESTAMP, TIME, DATE
 * storageType timestamp
 */
class PXStorageTypeTimestamp extends PXStorageType {
	function normalizeObjectAttribute($field, $object, &$param) {
		$object[$field->name] = $object[$field->name]=== 'null' ? NULL : $param['db']->exportDateTime($object[$field->name]);
		$v = $this->normalizeObjectAttributeString($field, $object, $param);

		return $v;
	}

	function objectsSortOrder($field, $object, &$param) {
		return $this->objectsSortOrderString($field, $object, $param);
	}

	function normalize($field, $object, &$param) {
		return $param['db']->importDateTime($object[$field->name]);
	}

	function createPathname($field, $object, &$param) {
		if (isset($object[$field->name])) {
			return preg_replace("/^(\d{2})\.(\d{2})\.(\d{4})\s(\d{2}):(\d{2}):(\d{2}).*/", "$3$2$1-$4$5", trim($object[$field->name]));
		}
	}
}
?>