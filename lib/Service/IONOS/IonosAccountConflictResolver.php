<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Mail\Service\IONOS;

use OCA\Mail\Service\IONOS\Dto\MailAccountConfig;
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
	 * @param string $emailUser The email username (local part before @)
	 * @return ConflictResolutionResult Result indicating whether to retry and with what config
	 */
	public function resolveConflict(string $emailUser): ConflictResolutionResult {
		$ionosConfig = $this->ionosMailService->getAccountConfigForCurrentUser();

		if ($ionosConfig === null) {
			$this->logger->debug('No existing IONOS account found for conflict resolution');
			return ConflictResolutionResult::noExistingAccount();
		}

		// Construct full email address from username to compare with existing account
		$domain = $this->ionosConfigService->getMailDomain();
		$expectedEmail = $emailUser . '@' . $domain;

		// Ensure the retrieved email matches the requested email
		if ($ionosConfig->getEmail() === $expectedEmail) {
			$this->logger->info('IONOS account already exists, retrieving configuration for retry', [
				'emailAddress' => $ionosConfig->getEmail()
			]);
			return ConflictResolutionResult::retry($ionosConfig);
		}

		$this->logger->warning('IONOS account exists but email mismatch', [
			'requestedEmail' => $expectedEmail,
			'existingEmail' => $ionosConfig->getEmail()
		]);

		return ConflictResolutionResult::emailMismatch($expectedEmail, $ionosConfig->getEmail());
	}
}
