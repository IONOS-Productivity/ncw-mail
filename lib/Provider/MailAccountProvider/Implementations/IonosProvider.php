<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider\Implementations;

use OCA\Mail\Account;
use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\IProviderCapabilities;
use OCA\Mail\Provider\MailAccountProvider\ProviderCapabilities;
use OCA\Mail\Service\IONOS\IonosAccountCreationService;
use OCA\Mail\Service\IONOS\IonosConfigService;
use OCA\Mail\Service\IONOS\IonosMailService;
use Psr\Log\LoggerInterface;

/**
 * IONOS Mail Account Provider
 *
 * Provides mail account provisioning through the IONOS Mail API
 */
class IonosProvider implements IMailAccountProvider {
	private const PROVIDER_ID = 'ionos';
	private const PROVIDER_NAME = 'IONOS Mail';

	private ?IProviderCapabilities $capabilities = null;

	public function __construct(
		private IonosConfigService $configService,
		private IonosMailService $mailService,
		private IonosAccountCreationService $creationService,
		private LoggerInterface $logger,
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
			// Get email domain from config service
			$emailDomain = null;
			try {
				$emailDomain = $this->configService->getMailDomain();
			} catch (\Exception $e) {
				$this->logger->debug('Could not get IONOS email domain', [
					'exception' => $e,
				]);
			}

			$this->capabilities = new ProviderCapabilities(
				multipleAccounts: false, // IONOS allows only one account per user
				appPasswords: true,      // IONOS supports app password generation
				passwordReset: true,     // IONOS supports password reset
				configSchema: [
					'ionos_mailconfig_api_base_url' => [
						'type' => 'string',
						'required' => true,
						'description' => 'Base URL for the IONOS Mail Configuration API',
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
		try {
			return $this->configService->isIonosIntegrationEnabled();
		} catch (\Exception $e) {
			$this->logger->debug('IONOS provider is not enabled', [
				'exception' => $e,
			]);
			return false;
		}
	}

	public function isAvailableForUser(string $userId): bool {
		try {
			// For IONOS, account is available only if user doesn't already have one
			// (since multipleAccounts = false)
			$hasAccount = $this->mailService->mailAccountExistsForCurrentUserId($userId);
			return !$hasAccount;
		} catch (\Exception $e) {
			$this->logger->error('Error checking IONOS availability for user', [
				'userId' => $userId,
				'exception' => $e,
			]);
			return false;
		}
	}

	public function createAccount(string $userId, array $parameters): Account {
		$emailUser = $parameters['emailUser'] ?? '';
		$accountName = $parameters['accountName'] ?? '';

		if (empty($emailUser) || empty($accountName)) {
			throw new \InvalidArgumentException('emailUser and accountName are required');
		}

		return $this->creationService->createOrUpdateAccount($userId, $emailUser, $accountName);
	}

	public function updateAccount(string $userId, int $accountId, array $parameters): Account {
		// For now, use same creation logic which handles updates
		return $this->createAccount($userId, $parameters);
	}

	public function deleteAccount(string $userId, string $email): bool {
		return $this->mailService->deleteEmailAccount($userId);
	}

	public function managesEmail(string $userId, string $email): bool {
		try {
			$ionosEmail = $this->mailService->getIonosEmailForUser($userId);
			if ($ionosEmail === null) {
				return false;
			}
			return strcasecmp($email, $ionosEmail) === 0;
		} catch (\Exception $e) {
			$this->logger->debug('Error checking if IONOS manages email', [
				'userId' => $userId,
				'email' => $email,
				'exception' => $e,
			]);
			return false;
		}
	}

	public function getProvisionedEmail(string $userId): ?string {
		try {
			return $this->mailService->getIonosEmailForUser($userId);
		} catch (\Exception $e) {
			$this->logger->debug('Error getting IONOS provisioned email', [
				'userId' => $userId,
				'exception' => $e,
			]);
			return null;
		}
	}
}
