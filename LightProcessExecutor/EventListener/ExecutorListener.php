<?php
namespace LightProcessExecutor\EventListener;

/**
 * 
 * Listener that allow to hook inside the executor life cycles.
 * @author jpons
 */
use LightProcessExecutor\LightProcessExecutor;

interface ExecutorListener {

	/**
	 * Callback invoked when an executor is asked to shutdown
	 * @param LightProcessExecutor $executor the executor being shut down
	 * @return void
	 */
	public function onShutdown(LightProcessExecutor $executor);
	
	/**
	 * Callback invoked when the executor starts up, just before it enters the main loop 
	 * @param LightProcessExecutor $executor
	 * @return void
	 */
	public function onStart(LightProcessExecutor $executor);
	
	/**
	 * Callbacl invoked when the executor exits the libevent's main loop
	 * @param LightProcessExecutor $executor 
	 * @return void
	 */
	public function onExitLoop(LightProcessExecutor $executor);

}
