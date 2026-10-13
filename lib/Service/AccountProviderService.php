<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service;

use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use Psr\Log\LoggerInterface;

/**
 * Helper service to add provider metadata to mail accounts
 *
 * This service determines which external provider (if any) manages a given mail account.
 * It's used by controllers to add provider information to account JSON responses.
 */
class AccountProviderService {
	public function __construct(
		private ProviderRegistryService $providerRegistry,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Add provider metadata to account JSON
	 *
	 * Determines if the account is managed by an external provider and adds:
	 * - managedByProvider: provider ID or null
	 * - providerCapabilities: capabilities object or null
	 *
	 * @param array<string, mixed> $accountJson The account JSON to enhance
	 * @param string $userId The user ID
	 * @param string $email The account email address
	 * @return array<string, mixed> The enhanced account JSON
	 */
	public function addProviderMetadata(array $accountJson, string $userId, string $email): array {
		try {
			$provider = $this->providerRegistry->findProviderForEmail($userId, $email);

			if ($provider !== null) {
				$capabilities = $provider->getCapabilities();

				$accountJson['managedByProvider'] = $provider->getId();
				$accountJson['providerCapabilities'] = [
					'multipleAccounts' => $capabilities->allowsMultipleAccounts(),
					'appPasswords' => $capabilities->supportsAppPasswords(),
					'passwordReset' => $capabilities->supportsPasswordReset(),
					'emailDomain' => $capabilities->getEmailDomain(),
				];
			} else {
				$accountJson['managedByProvider'] = null;
				$accountJson['providerCapabilities'] = null;
			}
		} catch (\Exception $e) {
			$this->logger->debug('Error determining account provider', [
				'userId' => $userId,
				'email' => $email,
				'exception' => $e,
			]);

			// Safe defaults on error
			$accountJson['managedByProvider'] = null;
			$accountJson['providerCapabilities'] = null;
		}

		return $accountJson;
	}

	/**
	 * Get all providers available for a user
	 *
	 * @param string $userId The user ID
	 * @return array<string, array{id: string, name: string, capabilities: array, parameterSchema: array}>
	 */
	public function getAvailableProvidersForUser(string $userId): array {
		$providers = $this->providerRegistry->getAvailableProvidersForUser($userId);
		$result = [];

		foreach ($providers as $provider) {
			$capabilities = $provider->getCapabilities();
			$result[$provider->getId()] = [
				'id' => $provider->getId(),
				'name' => $provider->getName(),
				'capabilities' => [
					'multipleAccounts' => $capabilities->allowsMultipleAccounts(),
					'appPasswords' => $capabilities->supportsAppPasswords(),
					'passwordReset' => $capabilities->supportsPasswordReset(),
					'emailDomain' => $capabilities->getEmailDomain(),
				],
				'parameterSchema' => $capabilities->getCreationParameterSchema(),
			];
		}

		return $result;
	}
}
