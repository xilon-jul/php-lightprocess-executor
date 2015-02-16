<?php
use LightProcessExecutor\Event\MessageEvent;

use LightProcessExecutor\EventListener\RouterEventListener;

use LightProcessExecutor\LightProcessExecutor;

declare(ticks=1) ;
require __DIR__ . '/vendor/autoload.php';


// Define a simple router listener
class TestListener implements RouterEventListener {

		public function onInterruptReceive(MessageEvent $e){
		}
	
		public function onPeerShutdown(LightProcessExecutor $executor, $pid, array $lostMessages){
		}
	
		public function onMessageReceived(MessageEvent $e){
			echo "Pid ".posix_getpid()." message reveived $e\n";
		}
	
		public function onMessageSent(MessageEvent $e){
		}
	
		public function onRouterError($operation, $errno, $errstr, \Exception $e = NULL){
		}

		public function getPriority(){
			return 0;
		}
}


$root = new LightProcessExecutor();
$root->addRouterMessageListener(new TestListener());
// Might want to fork processes here
$root->fork();
$pid2 = $root->fork("", null, function($executor){
	$executor->fork();
});
$root->submit("test", null, false, false, true);
$root->submit("for pid2 only", $pid2);
$root->loop();


