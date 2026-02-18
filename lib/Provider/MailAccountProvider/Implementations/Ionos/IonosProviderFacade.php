<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos;

use IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse;
use OCA\Mail\Account;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Provider\MailAccountProvider\Dto\MailboxInfo;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\Core\IonosAccountMutationService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\Core\IonosAccountQueryService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosAccountCreationService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosConfigService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosMailConfigService;
use OCA\Mail\Service\AccountService;
use OCP\IUserManager;
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
		private readonly AccountService $accountService,
		private readonly IUserManager $userManager,
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
	 * @param string $email The email address to verify before deletion
	 * @return bool True if deletion was successful
	 * @throws \OCA\Mail\Exception\ServiceException If deletion fails
	 */
	public function deleteAccount(string $userId, string $email): bool {
		$this->logger->info('Deleting IONOS account via facade', [
			'userId' => $userId,
			'email' => $email,
		]);

		try {
			$this->mutationService->tryDeleteEmailAccount($userId, $email);
			return true;
		} catch (\Exception $e) {
			$this->logger->error('Error deleting IONOS account via facade', [
				'userId' => $userId,
				'email' => $email,
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
	 * Returns a list of all mailboxes (email accounts) managed by this provider
	 * across all users. Used for administration/overview purposes.
	 *
	 * Enriches the data with:
	 * - User existence status (whether NC user exists)
	 * - Mail app account status (whether mail app account is configured)
	 * - Mail app account details (ID and name if configured)
	 *
	 * @return array<int, MailboxInfo> List of enriched mailbox information
	 * @throws ServiceException If retrieving mailboxes fails
	 */
	public function getMailboxes(): array {
		$this->logger->debug('Getting all IONOS mailboxes');

		$accountResponses = $this->queryService->getAllMailAccountResponses();

		$mailboxes = [];
		foreach ($accountResponses as $response) {
			$mailboxes[] = $this->createMailboxInfo($response);
		}

		$this->logger->debug('Retrieved IONOS mailboxes', ['count' => count($mailboxes)]);

		return $mailboxes;
	}

	/**
	 * Create mailbox info from API response
	 *
	 * @param MailAccountResponse $response The API response
	 * @return MailboxInfo The mailbox information
	 */
	private function createMailboxInfo(MailAccountResponse $response): MailboxInfo {
		$email = $response->getEmail();
		$userId = $response->getNextcloudUserId();

		$user = $this->userManager->get($userId);
		$userExists = $user !== null;

		$mailAppAccountId = null;
		$mailAppAccountName = null;
		$mailAppAccountExists = false;

		if ($userExists) {
			$matchingAccount = $this->findMatchingMailAppAccount($userId, $email);
			if ($matchingAccount !== null) {
				$mailAccount = $matchingAccount->getMailAccount();
				$mailAppAccountId = $mailAccount->getId();
				$mailAppAccountName = $mailAccount->getName();
				$mailAppAccountExists = true;
			}
		}

		return new MailboxInfo(
			userId: $userId,
			email: $email,
			userExists: $userExists,
			mailAppAccountId: $mailAppAccountId,
			mailAppAccountName: $mailAppAccountName,
			mailAppAccountExists: $mailAppAccountExists,
		);
	}

	/**
	 * Find mail app account matching the provider email
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $email The email address to match
	 * @return Account|null The matching account or null if not found
	 */
	private function findMatchingMailAppAccount(string $userId, string $email): ?Account {
		try {
			$accounts = $this->accountService->findByUserId($userId);
			foreach ($accounts as $account) {
				if (strcasecmp($account->getEmail(), $email) === 0) {
					return $account;
				}
			}
		} catch (\Exception $e) {
			$this->logger->debug('Error checking mail app account', [
				'userId' => $userId,
				'email' => $email,
				'exception' => $e,
			]);
		}
		return null;
	}

	/**
	 * Update a mailbox (e.g., change localpart)
	 *
	 * This method updates the mailbox by changing the localpart (email username).
	 * It updates the remote IONOS mailbox if localpart is provided, and also updates
	 * the local Nextcloud account if it exists.
	 *
	 * If no localpart is provided, this method simply returns the current mailbox
	 * information without making any changes to the remote IONOS mailbox.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param array<string, mixed> $data Update data (localpart is optional)
	 * @return MailboxInfo Enriched mailbox information
	 * @throws \OCA\Mail\Exception\AccountAlreadyExistsException If email is already taken
	 * @throws \OCA\Mail\Exception\ServiceException If update fails
	 */
	public function updateMailbox(string $userId, array $data): MailboxInfo {
		$this->logger->info('Updating IONOS mailbox via facade', [
			'userId' => $userId,
			'data' => array_keys($data),
		]);

		$localpart = $data['localpart'] ?? null;

		// Get current mailbox info from provider
		$accountResponse = $this->queryService->getMailAccountResponse($userId);
		if ($accountResponse === null) {
			throw new ServiceException('IONOS mailbox not found for user', 404);
		}
		$currentEmail = $accountResponse->getEmail();

		// If localpart is provided, update the remote IONOS mailbox
		$newEmail = $currentEmail;
		if ($localpart !== null && $localpart !== '') {
			try {
				// Update the remote IONOS mailbox
				$newEmail = $this->mutationService->updateMailboxLocalpart($userId, $localpart);

				$this->logger->info('Updated IONOS mailbox on provider', [
					'userId' => $userId,
					'oldEmail' => $currentEmail,
					'newEmail' => $newEmail,
				]);
			} catch (ServiceException $e) {
				// Convert 409 conflicts to AccountAlreadyExistsException
				if ($e->getCode() === 409) {
					throw new \OCA\Mail\Exception\AccountAlreadyExistsException(
						$e->getMessage(),
						$e->getCode(),
						[],
						$e
					);
				}
				throw $e;
			}
		} else {
			$this->logger->debug('No localpart provided, skipping remote mailbox update', [
				'userId' => $userId,
			]);
		}

		// Check if Nextcloud user exists
		$user = $this->userManager->get($userId);
		$userExists = $user !== null;

		// Try to find and update local mail account if it exists
		$mailAppAccountId = null;
		$mailAppAccountName = null;
		$mailAppAccountExists = false;

		if ($userExists) {
			try {
				// Get all accounts for this user and find the matching one by email
				$existingAccounts = $this->accountService->findByUserId($userId);

				foreach ($existingAccounts as $account) {
					$email = $account->getEmail();
					// Match the specific account by its full email address (current or new)
					if (strcasecmp($email, $currentEmail) === 0 || strcasecmp($email, $newEmail) === 0) {
						$mailAccount = $account->getMailAccount();

						$hasChanges = false;

						// Update the local account with new email and usernames (if email changed)
						if ($newEmail !== $currentEmail) {
							$mailAccount->setEmail($newEmail);
							$mailAccount->setInboundUser($newEmail);
							$mailAccount->setOutboundUser($newEmail);
							$hasChanges = true;
						}

						if ($hasChanges) {
							// Save the updated account
							$updatedMailAccount = $this->accountService->update($mailAccount);

							$mailAppAccountId = $updatedMailAccount->getId();
							$mailAppAccountName = $updatedMailAccount->getName();
							$mailAppAccountExists = true;

							$this->logger->info('Updated local mail account', [
								'userId' => $userId,
								'accountId' => $mailAppAccountId,
								'newEmail' => $newEmail,
							]);
						}

						break;
					}
				}
			} catch (\Exception $e) {
				// Log but don't fail - provider mailbox was updated successfully
				$this->logger->warning('Could not update local mail account', [
					'userId' => $userId,
					'exception' => $e,
				]);
			}
		}

		$this->logger->info('Successfully updated IONOS mailbox', [
			'userId' => $userId,
			'email' => $newEmail,
			'userExists' => $userExists,
			'mailAppAccountExists' => $mailAppAccountExists,
		]);

		// Return enriched mailbox data using MailboxInfo DTO (consistent with getMailboxes)
		return new MailboxInfo(
			userId: $userId,
			email: $newEmail,
			userExists: $userExists,
			mailAppAccountId: $mailAppAccountId,
			mailAppAccountName: $mailAppAccountName,
			mailAppAccountExists: $mailAppAccountExists,
		);
	}
}
