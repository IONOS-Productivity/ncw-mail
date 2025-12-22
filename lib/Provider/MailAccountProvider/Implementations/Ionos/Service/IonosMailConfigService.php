<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service;

use OCA\Mail\Service\AccountService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service to check if IONOS mail configuration should be available for the user
 */
class IonosMailConfigService {
	public function __construct(
		private IonosConfigService $ionosConfigService,
		private IonosMailService $ionosMailService,
		private AccountService $accountService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Check if IONOS mail configuration should be available for the current user
	 *
	 * The configuration is available only if:
	 * 1. The IONOS integration is enabled and properly configured
	 * 2. The user does NOT already have an IONOS mail account configured remotely
	 * 3. OR the user has a remote IONOS account but it's NOT configured locally in the mail app
	 *
	 * @return bool True if mail configuration should be shown, false otherwise
	 */
	public function isMailConfigAvailable(): bool {
		try {
			// Check if IONOS integration is enabled and configured
			if (!$this->ionosConfigService->isIonosIntegrationEnabled()) {
				return false;
			}

			// Get current user
			$user = $this->userSession->getUser();
			if ($user === null) {
				$this->logger->debug('IONOS mail config not available - no user session');
				return false;
			}
			$userId = $user->getUID();

			// Check if user already has a remote IONOS account
			$userHasRemoteAccount = $this->ionosMailService->mailAccountExistsForCurrentUser();

			if (!$userHasRemoteAccount) {
				// No remote account exists, configuration should be available
				return true;
			}

			// User has a remote account, check if it's configured locally
			$ionosEmail = $this->ionosMailService->getIonosEmailForUser($userId);
			if ($ionosEmail === null) {
				// This shouldn't happen if userHasRemoteAccount is true, but handle it gracefully
				$this->logger->warning('IONOS remote account exists but email could not be retrieved');
				return false;
			}

			// Check if the IONOS email is configured in the local mail app
			$localAccounts = $this->accountService->findByUserIdAndAddress($userId, $ionosEmail);
			$hasLocalAccount = count($localAccounts) > 0;

			if ($hasLocalAccount) {
				$this->logger->debug('IONOS mail config not available - user already has account configured locally', [
					'email' => $ionosEmail,
				]);
				return false;
			}

			// Remote account exists but not configured locally - show configuration
			$this->logger->debug('IONOS mail config available - remote account exists but not configured locally', [
				'email' => $ionosEmail,
			]);
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
