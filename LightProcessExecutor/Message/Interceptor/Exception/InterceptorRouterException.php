<?php
namespace LightProcessExecutor\Message\Interceptor\Exception;

/**
 * Exception class used by the interceptor pattern. It wraps all of the router exception raised during the router event listener callback.
 * @author jpons
 */
class InterceptorRouterException extends \Exception {
}