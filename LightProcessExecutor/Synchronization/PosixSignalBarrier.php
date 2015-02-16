<?php
namespace LightProcessExecutor\Synchronization;

use LightProcessExecutor\Synchronization\Exception\BrokenBarrierException;

use LightProcessExecutor\Synchronization\Exception\TimeoutException;

use LightProcessExecutor\Synchronization\Exception\InterruptedException;

use LightProcessExecutor\Synchronization\BarrierInterface;

/**
 * A simple barrier synchronization mechanism that uses a shared memory counter
 * to synchronize processes and posix blocking sigwait mechanism to get notify when the barrier gets tripped.
 * The last process to reach the barrier notifies all the others. 
 * <b>For this barrier to work, you must install a signal handler for SIGUSR2 signals, this handler should not rely on a specific behavior, and in most cases should be empty</b>
 * 
 * Note that this object might also gets serialized and sent using IPC. The receiving process would invoked the __wakeup method
 * automatically and would be able to use the barrier as if it was created before a fork. 
 */
class PosixSignalBarrier implements BarrierInterface {

	/**
	 * Semaphore resource to synchronize process when accessing shared memory segment
	 * @var Resource
	 */
	private $semaphore;
	
	/**
	 * Shared memory segment resource
	 * @var unknown
	 */
	private $shm;
	
	
	/**
	 * Temporary file created when the barrier is constructed whose inode would serve as the system V IPC key 
	 * @var string filename
	 */
	private $tmpfile;
	
	/**
	 * The semaphore and shared memory segment key
	 * @var int key
	 */
	private $key;
	
	/**
	 * How many processes would wait on this barrier 
	 * @var int number of parties
	 */
	private $parties;
	
	
	/**
	 * Once constructed, a barrier stores information into a shared memory segment.
	 * @var int size
	 */
	private $shmSegmentSize = 8192;
	

	/**
	 * Posix signal used to notify once the barrier gets tripped
	 * @var integer constant
	 */
	private $signal = SIGUSR2;
	

	/**
	 * When a process reached the barrier by invoking await() its pid get stored into a shared memory segment
	 * whose size is specified as second argument. The size must be large enough to store
	 * an integer, and a list of process ids (at least the one participating)
	 * @param integer $parties the number of processes partipating in the synchonization process
	 * @param integer $size a power of two size. 
	 */
	public function __construct($parties, $segmentSize = NULL){
		$this->parties = $parties;
		$this->shmSegmentSize = $segmentSize === NULL ? $this->shmSegmentSize : $segmentSize;
		$this->generateShmkey();
		$this->attach();
		$this->initBarrier();
	}

	private function initBarrier(){
		sem_acquire($this->semaphore);
		shm_put_var($this->shm, 0x1, $this->parties);
		shm_put_var($this->shm, 0x2, []);
		shm_put_var($this->shm, 0x3, false);
		sem_release($this->semaphore);
	}
	
	/**
	 * Sets the signal that would be sent to wake up processes waiting for the barrier to get tripped
	 * @param int $signal
	 */
	public function setSignal($signal){
		$this->signal = $signal;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see LightProcessExecutor\Synchronization.BarrierInterface::getNumberWaiting()
	 */
	public function getNumberWaiting(){
		sem_acquire($this->semaphore);
		$waiting = $this->parties - (int)shm_get_var($this->shm, 0x1);
		sem_release($this->semaphore);
		return (int)$waiting;
	}
	
	
	/**
	 * (non-PHPdoc)
	 * @see LightProcessExecutor\Synchronization.BarrierInterface::getParties()
	 */
	public function getParties(){
		return $this->parties;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see LightProcessExecutor\Synchronization.BarrierInterface::reset()
	 */
	public function reset(){
		if($this->getNumberWaiting() !== 0){
			throw new \Exception("Cannot reset barrier while parties are waiting");
		}
		$this->initBarrier();	
	}
	
	/**
	 * (non-PHPdoc)
	 * @see \LightProcessExecutor\Synchronization\BarrierInterface::await()
	 */
	public function await($timeout = 0) {
		if($this->isBroken()){
			throw new BrokenBarrierException("Barrier is broken. Cannot reach until reset.");
		}
		sem_acquire($this->semaphore);
		// A new process reached the barrier, decrement counter
		$count = shm_get_var($this->shm, 0x1);
		shm_put_var($this->shm, 0x1, --$count);
		$parties = shm_get_var($this->shm, 0x2);
		if($count === 0){
			// Barrier tripped
			sem_release($this->semaphore);
			$this->notifyAll($parties);
			shm_detach($this->shm);
		}
		else {
			// Somes processes have not yet terminated, make the current process sleep until it gets notified
			$parties[] =  posix_getpid();
			shm_put_var($this->shm, 0x2, $parties);
			sem_release($this->semaphore);
			$siginfo = [];
			if($timeout !== 0){
				$ret = pcntl_sigtimedwait([$this->signal], $siginfo, $timeout);
			}
			else {
				$ret = pcntl_sigwaitinfo( [$this->signal], $siginfo);
			}
			if($ret === -1){
				// Mark the shared memory segment broken variable as true and notify all processes
				// Re-read the processes that have reached the barrier
				sem_acquire($this->semaphore);
					shm_put_var($this->shm, 0x3, true);
					$parties = shm_get_var($this->shm, 0x2);
				sem_release($this->semaphore);
				$this->notifyAll($parties);
				$err = pcntl_get_last_error();
				if($err === SOCKET_EINTR){
					throw new InterruptedException(sprintf("Interrupted system call %s", 'pcntl_sigtimedwait'));
				}
				// SOCKET_EINVAL and SOCKET_EAGAIN are not properly set as specified by the system man, this exception would set the errno appropriatly to mock the PHP engine behavior
				throw new TimeoutException($timeout);
			}
			else {
				// The process might exit the blocking call because one process has raised an exception (barrier is broken), check the shared memomy variable
				if($this->isBroken()){
					throw new BrokenBarrierException("Process woke up due to broken barrier");
				}
			}
		}
	}

	/**
	 * Exclusive read on a shared memory variable indicating whether the barrier is broken
	 * @return boolean true or false
	 */
	private function isBroken(){
		sem_acquire($this->semaphore);
		$broken = shm_get_var($this->shm, 0x3);
		sem_release($this->semaphore);
		return $broken;
	}
	
	/**
	 * Notifies all the processes in parties array with a signal. Signal sent is configured using setSignal()
	 * @param array $parties
	 */
	private function notifyAll($parties){
		foreach($parties as $pid){
			posix_kill($pid, $this->signal);
		}
	}
	
	private function generateShmkey(){
			$this->tmpfile = tempnam('/tmp', 'barrier');
			$this->key = fileinode($this->tmpfile);
	}
	
	private function attach(){
		$this->shm = shm_attach($this->key, $this->shmSegmentSize);
		if(!$this->shm){
			throw new \RuntimeException("Cannot create shared memory segment {$this->key}");
		}
		if(false === ($this->semaphore = sem_get($this->key))){
		throw new \RuntimeException("Cannot create semaphore with key {$this->key} for shared memory segment {$this->key}");
		}
	} 
	
	
	private function detach(){
		if(false === shm_detach($this->shm)){
		throw new \RuntimeException("Cannot detach shared memory segment {$this->key}");
		}
		sem_remove($this->semaphore);
	}
	
	/**
	 * Upon reconstructing the object, recreate the shared memory segment resource and the semaphore resource from
	 * the key
	 */
	public function __wakeup(){
		$this->attach();
	}
	
	/**
	 * On serialization, detach the shared memory segment, and remove the semaphore resource
	 * @return array of string that maps to internal properties to be serialized
	 */
	public function __sleep(){
		$this->detach();
		return ['parties', 'key', 'tmpfile', 'shmSegmentSize', 'signal'];
	}

	
	public function __destruct(){
		shm_remove($this->shm);
		sem_remove($this->semaphore);
		unlink($this->tmpfile);
	}	
}
