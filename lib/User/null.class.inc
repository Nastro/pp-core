<?php
class PXUserNull extends PXUser {
	function can($what, $format, $object) {
		FatalError('��... � ������ �������� ���� ��� ��� �� ������?');
	}
}
?>