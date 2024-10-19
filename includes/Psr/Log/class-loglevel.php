<?php
/**
 * PSR-3 log level constants.
 * This file is part of the PSR-3 logger interface that defines a common interface for logging libraries.
 *
 * @link https://www.php-fig.org/psr/psr-3/
 * @link htts://plugins.joseconti.com
 * @author José Conti
 * @copyright 2024 José Conti
 *
 * @package Psr\Log
 */

namespace Psr\Log;

/**
 * Describes log levels.
 */
class LogLevel {
	const EMERGENCY = 'emergency';
	const ALERT     = 'alert';
	const CRITICAL  = 'critical';
	const ERROR     = 'error';
	const WARNING   = 'warning';
	const NOTICE    = 'notice';
	const INFO      = 'info';
	const DEBUG     = 'debug';
}
