<?php
require_once 'treeBranch.class.inc';

class PXAdminTree extends PXAdminWidget {
	/**
	 * ����������� ������ PXAdminTree.
	 * �������������� ���� ������. ����������� ��� ���������� ������.
	 */
	function PxAdminTree(&$datatype, &$tree) {
		$this->datatype = $datatype;

		$this->tree             = $tree;
		$this->getData          = array();
		$this->selectedSid      = 0;
		$this->leafsToOpen      = array();
		$this->caption          = '';
		$this->showChildLinks   = false;
		$this->childLinkVarName = 'cid';
	}

	function &getLeaf($id) {
		return $this->tree->leafs[$id];
	}

	function isSelected($id) {
		return $this->selectedSid == $id;
	}

	function canChangeOrder() {
		return strpos($this->datatype->order, 'sys_order') !== false;
	}

	function setChildLink($chVarName) {
		$this->childLinkVarName = $chVarName;
	}

	function loadDropdownValues() {
		PXRegistry::getDb()->loadDropdownValuesForListedFields($this->datatype, $this->tree->toTable());
	}

	function setShowChildObjects($isShow) {
		$this->showChildLinks = $isShow;
	}

	function childsLink($id) {
		if($this->datatype->childs) {
			if($this->showChildLinks) { 
				return NLAbstractLayout::_BuildHref($this->childLinkVarName, $id);
			}
		}

		return null;
	}

	function isOpenLeaf($id) {
		return in_array($id, $this->leafsToOpen);
	}

	function html(){
		$caption = $this->htmlCaption();

		if(sizeof($this->tree->leafs[0]->children) > 0) {
			// ���������� ���������� ������
			return '<div class="tree">'.$caption.PXAdminTreeBranch::childrenHTML($this->tree->leafs[0]->children, $this).'</div>';
		} else {
			return '<div class="tree">'.$caption.'<div class="empty">&#8212;</div></div>';
		}
	}

	function setSelected($selectedSid) {
		$this->selectedSid = $selectedSid;

		// ��������� � ������ "����������������" ������� ���� ������� ����������
		$tmpId = $selectedSid;
		while (isset($this->tree->leafs[$tmpId]->parent)) {
			$tmpId = $this->tree->leafs[$tmpId]->parent;
			$this->leafsToOpen[] = $tmpId;
		}

	}

	function setOpenedLeafs($leafsToOpen) {
		$this->leafsToOpen = array_merge($this->leafsToOpen, $leafsToOpen);
	}

	function getPathname($id) {
		return isset($this->datatype->fields['pathname']) ? $this->tree->getFullPathString($id) : '';
	}

	function setCaption($text) {
		$this->caption = $text;
	}

	function htmlCaption() {
		if (!strlen($this->caption)) {
			return '';
		}

		$html = '';
		$html .= '<h2>'.$this->caption;

		if ($this->datatype->struct == 'tree') {
			$html .= '[<a href="'.NLAbstractLayout::_buildHref($this->datatype->id.'_view', 'plain').'">������</a>]';
		}

		$html .= '</h2>';
		return $html;
	}
}
?>