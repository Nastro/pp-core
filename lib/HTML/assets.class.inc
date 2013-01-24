<?php

	final class PXHtmlAssetsManager {
		
		private $assets = array();
		private $assetsRoot;
		private $allowedBase;
		private static $self;
		
		public static function getInstance(){
			if (!self::$self) {
				$args = func_get_args();
				$self = new ReflectionClass(__CLASS__);
				self::$self = $self->newInstanceArgs($args);
			}
			
			return self::$self;
		}
		
		public function __construct($assetsRoot, array $allowedBase) {
			$this->assetsRoot  = $assetsRoot;
			$this->allowedBase = $allowedBase;
		}
		
		public function addFileToBundle($name, $type, $group = null) {
			$group || $group = 'default';
			if (!ctype_alpha($type)) {
				return;
			}
			$isSafe = false;
			foreach($this->allowedBase as $base) {
				if (strpos(realpath($name), realpath($base)) === 0) {
					$isSafe = true;
					break;
				}
			}
			if (!$isSafe) {
				return;
			}
			$this->assets[$type][$group][] = $name;
		}
		
		public function makeAssetsBundle($type, $delim = "\n", $group = null) {
			$group || $group = 'default';
			if (empty($this->assetsRoot) || empty($this->assets[$type][$group])) {
				return;
			}

			$bundleContent = array();
			foreach($this->assets[$type][$group] as $name) {
				$bundleContent[] = file_get_contents($name);
			}
			$bundleContent = join($delim, $bundleContent);

			$md5 = md5($bundleContent);
			$md5subdir = substr($md5, 0, 2);
			$bundleName     = sprintf("asset-%s.%s", $md5, $type);
			$bundleRelPath  = "/{$type}/{$group}/{$md5subdir}/{$bundleName}";
			$bundleFullPath = $this->assetsRoot . $bundleRelPath;

			if (!file_exists($bundleFullPath)) {
				$bundleTypeRoot = "{$this->assetsRoot}/{$type}/{$group}/{$md5subdir}";

				MakeDirIfNotExists($bundleTypeRoot);
				WriteStringToFile($bundleFullPath, $bundleContent);
			}

			$this->assets[$type][$group] = array(); //Make an asset blank for future use

			return array($bundleFullPath, $bundleRelPath, filemtime($bundleFullPath));
		}
	}