<?php
/**
 * ������� ����� ����������� ����������, ��������� �� �������
 * (� ���-�� ���) ����� ������� � ������������ storageType
 */
class PXStorageType {
	var $field;
	var $name;

	function PXStorageType(&$field, $name) {
		$this->field = &$field;
		$this->name = $name;
	}

	/**
	 * ���������� ��������� ������� ��������
	 * �������� � ������� �� ��������� �������
	 * ���������� ��������
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return String
	 */
	function objectsSortOrder($field, $object, $param=NULL) {
		return $field->description;
	}

	function objectsSortOrderString($field, $object, &$param) {
		$html = '<a ';

		switch($param['order']) {
			case ($field->name.' DESC') :
				$html .= ' class="down"';
				$order =  $field->name;
				$title = '';
				break;

			case $field->name :
				$html .= ' class="up"';
				$order = $field->name.' DESC';
				$title = ' � �������� �������';
				break;

			default:
				$order =  $field->name;
				$title = '';
				break;
		}

		$html .= ' href="'.$param['table']->_BuildHref($param['dtype'].'_order', $order).'"';
		$html .= ' title="����������� �� ���� &bdquo;'.$field->description.'&ldquo;'.$title.'">'.$field->description.'</a>';

		return $html;
	}

	/**
	 * ���������� ������������� �������� ���� �� �������
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return Mixed
	 */
	function getFromRequest($field, $object, &$param) {
		return $object[$field->name];
	}

	/**
	 * ���������� ������������� ��������
	 * ���� ����� ����������� ������� � ����
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return Array
	 */
	function normalizeObjectAttribute($field, $object, &$param) {
		return array(NULL, $param['dbFields'], $param['dbValues']);
	}

	function normalizeObjectAttributeString($field, $object, &$param) {
		$k = $field->name;
		$v = $object[$k];

		if(is_array($param['dbFields'])) {
			$param['dbFields'][] = $k;
		}

		if(is_array($param['dbValues'])) {
			$param['dbValues'][] = $v;
		}

		return array($v, $param['dbFields'], $param['dbValues']);
	}

	/**
	 * ���������� ������� ������ (SQL) ������� �����
	 * � ����
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return String
	 */
	function searchLikeQuery($field, $object, &$param) {
		return '';
	}

	/**
	 * ��������� ����� �� $_FILES � site/htdocs/ai
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return String
	 */
	function proceedFile($field, $object, &$param) {
		return null;
	}

	/**
	 * ���������� ������������� ��������
	 * ���� ����� ������� ������� �� ����
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return Mixed
	 */
	function normalize($field, $object, &$param) {
		return isset($object[$field->name]) ? $object[$field->name] : NULL;
	}

	/**
	 * ������� pathname �������
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return String
	 */
	function createPathname($field, $object, &$param) {
		return '';
	}

	/**
	 * ���������� � PXDatabase::_createSelector ��� �����������
	 * ������ ����� ������� ����������� �� ����
	 * ���������� ����, ��������� �� ���� �� ����
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return Boolean
	 */
	function notInDb($field, $object, $param=NULL) {
		return false;
	}

	/**
	 * ���������� ����������� ���������� ������ �������� StorageType � PXAdminObjectForm::LinksForm
	 */
	function filterable(){
		return false;
	}
}
?>