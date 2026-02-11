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
use OCA\Mail\Provider\MailAccountProvider\Dto\MailboxInfo;
use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCA\Mail\Service\AccountProviderService;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Settings\ProviderAccountOverviewSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
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
		private AccountService $accountService,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private IConfig $config,
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

			// Get and validate the provider
			$provider = $this->getValidatedProvider($providerId);
			if ($provider instanceof JSONResponse) {
				return $provider;
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
	 * Get information about available providers for the current user
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

			$providersInfo = $this->serializeProviders($availableProviders);

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
	 * Get all enabled providers (admin only)
	 *
	 * Returns all enabled providers regardless of user availability.
	 * Used by admins to manage mailboxes across all providers.
	 *
	 * @return JSONResponse
	 */
	#[TrapError]
	#[AuthorizedAdminSetting(settings: ProviderAccountOverviewSettings::class)]
	public function getEnabledProviders(): JSONResponse {
		try {
			$userId = $this->getUserIdOrFail();

			$this->logger->debug('Getting enabled providers for admin', [
				'userId' => $userId,
			]);

			$enabledProviders = $this->providerRegistry->getEnabledProviders();

			$providersInfo = $this->serializeProviders($enabledProviders);

			return MailJsonResponse::success([
				'providers' => $providersInfo,
			]);
		} catch (\Exception $e) {
			$this->logger->error('Error getting enabled providers', [
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

			$provider = $this->getValidatedProvider($providerId);
			if ($provider instanceof JSONResponse) {
				return $provider;
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
	 * @param string $providerId The provider ID
	 * @return JSONResponse
	 */
	#[TrapError]
	#[AuthorizedAdminSetting(settings: ProviderAccountOverviewSettings::class)]
	public function indexMailboxes(string $providerId): JSONResponse {
		try {
			$userId = $this->getUserIdOrFail();

			$this->logger->debug('Listing mailboxes for provider', [
				'providerId' => $providerId,
				'userId' => $userId,
			]);

			$provider = $this->getValidatedProvider($providerId);
			if ($provider instanceof JSONResponse) {
				return $provider;
			}

			$mailboxes = $provider->getMailboxes();

			// Extend mailboxes with user display names
			$mailboxes = array_map(
				fn (MailboxInfo $mailbox) => $this->enrichMailboxWithUserName($mailbox)->toArray(),
				$mailboxes
			);

			return MailJsonResponse::success([
				'mailboxes' => $mailboxes,
				'debug' => $this->config->getSystemValue('debug', false),
			]);
		} catch (ServiceException $e) {
			return $this->buildServiceErrorResponse($e, $providerId);
		} catch (\Exception $e) {
			$this->logger->error('Unexpected error listing mailboxes', [
				'providerId' => $providerId,
				'exception' => $e,
			]);
			return MailJsonResponse::error('Could not list mailboxes');
		}
	}

	/**
	 * Enrich mailbox with user display name
	 *
	 * @param MailboxInfo $mailbox The mailbox information
	 * @return MailboxInfo The enriched mailbox with user display name
	 */
	private function enrichMailboxWithUserName(MailboxInfo $mailbox): MailboxInfo {
		if (!$mailbox->userExists) {
			return $mailbox;
		}

		$user = $this->userManager->get($mailbox->userId);
		if ($user === null) {
			return $mailbox;
		}

		return $mailbox->withUserName($user->getDisplayName());
	}

	/**
	 * Delete a mailbox
	 *
	 * @param string $providerId The provider ID
	 * @param string $userId The user ID whose mailbox to delete
	 * @return JSONResponse
	 */
	#[TrapError]
	#[AuthorizedAdminSetting(settings: ProviderAccountOverviewSettings::class)]
	public function destroyMailbox(string $providerId, string $userId): JSONResponse {
		try {
			$currentUserId = $this->getUserIdOrFail();

			// Get email from query parameters and decode it
			$email = $this->request->getParam('email');
			if (empty($email)) {
				return MailJsonResponse::fail([
					'error' => self::ERR_INVALID_PARAMETERS,
					'message' => 'Email parameter is required',
				], Http::STATUS_BAD_REQUEST);
			}

			// URL decode the email parameter (handles encoded @ and other special chars)
			$email = urldecode($email);

			// Validate email format
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				return MailJsonResponse::fail([
					'error' => self::ERR_INVALID_PARAMETERS,
					'message' => 'Invalid email format',
				], Http::STATUS_BAD_REQUEST);
			}

			$this->logger->info('Deleting mailbox', [
				'providerId' => $providerId,
				'userId' => $userId,
				'email' => $email,
				'currentUserId' => $currentUserId,
			]);

			$provider = $this->getValidatedProvider($providerId);
			if ($provider instanceof JSONResponse) {
				return $provider;
			}

			// Find associated mail app account before deletion
			$mailAppAccountId = null;
			try {
				$accounts = $this->accountService->findByUserIdAndAddress($userId, $email);
				if (!empty($accounts)) {
					$mailAppAccountId = $accounts[0]->getId();
				}
			} catch (\Exception $e) {
				$this->logger->warning('Could not retrieve mail app account before deletion', [
					'userId' => $userId,
					'email' => $email,
					'exception' => $e,
				]);
			}

			// Delete provider mailbox
			$success = $provider->deleteAccount($userId, $email);

			if ($success) {
				// Also delete local mail app account if it exists
				if ($mailAppAccountId !== null) {
					try {
						$this->accountService->delete($userId, $mailAppAccountId);
						$this->logger->info('Deleted associated mail app account', [
							'userId' => $userId,
							'accountId' => $mailAppAccountId,
							'email' => $email,
						]);
					} catch (\Exception $e) {
						// Log but don't fail - provider mailbox was deleted successfully
						$this->logger->warning('Could not delete associated mail app account', [
							'userId' => $userId,
							'accountId' => $mailAppAccountId,
							'exception' => $e,
						]);
					}
				}

				$this->logger->info('Mailbox deleted successfully', [
					'userId' => $userId,
					'deletedMailAppAccount' => $mailAppAccountId !== null,
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

		// sanitize internal info from payload returned to customer
		$data['message'] = $this->sanitizeErrorMessage($data['message']);

		// Use exception code as HTTP status, default to 400 if invalid
		$httpStatus = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
		return MailJsonResponse::fail($data, $httpStatus);
	}

	/**
	 * Sanitize error messages by redacting server URLs
	 *
	 * Detects and replaces hostnames with [SERVER]
	 * while preserving the protocol and path for debugging purposes.
	 *
	 * @param string $message The error message to sanitize
	 * @return string The sanitized message
	 */
	private function sanitizeErrorMessage(string $message): string {
		// Pattern to match any URL
		// Captures: (protocol)(hostname:port)(path)
		$pattern = '/(https?:\/\/)([a-zA-Z0-9.-]+(?::\d+)?)(\/[^\s]*)?/';

		return preg_replace_callback($pattern, function ($matches) {
			$protocol = $matches[1];
			$path = $matches[3] ?? '';

			return $protocol . '[SERVER]' . $path;
		}, $message);
	}

	/**
	 * Get a provider by ID and validate it exists
	 *
	 * @return IMailAccountProvider|JSONResponse
	 *                                           Returns the provider if found, or JSONResponse error if not found
	 */
	private function getValidatedProvider(string $providerId): IMailAccountProvider|JSONResponse {
		$provider = $this->providerRegistry->getProvider($providerId);
		if ($provider === null) {
			return MailJsonResponse::fail([
				'error' => self::ERR_PROVIDER_NOT_FOUND,
				'message' => 'Provider not found: ' . $providerId,
			], Http::STATUS_NOT_FOUND);
		}
		return $provider;
	}

	/**
	 * Serialize an array of providers into a consistent format
	 *
	 * @param array $providers Array of IMailAccountProvider instances
	 * @return array Serialized provider information
	 */
	private function serializeProviders(array $providers): array {
		$providersInfo = [];
		foreach ($providers as $provider) {
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
		return $providersInfo;
	}
}
