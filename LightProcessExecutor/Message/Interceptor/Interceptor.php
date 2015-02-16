<?php
namespace LightProcessExecutor\Message\Interceptor;

use LightProcessExecutor\Message\MessageEventProxy;

use LightProcessExecutor\LightProcessExecutor;

use LightProcessExecutor\Event\MessageEvent;

/**
 * An interceptor is used in conjunction with a message handler. 
 * It aims at intercepting a message based on the MessageEvent class properties / method and choose whether
 * this message should be processed by any of its handlers attached and returned by the getMessageHandler() method
 * @see LightProcessExecutor\Message\Handler\MessageHandler $handler the message handler
 * @author jpons
 */
interface Interceptor {
	
	/**
	 * Returns a boolean indicating whether this message should pass this interceptor
	 * @param MessageEvent $e the message to match against
	 * @return boolean true if the interceptor should handle this message, false otherwise
	 */
	public function matches(MessageEventProxy $e);

	/**
	 * Returns the message handler which would be invoked to process this message
	 * @return array of LightProcessExecutor\Message\Handler\MessageHandler $handler objects
	 */
	public function getMessageHandlers();
	
	/**
	 * Returns a boolean that indicates whether this interceptor is the last one to process this message. Thus, no action is taken after this interceptor
	 * catches the message exception the one defined by its own handler
	 * @return true if chain of interceptor should be stopped, false otherwise
	 */
	public function stopPropagation();
}