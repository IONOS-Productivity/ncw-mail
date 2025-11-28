<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\IONOS;

use IONOS\MailConfigurationAPI\Client\Api\MailConfigurationAPIApi;
use IONOS\MailConfigurationAPI\Client\ApiException;
use IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse;
use IONOS\MailConfigurationAPI\Client\Model\MailAddonErrorMessage;
use IONOS\MailConfigurationAPI\Client\Model\MailCreateData;
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
		try {
			$this->logger->debug('Checking if user has email account', [
				'userId' => $userId,
				'extRef' => $this->configService->getExternalReference(),
			]);

			$apiInstance = $this->createApiInstance();

			$result = $apiInstance->getFunctionalAccount(self::BRAND, $this->configService->getExternalReference(), $userId);

			if ($result instanceof MailAccountResponse) {
				$this->logger->debug('User has existing IONOS mail account', [
					'email' => $result->getEmail(),
					'userId' => $userId
				]);
				return true;
			}

			return false;
		} catch (ApiException $e) {
			// 404 - no account exists
			if ($e->getCode() === self::HTTP_NOT_FOUND) {
				$this->logger->debug('User does not have IONOS mail account', [
					'userId' => $userId,
					'statusCode' => $e->getCode()
				]);
				return false;
			}

			$this->logger->error('API Exception when checking for existing mail account', [
				'statusCode' => $e->getCode(),
				'message' => $e->getMessage(),
				'responseBody' => $e->getResponseBody()
			]);
			return false;
		} catch (\Exception $e) {
			$this->logger->error('Exception when checking for existing mail account', [
				'exception' => $e,
				'userId' => $userId
			]);
			return false;
		}
	}

	/**
	 * Create an IONOS email account via API
	 *
	 * @return MailAccountConfig Mail account configuration
	 * @throws ServiceException
	 * @throws AppConfigException
	 */
	public function createEmailAccount(string $userName): MailAccountConfig {
		$userId = $this->getCurrentUserId();
		$domain = $this->configService->getMailDomain();

		$this->logger->debug('Sending request to mailconfig service', [
			'extRef' => $this->configService->getExternalReference(),
			'userName' => $userName,
			'domain' => $domain,
			'apiBaseUrl' => $this->configService->getApiBaseUrl()
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
			if ($result instanceof MailAccountResponse) {
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

		return $this->apiClientService->newEventAPIApi($client, $this->configService->getApiBaseUrl());
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
	 * Build success response with mail configuration
	 *
	 * @param MailAccountResponse $response
	 * @return MailAccountConfig
	 */
	private function buildSuccessResponse(MailAccountResponse $response): MailAccountConfig {
		$smtpServer = $response->getServer()->getSmtp();
		$imapServer = $response->getServer()->getImap();

		$imapConfig = new MailServerConfig(
			host: $imapServer->getHost(),
			port: $imapServer->getPort(),
			security: $this->normalizeSslMode($imapServer->getSslMode()),
			username: $response->getEmail(),
			password: $response->getPassword(),
		);

		$smtpConfig = new MailServerConfig(
			host: $smtpServer->getHost(),
			port: $smtpServer->getPort(),
			security: $this->normalizeSslMode($smtpServer->getSslMode()),
			username: $response->getEmail(),
			password: $response->getPassword(),
		);

		return new MailAccountConfig(
			email: $response->getEmail(),
			imap: $imapConfig,
			smtp: $smtpConfig,
		);
	}
}
