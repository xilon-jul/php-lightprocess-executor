<?php
namespace LightProcessExecutor\Composer;

use Composer\Script\Event;

class LightProcessExecutorRequirements
{
	const MODULE_EVENT_MIN_VERSION = '1.7.0';
	
	public static function preInstall(Event $event) {
		if(!extension_loaded('event')){
			throw new \Exception("Extension event must be installed");
		}
		// Extension event is found check extension version
		$version = self::get_module_version('event');
		if(version_compare($version, self::MODULE_EVENT_MIN_VERSION) < 0){
			throw new \Exception(sprintf("Extension event found but minimal version requirement is %s, current is %s", self::MODULE_EVENT_MIN_VERSION, $version));
		}
	}
	
	private static function get_module_version($module){
		$output = trim(shell_exec("php -i | grep -A 15 '$module' | grep -i 'version' | head -1"));
		list(,$version) = preg_split('/=>/', $output);
		return  trim($version);
	}
}