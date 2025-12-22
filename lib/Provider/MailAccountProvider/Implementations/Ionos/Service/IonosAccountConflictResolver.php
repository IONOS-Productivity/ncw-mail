<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service;

use OCA\Mail\Exception\ServiceException;
use Psr\Log\LoggerInterface;

/**
 * Handles conflict resolution when IONOS account creation fails
 */
class IonosAccountConflictResolver {


	public function __construct(
		private IonosMailService $ionosMailService,
		private IonosConfigService $ionosConfigService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Attempts to resolve account creation conflict by checking for existing account
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $emailUser The email username (local part before @)
	 * @return ConflictResolutionResult Result indicating whether to retry and with what config
	 * @throws ServiceException
	 */
	public function resolveConflict(string $userId, string $emailUser): ConflictResolutionResult {
		$ionosConfig = $this->ionosMailService->getAccountConfigForUser($userId);

		if ($ionosConfig === null) {
			$this->logger->debug('No existing IONOS account found for conflict resolution', [
				'userId' => $userId
			]);
			return ConflictResolutionResult::noExistingAccount();
		}

		// Construct full email address from username to compare with existing account
		$domain = $this->ionosConfigService->getMailDomain();
		$expectedEmail = $emailUser . '@' . $domain;

		// Ensure the retrieved email matches the requested email
		if ($ionosConfig->getEmail() === $expectedEmail) {
			$this->logger->info('IONOS account already exists, retrieving new password for retry', [
				'emailAddress' => $ionosConfig->getEmail(),
				'userId' => $userId
			]);

			// Get fresh password via resetAppPassword API since getAccountConfigForUser
			// does not return password for security reasons
			$newPassword = $this->ionosMailService->resetAppPassword($userId, IonosConfigService::APP_NAME);

			// Create new config with the fresh password
			$configWithPassword = $ionosConfig->withPassword($newPassword);

			return ConflictResolutionResult::retry($configWithPassword);
		}

		$this->logger->warning('IONOS account exists but email mismatch', [
			'requestedEmail' => $expectedEmail,
			'existingEmail' => $ionosConfig->getEmail(),
			'userId' => $userId
		]);

		return ConflictResolutionResult::emailMismatch($expectedEmail, $ionosConfig->getEmail());
	}
}
