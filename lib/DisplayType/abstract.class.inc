<?php

/**
 * ������� ����� ����������� ����, �����
 * (� ���-�� ���) ����� ������� � ������������
 * displayType
 *
 * ����������� displayType ������ � ����� ������� ���|��������1|��������2
 * � ��������1 ������ ����������� ������ ��������,
 * � ��������� ����� ��� ����� �� ������������, �����������
 * ������������� ���
 */
class PXDisplayType {
	var $display;
	var $name;

	function PXDisplayType(&$display, $name) {
		$this->display = &$display;
		$this->name = $name;
	}

	/**
	 * ���������� � PXRequest::GetContentObject ���
	 * ���������� ����� ������� �� �������
	 * ���������� �������� ����
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return Mixed
	 */
	function getFromRequest($field, $object, $param=NULL) {
		return $param['request']->getVar($field->name);
	}


	/**
	 * ���������� � PXDatabase::ModifyContentObject �����
	 * ������������� ����� �������
	 * ���������� ����, ���������� �� ������������ ����
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return Boolean
	 */
	function preModifyObject($field, $object, $param=NULL) {
		return false;
	}

	/**
	 * ���������� html ��� ������ � ����� ������� �
	 * ������� ����� PXAdminObjectForm
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return String
	 */
	function buildRow($field, $object, $param=NULL) {
		$even = PXDisplayType::getRow();

		$html = "\n";
		$html .= '<tr'.(!$even ? ' class="even"' : '' ).'>';

		$html .= '<th>'.$field->description.': </th>';
		$html .= '<td>';

		$html .= $this->buildInput($field, $object, $param);

		$html .= '</td>';
		$html .= '</tr>';

		return $html;
	}

	/**
	 * ���������� html ��� ���� ��������/����� PXAdminObjectForm
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return String
	 */
	function buildArray($field, $object, $param=NULL) {
	}

	/**
	 * ���������� html ��� ������ � ����� ������� �
	 * ������ ��������/����� PXAdminObjectForm
	 *
	 * @param String             $name
	 * @param Array              $value
	 *
	 * @return String
	 */
	function buildArrayItem($name, $value) {
	}

	/**
	 * ���������� ���/����� ������ � ����� ������� �
	 * ������ PXAdminObjectForm
	 *
	 * @param Boolean            $even
	 *
	 * @return Boolean
	 */
	function getRow() {
		static $even = false;

		$even = !$even;
		return $even;
	}

	/**
	 * ���������� html � ���������� ����� ��� ���� ������� �
	 * ������ PXAdminObjectForm
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return String
	 */
	function buildInput($field, $object, $param=NULL) {
		return '<span class="error">����� ���� ���� '.$field->displayType->name.' ��� �� ������</span>';
	}

	/**
	 * ���������� html � ������� �������� ���� ������� �
	 * �������� PXAdminTable
	 *
	 * @param PXFieldDescription $field
	 * @param Array              $object
	 * @param Array|NULL         $param
	 *
	 * @return String
	 */
	function buildCell($field, $object, $param=NULL) {
		$value = is_string($object[$field->name]) ? strip_tags($object[$field->name]) : $object[$field->name];
		return $field->displayType->name.' - '.$value;
	}
}
?>