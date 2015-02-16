<?php
namespace LightProcessExecutor\EventListener;

use LightProcessExecutor\Event\MessageEvent;

use LightProcessExecutor\LightProcessExecutor;

use LightProcessExecutor\EventListener\RouterEventListener;

/**
 * A convenient default implementation of a router listener that handles the priority parameter. This implementation has to be sub-classed by a user specific
 * child class
 * @author jpons
 * @see LightProcessExecutor\EventListener\RouterEventListener router interface
 */
abstract class DefaultRouterEventListener implements RouterEventListener {

	private $priority = 0;
	
	public function __construct($priority = 0){
		$this->priority = $priority;
	}
	
	public function getPriority() {
		return $this->priority;
	}
	
	public function onInterruptReceive(MessageEvent $e){
		throw new \BadMethodCallException("Method need user-specific implementation");
	}
	
	public function onPeerShutdown(LightProcessExecutor $executor, $pid, array $lostMessages){
		throw new \BadMethodCallException("Method need user-specific implementation");
	}
	
	public function onMessageReceived(MessageEvent $e){
		throw new \BadMethodCallException("Method need user-specific implementation");
	}
	
	public function onMessageSent(MessageEvent $e){
		throw new \BadMethodCallException("Method need user-specific implementation");
	}
	
	function onRouterError($operation, $errno, $errstr, \Exception $e = NULL){
		throw new \BadMethodCallException("Method need user-specific implementation");
	}
}
