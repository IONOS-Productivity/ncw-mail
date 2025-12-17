<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Exception;

use Throwable;

class IonosServiceException extends ServiceException {
	/**
	 * @param string $message [optional] The Exception message to throw.
	 * @param mixed $code [optional] The Exception code.
	 * @param null|Throwable $previous [optional] The previous throwable used for the exception chaining.
	 * @param array<string, mixed> $data [optional] Additional data to pass with the exception.
	 */
	public function __construct(
		$message = '',
		$code = 0,
		?Throwable $previous = null,
		private readonly array $data = [],
	) {
		parent::__construct($message, $code, $previous);
	}

	/**
	 * Get additional data associated with the exception
	 *
	 * @return array<string, mixed>
	 */
	public function getData(): array {
		return $this->data;
	}
}
