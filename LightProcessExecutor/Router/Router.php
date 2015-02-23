<?php
namespace LightProcessExecutor\Router;

use LightProcessExecutor\LightProcessExecutor;

use LightProcessExecutor\EventListener\RouterEventListener;
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
 * Router provides a mean for the process executor to send or receive messages between all forked processes at any level of hierarchy.
 * A router is strictly bound to an executor (strong aggregation) and is also direcly dependent of the event base. 
 * <b>Except its method send() all the other methods should never be used directly</b>.
 * 
 * Router internals :
 *
 *  <b> Message protocol </b>
 *  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
 * 	|							HEADER	(44 bytes)																	   										| PAYLOAD (DATA_LENGTH bytes) 	|	
 *  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
 *  										fix 																								
 *  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
 * D| DST | SRC	| SERIALIZE | REQUEST_ACK | ACK | ID | URGENT | REAL_DST	 | LAST_NODE_PID | BROACAST |  ALIAS_LEN | ALIAS    | DATA_LENGTH  | DATA    
 * B|  4  | 4   |	4		|		4	  | 4   | 4  |  4     |   4			 |    4          |   4		|   	4    |       X  |	4          |				|
 *  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
 *  
 * DST 			: The next direct destination process identifier (DST might change during routing), solely the REAL_DST identifies the genuine target)
 * SRC 			: Original emitter of message (never changed accross routing)
 * SERIALIZE 	: Whether the message is serialized (if true unserialization takes place upon received)
 * REQUEST_ACK	: This message asked for a ack to be sent back upon received
 * ACK			: Whether this message is an ack message
 * ID			: The message identifier that is kept during the message transport (an ack keeps the same id)
 * URGENT		: Whether this message is considered urgent (an urgent message interrupts each process during routing and thus this behavior should be avoided as much as possible to avoid bug related to signal handling)
 * REAL_DST		: The remote target of this message (genuine target)
 * LAST_NODE_PID: Last process identifier that routed this message. It is used to avoid sending back a message from where it comes when routing
 * BROADCAST	: Whether this message should be broadcast to all processes (if so all processes upon receive should invokes their listeners)
 * ALIAS_LEN	: Length in bytes of next field
 * ALIAS		: Process alias name. An alias is a feature that allows the user to send a message to a process by specifying a name rather than a pid
 * DATA_LENGTH	: How many bytes the message payload contains
 * 
 * 
 * <b> Basic elements before further reading </b>
 * 
 * Router is based on a flood algorithm, it means that message routing is solely based on direct links. A direct link is either a parent process or a child process.
 * When a message needs to communicate with a non direct process, it will go through all the direct routes that leads to the destination process.
 * 
 * <b> Examples are worth it </b>
 * 
 * Consider this hierarchy of processes :
 * 
 * 					----------
 * 					|  	 1   |  alias <=> root
 * 					----------
 * 					/		\
 * 	child3	<=>	2 -----		------- 3 : alias <=> child3
 * 								\
 * 							------- 4 : alias <=> child4
 *  
 * <i> Example 1 </i>
 * 
 * The process with id 1 sends a message to process with pid 2 using the process identifier :
 * Process 1 identifies process 2 as being a direct child, it retrieves the route and wraps the payload within a message packet as defined above.
 * The message is received by process 2 that checks that it is the targeted destiantion, in which case, it fires the listeners
 * <code>
 * $router = new Router(..);
 * $router->send('Hello world', 2, NULL, true, $ack = true, $broadcast = false, $urgent = false);
 * </code> 
 * 
 * <i> Example 2 </i>
 * The process with id 1 sends a message to process with id 4.
 * 1- As this process doesn't know the destination process, it decides to route it to all of its routes except the route where this message came from
 * 2- It routes the message to process 2 and process 3
 * 3- Process 2 receives the message and discard it as it is not the destination process and does not route it anywhere as the only route it has is the one where the message came from
 * 4- Process 3 receives the message and identifies process 4 as being the destination process, it routes it to process 4
 * 5- The message is received by process 4, and this process is the destination process, the listeners are fired 
 * 
 * <i> Example 2 bis </i>
 * Now, let's consider the same message but with an ack requested, upon receive in step 5, an ack message is routed using the original emitter identifier, in our case "process 1". The step below occur : 
 * 6- Process 4 know that the ack has to be sent back to process 3 as it knows who delivered the message to it, it routes the message to process 3
 * 7- Process 3 receives the ack, and realize that the destination is a direct-link it's father in our case, it then routes it to process 1 
 * 8- Process one receives the ack message and fire listeners
 * 
 * @copyright Julien Pons 2013
 * @author Julien Pons <xilon.jul@gmail.com>
 * @package process-executor
 * 
 */
class Router {

	private $executor = NULL;
	
	private $base = NULL;
	
	private $name = NULL;
	
	private $routes = array();

	/**
	 * @deprecated rethink the concept of a complete operation and its utility for a developper
	 * @var boolean whether a send operation is "completed". (listeners fired ?)
	 */
	private $routerSendOperationCompleted = false;
	
	/**
	 * @deprecated same as above
	 * @var boolean same as above except this targets a receive operation
	 */
	private $routerReceiveOperationCompleted = false;
	private $messageListeners = array();
	
	/**
	 * A message stores is used when CONFIG_PROC_CTX is set on this router. It's own goal is to store sent messages
	 * to avoid fire listeners more than once
	 * @var array keyed with the message identifier, and value the number of times this message has been sent 
	 */
	private $mstore = array(); 
	
	private $urgentMode = false;
	
	private $interrupted = false;
	
	// I/O related properties
	private $readBuffer = '';

	// Bytes to send before poping/shifting new element from stack
	private $writeBuffer = '';
	
	// Send message stack
	private $writestack = array();
	
	// The current stack element router is working with 
	private $currentStackEl = NULL;

	const RCV_BUF_SIZE = 8196;
	
	const SND_BUF_SIZE = 8196;

	const MSG_HEADER_LEN_BYTES = 44; // 11 * 4

	// Router operation types
	const ROUTE_OP_SND = 1;
	const ROUTE_OP_RCV = 2;
	
	/**
	 * Default internal destination remote field value in case of broadcast
	 */
	const DST_REMOTE_BROADCAST = 0;
	
	/**
	 * Default internal destination remote field value in case of alias message sending
	 */
	const DST_REMOTE_ALIAS = 1;
	
	
	
	// Router configuration constants
	/**
	 * The router has two operating modes, raw context mode and process context mode
	 * This mode indicates the router to fire the listeners each time a process receives or routes a messages, this allow the listeners
	 * to track all the messages that go through this router. This is equivalent to spy mode
	 * @var int the raw mode
	 */	
	const CONFIG_RAW_CTX = 1;
	
	/**
	 * This second operating mode which is the default, would probably be the most used, it tells the router
	 * to fire listeners only on a process context basis. This implies that solely the sender process would be called in its listener
	 * and also solely the targeted processes would be called in their "receive" listeners whatever the router internals routing  
	 * @var int the process context mode
	 */
	const CONFIG_PROC_CTX = 2;
	
	/**
	 * Default SIGNAL used to interrupt destination process during its callback 
	 * @var integer 
	 */
	const DEFAULT_INTERRUPT_SIGNAL = SIGUSR1;
	
	/**
	 * SIGNAL number used to interrupt destination process when urgent flag is set
	 * @var integer
	 */
	private $urgentSigno = self::DEFAULT_INTERRUPT_SIGNAL;	
	
	/**
	 * Default config mode for this router. Config mode can be either spy mode
	 * @var unknown_type
	 */
	private $config = self::CONFIG_PROC_CTX;
	
	/**
	 * Builds a new router object tightly coupled to the executor (cross-dependency)
	 * @param LightProcessExecutor $executor the executor object that instantiates this router
	 * @param string $name a process alias name that matches the name passed to the executor fork() method. The root process is by default called 'root'.
	 * @see LightProcessExecutor::fork() the name of forked process 
	 */
	public function __construct(LightProcessExecutor $ex, $name) {
		$this->executor = $ex;
		$this->name = $name;
		$this->base = $ex->getEventBase();
	}

	/**
	 * When a router works during an interruption signal handler, we should let it know. Indeed, when interrupted, the listener's method
	 * invoked once an I/O has completed (send or receive) is a specific one to avoid recursion and re-entrancy
	 * @param boolean $int whether this router is running within an interruption handler
	 * @return void
	 */
	public function setInterrupted($int = true){
		$this->interrupted = $int;
	}
	
	/**
	 * Sets the signal that would be used to interrupt destination process when a message is flag as urgent. 
	 * Default value is SIGUSR1 
	 * @param int $signo number
	 */
	public function setUrgentSignal($signo){
		$this->urgentSigno = $signo;
	}

	/**
	 * Retrieves the signal used to interrupt processes during message routing
	 * @return int signo 
	 */
	public function getUrgentSignal(){
		return $this->urgentSigno;
	}
	
	/**
	 * Checks whether this router is running within a signal handler
	 * @return boolean true if it is, false otherwise
	 */
	public function isInterrupted(){
		return $this->interrupted;
	}
	
	/**
	 * Sets the router configuration 
	 * @param int $config the constant of the configuration to use
	 * @throws \InvalidArgumentException if the constant passed isn't one of the pre-defined constant
	 */
	public function setConfig($config){
		if($config !== self::CONFIG_PROC_CTX && $config !== self::CONFIG_RAW_CTX) throw new \InvalidArgumentException("Bad configuration constant");
		$this->config = $config;
	}

	/**
	 * Adds a routes to this router and tells the event base to start monitoring this route for read events 
	 * A route associates a given process identifier to its file descriptors used for reading and writing to this process.
	 * @param int $pid the process identifier this routes leads to
	 * @param Resource $rfd a socketpair resource used for reading/writing from/to $pid 
	 * @param array $args specific args to be transmitted to the underlying libevent callback handling those file descriptors
	 * @return void
	 */
	public function addRoute($pid, $fd, array $args = array()) {
		$eread = new \Event($this->base, $fd, \Event::READ | \Event::PERSIST, array($this, 'recv'), array_merge(array(&$eread), array($pid), $args));
		$ewrite = new \Event($this->base, $fd, \Event::WRITE | \Event::PERSIST, array($this, 'ewrite'), array_merge(array(&$ewrite), array($pid), $args, array('first_stack' => true)));
		$this->routes[$pid] = array('pid' => $pid, 'fd' => $fd, 'eread' => &$eread, 'ewrite' => &$ewrite, 'eread_args' => array_merge(array(&$eread), array($pid), $args), 'ewrite_args' => array_merge(array(&$ewrite), array($pid), $args));
		$eread->add();
	}
	
	public function getAlias(){
		return $this->name;
	}
	
	/**
	 * Gets the internal routes this router owns
	 * @return array of routes where keys are pids and value a keyed array with [pid, write, read, eread, ewrite, eread_args]   
	 */
	public function getRoutes(){
		return $this->routes;
	}
	
	/**
	 * This method forces all the routes of this router to read their file descriptors and check whether there are some data to read.
	 * @throws \Exception if an attempt to re-call this method is made when it has not already exited
	 */
	public function handleUrgentDelivery(){
		if($this->urgentMode === TRUE) {
			throw new \Exception("Cannot re-enter urgent delivery");
		}
		$this->urgentMode = TRUE;
		foreach($this->routes as $pid => $route){
			$route['eread']->del();
			$this->recv($route['fd'], \Event::READ, $route['eread_args']);
			$route['eread']->add();
		}
		$this->urgentMode = FALSE;
	}
	
	
	
	/**
	 * Forces this router to flush either its current buffer if it isnt empty. 
	 * If the buffer is empty, then it would either take the first message that was stacked, or the last one depending on the first argument
	 * @param boolean $firstStacked (default to true) whether we flush the first stacked message or the last one. Flushing the first stacked message keeps priorities but it can
	 * be helpful to flush the last one if you have several messages stacked and you want the last one (the one you have justed submitted) to be flushed right away
	 */
	public function flushWrites($firstStacked = true){
		foreach($this->routes as $pid => $route){
			$route['ewrite']->del();
			$this->ewrite($route['fd'], \Event::WRITE, array_merge($route['ewrite_args'], array('first_stack' => $firstStacked)));
			$route['ewrite']->add();
		}
	}

	/**
	 * Removes a routes from this router based on the route process identifiers
	 * @param int $pid the process identifier
	 * @return true if the route was deleted, false otherwise
	 */
	public function removeRoute($pid) {
		if (!isset($this->routes[$pid])) {
			return false;
		}
		$this->routes[$pid]['eread']->free();
		$this->routes[$pid]['ewrite']->free();
		socket_close($this->routes[$pid]['fd']);
		unset($this->routes[$pid]);
		return true;
	}
	
	/**
	 * Clears all pending message currently in stack
	 * @return int the number of messages suppressed 
	 */
	public function clearPendingMessages(){
		$n = 0;
		foreach($this->writestack as $fd => &$messages){
			$n += count($messages);
			$messages = array();
		}
		unset($messages);
		return $n;
	}
	
	/**
	 * Invokes all the listeners method $method with arguments $args
	 * @param string $method the method name among the one defined in MessageEvent class
	 * @param array $args the arguments to pass to the method
	 * @see MessageEvent the message event class definition
	 * @return void
	 */
	protected function fireRouterEventListener($method, array $args = array()) {
		foreach ($this->messageListeners as $l) {
			call_user_func_array(array($l, $method), $args);
		}
	}
	
	/**
	 * Sets all the listeners of this router
	 * @param array $listeners an array of RouterEventListener instances
	 * @return void
	 */
	public function setRouterEventListeners(array $listeners = array()){
		$this->messageListeners = $listeners;
		usort($this->messageListeners, array($this, 'routerEventComparisonFunction'));
	}
	
	/**
	 * Order router events by priority.
	 * @param RouterEventListener $a the first operand
	 * @param RouterEventListener $b the 2nd operand
	 * @return void
	 */
	private function routerEventComparisonFunction(RouterEventListener $a, RouterEventListener $b){
		$pa = $a->getPriority();
		$pb = $b->getPriority();
		if($pa < $pb){
			return -1;
		}
		else if($pa === $pb){
			return 0;
		}
		return 1;
	}

	/**
	 * Gets this router listeners
	 * @return array of RouterEventListener instances
	 */
	public function getRouterEventListeners(){
		return $this->messageListeners;
	}
	
	/**
	 * Adds a listener to this router
	 * @param RouterEventListener $listener the listener to add
	 * @param $priority the lower the priority is the first this listener would be fired
	 * @return void
	 */
	public function addRouterEventListener(RouterEventListener $listener) {
		$this->messageListeners[] = $listener;
		usort($this->messageListeners, array($this, 'routerEventComparisonFunction'));
	}

	/**
	 * Removes a listener from this router
	 * @param RouterEventListener $l the listener to remove
	 * @return void
	 */
	public function removeRouterEventListener(RouterEventListener $l) {
		$nb = count($this->messageListeners);
		for($i=0; $i<$nb; $i++){
			if($l === $this->messageListeners[$i]){
				unset($this->messageListeners[$i]);
			}
		}
		// reset index
		$this->messageListeners = array_merge($this->messageListeners, array());
	}

	/**
	 * Finds a route entry based on a route process identifier 
	 * @param int $pid the process identifier
	 * @throws \Exception if the route is a loopback route
	 * @return the route entry
	 */
	private function findRoute($pid) {
		if ($pid !== NULL && isset($this->routes[$pid])) {
			if ($this->routes[$pid]['pid'] === posix_getpid()) {
				throw new \Exception("Loopback message not allowed\n");
			}
			return $this->routes[$pid];
		}
		return NULL;
	}
	
	/**
	 * Asynchronously sends a message with the appropriate information.
	 * This method is said to be asynchronous as it solely pushes the message on top of the write stack. The message is only sent when
	 * the remote socket file descriptor notifies the user space of a write event and that ofcourse all of the bytes contained in this message are sent.
	 * @param mixed $payload the data to send, note that if the serialize flag is set to true, the payload has to be serializable (cf Closure)
	 * @param mixed $pid int|string|NULL int : an process pid or a process alias given when invoking the fork method, specifies the process destination of this message. Null constant can be used when broadcast flag is set
	 * @param boolean $serialize (defaults to TRUE), whether the message payload should be serialized during transport  
	 * @param boolean $ack (defaults to FALSE) whether this message requires an ack to be sent by the destination process  
	 * @param boolean $broadcast (defaults to FALSE) whether this message should be broadcasted to all forked processes. When this flag is set (does not take care if the process destination given, process destination would be set to 0) 
	 * @param boolean $urgent (defaults to FALSE) indicates that this message should interrupt the destination process to compel him to read it immediatly. Underlying interruption is based
	 * on posix SIGUSR1 signals. <b>Specific care should be taken when using this functionnaly, stability of a program is better if not used</b>
	 * @see Router::flushWrites($firstStacked=true) to force the message to be shift/pop off the stack instantaneously 
	 * @return mixed string the message identifier used to transport this message. It can either be thoe one supplied or the internal id generated 
	 */
	public function send($payload, $pid, $serialize = true, $ack = false, $broadcast = false, $urgent = false) {
		// print "send() try send $payload urgent ".($urgent === FALSE ? '0' : '1')."\n";
		// Monitor event for writing
		$data = $serialize === TRUE ? serialize($payload) : $payload;
		// Prepare envelope
		$from = posix_getpid();
		$id = mt_rand(1, pow(2, 32));
		// Remote destination is set to 0 if broadcast, 1 if alias is used
		$remote = $broadcast ? self::DST_REMOTE_BROADCAST : $pid;
		$alias = '';
		if(null !== $pid && is_string($pid)){
			$alias = (string)$pid;
			$remote = self::DST_REMOTE_ALIAS;
			$pid = null;
		}
		$dataLen = strlen($data);
		$message = [
			'dst' => NULL, 
			'src' => $from, 
			'serialize' => $serialize, 
			'ack' => $ack, 
			'isAck' => false, 
			'id' => $id, // Internal message identifier
			'urgent' => $urgent, // Whether the message is urgent and compels the receiver process to handle it immediatly 
			'dst_remote' => $remote, // Real process destination
			'last_node_pid' => $from, // The last process that received this message during routing,
			'broadcast' => $broadcast,
			'dst_remote_alias_len' => strlen($alias),
			'dst_remote_alias' => $alias,
			'dataLen' => $dataLen, 
			'data' => $data
		];
		$route = NULL;
		if(!$broadcast) {
			$route = $this->findRoute($pid);
		}
		if ($route === NULL) {
			// This message targets a non direct process (not a child, neither the parent, or this is a router alias (name) or the message is a broadcast)
			$that = $this;
			array_walk($this->routes,
					function (&$route, $key, $args = NULL) use (&$that, $message) {
						$message['dst'] = $route['pid'];
						$this->pushMessage($message);
					});
		} else {
			$message['dst'] = $route['pid'];
			$this->pushMessage($message);
		}
		// This message has not been sent yet, keep an eye of how many times this message is routed
		$this->mstore[$id] = 0;
		return $id;
	}
	
	
	/**
	 * Pushes a message (internal array format) on the top of the stack and tells libevent to monitor this route's write channel to the list of pending events. There exist one stack per route. 
	 * The route is automatically retrieve using the message destination stored in $message['dst'] 
	 * @param array $message the message to send
	 * @throws \InvalidArgumentException if the message array misses the key 'dst' indicating the route identifier
	 * @return void
	 */
	private function pushMessage(array $message, $priority = -1){
		if(!isset($message['dst'])) {
			throw new \InvalidArgumentException("Cannot push message with empty destination");
		}
		$route = $this->findRoute($message['dst']);
		$this->writestack[intval($route['fd'])][] = $message;
		if($priority >= 0){
			$route['ewrite']->setPriority($priority);
		}
		$route['ewrite']->add();
	}
	
	/**
	 * Gets a messages from the stack based on the file descriptor
	 * @param Resource $fd the route associated file descriptor
	 * @throws \InvalidArgumentException if the argument isn't a resource
	 * @return array a message to be sent
	 */
	private function shiftMessage($fd){
		if(!is_resource($fd)) {
			throw new \InvalidArgumentException("Illegal argument exception");
		}
		return array_shift($this->writestack[intval($fd)]);
	}
	
	private function printRoutes() {
		// print "Known routes in " . posix_getpid() . " : \n";
		foreach ($this->routes as $pid => $data) {
			print "[pid: {$pid}/{$data['pid']}, r/w = {$data['fd']}}\n";
		}
	}
	
	
	/**
	 * Libevent callback invoked when a message needs to be written to a given route.
	 * It retrieves the first message in this stack route if any, pack it so that it complies with the underlying message protocol, and 
	 * writes as maximum bytes as possible.
	 * If for some reason, the whole message could not be sent, keep monitoring this event, and write remaining bytes later on next invocation and so on .. 
	 * Once all bytes that represents a message are sent : 
	 * 	- If the urgent flag was set, then the process node that is responsible for handling/delivering this message is interrupted
	 *  	- The listeners's onInterruptReceive method is called
 	 * 	- Else the listeners's onMessageReceive is called     
	 * @param Resource|int $fd the file descriptor to write to
	 * @param int $what a lib event Event::X constant that identifies the type of event that was raised 
	 * @param array $args would receive the associated Event instance as first argument,route pid as 2nd arg and executor instance as 3rd
	 */
	public function ewrite($fd = NULL, $what = NULL, $args = NULL) {
		//$this->printRoutes();
		// As long as there is bytes in the write buffer write them, remove write event from pending events only when write is fully completed
		// Move to next message if there is no more data to write for the current buffer
		$this->routerSendOperationCompleted = FALSE;
		//print "ewrite() in context " . posix_getpid() . " stack len = " . count($this->writestack) . " (resource = $fd)\n";
		$nbstackElements = isset($this->writestack[intval($fd)]) ?  count($this->writestack[intval($fd)]) : 0;
		//print "Nb stack = $nbstackElements write buffer = {$this->writeBuffer} \n ";
		if(!isset($this->writeBuffer[$fd])){
			$this->writeBuffer[$fd] = '';
		}
		if ($this->writeBuffer[$fd] === '' && $nbstackElements > 0) {
			// Be careful, write events are raised whenever a file descriptor is ready for writing, but messages in current
			// stack should not necessarily be written to this FD, it has to correspond to route pid
			if(!isset($args['first_stack']) || $args['first_stack'] === true) {
				$this->currentStackEl[$fd] = $this->shiftMessage($fd);
			}
			else {
				$this->currentStackEl[$fd] = array_pop($this->writestack[intval($fd)]); 
			}
			// Pack message into a binary string
			$this->writeBuffer[$fd] = call_user_func_array('pack', array_merge(array("V11a{$this->currentStackEl[$fd]['dst_remote_alias_len']}Va*"), $this->currentStackEl[$fd]));
		}
		while ($this->writeBuffer[$fd] !== '') {
			
			if (false === ($wrote = @socket_write($fd, $this->writeBuffer[$fd], self::SND_BUF_SIZE))) {
				//fprintf(STDERR, "router ewrite() error : %d - %s", \EventUtil::getLastSocketErrno(), \EventUtil::getLastSocketError());
				$this->fireRouterEventListener('onRouterError', array(self::ROUTE_OP_SND, \EventUtil::getLastSocketErrno(), \EventUtil::getLastSocketError()));
				break;
			}
			$this->writeBuffer[$fd] = substr($this->writeBuffer[$fd], $wrote);
			// We wrote all the buffer, empty it
			if ($this->writeBuffer[$fd] === false) {
				//print "Send ctx " . posix_getpid() . " message to {$this->currentStackEl[$fd]['dst_remote']} from {$this->currentStackEl[$fd]['src']}\n";
				// If the message written is urgent, dont wait for the process listeners to finish, wake up destination process first and continue firing listeners 
				if($this->currentStackEl[$fd]['urgent']){
					//print "Router was asked to do an urgent delivery\n";
					posix_kill($this->currentStackEl[$fd]['dst'], $this->urgentSigno);
				}
				//print "ewrite() [fd: $fd, id: {$this->currentStackEl[$fd]['id']}, request ack: {$this->currentStackEl[$fd]['ack']}, isAck: {$this->currentStackEl[$fd]['isAck']}, broadcast: {$this->currentStackEl[$fd]['broadcast']}, urgent: {$this->currentStackEl[$fd]['urgent']}, dst: {$this->currentStackEl[$fd]['dst']}, remote: {$this->currentStackEl[$fd]['dst_remote']}, origin: {$this->currentStackEl[$fd]['src']}, from: {$this->currentStackEl[$fd]['last_node_pid']}, me: ".posix_getpid()."]\n";
				//print "remaining : ".$this->getPendingMessages()."\n";
				// Notifies only the emitter process that sent the message (when broadcast is used)
				if($this->config === self::CONFIG_RAW_CTX || ($this->currentStackEl[$fd]['src'] === posix_getpid() && $this->mstore[$this->currentStackEl[$fd]['id']] === 0)) {
					try {
						$this->fireRouterEventListener('onMessageSent', array(new MessageEvent(
										$args[2], 
										$this->currentStackEl[$fd]['serialize'] ? unserialize($this->currentStackEl[$fd]['data']) : $this->currentStackEl[$fd]['data'], 
										$this->currentStackEl[$fd]['last_node_pid'],
										$this->currentStackEl[$fd]['dst_remote'], 
										$this->currentStackEl[$fd]['id'], 
										$fd,
										$this->currentStackEl[$fd]['urgent'],
										$this->currentStackEl[$fd]['isAck'],
										$this->currentStackEl[$fd]['broadcast']
									)));
					}
					catch(\Exception $e) {
						// Shutdown executor on exception
						$this->fireRouterEventListener('onRouterError', array(self::ROUTE_OP_SND, $e->getCode(), $e->getMessage(), $e));
					}
					if(++$this->mstore[$this->currentStackEl[$fd]['id']] === count($this->routes)){
						unset($this->mstore[$this->currentStackEl[$fd]['id']]);
					}
				}
				$this->writeBuffer[$fd] = '';
				$this->currentStackEl[$fd] = NULL;
				$this->routerSendOperationCompleted = TRUE;
			}
		
		}
		if($this->writeBuffer[$fd] === '' && $nbstackElements === 0){
			//print "From ".posix_getpid()." remove write event \n";
			$args[0]->del();
		}
		else {
			// Make it persist as long as we have messages to send
			$args[0]->add();
		}	
	}

	/**
	 * A operation is said to be completed when at least a listener onMessageReceive/onMessageSent method is ready to be called
	 * @param int $opflags binary flags that tells which operation we want to know as completed
	 * @param int $flags a reference whose value stores the operations completed (can be either a READ, a WRITE or a READ and a WRITE)
	 * @see Router::ROUTE_OP_SND flag that identifies that this router has sent a message
	 * @see Router::ROUTE_OP_RCV flag that identifies that this router has received a message
	 * @deprecated is this method really useful, what are the concept of an operation that completes ?
	 * @return boolean true if at least one of the operation watched has terminated
	 */
	public function isOperationCompleted($opflags, &$flags = 0) {
		$ret = FALSE;
		$flags = 0;
		//print sprintf("Operation completed [snd: %d, rcv: %d]", $this->routerSendOperationCompleted, $this->routerReceiveOperationCompleted);
		if (((int) ($opflags & self::ROUTE_OP_SND)) === self::ROUTE_OP_SND) {
			$ret |= $this->routerSendOperationCompleted;
		}
		if (((int) ($opflags & self::ROUTE_OP_RCV)) === self::ROUTE_OP_RCV) {
			$ret |= $this->routerReceiveOperationCompleted;
		}
		$flags = (self::ROUTE_OP_SND & (int) $this->routerSendOperationCompleted) | (self::ROUTE_OP_RCV & (int) $this->routerReceiveOperationCompleted);
		return $ret;
	}

	/**
	 * Returns the number of messages that are waiting to be written 
	 * @return int the number of messages
	 */
	public function getPendingMessages() {
		$c = 0;
		foreach($this->writestack as $fd => $stack){
			$c += count($stack);
		}
		return $c;
	}
	
	/**
	 * Receives as many bytes as available and parse message header. Once a message has been completly received, including payload
	 * the recv method can either :
	 * 	- Deliver the message to this process (eg: call the listeners specific method) if it was directly targeted or if the message is a broadcast
	 *  - Prepare an ack message to be delivered to the original process that emitted this message
	 *  - Route this message if necessary to all known routes except the one where this message comes from to avoid recursion
	 *    Routing occurs :
	 *    	- when the remote destination isn't part of this process known routes
	 *  	- when a broadcast was requested 
	 * @param Resource|int $fd the route file descriptor that is ready for reading
	 * @param int $what a lib event Event::X constant that identifies the type of event that was raised 
	 * @param array $args would receive the associated Event instance as first argument,route pid as 2nd arg and executor instance as 3rd, 
	 * @see Router::ewrite($fd = null, $what = NULL, $args = NULL) the counter-part method of this method
	 */
	public function recv($fd = null, $what = NULL, $args = NULL) {
		$this->routerReceiveOperationCompleted = FALSE;
		//$this->printRoutes();
		if(!isset($this->readBuffer[$fd])){
			$this->readBuffer[$fd] = '';
		}
		$buf = '';
		if (false === ($bytes = @socket_recv($fd, $buf, self::RCV_BUF_SIZE, 0))) {
			// fprintf(STDERR, "Router recv() error %d - %s\n", \EventUtil::getLastSocketErrno(), \EventUtil::getLastSocketError());
			$this->fireRouterEventListener('onRouterError', array(self::ROUTE_OP_RCV, \EventUtil::getLastSocketErrno(), \EventUtil::getLastSocketError()));
			return;
		}
		if ($bytes === 0) {
			// Peer has performed an orderly shutdown, in most case the process died
			// The SIGCHLD signal might already have been processed, but libevent has blocked the sigchld and will re-schedule it after the mainloop
			// sigtimedwait should return immedialy as the signal is already pending
			// If SIGCHLD was pending it is removed from the list of pending signals
			$pid = $args[1];
			$this->executor->sighandler(SIGCHLD, 0, $pid);
			
			$messages = array();
			if(isset($this->writestack[intval($fd)]) && count($this->writestack[intval($fd)]) > 0) {
				// There were messages that could not have been sent to this process
				while( ($lm = array_shift($this->writestack[intval($fd)])) !== null) {
					$messages[] = array_intersect_key($lm, array('dst', 'serialize', 'isAck', 'urgent', 'broadcast', 'data', 'dst_remote_alias'));
				}
			}
			$this->fireRouterEventListener('onPeerShutdown', array($args[2], $pid, $messages));
			// Free all messages that could not have been sent and free routes (would also free events)
			$args[0]->free();
			$this->removeRoute($pid);
			unset($this->writestack[intval($fd)]);
			return;
		}
		// Store bytes read into internal buffer so that next time we get called, we just concatenate bytes
		$this->readBuffer[$fd] .= $buf;
		while ($this->readBuffer[$fd] !== '') {
			$buflen = strlen($this->readBuffer[$fd]);
			if ($buflen <= self::MSG_HEADER_LEN_BYTES) {
				// Keep waiting, we can not even parse headers
				return;
			}
			// Headers are available, we try to parse a "packet", otherwise we keep waiting
			$udata = unpack('V11head', $this->readBuffer[$fd]);
			$broadcast = $udata['head10'];
			$aliasLen = $udata['head11'];
			$dstRemote = $udata['head8'];
			$comesFromPid = $udata['head9'];
			$dst = $udata['head1']; // destination pid
			$origin = $udata['head2']; // emitter pid
			$ack = $udata['head4']; // Message needs ack ?
			$isAck = $udata['head5'];
			$id = $udata['head6']; // Message identifier
			$urgent = $udata['head7']; // Urgent ?
			$serialize = $udata['head3'];
			// Return unless we can read the alias string and the next 4 bytes indicating payload length 
			$headerSize = $aliasLen + 4 + self::MSG_HEADER_LEN_BYTES;
			if ($buflen < $headerSize){
				return;
			}
			// We read the alias, read 4 more byte to determine message payload length
			$udata = unpack("V11head/a${aliasLen}alias/Vpayload", $this->readBuffer[$fd]);
			$alias = $udata['alias'];
			$payloadLen = $udata['payload'];
			
			if($buflen < $headerSize + $payloadLen){
				return;
			}
			#print "recv() [fd: $fd, id:{$udata['head6']} (or {$udata['head6']}), request ack:$ack, ack: $isAck, broadcast: $broadcast, urgent: $urgent, dst: $dst, remote: $dstRemote, origin: $origin, from: $comesFromPid, me: ".posix_getpid()."]\n";
			// From now on we have at least enough bytes to retrieve several messages (eg: user payload)
			// Processes as many bytes as possible and reset buffer for next processing
			$payload = substr($this->readBuffer[$fd], $headerSize, $payloadLen);
			//print "Payload $payload\n";
			$this->readBuffer[$fd] = substr($this->readBuffer[$fd], $headerSize + $payloadLen);
			if (false === $this->readBuffer) { // substr starts at the end of the readBuffer, it means no more data
				$this->readBuffer[$fd] = '';
			}
			// Re-initialize message data
			$message = [
				'dst' => NULL,  // Force reset
				'src' => $origin, 
				'serialize' => $serialize, 
				'ack' => $ack, 
				'isAck' => $isAck, 
				'id' => $id, 
				'urgent' => $urgent,
				'dst_remote' => $dstRemote, // The real process destination (either a real pid)
				'last_node_pid' => $comesFromPid, // Force reset
				'broadcast' => $broadcast, 
				'dst_remote_alias_len' => $aliasLen,
				'dst_remote_alias' => $alias, 
				'dataLen' => $payloadLen, 
				'data' => $payload
				];
			// Check whether this process is the targeted process destination / (check pid or router name)
			$notTargeted = (($dstRemote === self::DST_REMOTE_ALIAS && strcmp($alias, $this->name) !== 0) || ($dstRemote > 1 && $dstRemote !== posix_getpid()));
			if ($notTargeted ||$dstRemote === self::DST_REMOTE_BROADCAST) {
				// This process isnt the targeted process, broadcast it but skip route where this message comes from
				// print "Discard message id $id or broadcast in ctx " . posix_getpid() . " (from: $origin, for: $dstRemote) : (reason = not targeted)\n";
				$that = $this;
				
				array_walk($this->routes,
						function (&$route, $key, $args = NULL) use ($that, $message) {
							if ($route['pid'] === $message['last_node_pid']) {
								// print "route()  ${route['pid']} in ".posix_getpid()." skipped\n";
								return;
							}
							// print "route() to {$route['pid']}\n";
							//print "Route it to {$route['pid']}\n";
							$message['last_node_pid'] = posix_getpid();
							$message['dst'] = $route['pid'];
							$this->pushMessage($message);
						});
				// If this message is a unicast and we are not the target process, don't even try to send an ack or to notify listeners that a message has been received
				if($broadcast === 0 ) {
					continue;
				}
			}
			
			// We are the targeted process and emitter requires an ack, send it back
			if ($ack && !$notTargeted) {
				$message['ack'] = FALSE;
				$message['isAck'] = TRUE;
				$message['data'] = '1';
				$message['serialize'] = FALSE;
				$message['dataLen'] = 1;
				$message['dst_remote'] = $origin;
				$message['last_node_pid'] = $message['src'] = posix_getpid();
				$message['broadcast'] = FALSE; // Do not broadcast ack messages

				// Send message back to destination 
				$route = $this->routes[$comesFromPid];
				// Route should always be found, but check it anyway
				if($route !== NULL){
					//print "ack() to {$route['pid']}\n";
					$message['dst'] = $route['pid']; // = $comesFromPid
 					$this->pushMessage($message);
 					// print "ack () [id:$id, request ack:{$message['ack']}, ack: {$message['isAck']}, broadcast: {$message['broadcast']}, urgent: {$message['urgent']}, dst: {$message['dst']}, remote: {$message['dst_remote']}, origin: {$message['src']}, from: {$message['last_node_pid']}, me: ".posix_getpid()."]\n";
				}
				else {
					// fprintf(STDERR, "router ack() error : route not found\n");
				}
				
			}
			
			if(self::CONFIG_RAW_CTX || !$notTargeted) {
				$listenerMethod = $this->isInterrupted() ? 'onInterruptReceive' : 'onMessageReceived';
				// Whenever the router was forced to interrupt during a listener (do not re-enter the same listener method (avoid recursion))
				// Listeners should only be fired when the config is set to CONFIG_RAW_CTX or when this process is a targeted process
				try {
					$user_payload = $serialize ? unserialize($payload) : $payload;
					$mObject = new MessageEvent($args[2], $user_payload, $origin, $dstRemote, $id, $fd, $this->isInterrupted() ? TRUE : $urgent, $isAck, $broadcast);
					$this->fireRouterEventListener($listenerMethod, array($mObject));
				}
				catch(\Exception $e){
					$this->fireRouterEventListener('onRouterError', array(self::ROUTE_OP_RCV, $e->getCode(), $e->getMessage(), $e));
				}
			}
			$this->routerReceiveOperationCompleted = true;
		}
	}
}
