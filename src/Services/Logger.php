<?php
declare(strict_types=1);

namespace WicketLockbox\Services;

class Logger
{
	/**
	 * Log info message.
	 */
	public function info( string $message, array $context = [] ): void
	{
		$this->log( 'info', $message, $context );
	}

	/**
	 * Log error message.
	 */
	public function error( string $message, array $context = [] ): void
	{
		$this->log( 'error', $message, $context );
	}

	/**
	 * Log warning message.
	 */
	public function warning( string $message, array $context = [] ): void
	{
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Internal log handler.
	 */
	private function log( string $level, string $message, array $context ): void
	{
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->log( $level, $message, array_merge( [ 'source' => 'wicket-wp-lockbox' ], $context ) );
		} else {
			error_log( sprintf( '[Wicket Lockbox %s]: %s %s', strtoupper( $level ), $message, json_encode( $context ) ) );
		}
	}
}
