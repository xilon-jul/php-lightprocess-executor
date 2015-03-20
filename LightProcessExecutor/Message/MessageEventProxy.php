<?php
namespace LightProcessExecutor\Message;

use LightProcessExecutor\Message\Interceptor\Exception\InterceptorRouterException;

use LightProcessExecutor\Event\MessageEvent;

/**
 * A message event proxy proxifies a MessageEvent object by augmenting its responsabilites.
 * Whenever the lightprocessexecutor invokes its callback method, it can depending on the event provide a MessageEvent or information data about an error,
 * but in both case, it does not provide a common interface
 * This proxy class provides this and allow the caller to call any of the method contained in the MessageEvent proxified object
 * @author jpons
 *
 */
class MessageEventProxy {
	
	private $proxifiedObject = null;
	private $exception = null;
        private $messageType = null;

        const MESSAGE_SENT = 1;
        const MESSAGE_RECEIVED = 2;
        const MESSAGE_RECEIVED_INTERRUPTED = 3;
        const MESSAGE_EXCEPTION = 4;


	/**
	 * Constructs a new proxy class object by giving him the proxified object and the underlying exception if any
	 * @param MessageEvent $proxifiedObject the proxified object
	 * @param \Exception $e the exception
	 */
	public function __construct($messageTypeConstant, MessageEvent $proxifiedObject = null, \Exception $e = null){
		$this->proxifiedObject = $proxifiedObject;
                $this->exception = $e;
                $this->messageType = $messageTypeConstant;
        }
        
        /**
         * Returns the message event type where this interceptor hooks
         * @see MessageEventProxy::MESSAGE_XXX
         * @return int constant 
         */
        public function getMessageType(){
            return $this->messageType;
        }
	
	/**
	 * Returns true if this proxified object aggregates an exception, false otherwise 
	 * @return boolean true or false
	 */
	public function hasException(){
		return $this->exception !== null; 
	}
	
	/**
	 * Retrieves the proxified object exception
	 * @return InterceptorRouterException $exception 
	 */
	public function getException(){
		return $this->exception;
	}
	
	/**
	 * Magic method for handling class proxying to the proxified MessageEvent class
	 * @param unknown_type $method_name
	 * @param array $args
	 * @throws \BadMethodCallException
	 */
	public function __call($method_name, array $args = []){
		if($this->proxifiedObject !== null && true === method_exists($this->proxifiedObject, $method_name)){
			return call_user_method_array($method_name, $this->proxifiedObject, $args);
		}
		throw new \BadMethodCallException("Bad method $method_name invoked on proxy class. Proxified object does not contain this method");
	}
}
