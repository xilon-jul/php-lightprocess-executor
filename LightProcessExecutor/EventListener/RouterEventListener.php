<?php
namespace LightProcessExecutor\EventListener;

use LightProcessExecutor\LightProcessExecutor;

use LightProcessExecutor\Event\MessageEvent;

/*
Copyright (c) 2013 Julien Pons

This file is part of LightProcessExecutor.

LightProcessExecutor is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

LightProcessExecutor is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Foobar.  If not, see <http://www.gnu.org/licenses/>
*/

/**
 *
 * A router event listener allows to be notified on specific event related to routing of messages. 
 * 
 * It is notified when :
 * 	1- A message has been sent : the process that submitted the message guarentees that this message was given to another process in charge of delivering it to destination
 *  2- A message has been received : A message has reached it's destination and has been delivered
 *  3- A message has been received but the receiver was force to interrupt current code execution to accept the delivery
 *  4- A router error either during the send or receive operation has been raised 
 * 
 * @copyright Julien Pons 2013
 * @author Julien Pons <xilon.jul@gmail.com>
 * @package process-executor
 */
interface RouterEventListener {
	
	/**
	 * Returns this listener priority. Priority determines the order for firing listeners. The event with the lowest priority gets fired first. Priority starts at 0
	 * @return int priority
	 */
	public function getPriority();
	
	/**
	 * A message has been received by the current process during an interrupt handler.
	 * This might only happen if an urgent message was sent and that this process was executing another callback different from this one  
	 * As a piece of advise, keep your code as light as possible (this code is executed in a signal handler)
	 * @param MessageEvent $e the message that has been delivered during the process interrupt handler
	 * @return void
	 */
	public function onInterruptReceive(MessageEvent $e);
	
	/**
	 * When a remote end-point get closed, this callback gets invoked. A remote end-point closes when the remote peer has closed the connection.
	 * A shut down can occur either because of a process that terminates, in which case you might check the process state by reading the child state in the process executor or
	 * a process might have simply voluntarily closed this connection.
	 * @param LightProcessExecutor $executor the executor that received the notification
	 * @param int $pid the pid of the process that was responsible of the close operation
	 * @param $lostMessages a two dimensial array containing a list of messages that could not have been sent to the process that has just terminated
	 * Each value is a keyed message array containing the following keys :<br>
	 * <ul>
	 * <li>dst : The destination of the message </li>
	 * <li>serialize: Serialization flag of the message  </li>
	 * <li>isAck: The message requests an ack ?</li>
	 * <li>urgent: The message is urgent ?</li>
	 * <li>broadcast: The message is a broadcast </li>
	 * <li>data: The message payload </li>    
	 * @return void
	 */
	public function onPeerShutdown(LightProcessExecutor $executor, $pid, array $lostMessages);
	
	/**
	 * A new message has been delivered in current process. All application-specific code can be contained within this callback 
	 * @param MessageEvent $e the message that has been received
	 * @return void
	 */
	public function onMessageReceived(MessageEvent $e);
	
	/**
	 * Message sents are asynchronous, therefore this method notifies you when the current process guarentees that it has delivered the message to another process in charge of routing or accepting it 
	 * @param MessageEvent $e the message that has been sent
	 * @return void
	 */
	public function onMessageSent(MessageEvent $e);
	
	/**
	 * Whenever a router error occurs during either a send or a receive operation, this callback function gets called.
	 * Errors currently raised are router internal ones. Such errors are directly bound to underlying socket IO operations. 
	 * Logical errors made by the programmer are not tracked by this library. Sending a message to a non-existent process would not raise an error 
	 * @param int $operation a router class constant starting with ROUTE_OP_  
	 * @param int $errno the error code
	 * @param string $errstr the error string
	 * @param \Exception $e an uncaught exception thrown during an invokation a of listener callback (send or receive) or null if the error was not thrown due to a listener callback 
	 * @see Router::ROUTE_OP_SND identifies a send operation
	 * @see Router::ROUTE_OP_RCV identifies a receive operation 
	 * @return void
	 */
	public function onRouterError($operation, $errno, $errstr, \Exception $e = NULL);
}
