<?php
/**
 * ������
 * �������� � site/htdocs/ai/���_������/��_�������/���_����/���_�����.swf
 * ��������: site/htdocs/ai/banner/125846/movie/banner.swf
 *
 * displayType FLASH
 * storageType flash
 */
class PXStorageTypeFlash extends PXStorageTypeImage {
	function proceedFile($field, $object, &$param) {
		return parent::proceedFile($field, $object, $param, ENUM_ALLOWED_FLASHTYPES);
	}

	function normalize($field, $object, &$param) {
		return parent::normalize($field, $object, $param, ENUM_ALLOWED_FLASHTYPES);
	}
}
?>