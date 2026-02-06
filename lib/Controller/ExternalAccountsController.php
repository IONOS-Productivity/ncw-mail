<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Controller;

use OCA\Mail\Exception\ProviderServiceException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Http\JsonResponse as MailJsonResponse;
use OCA\Mail\Http\TrapError;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCA\Mail\Service\AccountProviderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class ExternalAccountsController extends Controller {
	// Error message constants
	private const ERR_PROVIDER_NOT_FOUND = 'PROVIDER_NOT_FOUND';
	private const ERR_PROVIDER_NOT_AVAILABLE = 'PROVIDER_NOT_AVAILABLE';
	private const ERR_INVALID_PARAMETERS = 'INVALID_PARAMETERS';
	private const ERR_SERVICE_ERROR = 'SERVICE_ERROR';

	public function __construct(
		string $appName,
		IRequest $request,
		private ProviderRegistryService $providerRegistry,
		private AccountProviderService $accountProviderService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Create a new external mail account via provider
	 *
	 * @NoAdminRequired
	 *
	 * @param string $providerId Provider identifier
	 * @return JSONResponse
	 */
	#[TrapError]
	public function create(string $providerId): JSONResponse {
		try {
			$userId = $this->getUserIdOrFail();

			// Get parameters from request body
			$parameters = $this->request->getParams();

			// Remove Nextcloud-specific parameters
			unset($parameters['providerId']);
			unset($parameters['_route']);

			$this->logger->info('Starting external mail account creation', [
				'userId' => $userId,
				'providerId' => $providerId,
				'parameters' => array_keys($parameters),
			]);

			// Get the provider
			$provider = $this->providerRegistry->getProvider($providerId);
			if ($provider === null) {
				return MailJsonResponse::fail([
					'error' => self::ERR_PROVIDER_NOT_FOUND,
					'message' => 'Provider not found: ' . $providerId,
				], Http::STATUS_NOT_FOUND);
			}

			// Check if provider is enabled and available for this user
			if (!$provider->isEnabled()) {
				return MailJsonResponse::fail([
					'error' => self::ERR_PROVIDER_NOT_AVAILABLE,
					'message' => 'Provider is not enabled: ' . $providerId,
				], Http::STATUS_BAD_REQUEST);
			}

			if (!$provider->isAvailableForUser($userId)) {
				// Try to get existing email for a better error message
				$existingEmail = $provider->getExistingAccountEmail($userId);

				$errorData = [
					'error' => self::ERR_PROVIDER_NOT_AVAILABLE,
					'message' => 'Provider is not available for this user',
				];

				if ($existingEmail !== null) {
					$errorData['existingEmail'] = $existingEmail;
				}

				return MailJsonResponse::fail($errorData, Http::STATUS_BAD_REQUEST);
			}

			// Create the account
			$account = $provider->createAccount($userId, $parameters);

			$this->logger->info('External account creation completed successfully', [
				'emailAddress' => $account->getEmail(),
				'accountId' => $account->getId(),
				'userId' => $userId,
				'providerId' => $providerId,
			]);

			$json = $account->jsonSerialize();
			$json = $this->accountProviderService->addProviderMetadata($json, $userId, $account->getEmail());

			return MailJsonResponse::success($json, Http::STATUS_CREATED);
		} catch (ServiceException $e) {
			return $this->buildServiceErrorResponse($e, $providerId);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error('Invalid parameters for account creation', [
				'providerId' => $providerId,
				'exception' => $e,
			]);
			return MailJsonResponse::fail([
				'error' => self::ERR_INVALID_PARAMETERS,
				'message' => $e->getMessage(),
			], Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			$this->logger->error('Unexpected error during external account creation', [
				'providerId' => $providerId,
				'exception' => $e,
			]);
			return MailJsonResponse::error('Could not create account');
		}
	}

	/**
	 * Get information about available providers
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	#[TrapError]
	public function getProviders(): JSONResponse {
		try {
			$userId = $this->getUserIdOrFail();
			$availableProviders = $this->providerRegistry->getAvailableProvidersForUser($userId);

			$providersInfo = [];
			foreach ($availableProviders as $provider) {
				$capabilities = $provider->getCapabilities();
				$providersInfo[] = [
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

			return MailJsonResponse::success([
				'providers' => $providersInfo,
			]);
		} catch (\Exception $e) {
			$this->logger->error('Error getting available providers', [
				'exception' => $e,
			]);
			return MailJsonResponse::error('Could not get providers');
		}
	}

	/**
	 * Generate an app password for a provider-managed account
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $providerId The provider ID
	 * @return JSONResponse
	 */
	#[TrapError]
	public function generatePassword(string $providerId): JSONResponse {
		// Get accountId from request body
		$accountId = $this->request->getParam('accountId');

		if ($accountId === null) {
			return MailJsonResponse::fail(['error' => 'Account ID is required']);
		}

		try {
			$userId = $this->getUserIdOrFail();

			$this->logger->info('Generating app password', [
				'accountId' => $accountId,
				'providerId' => $providerId,
			]);

			$provider = $this->providerRegistry->getProvider($providerId);
			if ($provider === null) {
				return MailJsonResponse::fail([
					'error' => self::ERR_PROVIDER_NOT_FOUND,
					'message' => 'Provider not found',
				], Http::STATUS_NOT_FOUND);
			}

			// Check if provider supports app passwords
			if (!$provider->getCapabilities()->supportsAppPasswords()) {
				return MailJsonResponse::fail([
					'error' => 'NOT_SUPPORTED',
					'message' => 'Provider does not support app passwords',
				], Http::STATUS_BAD_REQUEST);
			}

			// Use the provider interface method for generating app passwords
			$password = $provider->generateAppPassword($userId);

			$this->logger->info('App password generated successfully', [
				'accountId' => $accountId,
				'providerId' => $providerId,
			]);

			return MailJsonResponse::success(['password' => $password]);
		} catch (ServiceException $e) {
			return $this->buildServiceErrorResponse($e, $providerId);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error('Invalid arguments for app password generation', [
				'exception' => $e,
				'accountId' => $accountId,
				'providerId' => $providerId,
			]);
			return MailJsonResponse::fail([
				'error' => self::ERR_INVALID_PARAMETERS,
				'message' => $e->getMessage(),
			], Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			$this->logger->error('Unexpected error generating app password', [
				'exception' => $e,
				'accountId' => $accountId,
				'providerId' => $providerId,
			]);
			return MailJsonResponse::error('Could not generate app password');
		}
	}

	/**
	 * List all mailboxes for a specific provider
	 *
	 * @NoAdminRequired
	 *
	 * @param string $providerId The provider ID
	 * @return JSONResponse
	 */
	#[TrapError]
	public function indexMailboxes(string $providerId): JSONResponse {
		try {
			$userId = $this->getUserIdOrFail();

			$this->logger->debug('Listing mailboxes for provider', [
				'providerId' => $providerId,
				'userId' => $userId,
			]);

			$provider = $this->providerRegistry->getProvider($providerId);
			if ($provider === null) {
				return MailJsonResponse::fail([
					'error' => self::ERR_PROVIDER_NOT_FOUND,
					'message' => 'Provider not found: ' . $providerId,
				], Http::STATUS_NOT_FOUND);
			}

			$mailboxes = $provider->getMailboxes();

			return MailJsonResponse::success(['mailboxes' => $mailboxes]);
		} catch (\Exception $e) {
			$this->logger->error('Error listing mailboxes', [
				'providerId' => $providerId,
				'exception' => $e,
			]);
			return MailJsonResponse::error('Could not list mailboxes');
		}
	}

	/**
	 * Update a mailbox (e.g., change localpart)
	 *
	 * @NoAdminRequired
	 *
	 * @param string $providerId The provider ID
	 * @param string $userId The user ID whose mailbox to update
	 * @return JSONResponse
	 */
	#[TrapError]
	public function updateMailbox(string $providerId, string $userId): JSONResponse {
		try {
			$currentUserId = $this->getUserIdOrFail();

			// Get update data from request
			$data = $this->request->getParams();
			unset($data['providerId']);
			unset($data['userId']);
			unset($data['_route']);

			// Validate localpart if provided
			if (isset($data['localpart'])) {
				$localpart = trim($data['localpart']);
				if (empty($localpart)) {
					return MailJsonResponse::fail([
						'error' => self::ERR_INVALID_PARAMETERS,
						'message' => 'Localpart cannot be empty',
					], Http::STATUS_BAD_REQUEST);
				}
				// Basic validation: alphanumeric, dots, hyphens, underscores
				if (!preg_match('/^[a-zA-Z0-9._-]+$/', $localpart)) {
					return MailJsonResponse::fail([
						'error' => self::ERR_INVALID_PARAMETERS,
						'message' => 'Localpart contains invalid characters',
					], Http::STATUS_BAD_REQUEST);
				}
				$data['localpart'] = $localpart;
			}

			$this->logger->info('Updating mailbox', [
				'providerId' => $providerId,
				'userId' => $userId,
				'currentUserId' => $currentUserId,
				'data' => array_keys($data),
			]);

			$provider = $this->providerRegistry->getProvider($providerId);
			if ($provider === null) {
				return MailJsonResponse::fail([
					'error' => self::ERR_PROVIDER_NOT_FOUND,
					'message' => 'Provider not found: ' . $providerId,
				], Http::STATUS_NOT_FOUND);
			}

			$mailbox = $provider->updateMailbox($userId, $data);

			$this->logger->info('Mailbox updated successfully', [
				'userId' => $userId,
				'email' => $mailbox['email'] ?? null,
			]);

			return MailJsonResponse::success($mailbox);
		} catch (\OCA\Mail\Exception\AccountAlreadyExistsException $e) {
			$this->logger->warning('Email address already taken', [
				'providerId' => $providerId,
				'userId' => $userId,
			]);
			return MailJsonResponse::fail([
				'error' => 'EMAIL_ALREADY_TAKEN',
				'message' => 'Email is already taken',
			], Http::STATUS_CONFLICT);
		} catch (ServiceException $e) {
			return $this->buildServiceErrorResponse($e, $providerId);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error('Invalid parameters for mailbox update', [
				'providerId' => $providerId,
				'userId' => $userId,
				'exception' => $e,
			]);
			return MailJsonResponse::fail([
				'error' => self::ERR_INVALID_PARAMETERS,
				'message' => $e->getMessage(),
			], Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			$this->logger->error('Unexpected error updating mailbox', [
				'providerId' => $providerId,
				'userId' => $userId,
				'exception' => $e,
			]);
			return MailJsonResponse::error('Could not update mailbox');
		}
	}

	/**
	 * Delete a mailbox
	 *
	 * @NoAdminRequired
	 *
	 * @param string $providerId The provider ID
	 * @param string $userId The user ID whose mailbox to delete
	 * @return JSONResponse
	 */
	#[TrapError]
	public function destroyMailbox(string $providerId, string $userId): JSONResponse {
		try {
			$currentUserId = $this->getUserIdOrFail();

			$this->logger->info('Deleting mailbox', [
				'providerId' => $providerId,
				'userId' => $userId,
				'currentUserId' => $currentUserId,
			]);

			$provider = $this->providerRegistry->getProvider($providerId);
			if ($provider === null) {
				return MailJsonResponse::fail([
					'error' => self::ERR_PROVIDER_NOT_FOUND,
					'message' => 'Provider not found: ' . $providerId,
				], Http::STATUS_NOT_FOUND);
			}

			$success = $provider->deleteMailbox($userId);

			if ($success) {
				$this->logger->info('Mailbox deleted successfully', [
					'userId' => $userId,
				]);
				return MailJsonResponse::success(['deleted' => true]);
			} else {
				return MailJsonResponse::fail([
					'error' => self::ERR_SERVICE_ERROR,
					'message' => 'Failed to delete mailbox',
				], Http::STATUS_INTERNAL_SERVER_ERROR);
			}
		} catch (ServiceException $e) {
			return $this->buildServiceErrorResponse($e, $providerId);
		} catch (\Exception $e) {
			$this->logger->error('Unexpected error deleting mailbox', [
				'providerId' => $providerId,
				'userId' => $userId,
				'exception' => $e,
			]);
			return MailJsonResponse::error('Could not delete mailbox');
		}
	}

	/**
	 * Get the current user ID
	 *
	 * @return string User ID string
	 * @throws ServiceException
	 */
	private function getUserIdOrFail(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new ServiceException('No user session found', 401);
		}
		return $user->getUID();
	}

	/**
	 * Build service error response
	 */
	private function buildServiceErrorResponse(ServiceException $e, string $providerId): JSONResponse {
		$data = [
			'error' => self::ERR_SERVICE_ERROR,
			'statusCode' => $e->getCode(),
			'message' => $e->getMessage(),
		];

		// If it's a ProviderServiceException, merge in the additional data
		if ($e instanceof ProviderServiceException) {
			$data = array_merge($data, $e->getData());
		}

		$this->logger->error('Service error during provider operation', array_merge($data, [
			'providerId' => $providerId,
		]));

		return MailJsonResponse::fail($data);
	}
}
