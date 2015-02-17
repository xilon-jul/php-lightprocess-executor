# php-lightprocess-executor
Php asynchronous multi-processing library

- Forking capabilities
- Asynchronous event handling via libevent
- The routing uses a flood-like algo to route messages accross a tree of processes
- Internal process communication via socket pairs
  A message is any type of payload and can be unicast or broadcast. There is currently no multicast support (aka group).
  Thus it can be achieved via aliasing processes before fork, it was not made for this purpose
- Application developper are responsible for defining listeners and writing their own application code.


```php
<?php
use LightProcessExecutor\Event\MessageEvent;

use LightProcessExecutor\EventListener\RouterEventListener;

use LightProcessExecutor\LightProcessExecutor;

declare(ticks=1) ;
require __DIR__ . '/vendor/autoload.php';


// Define a simple router listener
class TestListener implements RouterEventListener {

		public function onInterruptReceive(MessageEvent $e){
		  // Not to be used, the urgent flag in the routing protocol is obsolete and <b>should NOT</b> be used
		}
	
		public function onPeerShutdown(LightProcessExecutor $executor, $pid, array $lostMessages){
		  // invoked when a peer shuts down (the peer is necessarily connected to the process receiving the event)
		}
	
		public function onMessageReceived(MessageEvent $e){
			// invoked when a message is received 
		}
	
		public function onMessageSent(MessageEvent $e){
		  // invoked when this process has fully written its message to the targeted peers
		}
	
		public function onRouterError($operation, $errno, $errstr, \Exception $e = NULL){
		  // invoked when the router raises an error that the application programmer might want to handle
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

```


