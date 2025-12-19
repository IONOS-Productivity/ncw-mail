<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos;

use OCA\Mail\Account;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\IONOS\Core\IonosAccountMutationService;
use OCA\Mail\Service\IONOS\Core\IonosAccountQueryService;
use OCA\Mail\Service\IONOS\IonosAccountCreationService;
use OCA\Mail\Service\IONOS\IonosConfigService;
use Psr\Log\LoggerInterface;

/**
 * Facade for IONOS provider operations
 *
 * This facade reduces coupling between IonosProvider and IONOS services
 * by providing a single, simplified interface for all IONOS operations.
 * It acts as a centralized entry point for provider functionality.
 */
class IonosProviderFacade {
	public function __construct(
		private IonosConfigService $configService,
		private IonosAccountQueryService $queryService,
		private IonosAccountMutationService $mutationService,
		private IonosAccountCreationService $creationService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Check if IONOS integration is enabled and properly configured
	 *
	 * @return bool True if provider can be used
	 */
	public function isEnabled(): bool {
		try {
			return $this->configService->isIonosIntegrationEnabled();
		} catch (\Exception $e) {
			$this->logger->debug('IONOS provider is not enabled', [
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Check if IONOS account provisioning is available for a user
	 *
	 * For IONOS, account is available only if user doesn't already have one
	 * (since multipleAccounts = false)
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return bool True if provisioning should be shown
	 */
	public function isAvailableForUser(string $userId): bool {
		try {
			$hasAccount = $this->queryService->mailAccountExistsForUserId($userId);
			return !$hasAccount;
		} catch (\Exception $e) {
			$this->logger->error('Error checking IONOS availability for user', [
				'userId' => $userId,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Create or update an IONOS mail account
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $emailUser The email username (local part before @)
	 * @param string $accountName The display name for the account
	 * @return Account The created or updated mail account
	 * @throws ServiceException If account creation fails
	 */
	public function createAccount(string $userId, string $emailUser, string $accountName): Account {
		$this->logger->info('Creating IONOS account via facade', [
			'userId' => $userId,
			'emailUser' => $emailUser,
		]);

		return $this->creationService->createOrUpdateAccount($userId, $emailUser, $accountName);
	}

	/**
	 * Update an existing IONOS mail account
	 *
	 * Currently uses the same logic as creation (which handles updates)
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $emailUser The email username (local part before @)
	 * @param string $accountName The display name for the account
	 * @return Account The updated account
	 * @throws ServiceException If update fails
	 */
	public function updateAccount(string $userId, string $emailUser, string $accountName): Account {
		$this->logger->info('Updating IONOS account via facade', [
			'userId' => $userId,
			'emailUser' => $emailUser,
		]);

		// Currently, creation service handles both create and update
		return $this->creationService->createOrUpdateAccount($userId, $emailUser, $accountName);
	}

	/**
	 * Delete an IONOS mail account
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return bool True if deletion was successful
	 */
	public function deleteAccount(string $userId): bool {
		$this->logger->info('Deleting IONOS account via facade', [
			'userId' => $userId,
		]);

		try {
			$this->mutationService->tryDeleteEmailAccount($userId);
			return true;
		} catch (\Exception $e) {
			$this->logger->error('Error deleting IONOS account via facade', [
				'userId' => $userId,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Get the provisioned email address for a user
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return string|null The email address or null if no account exists
	 */
	public function getProvisionedEmail(string $userId): ?string {
		try {
			return $this->queryService->getIonosEmailForUser($userId);
		} catch (\Exception $e) {
			$this->logger->debug('Error getting IONOS provisioned email', [
				'userId' => $userId,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * Check if a specific email address is managed by IONOS for a user
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $email The email address to check
	 * @return bool True if this email is managed by IONOS
	 */
	public function managesEmail(string $userId, string $email): bool {
		$ionosEmail = $this->getProvisionedEmail($userId);
		if ($ionosEmail === null) {
			return false;
		}
		return strcasecmp($email, $ionosEmail) === 0;
	}

	/**
	 * Get the email domain used by IONOS
	 *
	 * @return string|null The email domain or null if not configured
	 */
	public function getEmailDomain(): ?string {
		try {
			return $this->configService->getMailDomain();
		} catch (\Exception $e) {
			$this->logger->debug('Could not get IONOS email domain', [
				'exception' => $e,
			]);
			return null;
		}
	}
}
