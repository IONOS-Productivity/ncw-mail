<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos;

use OCA\Mail\Account;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\Core\IonosAccountMutationService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\Core\IonosAccountQueryService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosAccountCreationService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosConfigService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosMailConfigService;
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
		private readonly IonosConfigService $configService,
		private readonly IonosAccountQueryService $queryService,
		private readonly IonosAccountMutationService $mutationService,
		private readonly IonosAccountCreationService $creationService,
		private readonly IonosMailConfigService $mailConfigService,
		private readonly LoggerInterface $logger,
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
	 * The configuration is available only if:
	 * 1. The IONOS integration is enabled and properly configured
	 * 2. The user does NOT already have an IONOS mail account configured remotely
	 * 3. OR the user has a remote IONOS account but it's NOT configured locally in the mail app
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return bool True if provisioning should be shown
	 */
	public function isAvailableForUser(string $userId): bool {
		try {
			return $this->mailConfigService->isMailConfigAvailable($userId);
		} catch (\Exception $e) {
			$this->logger->error('Error checking IONOS availability for user', [
				'userId' => $userId,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Get existing IONOS mail account email for a user
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return string|null The email address if account exists, null otherwise
	 */
	public function getExistingAccountEmail(string $userId): ?string {
		try {
			$accountResponse = $this->queryService->getMailAccountResponse($userId);
			if ($accountResponse !== null) {
				return $accountResponse->getEmail();
			}
			return null;
		} catch (\Exception $e) {
			$this->logger->error('Error getting existing IONOS account email', [
				'userId' => $userId,
				'exception' => $e,
			]);
			return null;
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

	/**
	 * Generate an app password for the IONOS account
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return string The generated app password
	 * @throws \Exception If password generation fails
	 */
	public function generateAppPassword(string $userId): string {
		$this->logger->info('Generating IONOS app password via facade', [
			'userId' => $userId,
		]);

		return $this->mutationService->resetAppPassword($userId, IonosConfigService::APP_PASSWORD_NAME_USER);
	}

	/**
	 * Get all mailboxes managed by this provider
	 *
	 * Note: The IONOS API doesn't provide a direct "list all mailboxes" endpoint.
	 * This implementation returns an empty array as mailbox listing requires
	 * admin-level functionality that would need to be implemented separately.
	 *
	 * @return array<int, array{userId: string, email: string, name: string}> List of mailbox information
	 */
	public function getMailboxes(): array {
		$this->logger->debug('Getting all IONOS mailboxes');
		
		// TODO: Implement when IONOS API provides admin-level mailbox listing
		// For now, return empty array as this requires iterating over all users
		// which is not practical without dedicated API support
		return [];
	}

	/**
	 * Update a mailbox (e.g., change localpart)
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param array<string, mixed> $data Update data
	 * @return array{userId: string, email: string, name: string} Updated mailbox information
	 * @throws \OCA\Mail\Exception\ServiceException If update fails
	 */
	public function updateMailbox(string $userId, array $data): array {
		$this->logger->info('Updating IONOS mailbox via facade', [
			'userId' => $userId,
			'data' => array_keys($data),
		]);

		$localpart = $data['localpart'] ?? null;
		$name = $data['name'] ?? '';

		if ($localpart === null || $localpart === '') {
			throw new \InvalidArgumentException('localpart is required for mailbox update');
		}

		// Update the account using the creation service (which handles updates)
		$account = $this->creationService->createOrUpdateAccount($userId, $localpart, $name);

		return [
			'userId' => $userId,
			'email' => $account->getEmail(),
			'name' => $account->getName(),
		];
	}

	/**
	 * Delete a mailbox
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return bool True if deletion was successful
	 * @throws \OCA\Mail\Exception\ServiceException If deletion fails
	 */
	public function deleteMailbox(string $userId): bool {
		return $this->deleteAccount($userId);
	}
}
