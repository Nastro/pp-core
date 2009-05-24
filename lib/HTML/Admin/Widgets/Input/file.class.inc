<?php
class PXInputFile extends PXAdminWidget {
	var $value = array();
	var $name;
	var $entry;
	var $distension;
	
	function PXInputFile($name, $value, $entry = NULL) {
		$this->value = $value;
		$this->name = $name;
		$this->entry = $entry ? $entry : NULL;
		$this->type = 'file';
	}
	
	function setparam() {
		$this->distension = is_null($this->entry) ? '' : '['.$this->entry.']';
	}
	
	function preview() {
		return;
	}
	
	function param() {		
		$this->value['path']   = $this->value['fullpath'];
		$this->value['width']  = 0;
		$this->value['height'] = 0;
		
		return <<<HTML
			<input type="text" readonly value="{$this->value['filename']}" >
			<span>���</span>
			<input type="text" readonly value="{$this->value['size']}" >
			<span>������</span>
			<input type="text" readonly value="{$this->value['type']}" >
			<span>���</span>
HTML;
	}
	
	function view_copy_del() {
		if (!is_null($this->value['path'])) {
			$html = '<br>';

			$html .= '<a href="'.$this->value['path'].'" target="_blank" class="img-preview">��������</a> ';
			$html .= '<button onclick="ToClipboardMulti(\''.$this->value['path'].'\', '.(int)$this->value['width'].', '.(int)$this->value['height'].', \''.$this->type.'\'); return false;">HTML � �����</button>';

			$html .= '<br>';

			$picName = substr($this->value['path'], strrpos($this->value['path'], '/')+1);
			$html .= '<input class="checkbox" type="checkbox" id="'.$this->name.'_remove'.$this->distension.'" name="'.$this->name.'_remove['.$picName.']"'.$this->disable.'>';
			$html .= '<label for="'.$this->name.'_remove'.$this->distension.'">������� ����</label>';
		}
		else {
			$html = '';
		}
		return $html;
	}
	
	function html() {
		$this->setparam();
		
		$html  = '<div class="imagepreview">';
		
		$html .= $this->preview();

		$html .= '<input class="file" type="file" id="'.$this->name.$this->distension.'" name="'.$this->name.(!is_null($this->entry) && strlen($this->entry) ? '_edit' : '').$this->distension.'"'.$this->disable.' onpropertychange="Preview(this.value, \''.$this->type.'\', \''.$this->name.$this->distension.'\');"><br>';
		
		$html .= $this->param();

		$html .= $this->view_copy_del();

		$html .= '</div>';
		return $html;
	}
}
?>