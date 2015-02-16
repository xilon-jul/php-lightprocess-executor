<?php
namespace LightProcessExecutor\Event;

use LightProcessExecutor\Router\Router;

use LightProcessExecutor\LightProcessExecutor;

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
 * A message event is mutable wrapper class over events that are sent by the router listeners.
 * Mutable events allows a listener to alter the event structure before giving the hand back to another listener.
 * 
 * @copyright Julien Pons 2013
 * @author Julien Pons <xilon.jul@gmail.com>
 * @package process-executor
 */
class MessageEvent {

	private $id;
	private $src;
	private $dst;
	private $isUrgent = false;
	private $isAck = false;
	private $broadcast = false;
	private $payload;
	private $executor = NULL;
	private $fd;
	
	public function __construct(LightProcessExecutor $executor, $payload, $src, $dst, $id, $fd, $isUrgent = false, $isAck = false, $broadcast = false) {
		$this->executor = $executor;
		$this->src = $src;
		$this->dst = $dst;
		$this->id = $id;
		$this->fd = $fd;
		$this->isUrgent = $isUrgent;
		$this->isAck = $isAck;
		$this->payload = $payload;
		$this->broadcast = $broadcast;
	}
	
	/**
	 * Return the file descriptopr the message has been written to or received from depending on the event
	 * @return Resource socket or int fd
	 */
	public function getFd(){
		return $this->fd;	
	}
	
	/**
	 * Returns this process executor
	 * @return LightProcessExecutor this process executor 
	 */
	public function getExecutor() {
		return $this->executor;
	}

	/**
	 * Returns this process router
	 * @return Router this process router
	 */
	public function getRouter() {
		return $this->executor->getRouter();
	}

	/**
	 * Returns the message internal identifier
	 * @return int message identifier 
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * Returns the message payload
	 * @return mixed the message payload
	 */
	public function getPayload() {
		return $this->payload;
	}

	/**
	 * Return the emitter process identifier
	 * @return int emitter process identifier     
	 */
	public function getSource() {
		return $this->src;
	}

	/**
	 * Return the message destination as a string 
	 * <b>Note that when a broadcast is used, the destination returns 0</b>
	 * @return mixed message destination (string or int) 
	 */
	public function getDestination() {
		return $this->dst;
	}
	
	/**
	 * Checks whether the message was urgent. Not that checking this flag inside the onInterruptReceive would always be set to true. 
	 * @see RouterEventListener::onInterruptReceive(MessageEvent $e)
	 * @return boolean true if this message was marked as urgent  
	 */
	public function isUrgent() {
		return $this->isUrgent;
	}

	/**
	 * Checks whether this message is an ack response
	 * @return boolean true if the message acknowledges the acceptance of the destination process, false otherwise  
	 */
	public function isAck() {
		return $this->isAck;
	}
	
	/**
	 * Checks whether this message was a broadcast message
	 * @return boolean true if this message is a broadcast, false otherwise 
	 */
	public function isBroadcast(){
		return $this->broadcast;
	}
	
	/**
	 * Checks whether the message was a unicast (contrary of broadcast)
	 * @return boolean true if this is a unicast, false otherwise
	 */
	public function isUnicast(){
		return !$this->broadcast;
	}

	/**
	 * Alters the message payload
	 * @param mixed $payload the payload
	 */
	public function setPayload($payload){
		$this->payload = $payload;
	}
	
	public function __toString() {
		return sprintf(
				"@message [fd: %s, in: %d, id:%s, dst: %s, src: %d, urgent: %d, isAck: %d, broadcast: %d : payload: %s]",
				$this->fd,
				posix_getpid(),
				$this->id, $this->dst, $this->src, $this->isUrgent,
				$this->isAck, $this->broadcast,
				$this->payload
				);
	}
}
