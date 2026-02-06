<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 IONOS SE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service;

use OCA\Mail\Db\MailAccountMapper;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\IonosProviderFacade;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosConfigService;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Service for managing mailboxes in the admin interface
 */
class MailboxAdminService {
	public function __construct(
		private IonosProviderFacade $ionosFacade,
		private IonosConfigService $configService,
		private IUserManager $userManager,
		private MailAccountMapper $mailAccountMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * List all IONOS mailboxes with their linked users
	 *
	 * @return array<array{email: string, userId: string, displayName: string, username: string}>
	 */
	public function listAllMailboxes(): array {
		$mailboxes = [];

		// Check if IONOS integration is enabled
		if (!$this->ionosFacade->isEnabled()) {
			$this->logger->debug('IONOS integration is not enabled');
			return $mailboxes;
		}

		// Get all users from Nextcloud
		$this->userManager->callForSeenUsers(function ($user) use (&$mailboxes) {
			$userId = $user->getUID();
			
			// Try to get IONOS email for this user
			$email = $this->ionosFacade->getProvisionedEmail($userId);
			
			if ($email !== null) {
				$mailboxes[] = [
					'email' => $email,
					'userId' => $userId,
					'displayName' => $user->getDisplayName(),
					'username' => $userId,
				];
			}
		});

		$this->logger->info('Listed IONOS mailboxes', [
			'count' => count($mailboxes),
		]);

		return $mailboxes;
	}

	/**
	 * Update a mailbox email address by changing the localpart
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $newLocalpart The new local part (before @)
	 * @return array{success: bool, email?: string, error?: string}
	 */
	public function updateMailboxEmail(string $userId, string $newLocalpart): array {
		// Validate localpart
		if (empty($newLocalpart) || !$this->isValidLocalpart($newLocalpart)) {
			return [
				'success' => false,
				'error' => 'Invalid email localpart',
			];
		}

		// Get the domain
		$domain = $this->configService->getMailDomain();
		$newEmail = $newLocalpart . '@' . $domain;

		// Check if the new email is already taken
		if ($this->isEmailTaken($newEmail, $userId)) {
			return [
				'success' => false,
				'error' => 'Email is already taken. Please use some other username.',
			];
		}

		try {
			// Get user display name for account name
			$user = $this->userManager->get($userId);
			if ($user === null) {
				return [
					'success' => false,
					'error' => 'User not found',
				];
			}

			$accountName = $user->getDisplayName();

			// Update the account via IONOS facade
			// The createAccount method in the facade handles both create and update
			$account = $this->ionosFacade->createAccount($userId, $newLocalpart, $accountName);

			$this->logger->info('Successfully updated mailbox', [
				'userId' => $userId,
				'newEmail' => $newEmail,
			]);

			return [
				'success' => true,
				'email' => $newEmail,
			];
		} catch (\Exception $e) {
			$this->logger->error('Failed to update mailbox email', [
				'userId' => $userId,
				'newLocalpart' => $newLocalpart,
				'exception' => $e,
			]);

			return [
				'success' => false,
				'error' => $e->getMessage(),
			];
		}
	}

	/**
	 * Delete a mailbox
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return bool True if successful
	 */
	public function deleteMailbox(string $userId): bool {
		try {
			$success = $this->ionosFacade->deleteAccount($userId);
			
			$this->logger->info('Mailbox deletion result', [
				'userId' => $userId,
				'success' => $success,
			]);

			return $success;
		} catch (\Exception $e) {
			$this->logger->error('Failed to delete mailbox', [
				'userId' => $userId,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Check if an email is already taken by another user
	 *
	 * @param string $email The email address to check
	 * @param string $excludeUserId User ID to exclude from the check
	 * @return bool True if email is taken
	 */
	private function isEmailTaken(string $email, string $excludeUserId): bool {
		// Check all users for this email
		$allUsers = $this->userManager->search('');
		
		foreach ($allUsers as $user) {
			$userId = $user->getUID();
			
			// Skip the user we're updating
			if ($userId === $excludeUserId) {
				continue;
			}
			
			// Check if this user has this email
			$userEmail = $this->ionosFacade->getProvisionedEmail($userId);
			if ($userEmail !== null && strcasecmp($userEmail, $email) === 0) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Validate email localpart
	 *
	 * @param string $localpart The localpart to validate
	 * @return bool True if valid
	 */
	private function isValidLocalpart(string $localpart): bool {
		// Basic validation for email localpart
		// Allow alphanumeric, dot, hyphen, underscore
		return preg_match('/^[a-zA-Z0-9._-]+$/', $localpart) === 1;
	}
}
