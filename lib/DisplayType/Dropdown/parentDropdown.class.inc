<?php
/**
 * ���������� ������ � ������� �������� (��� ������� ���� parent)
 *
 * storageType integer
 * displayType PARENTDROPDOWN|width
 *             width �� ������������
 */
class PXDisplayTypeParentdropdown extends PXDisplayTypeDropdown {
	function buildInput($field, $object, $param=NULL) {
		$value    = isset($object[$field->name]) ? htmlspecialchars($object[$field->name]) : NULL;
		list($format,$parentFormat) = parent::getFormats($param);

		$tree     = parent::getParentTree($object,$parentFormat);
		$restrictedId = $format == $parentFormat ? getFromArray($object, 'id') : null;
		$parents  = $tree->getPlainTree($restrictedId);

		foreach($parents as $k => $v){
			@$allowed =& $tree->leafs[$k]->content['allowed'];

			if(isset($allowed[$format]) || $format == $parentFormat){
				$parents[$k] = array('title' => $v);
			} else {
				$parents[$k] = array('title' => $v, 'disabled' => true);
			}
		}

		return NLAbstractHTMLForm::BuildParentDropDown($field->name, $value, $parents);
	}
}

?>