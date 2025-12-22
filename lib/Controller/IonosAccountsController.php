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
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosAccountCreationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class IonosAccountsController extends Controller {

	// Error message constants
	private const ERR_ALL_FIELDS_REQUIRED = 'All fields are required';
	private const ERR_SERVICE_ERROR = 'SERVICE_ERROR';

	public function __construct(
		string $appName,
		IRequest $request,
		private IonosAccountCreationService $accountCreationService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	// Helper: input validation
	private function validateInput(string $accountName, string $emailUser): ?JSONResponse {
		if ($accountName === '' || $emailUser === '') {
			return new JSONResponse(['success' => false, 'message' => self::ERR_ALL_FIELDS_REQUIRED, 'error' => self::ERR_SERVICE_ERROR], 400);
		}
		return null;
	}

	/**
	 * @NoAdminRequired
	 */
	#[TrapError]
	public function create(string $accountName, string $emailUser): JSONResponse {
		if ($error = $this->validateInput($accountName, $emailUser)) {
			return $error;
		}

		try {
			$userId = $this->getUserIdOrFail();

			$this->logger->info('Starting IONOS email account creation from web', [
				'userId' => $userId,
				'emailAddress' => $emailUser,
				'accountName' => $accountName,
			]);

			$account = $this->accountCreationService->createOrUpdateAccount($userId, $emailUser, $accountName);

			$this->logger->info('Account creation completed successfully', [
				'emailAddress' => $account->getEmail(),
				'accountName' => $accountName,
				'accountId' => $account->getId(),
				'userId' => $userId,
			]);

			return MailJsonResponse::success($account, Http::STATUS_CREATED);
		} catch (ServiceException $e) {
			return $this->buildServiceErrorResponse($e, 'account creation');
		} catch (\Exception $e) {
			$this->logger->error('Unexpected error during account creation: ' . $e->getMessage(), [
				'exception' => $e,
			]);
			return MailJsonResponse::error('Could not create account');
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
			throw new ServiceException('No user session found during account creation', 401);
		}
		return $user->getUID();
	}

	/**
	 * Build service error response
	 */
	private function buildServiceErrorResponse(ServiceException $e, string $context): JSONResponse {
		$data = [
			'error' => 'SERVICE_ERROR',
			'statusCode' => $e->getCode(),
			'message' => $e->getMessage(),
		];

		// If it's a ProviderServiceException, merge in the additional data
		if ($e instanceof ProviderServiceException) {
			$data = array_merge($data, $e->getData());
		}

		$this->logger->error('IONOS service error during ' . $context . ': ' . $e->getMessage(), $data);
		return MailJsonResponse::fail($data);
	}
}
