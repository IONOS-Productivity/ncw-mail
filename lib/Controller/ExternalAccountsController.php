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

			return MailJsonResponse::success($account, Http::STATUS_CREATED);
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
