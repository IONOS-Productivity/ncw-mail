<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider;

use OCA\Mail\Account;
use OCA\Mail\Provider\MailAccountProvider\Dto\MailboxInfo;

/**
 * Interface for external mail account providers
 *
 * Providers implement this interface to offer mail account provisioning
 * through external APIs. Examples: IONOS, Office365, Google Workspace, etc.
 */
interface IMailAccountProvider {
	/**
	 * Get the unique identifier for this provider
	 *
	 * @return string Provider ID (e.g., 'ionos', 'office365')
	 */
	public function getId(): string;

	/**
	 * Get the human-readable name of this provider
	 *
	 * @return string Display name (e.g., 'IONOS Mail', 'Microsoft 365')
	 */
	public function getName(): string;

	/**
	 * Get the capabilities of this provider
	 *
	 * @return IProviderCapabilities Capabilities object
	 */
	public function getCapabilities(): IProviderCapabilities;

	/**
	 * Check if this provider is enabled and properly configured
	 *
	 * @return bool True if provider can be used
	 */
	public function isEnabled(): bool;

	/**
	 * Check if mail account provisioning should be available for the given user
	 *
	 * This determines if the user should see the option to create an account
	 * through this provider in the UI.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return bool True if provisioning should be shown
	 */
	public function isAvailableForUser(string $userId): bool;

	/**
	 * Create a mail account via the external provider
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param array<string, mixed> $parameters Provider-specific parameters (e.g., email username, domain)
	 * @return Account The created Nextcloud mail account
	 * @throws \OCA\Mail\Exception\ServiceException If account creation fails
	 */
	public function createAccount(string $userId, array $parameters): Account;

	/**
	 * Update an existing mail account (e.g., reset password)
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param int $accountId The Nextcloud mail account ID
	 * @param array<string, mixed> $parameters Provider-specific parameters
	 * @return Account The updated account
	 * @throws \OCA\Mail\Exception\ServiceException If update fails
	 */
	public function updateAccount(string $userId, int $accountId, array $parameters): Account;

	/**
	 * Delete a mail account from the external provider
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $email The email address to delete
	 * @return bool True if deletion was successful
	 */
	public function deleteAccount(string $userId, string $email): bool;

	/**
	 * Check if the given email address is managed by this provider
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $email The email address to check
	 * @return bool True if this provider manages this email
	 */
	public function managesEmail(string $userId, string $email): bool;

	/**
	 * Get the email address managed by this provider for the given user
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return string|null The email address or null if no account exists
	 */
	public function getProvisionedEmail(string $userId): ?string;

	/**
	 * Get the email address of an existing account for the given user
	 *
	 * This method is used to provide better error messages when a user
	 * tries to create an account but already has one configured.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return string|null The email address if account exists, null otherwise
	 */
	public function getExistingAccountEmail(string $userId): ?string;

	/**
	 * Generate a new app password for the user's account
	 *
	 * This method generates a new application-specific password that can be used
	 * for IMAP/SMTP authentication. Only available if the provider supports
	 * app passwords (check getCapabilities()->supportsAppPasswords()).
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return string The generated password
	 * @throws \OCA\Mail\Exception\ProviderServiceException If password generation fails
	 * @throws \InvalidArgumentException If provider doesn't support app passwords
	 */
	public function generateAppPassword(string $userId): string;

	/**
	 * Get all mailboxes managed by this provider
	 *
	 * Returns a list of all mailboxes (email accounts) managed by this provider
	 * across all users. Used for administration/overview purposes.
	 *
	 * The returned data includes status information to help identify configuration issues:
	 * - userExists: Whether the Nextcloud user still exists
	 * - mailAppAccountExists: Whether a mail app account is configured for this email
	 * - mailAppAccountId/Name: Details of the configured mail app account (if exists)
	 *
	 * @return array<int, MailboxInfo> List of enriched mailbox information
	 * @throws \OCA\Mail\Exception\ServiceException If fetching mailboxes fails
	 */
	public function getMailboxes(): array;
}
