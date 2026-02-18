<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Exception;

/**
 * Exception thrown when attempting to create or update a mail account
 * with an email address that already exists for another user
 */
class AccountAlreadyExistsException extends ServiceException {
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
	 * @return array<string, mixed> Error data (e.g., conflicting email, user ID)
	 */
	public function getData(): array {
		return $this->data;
	}
}
