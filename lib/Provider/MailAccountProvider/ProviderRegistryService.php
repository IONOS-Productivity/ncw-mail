<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider;

use Psr\Log\LoggerInterface;

/**
 * Registry service for managing mail account providers
 *
 * Responsible for discovering, registering, and accessing providers
 */
class ProviderRegistryService {
	/** @var array<string, IMailAccountProvider> */
	private array $providers = [];

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Register a provider
	 *
	 * @param IMailAccountProvider $provider The provider to register
	 */
	public function registerProvider(IMailAccountProvider $provider): void {
		$id = $provider->getId();

		if (isset($this->providers[$id])) {
			$this->logger->warning('Provider already registered, overwriting', [
				'providerId' => $id,
			]);
		}

		$this->providers[$id] = $provider;
		$this->logger->debug('Registered mail account provider', [
			'providerId' => $id,
			'providerName' => $provider->getName(),
		]);
	}

	/**
	 * Get a provider by ID
	 *
	 * @param string $providerId The provider ID
	 * @return IMailAccountProvider|null The provider or null if not found
	 */
	public function getProvider(string $providerId): ?IMailAccountProvider {
		return $this->providers[$providerId] ?? null;
	}

	/**
	 * Get all registered providers
	 *
	 * @return array<string, IMailAccountProvider> Array of providers indexed by ID
	 */
	public function getAllProviders(): array {
		return $this->providers;
	}

	/**
	 * Get all enabled providers
	 *
	 * @return array<string, IMailAccountProvider> Array of enabled providers indexed by ID
	 */
	public function getEnabledProviders(): array {
		return array_filter($this->providers, fn (IMailAccountProvider $provider) => $provider->isEnabled());
	}

	/**
	 * Get all providers available for a specific user
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return array<string, IMailAccountProvider> Array of available providers indexed by ID
	 */
	public function getAvailableProvidersForUser(string $userId): array {
		return array_filter(
			$this->getEnabledProviders(),
			fn (IMailAccountProvider $provider) => $provider->isAvailableForUser($userId)
		);
	}

	/**
	 * Find which provider manages a specific email address
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $email The email address
	 * @return IMailAccountProvider|null The managing provider or null
	 */
	public function findProviderForEmail(string $userId, string $email): ?IMailAccountProvider {
		foreach ($this->getEnabledProviders() as $provider) {
			if ($provider->managesEmail($userId, $email)) {
				return $provider;
			}
		}
		return null;
	}

	/**
	 * Get provider information for API responses
	 *
	 * @return array<string, array{id: string, name: string, enabled: bool, capabilities: array}>
	 */
	public function getProviderInfo(): array {
		$info = [];
		foreach ($this->providers as $id => $provider) {
			$capabilities = $provider->getCapabilities();
			$info[$id] = [
				'id' => $id,
				'name' => $provider->getName(),
				'enabled' => $provider->isEnabled(),
				'capabilities' => [
					'multipleAccounts' => $capabilities->allowsMultipleAccounts(),
					'appPasswords' => $capabilities->supportsAppPasswords(),
					'passwordReset' => $capabilities->supportsPasswordReset(),
				],
			];
		}
		return $info;
	}

	/**
	 * Delete all provider-managed accounts for a specific user
	 *
	 * This method iterates through the user's accounts and deletes those
	 * that are managed by registered providers.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param array $accounts List of user's mail accounts
	 */
	public function deleteProviderManagedAccounts(string $userId, array $accounts): void {
		foreach ($accounts as $account) {
			$email = $account->getEmail();

			// Check if this account is managed by a provider
			$provider = $this->findProviderForEmail($userId, $email);
			if ($provider !== null) {
				try {
					$this->logger->info('Deleting provider-managed account', [
						'provider' => $provider->getId(),
						'userId' => $userId,
						'email' => $email,
					]);

					$provider->deleteAccount($userId, $email);

					$this->logger->info('Successfully deleted provider-managed account', [
						'provider' => $provider->getId(),
						'userId' => $userId,
						'email' => $email,
					]);
				} catch (\Exception $e) {
					$this->logger->error('Failed to delete provider-managed account', [
						'provider' => $provider->getId(),
						'userId' => $userId,
						'email' => $email,
						'exception' => $e,
					]);
					// Continue with other accounts even if one fails
				}
			}
		}
	}
}
