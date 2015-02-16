<?php
namespace LightProcessExecutor\Message\EventListener;

use LightProcessExecutor\Message\Interceptor\Exception\InterceptorRouterException;

use LightProcessExecutor\Message\MessageEventProxy;

use LightProcessExecutor\Message\Interceptor\Interceptor;

use LightProcessExecutor\LightProcessExecutor;

use LightProcessExecutor\EventListener\DefaultRouterEventListener;

use LightProcessExecutor\Event\MessageEvent;

use LightProcessExecutor\Message\Handler\MessageHandler;

/**
 * This listener catches every event callbacks called by the executor router<br>
 * It then loops through each of its interceptors to determine whether an interceptor matches the event received in which case
 * it delegates the processing of the event to each handlers attached to the interceptor<br>
 * 
 * <b>Whenever, an interceptor decides to stop its propagation, it prevents another one of calling its handlers</b>
 * @author jpons
 *
 */
class MessageInterceptorEventListener extends DefaultRouterEventListener {

	private $interceptors = [];
	
	public function __construct(array $interceptors = []) {
		$this->interceptors = $interceptors;	
	}
	
	public function onInterruptReceive(MessageEvent $e) {
		$this->processMessageEventInterceptor(new MessageEventProxy($e));
	}
	
	/**
	 * An interceptor has nothing to do with this. It solely catches messages and exception
	 * (non-PHPdoc)
	 * @see LightProcessExecutor\EventListener.DefaultRouterEventListener::onPeerShutdown()
	 */
	public function onPeerShutdown(LightProcessExecutor $executor, $pid, array $lostMessages) {
		// Empty, nothing to do
		return;
	}
	
	/**
	 * Call each handlers for each interceptor that matches against the proxified object
	 * (non-PHPdoc)
	 * @see LightProcessExecutor\EventListener.DefaultRouterEventListener::onMessageReceived()
	 */
	public function onMessageReceived(MessageEvent $e) {
		$this->processMessageEventInterceptor(new MessageEventProxy($e));
	}
	
	/**
	 * Call each handlers for each interceptor that matches against the proxified object
	 * (non-PHPdoc)
	 * @see LightProcessExecutor\EventListener.DefaultRouterEventListener::onMessageSent()
	 */
	public function onMessageSent(MessageEvent $e) {
		$this->processMessageEventInterceptor(new MessageEventProxy($e));
	}
	
	/**
	 * Call each handlers for each interceptor that matches against the proxified object (non-PHPdoc)
	 * Note that the proxified object in this case is NULL, only the exception is given and injected into the proxy class  
	 * @see LightProcessExecutor\EventListener.DefaultRouterEventListener::onRouterError()
	 */
	public function onRouterError($operation, $errno, $errstr, \Exception $e = NULL) {
		$this->processMessageEventInterceptor(new MessageEventProxy(null, new InterceptorRouterException($errstr, $errno, $e)));
	}
	
	/**
	 * Adds an interceptor
	 * @param Interceptor $inter the interceptor to add
	 * @see Interceptor interceptor class
	 * @return void
	 */
	public function addMessageInterceptor(Interceptor $inter){
		$this->interceptors[] = $inter;
	}
	
	/**
	 * Removes an interceptor and returns true if it has been removed, false otherwise
	 * @param Interceptor $inter the interceptor to delete from the list of interceptors
	 * @return boolean true if removed, false otherwise
	 */
	public function removeMessageInterceptor(Interceptor $inter){
		for($i=0;$i<count($this->interceptors);$i++){
			if($this->interceptors[$i] === $inter){
				unset($this->interceptors[$i]);
				return true;
			}
		}
		return false;
	}
	
	private function processMessageEventInterceptor(MessageEventProxy $e){
		foreach($this->interceptors as $i){
			if(true === $i->matches($e)) {
				array_walk($i->getMessageHandlers(), function($handler) use($e) {
					$handler->intercept($e);
				});
				if(true === $i->stopPropagation()){
					return;
				}
			}
		}	
	}
}
