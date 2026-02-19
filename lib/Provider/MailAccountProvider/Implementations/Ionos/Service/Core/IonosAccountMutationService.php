<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\Core;

use IONOS\MailConfigurationAPI\Client\ApiException;
use IONOS\MailConfigurationAPI\Client\Model\ImapConfig;
use IONOS\MailConfigurationAPI\Client\Model\MailAccountCreatedResponse;
use IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse;
use IONOS\MailConfigurationAPI\Client\Model\MailAddonErrorMessage;
use IONOS\MailConfigurationAPI\Client\Model\MailCreateData;
use IONOS\MailConfigurationAPI\Client\Model\PatchMailRequest;
use IONOS\MailConfigurationAPI\Client\Model\SmtpConfig;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Provider\MailAccountProvider\Common\Dto\MailAccountConfig;
use OCA\Mail\Provider\MailAccountProvider\Common\Dto\MailServerConfig;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\ApiMailConfigClientService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosConfigService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for mutating IONOS mail accounts (create, update, delete operations)
 */
class IonosAccountMutationService {
	private const BRAND = 'IONOS';
	private const HTTP_NOT_FOUND = 404;
	private const HTTP_INTERNAL_SERVER_ERROR = 500;

	public function __construct(
		private ApiMailConfigClientService $apiClientService,
		private IonosConfigService $configService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private IonosAccountQueryService $queryService,
	) {
	}

	/**
	 * Create an IONOS email account for the current user
	 *
	 * @param string $userName The local part of the email address (before @domain)
	 * @return MailAccountConfig Mail account configuration
	 * @throws ServiceException
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
	 * Delete an IONOS email account via API
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $email The email address to verify before deletion
	 * @return bool true if deletion was successful
	 * @throws ServiceException
	 */
	public function deleteEmailAccount(string $userId, string $email): bool {
		$this->logger->info('Attempting to delete IONOS email account', [
			'userId' => $userId,
			'email' => $email,
			'extRef' => $this->configService->getExternalReference(),
		]);

		try {
			$apiInstance = $this->createApiInstance();

			// First, verify the email matches the account we're about to delete
			try {
				$accountResponse = $apiInstance->getFunctionalAccount(
					self::BRAND,
					$this->configService->getExternalReference(),
					$userId
				);

				if ($accountResponse instanceof MailAccountResponse) {
					$currentEmail = $accountResponse->getEmail();

					// Case-insensitive comparison
					if (strcasecmp($currentEmail, $email) !== 0) {
						$this->logger->warning('Email mismatch during deletion - refusing to delete', [
							'userId' => $userId,
							'requestedEmail' => $email,
							'currentEmail' => $currentEmail,
						]);
						throw new ServiceException(
							'Email mismatch: Cannot delete account. Requested: ' . $email . ', Found: ' . $currentEmail,
							400
						);
					}

					$this->logger->debug('Email verified before deletion', [
						'userId' => $userId,
						'email' => $email,
					]);
				}
			} catch (ApiException $e) {
				// If account doesn't exist (404), we can proceed to delete (it's already gone)
				if ($e->getCode() === self::HTTP_NOT_FOUND) {
					$this->logger->debug('IONOS mailbox does not exist (already deleted or never created)', [
						'userId' => $userId,
						'email' => $email,
						'statusCode' => $e->getCode()
					]);
					return true;
				}
				// For other errors during verification, log but proceed with deletion attempt
				$this->logger->warning('Could not verify email before deletion, proceeding anyway', [
					'userId' => $userId,
					'email' => $email,
					'exception' => $e->getMessage(),
				]);
			}

			// Proceed with deletion
			$apiInstance->deleteMailbox(self::BRAND, $this->configService->getExternalReference(), $userId);

			$this->logger->info('Successfully deleted IONOS email account', [
				'userId' => $userId,
				'email' => $email
			]);

			return true;
		} catch (ServiceException $e) {
			// Re-throw ServiceException without additional logging
			throw $e;
		} catch (ApiException $e) {
			// 404 means the mailbox doesn't exist - treat as success
			if ($e->getCode() === self::HTTP_NOT_FOUND) {
				$this->logger->debug('IONOS mailbox does not exist (already deleted or never created)', [
					'userId' => $userId,
					'email' => $email,
					'statusCode' => $e->getCode()
				]);
				return true;
			}

			$this->logger->error('API Exception when calling MailConfigurationAPIApi->deleteMailbox', [
				'statusCode' => $e->getCode(),
				'message' => $e->getMessage(),
				'responseBody' => $e->getResponseBody(),
				'userId' => $userId,
				'email' => $email
			]);

			throw new ServiceException('Failed to delete IONOS mail: ' . $e->getMessage(), $e->getCode(), $e);
		} catch (\Exception $e) {
			$this->logger->error('Exception when calling MailConfigurationAPIApi->deleteMailbox', [
				'exception' => $e,
				'userId' => $userId,
				'email' => $email
			]);

			throw new ServiceException('Failed to delete IONOS mail', self::HTTP_INTERNAL_SERVER_ERROR, $e);
		}
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
	 * @param string $email The email address to verify before deletion
	 * @return void
	 */
	public function tryDeleteEmailAccount(string $userId, string $email): void {
		// Check if IONOS integration is enabled
		if (!$this->configService->isIonosIntegrationEnabled()) {
			$this->logger->debug('IONOS integration is not enabled, skipping email account deletion', [
				'userId' => $userId,
				'email' => $email
			]);
			return;
		}

		try {
			$this->deleteEmailAccount($userId, $email);
			// Success is already logged by deleteEmailAccount
		} catch (ServiceException $e) {
			$this->logger->error('Failed to delete IONOS mailbox for user', [
				'userId' => $userId,
				'email' => $email,
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
	 * Update the localpart of an IONOS email account
	 *
	 * This method updates the email address by changing only the localpart (username before @).
	 * It verifies that the new email address is not already taken by another user,
	 * then updates the remote IONOS mailbox.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $newLocalpart The new local part of the email address (before @domain)
	 * @return string The new email address
	 * @throws ServiceException If update fails or new email is already taken
	 */
	public function updateMailboxLocalpart(string $userId, string $newLocalpart): string {
		$domain = $this->configService->getMailDomain();
		$newEmail = $newLocalpart . '@' . $domain;

		$this->logger->info('Updating IONOS mailbox localpart', [
			'userId' => $userId,
			'newLocalpart' => $newLocalpart,
			'newEmail' => $newEmail,
		]);

		// Check if the new email is already taken by another user
		if ($this->isEmailTakenByAnotherUser($userId, $newEmail)) {
			throw new ServiceException(
				'The email address ' . $newEmail . ' is already taken by another user',
				409 // Conflict
			);
		}

		try {
			$apiInstance = $this->createApiInstance();

			// Create patch request to update the email address
			$patchRequest = new PatchMailRequest();
			$patchRequest->setOp(PatchMailRequest::OP_REPLACE);
			$patchRequest->setPath(PatchMailRequest::PATH_MAILADDRESS);
			$patchRequest->setValue($newLocalpart);

			if (!$patchRequest->valid()) {
				$this->logger->error('Invalid patch request for mailbox update', [
					'userId' => $userId,
					'invalidProperties' => $patchRequest->listInvalidProperties(),
				]);
				throw new ServiceException('Invalid patch request', self::HTTP_INTERNAL_SERVER_ERROR);
			}

			// Update the mailbox via API and check response status
			[, $statusCode] = $apiInstance->patchMailboxWithHttpInfo(
				self::BRAND,
				$this->configService->getExternalReference(),
				$userId,
				$patchRequest
			);

			// Verify the update was successful
			if ($statusCode !== 200) {
				$this->logger->error('Unexpected status code from patchMailbox API', [
					'statusCode' => $statusCode,
					'userId' => $userId,
					'newEmail' => $newEmail,
				]);
				throw new ServiceException('Failed to update IONOS mailbox: unexpected status code ' . $statusCode, $statusCode);
			}

			$this->logger->info('Successfully updated IONOS mailbox email address', [
				'userId' => $userId,
				'newEmail' => $newEmail,
				'statusCode' => $statusCode,
			]);

			return $newEmail;
		} catch (ServiceException $e) {
			throw $e;
		} catch (ApiException $e) {
			$this->logger->error('API Exception when updating mailbox localpart', [
				'statusCode' => $e->getCode(),
				'message' => $e->getMessage(),
				'responseBody' => $e->getResponseBody(),
				'userId' => $userId,
				'newEmail' => $newEmail,
			]);

			// Special handling for 404 errors to provide a more helpful message
			if ($e->getCode() === 404) {
				$errorMessage = 'The IONOS API could not find the mailbox to update. This may be a backend API issue. '
					. 'Please verify the mailbox exists in the IONOS system or contact IONOS support.';
				throw new ServiceException($errorMessage, $e->getCode(), $e);
			}

			throw new ServiceException('Failed to update IONOS mailbox: ' . $e->getMessage(), $e->getCode(), $e);
		} catch (\Exception $e) {
			$this->logger->error('Exception when updating mailbox localpart', [
				'exception' => $e,
				'userId' => $userId,
				'newEmail' => $newEmail,
			]);
			throw new ServiceException('Failed to update IONOS mailbox', self::HTTP_INTERNAL_SERVER_ERROR, $e);
		}
	}

	/**
	 * Check if an email address is already taken by another user
	 *
	 * @param string $currentUserId The current user ID (to exclude from check)
	 * @param string $email The email address to check
	 * @return bool True if the email is taken by another user
	 * @throws ServiceException If the check fails
	 */
	private function isEmailTakenByAnotherUser(string $currentUserId, string $email): bool {
		try {
			// Reuse existing query service to get all accounts
			$allAccounts = $this->queryService->getAllMailAccountResponses();

			foreach ($allAccounts as $account) {
				if ($account instanceof MailAccountResponse) {
					$accountEmail = $account->getEmail();
					$accountUserId = $account->getNextcloudUserId();

					// Check if email matches and belongs to a different user
					if (strcasecmp($accountEmail, $email) === 0 && $accountUserId !== $currentUserId) {
						$this->logger->warning('Email already taken by another user', [
							'email' => $email,
							'takenByUserId' => $accountUserId,
							'requestedByUserId' => $currentUserId,
						]);
						return true;
					}
				}
			}

			return false;
		} catch (ServiceException $e) {
			// Re-throw to fail closed (safer than silently allowing operation)
			throw $e;
		} catch (\Exception $e) {
			$this->logger->error('Error checking if email is taken', [
				'email' => $email,
				'exception' => $e,
			]);
			// Fail closed for security: prevent operation if we can't verify uniqueness
			throw new ServiceException(
				'Unable to verify email uniqueness: ' . $e->getMessage(),
				self::HTTP_INTERNAL_SERVER_ERROR,
				$e
			);
		}
	}

	/**
	 * Get the current user ID from the session
	 *
	 * @return string The user ID
	 * @throws ServiceException If no user is logged in
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
	 * Build mail account configuration from IMAP/SMTP server details
	 *
	 * Creates a complete MailAccountConfig object by combining IMAP and SMTP server
	 * configurations with email credentials. SSL modes are normalized to standard format.
	 *
	 * @param ImapConfig $imapServer IMAP server configuration object
	 * @param SmtpConfig $smtpServer SMTP server configuration object
	 * @param string $email Email address
	 * @param string $password Account password
	 * @return MailAccountConfig Complete mail account configuration
	 */
	private function buildMailAccountConfig(ImapConfig $imapServer, SmtpConfig $smtpServer, string $email, string $password): MailAccountConfig {
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
}
