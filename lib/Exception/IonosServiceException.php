<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Exception;

use Throwable;

/**
 * IONOS-specific service exception
 *
 * This exception extends ProviderServiceException to maintain backward compatibility
 * with existing IONOS code while also supporting the generic provider error handling.
 *
 * @deprecated Use ProviderServiceException directly for new code
 */
class IonosServiceException extends ProviderServiceException {
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
		array $data = [],
	) {
		parent::__construct($message, $code, $data, $previous);
	}
}
