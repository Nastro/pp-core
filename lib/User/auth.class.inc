<?php
class PXUserAuthorized extends PXUser {
	function can($what, $format = NULL, $object = NULL) {
		if($format === NULL){
			$format = & new PXTypeDescription;
		}

		if($object === NULL){
			$object = array('id'=>NULL,'parent'=>NULL);
		}

		return $this->acl->check($what, $format, $object);
	}

	function checkAuth() {
		$app     = PXRegistry::getApp();
		$db      = PXRegistry::getDb();
		$request = PXRegistry::getRequest();
		$user    =& $this;

		foreach ($app->authrules as $rule => $params) {
			if (!$params['enabled']) {
				continue;
			}

			$authClass = 'PXAuth'.ucfirst(strtolower($rule));
			if (!class_exists($authClass)) {
				FatalError('����������� ������ ����������� '.$authClass);
			}

			$tmpAuth  = new $authClass($app, $request, $user, $db, $params);
			if (!$this->isAuthed()) {
				continue;
			}

			$this->auth =& $tmpAuth;

			if (isset($app->types[DT_GROUP])) {
				$groups = $db->getLinks($app->references[LINK_GROUP2USER], DT_USER, $this->id);
				$this->groups = array_unique(array_merge(array(0, (int)$this->data['parent']), array_keys($groups)));
			}
			break;
		}

		$this->acl->reload();
		return $this->auth->onAuth();
	}

	function aclType() {
		return (sizeof($this->groups) > 1) ? 'bygroup' : 'basic';
	}
}
?>