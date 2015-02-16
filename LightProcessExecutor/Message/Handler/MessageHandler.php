<?php
namespace LightProcessExecutor\Message\Handler;

use LightProcessExecutor\Message\MessageEventProxy;

use LightProcessExecutor\Message\Message;

use LightProcessExecutor\LightProcessExecutor;


/**
 * A message handler receives a MessageEventProxy object that encapsulates acts as a proxy for the MessageEvent class and adds 
 * augment its behavior by adding new methods  
 * @author jpons
 */
interface MessageHandler {
	
	/**
	 * Intercept a proxyfied message and do something with it
	 * @param MessageEventProxy $event the proxified message
	 * @return void
	 */
	public function intercept(MessageEventProxy $event);
}
