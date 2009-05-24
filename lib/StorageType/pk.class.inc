<?php
/**
 * ����� �����, id �������
 * � ���� ����� ��������� ��� INT4 PRIMARY KEY
 *
 * displayType HIDDEN, STATIC
 * storageType pk
 */
class PXStorageTypePk extends PXStorageTypeInteger {
	function objectsSortOrder($field, $object, &$param) {
		return PXStorageType::objectsSortOrder($field, $object, $param);
	}

	function normalizeObjectAttribute($field, $object, &$param) {
		return PXStorageType::normalizeObjectAttribute($field, $object, $param);
	}
}
?>