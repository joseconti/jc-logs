<?php
/**
 * PSR-3 logger interface.
 *
 * @package Psr\Log
 */

namespace Psr\Log;

interface LoggerInterface {
	/**
	 * System is unusable.
	 *
	 * @param string $message  The log message.
	 * @param array  $context  The context array.
	 * @return void
	 */
	public function emergency( $message, array $context = array() );

	/**
	 * Action must be taken immediately.
	 *
	 * @param string $message  The log message.
	 * @param array  $context  The context array.
	 * @return void
	 */
	public function alert( $message, array $context = array() );

	/**
	 * Critical conditions.
	 *
	 * @param string $message  The log message.
	 * @param array  $context  The context array.
	 * @return void
	 */
	public function critical( $message, array $context = array() );

	/**
	 * Runtime errors that do not require immediate action but should typically
	 * be logged and monitored.
	 *
	 * @param string $message  The log message.
	 * @param array  $context  The context array.
	 * @return void
	 */
	public function error( $message, array $context = array() );

	/**
	 * Exceptional occurrences that are not errors.
	 *
	 * @param string $message  The log message.
	 * @param array  $context  The context array.
	 * @return void
	 */
	public function warning( $message, array $context = array() );

	/**
	 * Normal but significant events.
	 *
	 * @param string $message  The log message.
	 * @param array  $context  The context array.
	 * @return void
	 */
	public function notice( $message, array $context = array() );

	/**
	 * Interesting events.
	 *
	 * @param string $message  The log message.
	 * @param array  $context  The context array.
	 * @return void
	 */
	public function info( $message, array $context = array() );

	/**
	 * Detailed debug information.
	 *
	 * @param string $message  The log message.
	 * @param array  $context  The context array.
	 * @return void
	 */
	public function debug( $message, array $context = array() );

	/**
	 * Logs with an arbitrary level.
	 *
	 * @param mixed  $level    The log level.
	 * @param string $message  The log message.
	 * @param array  $context  The context array.
	 * @return void
	 */
	public function log( $level, $message, array $context = array() );
}
