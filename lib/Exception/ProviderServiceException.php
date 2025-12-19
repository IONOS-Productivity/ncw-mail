<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Exception;

/**
 * Generic exception for external mail account provider errors
 *
 * This exception can be thrown by any provider implementation to communicate
 * structured error information to the controller layer. It includes an optional
 * data payload for provider-specific error details.
 */
class ProviderServiceException extends ServiceException {
	/**
	 * @param string $message Error message
	 * @param int $code HTTP status code
	 * @param array<string, mixed> $data Additional structured error data
	 * @param \Throwable|null $previous Previous exception
	 */
	public function __construct(
		string $message,
		int $code = 0,
		private array $data = [],
		?\Throwable $previous = null,
	) {
		parent::__construct($message, $code, $previous);
	}

	/**
	 * Get additional structured error data
	 *
	 * @return array<string, mixed> Error data (e.g., validation errors, provider-specific codes)
	 */
	public function getData(): array {
		return $this->data;
	}
}
