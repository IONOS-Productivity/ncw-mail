<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\IONOS;

use IONOS\MailConfigurationAPI\Client\Api\MailConfigurationAPIApi;
use IONOS\MailConfigurationAPI\Client\ApiException;
use IONOS\MailConfigurationAPI\Client\Model\Imap;
use IONOS\MailConfigurationAPI\Client\Model\MailAccountCreatedResponse;
use IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse;
use IONOS\MailConfigurationAPI\Client\Model\MailAddonErrorMessage;
use IONOS\MailConfigurationAPI\Client\Model\MailCreateData;
use IONOS\MailConfigurationAPI\Client\Model\Smtp;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\IONOS\Dto\MailAccountConfig;
use OCA\Mail\Service\IONOS\Dto\MailServerConfig;
use OCP\Exceptions\AppConfigException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for managing IONOS email account creation
 */
class IonosMailService {
	private const BRAND = 'IONOS';
	private const HTTP_NOT_FOUND = 404;
	public const STATUS__409_CONFLICT = 409;
	private const HTTP_INTERNAL_SERVER_ERROR = 500;

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
		return $this->mailAccountExistsForCurrentUserId($userId);
	}

	/**
	 * Check if a specific user has an IONOS email account
	 *
	 * @param string $userId The user ID to check
	 * @return bool true if account exists, false otherwise
	 */
	public function mailAccountExistsForCurrentUserId(string $userId): bool {
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
	private function getMailAccountResponse(string $userId): ?MailAccountResponse {
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
				$this->logger->debug('User does not have IONOS mail account', [
					'userId' => $userId,
					'statusCode' => $e->getCode()
				]);
				return null;
			}

			$this->logger->error('API Exception when getting IONOS mail account', [
				'statusCode' => $e->getCode(),
				'message' => $e->getMessage(),
				'responseBody' => $e->getResponseBody(),
				'userId' => $userId
			]);
			return null;
		} catch (\Exception $e) {
			$this->logger->error('Exception when getting IONOS mail account', [
				'exception' => $e,
				'userId' => $userId
			]);
			return null;
		}
	}

	/**
	 * Create an IONOS email account via API for the current logged-in user
	 *
	 * @param string $userName The local part of the email address (before @domain)
	 * @return MailAccountConfig Mail account configuration
	 * @throws ServiceException
	 * @throws AppConfigException
	 */
	public function createEmailAccount(string $userName): MailAccountConfig {
		$userId = $this->getCurrentUserId();
		return $this->createEmailAccountForUser($userId, $userName);
	}

	/**
	 * Create an IONOS email account via API for a specific user
	 *
	 * This method allows creating email accounts without relying on the user session,
	 * making it suitable for use in OCC commands or admin operations.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $userName The local part of the email address (before @domain)
	 * @return MailAccountConfig Mail account configuration
	 * @throws ServiceException
	 * @throws AppConfigException
	 */
	public function createEmailAccountForUser(string $userId, string $userName): MailAccountConfig {
		$domain = $this->configService->getMailDomain();

		$this->logger->debug('Sending request to mailconfig service', [
			'extRef' => $this->configService->getExternalReference(),
			'userName' => $userName,
			'domain' => $domain,
			'apiBaseUrl' => $this->configService->getApiBaseUrl(),
			'userId' => $userId
		]);

		$apiInstance = $this->createApiInstance();

		$mailCreateData = new MailCreateData();
		$mailCreateData->setNextcloudUserId($userId);
		$mailCreateData->setLocalPart($userName);

		if (!$mailCreateData->valid()) {
			$this->logger->error('Validate message to mailconfig service', [
				'data' => $mailCreateData->listInvalidProperties(),
				'userId' => $userId,
				'userName' => $userName
			]);
			throw new ServiceException('Invalid mail configuration', self::HTTP_INTERNAL_SERVER_ERROR);
		}

		try {
			$this->logger->debug('Send message to mailconfig service', ['data' => $mailCreateData]);
			$result = $apiInstance->createMailbox(self::BRAND, $this->configService->getExternalReference(), $mailCreateData);

			if ($result instanceof MailAddonErrorMessage) {
				$this->logger->error('Failed to create ionos mail', [
					'status code' => $result->getStatus(),
					'message' => $result->getMessage(),
					'userId' => $userId,
					'userName' => $userName
				]);
				throw new ServiceException('Failed to create ionos mail', $result->getStatus());
			}
			if ($result instanceof MailAccountCreatedResponse) {
				$this->logger->info('Successfully created IONOS mail account', [
					'email' => $result->getEmail(),
					'userId' => $userId,
					'userName' => $userName
				]);
				return $this->buildSuccessResponse($result);
			}

			$this->logger->error('Failed to create ionos mail: Unknown response type', [
				'data' => $result,
				'userId' => $userId,
				'userName' => $userName
			]);
			throw new ServiceException('Failed to create ionos mail', self::HTTP_INTERNAL_SERVER_ERROR);
		} catch (ServiceException $e) {
			// Re-throw ServiceException without additional logging
			throw $e;
		} catch (ApiException $e) {
			$this->logger->error('API Exception when calling MailConfigurationAPIApi->createMailbox', [
				'statusCode' => $e->getCode(),
				'message' => $e->getMessage(),
				'responseBody' => $e->getResponseBody()
			]);
			throw new ServiceException('Failed to create ionos mail: ' . $e->getMessage(), $e->getCode(), $e);
		} catch (\Exception $e) {
			$this->logger->error('Exception when calling MailConfigurationAPIApi->createMailbox', [
				'exception' => $e,
				'userId' => $userId,
				'userName' => $userName
			]);
			throw new ServiceException('Failed to create ionos mail', self::HTTP_INTERNAL_SERVER_ERROR, $e);
		}
	}

	/**
	 * Get IONOS account configuration for a specific user
	 *
	 * This method retrieves the configuration of an existing IONOS mail account.
	 * Useful when an account was previously created but Nextcloud account creation failed.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return MailAccountConfig|null Mail account configuration if exists, null otherwise
	 * @throws ServiceException
	 */
	public function getAccountConfigForUser(string $userId): ?MailAccountConfig {
		$response = $this->getMailAccountResponse($userId);

		if ($response === null) {
			$this->logger->debug('No existing IONOS account found for user', [
				'userId' => $userId
			]);
			return null;
		}

		$this->logger->info('Retrieved existing IONOS account configuration', [
			'email' => $response->getEmail(),
			'userId' => $userId
		]);

		return $this->buildConfigFromAccountResponse($response);
	}

	/**
	 * Get IONOS account configuration for the current logged-in user
	 *
	 * @return MailAccountConfig|null Mail account configuration if exists, null otherwise
	 * @throws ServiceException
	 */
	public function getAccountConfigForCurrentUser(): ?MailAccountConfig {
		$userId = $this->getCurrentUserId();
		return $this->getAccountConfigForUser($userId);
	}

	/**
	 * Get the current user ID
	 *
	 * @throws ServiceException
	 */
	private function getCurrentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			$this->logger->error('No user session found when attempting to create IONOS mail account');
			throw new ServiceException('No user session found');
		}
		return $user->getUID();
	}

	/**
	 * Create and configure API instance with authentication
	 *
	 * @return MailConfigurationAPIApi
	 */
	private function createApiInstance(): MailConfigurationAPIApi {
		$client = $this->apiClientService->newClient([
			'auth' => [$this->configService->getBasicAuthUser(), $this->configService->getBasicAuthPassword()],
			'verify' => !$this->configService->getAllowInsecure(),
		]);

		return $this->apiClientService->newMailConfigurationAPIApi($client, $this->configService->getApiBaseUrl());
	}

	/**
	 * Normalize SSL mode from API response to expected format
	 *
	 * Maps API SSL mode values (e.g., "TLS", "SSL") to standard values ("tls", "ssl", "none")
	 *
	 * @param string $apiSslMode SSL mode from API response
	 * @return string Normalized SSL mode: "tls", "ssl", or "none"
	 */
	private function normalizeSslMode(string $apiSslMode): string {
		$normalized = strtolower($apiSslMode);

		if (str_contains($normalized, 'tls') || str_contains($normalized, 'starttls')) {
			$result = 'tls';
		} elseif (str_contains($normalized, 'ssl')) {
			$result = 'ssl';
		} else {
			$result = 'none';
		}

		$this->logger->debug('Normalized SSL mode', [
			'input' => $apiSslMode,
			'output' => $result
		]);

		return $result;
	}

	/**
	 * Build success response with mail configuration from MailAccountCreatedResponse (newly created account)
	 *
	 * @param MailAccountCreatedResponse $response The account response from createFunctionalAccount
	 * @return MailAccountConfig The mail account configuration with password
	 */
	private function buildSuccessResponse(MailAccountCreatedResponse $response): MailAccountConfig {
		return $this->buildMailAccountConfig(
			$response->getServer()->getImap(),
			$response->getServer()->getSmtp(),
			$response->getEmail(),
			$response->getPassword()
		);
	}

	/**
	 * Build mail account configuration from server details
	 *
	 * @param Imap $imapServer IMAP server configuration object
	 * @param Smtp $smtpServer SMTP server configuration object
	 * @param string $email Email address
	 * @param string $password Account password
	 * @return MailAccountConfig Complete mail account configuration
	 */
	private function buildMailAccountConfig(Imap $imapServer, Smtp $smtpServer, string $email, string $password): MailAccountConfig {
		$imapConfig = new MailServerConfig(
			host: $imapServer->getHost(),
			port: $imapServer->getPort(),
			security: $this->normalizeSslMode($imapServer->getSslMode()),
			username: $email,
			password: $password,
		);

		$smtpConfig = new MailServerConfig(
			host: $smtpServer->getHost(),
			port: $smtpServer->getPort(),
			security: $this->normalizeSslMode($smtpServer->getSslMode()),
			username: $email,
			password: $password,
		);

		return new MailAccountConfig(
			email: $email,
			imap: $imapConfig,
			smtp: $smtpConfig,
		);
	}

	/**
	 * Build configuration from MailAccountResponse (existing account)
	 * Note: MailAccountResponse does not include password for security reasons
	 *
	 * @param MailAccountResponse $response The account response from getFunctionalAccount
	 * @return MailAccountConfig The mail account configuration with empty password
	 */
	private function buildConfigFromAccountResponse(MailAccountResponse $response): MailAccountConfig {
		// Password is not available when retrieving existing accounts
		// It should be retrieved from Nextcloud's credential store separately
		return $this->buildMailAccountConfig(
			$response->getServer()->getImap(),
			$response->getServer()->getSmtp(),
			$response->getEmail(),
			''
		);
	}

	/**
	 * Delete an IONOS email account via API
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return bool true if deletion was successful, false otherwise
	 * @throws ServiceException
	 */
	public function deleteEmailAccount(string $userId): bool {
		$this->logger->info('Attempting to delete IONOS email account', [
			'userId' => $userId,
			'extRef' => $this->configService->getExternalReference(),
		]);

		try {
			$apiInstance = $this->createApiInstance();

			$apiInstance->deleteMailbox(self::BRAND, $this->configService->getExternalReference(), $userId);

			$this->logger->info('Successfully deleted IONOS email account', [
				'userId' => $userId
			]);

			return true;
		} catch (ApiException $e) {
			// 404 means the mailbox doesn't exist - treat as success
			if ($e->getCode() === self::HTTP_NOT_FOUND) {
				$this->logger->debug('IONOS mailbox does not exist (already deleted or never created)', [
					'userId' => $userId,
					'statusCode' => $e->getCode()
				]);
				return true;
			}

			$this->logger->error('API Exception when calling MailConfigurationAPIApi->deleteMailbox', [
				'statusCode' => $e->getCode(),
				'message' => $e->getMessage(),
				'responseBody' => $e->getResponseBody(),
				'userId' => $userId
			]);

			throw new ServiceException('Failed to delete IONOS mail: ' . $e->getMessage(), $e->getCode(), $e);
		} catch (\Exception $e) {
			$this->logger->error('Exception when calling MailConfigurationAPIApi->deleteMailbox', [
				'exception' => $e,
				'userId' => $userId
			]);

			throw new ServiceException('Failed to delete IONOS mail', self::HTTP_INTERNAL_SERVER_ERROR, $e);
		}
	}

	/**
	 * Get the email address of the IONOS account for a specific user
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return string|null The email address if account exists, null otherwise
	 */
	public function getIonosEmailForUser(string $userId): ?string {
		$response = $this->getMailAccountResponse($userId);

		if ($response !== null) {
			$email = $response->getEmail();
			$this->logger->debug('Found IONOS mail account for user', [
				'email' => $email,
				'userId' => $userId
			]);
			return $email;
		}

		return null;
	}

	/**
	 * Delete an IONOS email account without throwing exceptions (fire and forget)
	 *
	 * This method checks if IONOS integration is enabled and attempts to delete
	 * the email account. All errors are logged but not thrown, making it safe
	 * to call in event listeners or other contexts where exceptions should not
	 * interrupt the flow.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return void
	 */
	public function tryDeleteEmailAccount(string $userId): void {
		// Check if IONOS integration is enabled
		if (!$this->configService->isIonosIntegrationEnabled()) {
			$this->logger->debug('IONOS integration is not enabled, skipping email account deletion', [
				'userId' => $userId
			]);
			return;
		}

		try {
			$this->deleteEmailAccount($userId);
			// Success is already logged by deleteEmailAccount
		} catch (ServiceException $e) {
			$this->logger->error('Failed to delete IONOS mailbox for user', [
				'userId' => $userId,
				'exception' => $e,
			]);
			// Don't throw - this is a fire and forget operation
		}
	}

	/**
	 * Reset app password for the IONOS mail account (generates a new password)
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $appName The application name for the password
	 * @return string The new password
	 * @throws ServiceException
	 */
	public function resetAppPassword(string $userId, string $appName): string {
		$this->logger->debug('Resetting IONOS app password', [
			'userId' => $userId,
			'appName' => $appName,
			'extRef' => $this->configService->getExternalReference(),
		]);

		try {
			$apiInstance = $this->createApiInstance();
			$result = $apiInstance->setAppPassword(
				self::BRAND,
				$this->configService->getExternalReference(),
				$userId,
				$appName
			);

			if (is_string($result)) {
				$this->logger->info('Successfully reset IONOS app password', [
					'userId' => $userId,
					'appName' => $appName
				]);
				return $result;
			}

			$this->logger->error('Failed to reset IONOS app password: Unexpected response type', [
				'userId' => $userId,
				'appName' => $appName,
				'result' => $result
			]);
			throw new ServiceException('Failed to reset IONOS app password', self::HTTP_INTERNAL_SERVER_ERROR);
		} catch (ServiceException $e) {
			// Re-throw ServiceException without additional logging
			throw $e;
		} catch (ApiException $e) {
			$this->logger->error('API Exception when calling MailConfigurationAPIApi->setAppPassword', [
				'statusCode' => $e->getCode(),
				'message' => $e->getMessage(),
				'responseBody' => $e->getResponseBody(),
				'userId' => $userId,
				'appName' => $appName
			]);
			throw new ServiceException('Failed to reset IONOS app password: ' . $e->getMessage(), $e->getCode(), $e);
		} catch (\Exception $e) {
			$this->logger->error('Exception when calling MailConfigurationAPIApi->setAppPassword', [
				'exception' => $e,
				'userId' => $userId,
				'appName' => $appName
			]);
			throw new ServiceException('Failed to reset IONOS app password', self::HTTP_INTERNAL_SERVER_ERROR, $e);
		}
	}

	/**
	 * Get the configured mail domain for IONOS accounts
	 *
	 * @return string The mail domain (e.g., "example.com")
	 */
	public function getMailDomain(): string {
		return $this->configService->getMailDomain();
	}
}
