<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\IONOS;

use Psr\Log\LoggerInterface;

/**
 * Service to check if IONOS mail configuration should be available for the user
 */
class IonosMailConfigService {
	public function __construct(
		private IonosConfigService $ionosConfigService,
		private IonosMailService $ionosMailService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Check if IONOS mail configuration should be available for the current user
	 *
	 * The configuration is available only if:
	 * 1. The feature is enabled in app config
	 * 2. The user does NOT already have an IONOS mail account
	 *
	 * @return bool True if mail configuration should be shown, false otherwise
	 */
	public function isMailConfigAvailable(): bool {
		try {
			// Check if feature is enabled in app config
			if (!$this->ionosConfigService->isMailConfigEnabled()) {
				return false;
			}

			// Check if user already has an account
			$userHasAccount = $this->ionosMailService->mailAccountExistsForCurrentUser();

			if ($userHasAccount) {
				$this->logger->debug('IONOS mail config not available - user already has an account');
				return false;
			}

			return true;
		} catch (\Exception $e) {
			$this->logger->error('Error checking IONOS mail config availability', [
				'exception' => $e,
			]);
			// Fail-safe: hide the feature on errors
			return false;
		}
	}
}
