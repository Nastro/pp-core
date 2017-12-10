<?php

namespace PP\Lib\Engine;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use PP\Lib\Database\Driver\PostgreSqlDriver;
use PP\Lib\Html\Layout\LayoutInterface;
use PP\ApplicationCacheFactory;

abstract class AbstractEngine {

	/** @var \PXModuleDescription[] */
	protected $modules;

	/** @var string */
	protected $area;

	/** @var ApplicationCacheFactory */
	protected $app = ['factory' => 'ApplicationCacheFactory', 'helper' => true];

	/** @var \PXRequest */
	protected $request = ['factory' => 'PXRequest'];

	/** @var \PXDatabase|PostgreSqlDriver */
	protected $db = ['factory' => 'PXDatabase', 'helper' => true];

	/** @var LayoutInterface */
	protected $layout = ['factory' => 'PP\Lib\Html\Layout\NullLayout'];

	/** @var \PXUserAuthorized */
	protected $user = ['factory' => 'PXUserAuthorized'];

	/**
	 * @var \Symfony\Component\DependencyInjection\ContainerInterface
	 */
	protected $container = ['factory' => 'Symfony\Component\DependencyInjection\ContainerBuilder', 'helper' => true];

	/**
	 * @var array
	 */
	protected $initOrder = ['container', 'app', 'db', 'request', 'user', 'layout'];

	// TODO: refactor
	function __construct() {
		$this->initApplication();
		$this->saveToRegistry();
	}

	/**
	 * @return $this
	 */
	public function start() {

		$this->user->setDb($this->db);
		$this->user->setApp($this->app);
		$this->user->setRequest($this->request);
		$this->user->checkAuth();

		/** @var \PXTriggerDescription $t */
		foreach ($this->app->triggers->system as $t) {

			/** @var \PXAbstractSystemTrigger $trigger */
			$trigger = $t->getTrigger();
			$trigger->onAfterEngineStart($this);
		}

		$this->app->loadProperties($this->db);
		$this->db->loadDirectoriesAutomatic($this->app->directory);

		$this->initModules();

		return $this;
	}

	/**
	 * Initializes DI container.
	 *
	 * @param string $klass
	 * @throws \InvalidArgumentException if services.yml is not found
	 */
	protected function initContainer($klass) {
		$path = APPPATH . 'config';
		$container = new $klass();
		$loader = new YamlFileLoader($container, new FileLocator($path));

		$loader->load('services.yml');
		$this->container = $container;
	}

	protected function initApplication() {
		foreach ($this->initOrder as $var) {
			if (!is_array($this->$var) || empty($this->{$var}['factory'])) {
				continue;
			}

			if (!class_exists($this->{$var}['factory'])) {
				\FatalError("Factory class '{$this->{$var}['factory']}' for '{$var}' is not exists !");
			}

			$initHelper = (!empty($this->{$var}['helper']) && method_exists($this, 'init' . ucfirst($var)) ? 'init' . ucfirst($var) : false);

			if ($initHelper) {
				$this->$initHelper($this->{$var}['factory']);
			} else {
				$this->$var = new $this->{$var}['factory'];
			}
		}
	}

	protected function saveToRegistry() {
		foreach (array_keys(get_object_vars($this)) as $var) {
			if (is_object($this->$var) && \PXRegistry::canSaveIt($var)) {
				call_user_func_array(array("PXRegistry", 'set' . ucfirst($var)), array(&$this->$var));
			}
		}

		$this->container->compile(true);
	}

	protected function initApp($klass) {
		$this->app = ApplicationCacheFactory::create($this);
	}

	protected function initDb($klass) {
		$this->db = new $klass($this->app);
	}

	protected function initModules() {
		$rArea = $this->request->getArea();

		if (!isset($this->app->modules[$rArea])) {
			Finalize('/');
		}

		$this->modules = $this->app->modules;
		$this->area = $rArea;
	}

	abstract function runModules();

	public function engineClass() {
		return PX_ENGINE_USER;
	}

	protected function checkArea($area) {
		if (!isset($this->modules[$area])) {
			FatalError('Некорректный параметр area = <em>' . strip_tags($this->area) . '</em>');
		}
	}

	public function getContainer() {
		return $this->container;
	}

}
