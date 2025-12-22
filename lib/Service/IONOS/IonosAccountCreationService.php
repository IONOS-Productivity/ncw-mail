<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\IONOS;

use OCA\Mail\Account;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Exception\ProviderServiceException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Provider\MailAccountProvider\Common\Dto\MailAccountConfig;
use OCA\Mail\Service\AccountService;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Service for creating and updating IONOS mail accounts in Nextcloud
 *
 * This service handles the common logic for creating or updating Nextcloud mail accounts
 * with IONOS configuration, ensuring consistency between CLI and web interfaces.
 */
class IonosAccountCreationService {
	public function __construct(
		private IonosMailService $ionosMailService,
		private IonosAccountConflictResolver $conflictResolver,
		private AccountService $accountService,
		private ICrypto $crypto,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Create or update IONOS mail account for a user
	 *
	 * This method handles the full flow:
	 * 1. Check if Nextcloud account already exists
	 * 2. If exists: Reset IONOS password and update Nextcloud account
	 * 3. If not exists: Create IONOS account and Nextcloud account
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $emailUser The email username (local part before @)
	 * @param string $accountName The display name for the account
	 * @return Account The created or updated mail account
	 * @throws ServiceException If account creation fails
	 * @throws IonosServiceException If IONOS account creation fails
	 */
	public function createOrUpdateAccount(string $userId, string $emailUser, string $accountName): Account {
		$expectedEmail = $this->buildEmailAddress($emailUser);

		// Check if Nextcloud account already exists
		$existingAccounts = $this->accountService->findByUserIdAndAddress($userId, $expectedEmail);

		if (!empty($existingAccounts)) {
			return $this->handleExistingAccount($userId, $emailUser, $accountName, $existingAccounts[0]);
		}

		// No existing account - create new one
		return $this->handleNewAccount($userId, $emailUser, $accountName);
	}

	/**
	 * Handle the case where a Nextcloud mail account already exists
	 */
	private function handleExistingAccount(string $userId, string $emailUser, string $accountName, $existingAccount): Account {
		$this->logger->info('Nextcloud mail account already exists, resetting credentials', [
			'accountId' => $existingAccount->getId(),
			'emailAddress' => $existingAccount->getEmail(),
			'userId' => $userId,
		]);

		try {
			$resolutionResult = $this->conflictResolver->resolveConflict($userId, $emailUser);

			if (!$resolutionResult->canRetry()) {
				if ($resolutionResult->hasEmailMismatch()) {
					throw new ProviderServiceException(
						'IONOS account exists but email mismatch. Expected: ' . $resolutionResult->getExpectedEmail() . ', Found: ' . $resolutionResult->getExistingEmail(),
						IonosMailService::STATUS__409_CONFLICT,
						[
							'expectedEmail' => $resolutionResult->getExpectedEmail(),
							'existingEmail' => $resolutionResult->getExistingEmail(),
						]
					);
				}
				throw new ServiceException('Nextcloud account exists but no IONOS account found', 500);
			}

			$mailConfig = $resolutionResult->getAccountConfig();
			return $this->updateAccount($existingAccount->getMailAccount(), $accountName, $mailConfig);
		} catch (ProviderServiceException $e) {
			// Re-throw ProviderServiceException as-is
			throw $e;
		} catch (ServiceException $e) {
			throw new ServiceException('Failed to reset IONOS account credentials: ' . $e->getMessage(), $e->getCode(), $e);
		}
	}

	/**
	 * Handle the case where no Nextcloud account exists yet
	 */
	private function handleNewAccount(string $userId, string $emailUser, string $accountName): Account {
		try {
			$this->logger->info('Creating new IONOS email account', [
				'userId' => $userId,
				'emailUser' => $emailUser,
				'accountName' => $accountName
			]);

			$mailConfig = $this->ionosMailService->createEmailAccountForUser($userId, $emailUser);

			$this->logger->info('IONOS email account created successfully', [
				'emailAddress' => $mailConfig->getEmail()
			]);

			return $this->createAccount($userId, $accountName, $mailConfig);
		} catch (ServiceException $e) {
			// Try to resolve conflict - IONOS account might already exist
			$this->logger->info('IONOS account creation failed, attempting conflict resolution', [
				'userId' => $userId,
				'emailUser' => $emailUser,
				'error' => $e->getMessage()
			]);

			$resolutionResult = $this->conflictResolver->resolveConflict($userId, $emailUser);

			if (!$resolutionResult->canRetry()) {
				if ($resolutionResult->hasEmailMismatch()) {
					throw new ProviderServiceException(
						'IONOS account exists but email mismatch. Expected: ' . $resolutionResult->getExpectedEmail() . ', Found: ' . $resolutionResult->getExistingEmail(),
						IonosMailService::STATUS__409_CONFLICT,
						[
							'expectedEmail' => $resolutionResult->getExpectedEmail(),
							'existingEmail' => $resolutionResult->getExistingEmail(),
						],
						$e
					);
				}
				// No existing IONOS account found - re-throw original error
				throw $e;
			}

			$mailConfig = $resolutionResult->getAccountConfig();
			return $this->createAccount($userId, $accountName, $mailConfig);
		}
	}

	/**
	 * Create a new Nextcloud mail account
	 */
	private function createAccount(string $userId, string $accountName, MailAccountConfig $mailConfig): Account {
		$account = new MailAccount();
		$account->setUserId($userId);
		$account->setName($accountName);
		$account->setEmail($mailConfig->getEmail());
		$account->setAuthMethod('password');

		$this->setAccountCredentials($account, $mailConfig);

		$account = $this->accountService->save($account);

		$this->logger->info('Created new Nextcloud mail account', [
			'accountId' => $account->getId(),
			'emailAddress' => $account->getEmail(),
			'userId' => $userId,
		]);

		return new Account($account);
	}

	/**
	 * Update an existing Nextcloud mail account
	 */
	private function updateAccount(MailAccount $account, string $accountName, MailAccountConfig $mailConfig): Account {
		$account->setName($accountName);
		$this->setAccountCredentials($account, $mailConfig);

		$account = $this->accountService->update($account);

		$this->logger->info('Updated existing Nextcloud mail account with new credentials', [
			'accountId' => $account->getId(),
			'emailAddress' => $account->getEmail(),
			'userId' => $account->getUserId(),
		]);

		return new Account($account);
	}

	/**
	 * Set IMAP and SMTP credentials on a mail account
	 */
	private function setAccountCredentials(MailAccount $account, MailAccountConfig $mailConfig): void {
		$imap = $mailConfig->getImap();
		$account->setInboundHost($imap->getHost());
		$account->setInboundPort($imap->getPort());
		$account->setInboundSslMode($imap->getSecurity());
		$account->setInboundUser($imap->getUsername());
		$account->setInboundPassword($this->crypto->encrypt($imap->getPassword()));

		$smtp = $mailConfig->getSmtp();
		$account->setOutboundHost($smtp->getHost());
		$account->setOutboundPort($smtp->getPort());
		$account->setOutboundSslMode($smtp->getSecurity());
		$account->setOutboundUser($smtp->getUsername());
		$account->setOutboundPassword($this->crypto->encrypt($smtp->getPassword()));
	}

	/**
	 * Build full email address from username
	 */
	private function buildEmailAddress(string $emailUser): string {
		$domain = $this->ionosMailService->getMailDomain();
		return $emailUser . '@' . $domain;
	}
}
