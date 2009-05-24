<?php
// ���������� ����� ��� ����� ��������� � ������������� �������, ����������� ������������� (organizer)

class NLMailAddEvent extends NLMailVEvent {
	
	function NLMailAddEvent() {
		$this->_method = 'ADD';
		
		$this->_requiredProps = array(
			'DTStamp',
			'DTStart',
			'organizer',
			'sequence',
			'summary',
			'uid'
		);
		
		$this->_uselessProps = array(
			'recurrenceId',
			'requestStatus'
		);
		
		$this->NLMailVEvent();
	}
}
?>