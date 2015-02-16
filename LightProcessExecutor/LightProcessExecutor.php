<?php
namespace LightProcessExecutor;

use LightProcessExecutor\EventListener\ExecutorListener;

use LightProcessExecutor\EventListener\RouterEventListener;

use LightProcessExecutor\EventListener\KernelListener;
use LightProcessExecutor\EventListener\MessageListener;
use LightProcessExecutor\Event\MessageEvent;
use LightProcessExecutor\Router\Router;

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
along with LightProcessExecutor.  If not, see <http://www.gnu.org/licenses/>
*/

/**
 *
 * 
 * A light process executor based on LibEvent meant to handle a tree hierarchy of processes and to provide a "Router mechanism" to communicate between them.
 * All IPCs are asynchronous, which implies that the router main entry point (the send method) is non blocking, when a message get sent, it gives back the hand
 * to the current calling process. This latter would then get notified in the router listeners.
 * 
 * <b>
 * Libevent's internal makes any "catchable" signals that had a user handler attached pending when it is in its main loop, causing signals to be delivered in callbacks methods only.
 * </b>
 * 
 * The process tree size can theoretically be unlimited which thus implies that a forked process can itself fork other processes. 
 * Asynchronous communication is then handled via the router object property of this class and application specific behavior is driven by the router listeners. 
 * 
 * Linux is fully posix-compliant regarding to signals. This implies :
 * 	Linux signals are said to be reliable, when another signal interrupts a process executing a signal handler, this signal is queued and re-scheduled for further delivery (pending)
 *  When a process enters a signal handler due to a signal delivery (any type), others signals are blocked until the handler terminates, at which point the blocked signal type gets unblocked and re-delivered (or pending).
 *  The SIGKILL and SIGSTOP signals however are not blocked even when executing a specific handler code. It forces the process to terminate. SIGCHLD signal that is thus sent to parent process behaves the same as any other signals.
 *  
 * @copyright Julien Pons 2013
 * @author Julien Pons <xilon.jul@gmail.com> 
 * @package process-executor 
 * @see Router a router object to manage IPC
 * @see RouterEventListener router listeners to interact with forked processes
 * @see http://fr2.php.net/manual/fr/class.event.php Documentation of PHP LibEvent C library wrapper  
 */
class LightProcessExecutor {

	/**
	 * Define the maximum number of iteration this executor can execute whenever it is shutdown. Once this TTL is reached the executor
	 * will definitely quit the loop. This provides a mean for avoiding recursive entry within the libevent's main loop indefinitely
	 * @see LightProcessExecutor::loop($flag)
	 * @see LightProcessExecutor::setTTL($ttl)
	 * @var integer maximum number of iterations to execute inside the loop that solely applies whenever the executor has been shut down
	 */
	const TTL = 100;
	
	
	//************************************************************
	// These properties should not be altered between forks (shared between all fork)
	// ************************************************************
	private $rootpid = null;
	private $exitAfterShutdown = true;
	
	/**
	 * Currently assigned TTL
	 * @see LightProcessExecutor::TTL 
	 * @var int ttl
	 */
	private $ttl = self::TTL;
	
	/**
	 * Whether STDERR outputs are printed
	 * @var boolean true if you want this executor to print its information to STDERR
	 */
	private $printToStderr = false;
	
	/**
	 * Track the number of iterations already processed within this executor loop(). 
	 * <b>Only relevant when the executor has been shutdown</b>  
	 * @var unknown_type
	 */
	private $iteration = 0;
	
	/**
	 * Default process name that is transmitted to this process router
	 * @var string process name that can be used to specify a router message destination
	 */
	private $routerName = 'root';
	
	/**
	 * Defines a process which has not terminated its execution yet
	 * @var int 
	 */
	const PROC_TERM_TYPE_LIVING = 0;
	
	/**
	 * Defines a process which has exited normally via the system exit call
	 * @var int
	 */
	const PROC_TERM_TYPE_EXITED = 1;
	
	/**
	 * Defines a process state which indicates that the process has exited due to an uncaught signal
	 * @var int 
	 */
	const PROC_TERM_TYPE_SIGNAL = 2;
	
	
	/**
	 * Once shut down, this behavior tells this executor to wait for pending messages (from this process to other processes) to be written out to their destination and solely after shut down 
	 * @var integer constant 
	 */
	const EXECUTOR_SHUTDOWN_BEHAVIOR_FLUSH_PENDING_MESSAGES = 1;
		
	/**
	 * Tells this executor to exit the main loop once all children have exited and that their status have been read by this process 
	 * @see LightProcessExecutor::readChildState($pid) for reading and acknowledging a children termination  
	 * @var unknown_type1
	 */
	const EXECUTOR_SHUTDOWN_BEHAVIOR_WAIT_FOR_PEERS_TERMINATION = 2;
	
	
	//************************************************************
	// Process memory related, each process alters these properties
	// ************************************************************
	
	/**
	 * Flag indicating whether the executor was asked to shut down
	 * @var boolean true or false
	 */
	private $shutdown = false;
	
	private $shutdownBehavior = self::EXECUTOR_SHUTDOWN_BEHAVIOR_FLUSH_PENDING_MESSAGES;
	
	/**
	 * Underlying event base for async IO 
	 * @var Resource eb
	 */
	protected $eb = null;
	
	/**
	 * The parent process identifier
	 * @var integer
	 */
	protected $ppid;
	
	/**
	 * This process identifier
	 * @var interger
	 */
	protected $pid;
	
	/**
	 * A map of children this process has forked. Array keyed by the process identifier and that contains an array of contextual information as the value
	 * @var array<int, array) 
	 */
	protected $children = [];
	
	/**
	 * Underlying router object reponsible for handling IPC messages
	 * @var Router router
	 */
	protected $router = null;

	/**
	 * This process exit code (0 is default), it might be altered using appropriate setter 
	 * @var int (0-255) exit code
	 */
	protected  $processExitCode = 0;
	
	/**
	 * A list of executor listeners
	 * @var array of executor listeners
	 */
	private $executorListeners = [];
	
	// A list of sockets left opened after forks, must be closed on exit
	private $socketpair = [];
	
	/**
	 * Gives an instance of an executor and binds itself a router object.
	 * It also initializes the event base used by libevent which can be manipulated by calling the specific getter
	 */
	public function __construct() {
		$this->eb = new \EventBase();
		$this->rootpid = $this->pid = posix_getpid();
		$this->router = new Router($this, $this->routerName);
	}

	/**
	 * Retrieves the underlying event base in the current process context
	 * @see http://fr2.php.net/manual/fr/class.eventbase.php
	 * @return \EventBase the event base 
	 */
	public function getEventBase() {
		return $this->eb;
	}
	
	/**
	 * Gets the master pid of the tree hierarchy. Each processes thus knows the root pid.
	 * @return int root pid
	 */
	public function getRootPid(){
		return $this->rootpid;
	}
	
	/**
	 * Returns this executor process pid
	 * @return int executor process pid
	 */
	public function getPid(){
		return $this->pid;
	}
	
	/**
	 * Activate or disactivate the printing to standard error file descriptor. Default behavior is to print nothing, but it might
	 * be helpful to activate standard error printing 
	 * @param boolean $bool whether this executor is allowed to expose its internal working to stderr
	 * @return void 
	 */
	public function setPrintToStderr($bool){
		$this->printToStderr = (bool)$bool;
	}
	
	/**
	 * Gets the status indicating whether this executor exposes its internal through the stderr file descriptor
	 * @return boolean true if it does, false otherwise 
	 */
	public function getPrintToStderr(){
		return $this->printToStderr;
	}

	/**
	 * Convenient wrapper over the router send method to communicate with forked processes.
	 * @param mixed $data the data to send
	 * @param mixed $pid int|string|NULL int : an process pid or a process alias given when invoking the fork method, specifies the process destination of this message. Null constant can be used when broadcast flag is set
	 * @param boolean $serialize (defaults to TRUE), whether the message payload should be serialized during transport  
	 * @param boolean $ack (defaults to FALSE) whether this message requires an ack to be sent by the destination process  
	 * @param boolean $broadcast (defaults to FALSE) whether this message should be broadcasted to all forked processes. 
	 * @param boolean $urgent (defaults to FALSE) indicates that this message should interrupt the destination process to compel him to read it immediatly. Underlying interruption is based
	 * on posix SIGUSR1 signals
	 * @see Router::send($data, $pid, $serialize = true, $ack = false, $broadcast = false, $urgent = false) router send wrapped method
	 * @see Router::flushWrites($firstStacked = true) to force the first or last message to be written 
	 * @return int the message identifier used to transport this message
	 */
	public function submit(/* mixed */ $data, $pid, $serialize = true, $ack = false, $broadcast = false, $urgent = false) {
		return $this->getRouter()->send($data, $pid, $serialize, $ack, $broadcast, $urgent);
	}
	
	/**
	 * Sets the maximum number of iterations this executor can process before exiting the loop
	 * @param int $ttl
	 * @throws \InvalidArgumentException if ttl isn't an integer and if it is equal to 0
	 * @see LightProcessExecutor::TTL
	 */
	public function setTTL($ttl){
		$ttl = (int)$ttl;
		if($ttl <= 0){
			throw new \InvalidArgumentException("TTL must be a positive integer");
		}
		$this->ttl = $ttl;
	}
	
	/**
	 * Gets this executor TTL.
	 * @see LightProcessExecutor::TTL
	 */
	public function getTTL(){
		return $this->ttl;
	}
	
	/**
	 * Defines whether the root process should exit itself once all of its children have shut down.
	 * @param boolean $doExit true (default behavior) to exit the master process once shutdown, false to give back the end to the caller
	 */
	public function setExitAfterShutdown($doExit){
		$this->exitAfterShutdown = $doExit;	
	}
	
	/**
	 * Adds a listener object to be notified for send/receive router operation as well as router errors, or any other router events.
	 * @see RouterEventListener the router listener definition 
	 * @param RouterEventListener $l
	 * @return void
	 */
	public function addRouterMessageListener(RouterEventListener $l) {
		$this->getRouter()->addRouterEventListener($l);
	}

	/**
	 * Removes a router listener from this executor's router object 
	 * @param RouterEventListener $l the listener to remove
	 * @return void
	 */
	public function removeRouterMessageListener(RouterEventListener $l) {
		$this->getRouter()->removeRouterEventListener($l);
	}
	
	/**
	 * Returns this process executor direct children pids that have not yet been watched for their status  
	 * @return array keyed arrays whose keys are process identifiers and values a keyed array containing :
	 * 	- termination_type the process termination type among signal|normal or NULL if the process is still running
	 *  - status the value associated to type of termination, either the signal number that caused this process to end, or the process exit code 
	 */
	public function getChildren() {
		return $this->children;
	}
	
	/**
	 * Adds contextual information to this executor process table.
	 * Add operation is refused if the targeted process isn't a child process of this process 
	 * @param int $pid the process identifier to which the info should be attached
	 * @param array $info a keyed array the information to attached
	 * @return boolean true if the information has been added, false otherwise
	 */
	public function addProcessContextInfo($pid, array $info){
		if(count($info) === 0) return false;
		if(!isset($this->children[$pid])) return false; 
		$this->children[$pid] = array_merge($this->children[$pid], $info);
		return true;
	}
	
	/**
	 * Reads a child process' status.  By reading the child process status, you remove it from the executor internal process table.
	 * <b>If you have assigned this executor to wait for its peers, then make sure you call this method whenever you are notified in the onPeerShutdown() callback</b>
	 * @param int $pid the process identifier to watch for
	 * @return array or NULL 
	 * - NULL if the child does not exists
	 * - a keyed array whose keys are process identifiers and values a keyed array containing :
	 * 	- termination_type the process termination type among signal|normal or NULL if the process is still running
	 *  - status the value associated to type of termination, either the signal number that caused this process to end, or the process exit code  
	 */
	public function readChildState($pid){
		if(!isset($this->children[$pid])) return NULL;
		$read = $this->children[$pid];
		$removed = $this->removeChild($pid);
		return $read;
	}
	
	/**
	 * Return the contextual information associated to a process pid
	 * @param int $pid the process pid
	 * @return false if the process does not exist or an array of info 
	 */
	public function getProcessContextInfo($pid){
		if(!isset($this->children[$pid])) return false; 
		return $this->children[$pid];	 
	}
	
	/**
	 * @internal removes a <b>direct</b> child process identifier for the children list
	 * @param int $pid the process pid to remove
	 * @return boolean true if the reference to this pid was removed, false otherwise 
	 */
	private function removeChild($pid) {
		if(!isset($this->children[$pid])) return false;
		unset($this->children[$pid]);
		return true;
	}

	/**
	 * This executor signal handler.
	 * Handles child process termination so that they get remove from the OS process table (avoid zombie processes)
	 * Handles the specific router urgent flag 
	 * <b>Must not be called directly</b>
	 * 
	 * @param int $signo a posix signal integer constant that made this process jump to this signal handler
	 * @param integer $wait a pcntl_wait system call wrapper constant (e.g: WNOHANG)  
	 * @return void
	 */
	public function sighandler($signo, $wait = NULL, $pid = -1) {
		$this->router->setInterrupted(TRUE);
		switch ($signo) {
		case $this->router->getUrgentSignal():
			$this->router->handleUrgentDelivery();
			break;
		case SIGCHLD:
			$status = NULL;
			// If by the time of this call, this process is already waiting for children state change event
			// pcntl_wait might return immediatly
			$wait = $wait === NULL ? WNOHANG : 0;
			while (($pid = pcntl_waitpid($pid, $status, $wait)) > 0) {
				$info = $this->getProcessContextInfo($pid);
				$uptime = time() - $info['uptime'];
				if(pcntl_wifexited($status)) {
					// Get child exit code
					$code = pcntl_wexitstatus($status);
					// fprintf(STDOUT, "Child %d exited with code $code\n", $pid);
					$this->addProcessContextInfo($pid, ['uptime' => $uptime, 'termination_type' => self::PROC_TERM_TYPE_EXITED, 'status' => $code]);
					// $this->children[$pid] = ['termination_type' => self::PROC_TERM_TYPE_EXITED, 'status' => $code];
				}
				else if(pcntl_wifsignaled($status)){
					// Child has exited due to an uncaught signal
					$signo = pcntl_wtermsig($status);
					// fprintf(STDOUT, "Child %d exited due to uncaught signal $signo\n", $pid);
					// $this->children[$pid] = ['termination_type' => self::PROC_TERM_TYPE_SIGNAL, 'status' => $signo];
					$this->addProcessContextInfo($pid, ['uptime' => $uptime, 'termination_type' => self::PROC_TERM_TYPE_SIGNAL, 'status' => $signo]);
				}
				// Free socket ressources in parent now that the child has gone, Routes are automatically freed by the router
				$this->freeSocketResource($pid);
			}
			break;
		}
		$this->router->setInterrupted(FALSE);
	}
	
	/**
	 * Adds an executor listener
	 * @param ExecutorListener $l the listener to be added
	 * @see LightProcessExecutor\EventListener\ExecutorListener executor listener
	 * @return void
	 */
	public function addExecutorListener(ExecutorListener $l){
		$this->executorListeners[] = $l;
	}
	
	/**
	 * Removes an executor listener from this executor
	 * @param ExecutorListener $l the listener to remove
	 * @return void
	 */
	public function removeExecutorListener(ExecutorListener $l){
		reset($this->executorListeners);
		foreach($this->executorListeners as $k => &$v){
			if($v === $l){
				unset($this->executorListeners[$k]);
			}
		}
		unset($v);
	}
	
	/**
	 * Triggers the executor listeners method whose name is specified in first argument
	 * @param string $method the method to fire on the executor listener
	 * @param array $args parameters to pass to the listener callback
	 */
	protected function fireExecutorListeners($method, $args = []){
		foreach($this->executorListeners as $l){
			call_user_func_array(array($l, $method), $args);
		}
	}
	
	/**
	 * Forks a child process using underlying OS fork() syscall that would get attached to this executor. When forked, the caller process returns just after the fork,
	 * whereas the child process enters a blocking loop 
	 * <b>Remember that any variable created before the fork() call will get inherited by the child process (as for listeners ...)</b>  
	 * @param string $name this process can be given an alias that can be used as the process destination parameter when submitting messages, the master process has a default alias of 'root'
	 * @param callable $parent a callable to be executed inside the current process context before it returns. The callable receives a single argument which is the process executor instance and the child pid just forked as the second argument
	 * @param callable $chld a callable to be executed in the child context before it enters the main loop. The callable receives a single argument which is the process executor instance. 
	 * Convenient place to fork a process from this child process and build a process tree hierarchy.
	 * @see LightProcessExecutor::loop() the blocking loop based on libevent non-blocking I/O
	 * @see http://linux.die.net/man/2/fork linux fork manpage
	 * @see http://linux.die.net/man/2/socketpair socketpairs manpage
	 * @throws \Exception if the fork sys call fails
	 */
	public function fork($name = NULL, callable $parent = NULL, callable $chld = NULL) {
		if($this->shutdown) throw new \Exception("Executor has been shut down");
		$pairs = [];
		if (false === socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pairs)) {
			throw new \Exception("socket_create_pair() failed\n");
		}
		// 0 => Parent reads from child and writes to child
		// 1 => Childs reads from parent and writes to parent
		socket_set_nonblock($pairs[0]);
		socket_set_nonblock($pairs[1]);
		// Signals handler gets inherited
		pcntl_signal($this->router->getUrgentSignal(), array($this, 'sighandler'));
		pcntl_signal(SIGCHLD, array($this, 'sighandler'));
		// Add main resources to handle IPC
		// Fork and share file descriptors from here
		$chldpid = pcntl_fork();
		// Maintains a reference to the parent process if only this is not the root process
		$this->pid = posix_getpid();
		if ($this->pid !== $this->rootpid) {
			$this->ppid = posix_getppid();
		}
		if ($chldpid === -1) {
			throw new \Exception("Could not fork");
		}
		// Parent
		if ($chldpid > 0) {
			socket_close($pairs[1]);
			$this->socketpair[$chldpid][] = $pairs[0];
			$this->children[$chldpid] = [];
			$this->addProcessContextInfo($chldpid, ['uptime' => time(), 'termination_type' => self::PROC_TERM_TYPE_LIVING, 'status' => NULL]);
			$this->router->addRoute($chldpid, $pairs[0], [$this]);
			// Parent process does not need the other channel, close it
			if (is_callable($parent)) {
				call_user_func($parent, $this, $chldpid);
			}
			return $chldpid;
		} else {
			$this->eb->reInit();
			socket_close($pairs[0]);
			// Remember socket to be closed when loop exits
			$this->socketpair[$chldpid][] = $pairs[1];
			// Re-create an event base to avoid file descriptors inheritance and duplication of event callbacks
			// As the router references the event base, it must also be rebuilt  
			$this->eb = new \EventBase();
			$listeners = $this->router->getRouterEventListeners();
			$signo = $this->router->getUrgentSignal();
			$this->router = new Router($this, $name);
			$this->router->setUrgentSignal($signo);
			$this->router->setRouterEventListeners($listeners);
			$this->children = [];
			$this->router->addRoute(posix_getppid(), $pairs[1], [$this]);
			// Child process
			if (is_callable($chld)) {
				call_user_func($chld, $this);
			}
			self::loop();
		}
	}
	
	public function freeSocketResource($pid){
		if(!isset($this->socketpair[$pid])){
			return false;
		}	
		$nbPairs = count($this->socketpair[$pid]);
		for($i=0; $i<$nbPairs; $i++){
			socket_close($this->socketpair[$pid][$i]);
			unset($this->socketpair[$pid][$i]);
		}
		unset($this->socketpair[$pid]);
		return true;
	}
	
	private function freeSocketResources(){
		$pairs = NULL;
		foreach($this->socketpair as $pid => &$pairs) {
			$nbpairs = count($pairs);  
			for($i=0; $i<$nbpairs; $i++) {
				socket_close($pairs[$i]);
				unset($pairs[$i]);
			}
		}
		unset($pairs);
	}

	/**
	 * Sets the exit code when an executor shuts down. Calling exit within a router listener
	 * would bypass this method. Used it for default value only 
	 * @param int $code a process exit code from 0 to 255
	 * @return void
	 */
	public function setProcessExitCode($code){
		$this->processExitCode = $code;
	}
	
	/**
	 * Shuts down this executor. 
	 * A shutdown make this process exit the mainloop after specific events have occured depending on the shutdown behavior preset and the loop flag constants.
	 * @see LightProcessExecutor::setShutdownBehavior() to customize the shutdown behavior
	 * @see LightProcessExecutor::loop($flag) to parametrize the loop type
	 * @return void
	 */
	public function shutdown(){
		$this->fireExecutorListeners("onShutdown", [$this]);
		$this->shutdown = TRUE;
	}
	
	/**
	 * Gets the router binded to this executor. 
	 * @return Router the router instance 
	 */
	public function getRouter(){
		return $this->router;
	}
	
	/**
	 * In a process tree hierarchy, one might want to known if it's the root process, this method tells you if the current calling process
	 * is the root one
	 * @return boolean true if current calling process is the root one, false otherwise 
	 */
	public function isMaster(){
		return $this->rootpid === posix_getpid();
	}

	/**
	 * Returns a boolean if this executor has been shut down
	 * @return boolean true if shut down false otherwise
	 */
	public function isShutdown(){
		return $this->shutdown;	
	}
	
	/**
	 * Gets child processes that matches filter requirements
	 * @param callable $filter a callback function taking the array key and element value and returns a boolean true if the value should be returned 
	 * @return array filtered array
	 */
	public function getChildProcessesFiltered(callable $filter){
		$children = [];
		array_walk($this->children, function($item, $key) use(&$children, $filter) {
			if(true === call_user_func_array($filter, [$item, $key])){
				$children[$key] = $item;
			}
		});
		return $children;
	}
	
	/**
	 * Returns the list of children processes which are currently running on the underlying operating system.
	 * Process that are ended but still within this executor memory are not returned (because a non implicit read of the children states was requested).
	 * @return array list of children alived 
	 */
	public function getAlivedChildren(){
		return $this->getChildProcessesFiltered(function($item, $key){
			return $item['termination_type'] === self::PROC_TERM_TYPE_LIVING;
		});
	}
	
	/**
	 * Once has executor has been shut down, it cannot fork anymore and neither it can process router messages pushed onto its stack
	 * Therefor this method allows 
	 */
	public function restart(){
		if($this->exitAfterShutdown === true) throw new \Exception("Illegal state : this method cannot be called if you do not plan to get the hand back after the main loop");
		$this->shutdown = false;	
	}
	
	
	/**
	 * Internal methods that waits (blocking mode) for termination of all of this executor children
	 * When the executor is asked to shutdown, it waits until it has pop all messaegs out of the write stack
	 * when this happens, and if there are children still alive, it will block until they report a process status change 
	 */
	private function gracefulShutdown(){
		// Wait until all children change their status but make
		// sure that we wait after the child termination and not SIGCONT/SIGSTOP signals
		while(count($this->getAlivedChildren()) > 0) {
				$this->sighandler(SIGCHLD, true);
		}
	}
	
	/**
	 * Defines the shut down behavior of an executor when the shutdown() method is called.
	 * Default behavior is to shut down once all messages have been written to their destination, this matches EXECUTOR_SHUTDOWN_BEHAVIOR_DEFAULT constant
	 * Another behavior is to wait for this executor to read for the status of all of its children, this matches EXECUTOR_SHUTDOWN_BEHAVIOR_WAIT_FOR_PEERS_TERMINATION constant
	 * @param integer LightProcessExecutor::EXECUTOR_xxx constant or bitmask of constants
	 * @see LightProcessExecutor::loop($flag) impacts of setting specific behaviors with this executor main loop  
	 * @return void
	 */
	public function setShutdownBehavior($behavior){
		$this->shutdownBehavior = $behavior;
	}
	
	/**
	 * Returns true if a particular shut down behavior has been set 
	 * @param integer LightProcessExecutor::EXECUTOR_xxx constant
	 * @return boolean true if this behavior is set, false otherwise 
	 */
	public function hasShutdownBehavior($behavior){
		if((int)($this->shutdownBehavior & $behavior) === $behavior) {
			return true;
		}
		return false;
	}
	
	/**
	 * Mainloop based on the libevent's mainloop with some extra features.
	 * Libevent's main loop has two main modes that you must be aware of : 
	 * 1) It can work in blocking mode, in which case, the underlying libevents main loop would block until it has at least one active event and then exit 
	 * 2) It can work in non-blocking mode which cost a lot more CPU cycles, but it would exit even if no events are active.
	 *  
	 * These modes have functionnal impacts on this executor shutdown process. This executor main loop re-enters the libevent's main loop as long as 
	 * one behavior cannot be satisfy.
	 * 
	 * <b>This is to say that even if this executor was asked to shutdown it will keep running (aka re-entering the libevent's main loop) as long as </b>:
	 * <ol> 
	 * <li> it has pending messages to flush (if corresponding behavior has been set set)</li>
	 * <li> it has children whose state has not been read yet. (if corresponding behavior has been set) </li>
	 * </ol>
	 * <ul>
	 * 
	 * <b> How to avoid and endless shut down process </b>
	 * When shut down and if the EXECUTOR_SHUTDOWN_BEHAVIOR_WAIT_FOR_PEERS_TERMINATION behavior has been set, you must
	 * quit the libevent's main loop yourself when you get called in onPeerShutdown() method
	 * 
	 * @param integer $flag an \EventBase flag constant, default is to block until the base has at least an active event
	 * @see http://fr2.php.net/manual/en/class.eventbase.php 
	 * @see http://en.wikipedia.org/wiki/Event_loop 
	 * @return void
	 */
	public function loop($flag = \EventBase::LOOP_ONCE){
		$this->fireExecutorListeners("onStart", [$this]);
		while(!$this->shutdown || ($this->shutdown && 
				($this->hasShutdownBehavior(self::EXECUTOR_SHUTDOWN_BEHAVIOR_FLUSH_PENDING_MESSAGES) && $this->router->getPendingMessages() > 0) 
						|| ($this->hasShutdownBehavior(self::EXECUTOR_SHUTDOWN_BEHAVIOR_WAIT_FOR_PEERS_TERMINATION) && count($this->getChildren()) > 0))
				){
			// Avoid an infinite loop by tracking the number of iterations of this executor, quit the loop if we have reached the TTL treshold
			if($this->shutdown && count($this->getChildren()) === 0 && ++$this->iteration === $this->ttl){
				if($this->printToStderr) {
					fprintf(STDERR, "LPE: Executor %d has reached max TTL iterations treshold (%d)\n", $this->getPid(), $this->ttl);
					fprintf(STDERR, "LPE: Pending messages : (%d)\n", $this->router->getPendingMessages());
				}
				break;
			}
			$this->eb->loop($flag);
			$this->fireExecutorListeners("onExitLoop", [$this]);
		}
		$this->freeSocketResources();
		$this->gracefulShutdown();
		if(!$this->isMaster() || $this->exitAfterShutdown) {
			exit($this->processExitCode);
		}
	}
	
	
	/**
	 * Renders this executor as a string
	 * @return string this executor as a string
	 */
	public function __toString(){
		return sprintf("@executor [pid %d, ppid: %d, isroot: %d, children:[%s]] @", $this->pid, $this->ppid, $this->rootpid === $this->pid, count($this->children));
	}
}