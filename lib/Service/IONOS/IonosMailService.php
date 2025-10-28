<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\IONOS;

use IONOS\MailConfigurationAPI\Client\ApiException;
use IONOS\MailConfigurationAPI\Client\Model\ErrorMessage;
use IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse;
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

	public function __construct(
		private ApiMailConfigClientService $apiClientService,
		private IonosConfigService $configService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Create an IONOS email account via API
	 *
	 * @return MailAccountConfig Mail account configuration
	 * @throws ServiceException
	 * @throws AppConfigException
	 */
	public function createEmailAccount(string $emailAddress): MailAccountConfig {
		$config = $this->configService->getApiConfig();
		$userId = $this->getCurrentUserId();
		$userName = $this->extractUsername($emailAddress);
		$domain = $this->extractDomain($emailAddress);

		$this->logger->debug('Sending request to mailconfig service', [
			'extRef' => $config['extRef'],
			'emailAddress' => $emailAddress,
			'apiBaseUrl' => $config['apiBaseUrl']
		]);

		$client = $this->apiClientService->newClient([
			'auth' => [$config['basicAuthUser'], $config['basicAuthPass']],
			'verify' => !$config['allowInsecure'],
		]);

		$apiInstance = $this->apiClientService->newEventAPIApi($client, $config['apiBaseUrl']);

		$mailCreateData = new MailCreateData();
		$mailCreateData->setNextcloudUserId($userId);
		$mailCreateData->setDomainPart($domain);
		$mailCreateData->setLocalPart($userName);

		if (!$mailCreateData->valid()) {
			$this->logger->error('Validate message to mailconfig service', ['data' => $mailCreateData->listInvalidProperties()]);
			throw new ServiceException('Invalid mail configuration', 0);
		}

		try {
			$this->logger->debug('Send message to mailconfig service', ['data' => $mailCreateData]);
			$result = $apiInstance->createMailbox(self::BRAND, $config['extRef'], $mailCreateData);

			if ($result instanceof ErrorMessage) {
				$this->logger->error('Failed to create ionos mail', ['status code' => $result->getStatus(), 'message' => $result->getMessage()]);
				throw new ServiceException('Failed to create ionos mail', $result->getStatus());
			}
			if ($result instanceof MailAccountResponse) {
				return $this->buildSuccessResponse($emailAddress, $result);
			}

			$this->logger->debug('Failed to create ionos mail: Unknown response type', ['data' => $result ]);
			throw new ServiceException('Failed to create ionos mail', 0);
		} catch (ApiException $e) {
			$statusCode = $e->getCode();
			$this->logger->error('API Exception when calling MailConfigurationAPIApi->createMailbox', [
				'statusCode' => $statusCode,
				'message' => $e->getMessage(),
				'responseBody' => $e->getResponseBody()
			]);
			throw new ServiceException('Failed to create ionos mail: ' . $e->getMessage(), $statusCode, $e);
		} catch (\Exception $e) {
			$this->logger->error('Exception when calling MailConfigurationAPIApi->createMailbox', ['exception' => $e]);
			throw new ServiceException('Failed to create ionos mail', 0, $e);
		}
	}

	/**
	 * Extract domain from email address
	 *
	 * @throws ServiceException
	 */
	public function extractDomain(string $emailAddress): string {
		$atPosition = strrchr($emailAddress, '@');
		if ($atPosition === false) {
			throw new ServiceException('Invalid email address: unable to extract domain');
		}
		$domain = substr($atPosition, 1);
		if ($domain === '') {
			throw new ServiceException('Invalid email address: unable to extract domain');
		}
		return $domain;
	}

	/**
	 * Extract username from email address
	 *
	 * @throws ServiceException
	 */
	public function extractUsername(string $emailAddress): string {
		$atPosition = strrpos($emailAddress, '@');
		if ($atPosition === false) {
			throw new ServiceException('Invalid email address: unable to extract username');
		}
		$userName = substr($emailAddress, 0, $atPosition);
		if ($userName === '') {
			throw new ServiceException('Invalid email address: unable to extract username');
		}
		return $userName;
	}

	/**
	 * Get the current user ID
	 *
	 * @throws ServiceException
	 */
	private function getCurrentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new ServiceException('No user session found');
		}
		return $user->getUID();
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
			return 'tls';
		}

		if (str_contains($normalized, 'ssl')) {
			return 'ssl';
		}

		return 'none';
	}

	/**
	 * Build success response with mail configuration
	 *
	 * @param string $emailAddress
	 * @param MailAccountResponse $response
	 * @return MailAccountConfig
	 */
	private function buildSuccessResponse(string $emailAddress, MailAccountResponse $response): MailAccountConfig {
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
			email: $emailAddress,
			imap: $imapConfig,
			smtp: $smtpConfig,
		);
	}
}
