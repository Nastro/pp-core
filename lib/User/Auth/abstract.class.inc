<?
class PXAuthInterface {
	function PXAuth(&$app, &$request, &$user, &$db, $authParams) {
	}

	function auth() {
		return true;
	}

	function unAuth() {
		return true;
	}

	/**
	 * �����-�������, ���������� � PXUser::checkAuth() ����� �������� ������ acl, 
	 * ��������� ��������� �������������� ��������.
	 *
	 * @return bool
	 */
	function onAuth(){
		return true;
	}

	function encodePasswd($passwd) {
		return $passwd;
	}

	function __parseVar($var) {
		$var = preg_replace('/[^\w\.\@\,]/', '', $var);

		return $var;
	}
	
	function login(&$getFromRequest){
		return $getFromRequest->GetVar('login');
	}
	
	function passwd(&$getFromRequest){
		return $getFromRequest->GetVar('passwd');
	}
}
?>