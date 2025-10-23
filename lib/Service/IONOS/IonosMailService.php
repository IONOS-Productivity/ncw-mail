<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\IONOS;

use IONOS\MailConfigurationAPI\Client\Model\MailCreateData;
use OCA\Mail\Exception\ServiceException;
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
	 * @return array{success: bool, message: string, mailConfig: array} Response with mail configuration
	 * @throws ServiceException
	 * @throws AppConfigException
	 */
	public function createEmailAccount(string $emailAddress): array {
		$config = $this->configService->getApiConfig();
		$userId = $this->getCurrentUserId();
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
		$mailCreateData->setMailaddress($emailAddress);

		try {
			$this->logger->debug('Send message to mailconfig service', ['data' => $mailCreateData]);
			$result = $apiInstance->createMailbox(self::BRAND, $config['extRef'], $mailCreateData);

			return $this->buildSuccessResponse($emailAddress, $domain);
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
	 * Build success response with mail configuration
	 * TODO: Replace mock values with actual API response data
	 *
	 * @return array{success: bool, message: string, mailConfig: array}
	 */
	private function buildSuccessResponse(string $emailAddress, string $domain): array {
		return [
			'success' => true,
			'message' => 'Email account created successfully via IONOS (mock)',
			'mailConfig' => [
				'imap' => [
					'host' => 'mail.localhost', // 'imap.' . $domain,
					'password' => 'tmp',
					'port' => 1143, // 993,
					'security' => 'none',
					'username' => $emailAddress,
				],
				'smtp' => [
					'host' => 'mail.localhost', // 'smtp.' . $domain,
					'password' => 'tmp',
					'port' => 1587, // 465,
					'security' => 'none',
					'username' => $emailAddress,
				]
			]
		];
	}
}
