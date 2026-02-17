<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider\Implementations;

use OCA\Mail\Account;
use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\IonosProviderFacade;
use OCA\Mail\Provider\MailAccountProvider\IProviderCapabilities;
use OCA\Mail\Provider\MailAccountProvider\ProviderCapabilities;

/**
 * IONOS Nextcloud Workspace Mail Account Provider
 *
 * Provides mail account provisioning through the IONOS Nextcloud Workspace Mail API
 * Uses a facade pattern to reduce coupling with IONOS services
 */
class IonosProvider implements IMailAccountProvider {
	private const PROVIDER_ID = 'ionos';
	private const PROVIDER_NAME = 'IONOS Nextcloud Workspace Mail';

	private ?IProviderCapabilities $capabilities = null;

	public function __construct(
		private IonosProviderFacade $facade,
	) {
	}

	public function getId(): string {
		return self::PROVIDER_ID;
	}

	public function getName(): string {
		return self::PROVIDER_NAME;
	}

	public function getCapabilities(): IProviderCapabilities {
		if ($this->capabilities === null) {
			// Get email domain via facade
			$emailDomain = $this->facade->getEmailDomain();

			$this->capabilities = new ProviderCapabilities(
				multipleAccounts: false, // IONOS allows only one account per user
				appPasswords: true,      // IONOS supports app password generation
				passwordReset: true,     // IONOS supports password reset
				configSchema: [
					'ionos_mailconfig_api_base_url' => [
						'type' => 'string',
						'required' => true,
						'description' => 'Base URL for the IONOS Nextcloud Workspace Mail Configuration API',
					],
					'ionos_mailconfig_api_auth_user' => [
						'type' => 'string',
						'required' => true,
						'description' => 'Basic auth username for IONOS API',
					],
					'ionos_mailconfig_api_auth_pass' => [
						'type' => 'string',
						'required' => true,
						'description' => 'Basic auth password for IONOS API',
					],
					'ionos_mailconfig_api_allow_insecure' => [
						'type' => 'boolean',
						'required' => false,
						'description' => 'Allow insecure connections (for development)',
					],
					'ncw.ext_ref' => [
						'type' => 'string',
						'required' => true,
						'description' => 'External reference ID (system config)',
					],
					'ncw.customerDomain' => [
						'type' => 'string',
						'required' => true,
						'description' => 'Customer domain for email addresses (system config)',
					],
				],
				creationParameterSchema: [
					'accountName' => [
						'type' => 'string',
						'required' => true,
						'description' => 'Name',
					],
					'emailUser' => [
						'type' => 'string',
						'required' => true,
						'description' => 'User',
					],
				],
				emailDomain: $emailDomain,
			);
		}
		return $this->capabilities;
	}

	public function isEnabled(): bool {
		return $this->facade->isEnabled();
	}

	public function isAvailableForUser(string $userId): bool {
		return $this->facade->isAvailableForUser($userId);
	}

	public function getExistingAccountEmail(string $userId): ?string {
		return $this->facade->getExistingAccountEmail($userId);
	}

	public function createAccount(string $userId, array $parameters): Account {
		$emailUser = $parameters['emailUser'] ?? '';
		$accountName = $parameters['accountName'] ?? '';

		if (empty($emailUser) || empty($accountName)) {
			throw new \InvalidArgumentException('emailUser and accountName are required');
		}

		return $this->facade->createAccount($userId, $emailUser, $accountName);
	}

	public function updateAccount(string $userId, int $accountId, array $parameters): Account {
		// For now, use same creation logic which handles updates
		return $this->createAccount($userId, $parameters);
	}

	public function deleteAccount(string $userId, string $email): bool {
		return $this->facade->deleteAccount($userId);
	}

	public function managesEmail(string $userId, string $email): bool {
		return $this->facade->managesEmail($userId, $email);
	}

	public function getProvisionedEmail(string $userId): ?string {
		return $this->facade->getProvisionedEmail($userId);
	}

	public function generateAppPassword(string $userId): string {
		// Check if provider supports app passwords
		if (!$this->getCapabilities()->supportsAppPasswords()) {
			throw new \InvalidArgumentException('IONOS provider does not support app password generation');
		}

		try {
			return $this->facade->generateAppPassword($userId);
		} catch (\Exception $e) {
			throw new \OCA\Mail\Exception\ProviderServiceException(
				'Failed to generate app password: ' . $e->getMessage(),
				0,
				[],
				$e
			);
		}
	}

	public function getMailboxes(): array {
		return $this->facade->getMailboxes();
	}
}
