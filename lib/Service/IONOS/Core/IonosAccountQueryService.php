<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\IONOS\Core;

use IONOS\MailConfigurationAPI\Client\ApiException;
use IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse;
use OCA\Mail\Service\IONOS\ApiMailConfigClientService;
use OCA\Mail\Service\IONOS\Dto\MailAccountConfig;
use OCA\Mail\Service\IONOS\Dto\MailServerConfig;
use OCA\Mail\Service\IONOS\IonosConfigService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for querying IONOS mail account information (read-only operations)
 */
class IonosAccountQueryService {
	private const BRAND = 'IONOS';
	private const HTTP_NOT_FOUND = 404;

	public function __construct(
		private ApiMailConfigClientService $apiClientService,
		private IonosConfigService $configService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Check if the current logged-in user already has an IONOS email account
	 *
	 * @return bool true if account exists, false otherwise
	 */
	public function mailAccountExistsForCurrentUser(): bool {
		$userId = $this->getCurrentUserId();
		return $this->mailAccountExistsForUserId($userId);
	}

	/**
	 * Check if a specific user has an IONOS email account
	 *
	 * @param string $userId The user ID to check
	 * @return bool true if account exists, false otherwise
	 */
	public function mailAccountExistsForUserId(string $userId): bool {
		$response = $this->getMailAccountResponse($userId);

		if ($response !== null) {
			$this->logger->debug('User has existing IONOS mail account', [
				'email' => $response->getEmail(),
				'userId' => $userId
			]);
			return true;
		}

		return false;
	}

	/**
	 * Get the IONOS mail account response for a specific user
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return MailAccountResponse|null The account response if it exists, null otherwise
	 */
	public function getMailAccountResponse(string $userId): ?MailAccountResponse {
		try {
			$this->logger->debug('Getting IONOS mail account for user', [
				'userId' => $userId,
				'extRef' => $this->configService->getExternalReference(),
			]);

			$apiInstance = $this->createApiInstance();
			$result = $apiInstance->getFunctionalAccount(
				self::BRAND,
				$this->configService->getExternalReference(),
				$userId
			);

			if ($result instanceof MailAccountResponse) {
				return $result;
			}

			return null;
		} catch (ApiException $e) {
			// 404 - no account exists
			if ($e->getCode() === self::HTTP_NOT_FOUND) {
				$this->logger->debug('No IONOS mail account found for user', [
					'userId' => $userId,
					'statusCode' => $e->getCode(),
				]);
				return null;
			}

			// Other errors
			$this->logger->error('Error checking IONOS mail account', [
				'userId' => $userId,
				'statusCode' => $e->getCode(),
				'error' => $e->getMessage(),
			]);
			return null;
		} catch (\Exception $e) {
			$this->logger->error('Unexpected error checking IONOS mail account', [
				'userId' => $userId,
				'error' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Get account configuration for a specific user
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return MailAccountConfig|null Account configuration or null if not found
	 */
	public function getAccountConfigForUser(string $userId): ?MailAccountConfig {
		$response = $this->getMailAccountResponse($userId);

		if ($response === null) {
			return null;
		}

		return $this->mapResponseToAccountConfig($response);
	}

	/**
	 * Get account configuration for the current logged-in user
	 *
	 * @return MailAccountConfig|null Account configuration or null if not found
	 */
	public function getAccountConfigForCurrentUser(): ?MailAccountConfig {
		$userId = $this->getCurrentUserId();
		return $this->getAccountConfigForUser($userId);
	}

	/**
	 * Get the IONOS email address for a specific user
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return string|null The email address or null if no account exists
	 */
	public function getIonosEmailForUser(string $userId): ?string {
		try {
			$response = $this->getMailAccountResponse($userId);

			if ($response === null) {
				$this->logger->debug('No IONOS email found for user', [
					'userId' => $userId,
				]);
				return null;
			}

			$email = $response->getEmail();
			$this->logger->debug('Retrieved IONOS email for user', [
				'userId' => $userId,
				'email' => $email,
			]);

			return $email;
		} catch (\Exception $e) {
			$this->logger->error('Error getting IONOS email for user', [
				'userId' => $userId,
				'error' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Get the configured mail domain
	 *
	 * @return string The mail domain
	 */
	public function getMailDomain(): string {
		return $this->configService->getMailDomain();
	}

	/**
	 * Get the current user ID from the session
	 *
	 * @return string The user ID
	 * @throws \RuntimeException If no user is logged in
	 */
	private function getCurrentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No user logged in');
		}
		return $user->getUID();
	}

	/**
	 * Create and configure API instance with authentication
	 *
	 * @return \IONOS\MailConfigurationAPI\Client\Api\MailConfigurationAPIApi
	 */
	private function createApiInstance(): \IONOS\MailConfigurationAPI\Client\Api\MailConfigurationAPIApi {
		$client = $this->apiClientService->newClient([
			'auth' => [$this->configService->getBasicAuthUser(), $this->configService->getBasicAuthPassword()],
			'verify' => !$this->configService->getAllowInsecure(),
		]);

		return $this->apiClientService->newMailConfigurationAPIApi($client, $this->configService->getApiBaseUrl());
	}

	/**
	 * Map API response to MailAccountConfig
	 *
	 * @param MailAccountResponse $response The API response
	 * @return MailAccountConfig The mapped configuration
	 */
	private function mapResponseToAccountConfig(MailAccountResponse $response): MailAccountConfig {
		$imapServer = $response->getImap();
		$smtpServer = $response->getSmtp();

		$imap = new MailServerConfig(
			host: $imapServer->getHost(),
			port: $imapServer->getPort(),
			security: 'tls', // Default, should be normalized from API response
			username: $response->getEmail(),
			password: $imapServer->getPassword()
		);

		$smtp = new MailServerConfig(
			host: $smtpServer->getHost(),
			port: $smtpServer->getPort(),
			security: 'tls', // Default, should be normalized from API response
			username: $response->getEmail(),
			password: $smtpServer->getPassword()
		);

		return new MailAccountConfig(
			email: $response->getEmail(),
			imap: $imap,
			smtp: $smtp
		);
	}
}
