<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Mail\Controller;

use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Http\JsonResponse as MailJsonResponse;
use OCA\Mail\Http\TrapError;
use OCA\Mail\Service\IONOS\IonosAccountConflictResolver;
use OCA\Mail\Service\IONOS\IonosAccountCreationService;
use OCA\Mail\Service\IONOS\IonosMailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class IonosAccountsController extends Controller {

	// Error message constants
	private const ERR_ALL_FIELDS_REQUIRED = 'All fields are required';
	private const ERR_IONOS_API_ERROR = 'IONOS_API_ERROR';

	public function __construct(
		string $appName,
		IRequest $request,
		private IonosAccountCreationService $accountCreationService,
		private IonosMailService $ionosMailService,
		private IonosAccountConflictResolver $conflictResolver,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	// Helper: input validation
	private function validateInput(string $accountName, string $emailUser): ?JSONResponse {
		if ($accountName === '' || $emailUser === '') {
			return new JSONResponse(['success' => false, 'message' => self::ERR_ALL_FIELDS_REQUIRED, 'error' => self::ERR_IONOS_API_ERROR], 400);
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

		$userId = $this->getUserIdOrFail();
		if ($userId instanceof JSONResponse) {
			return $userId;
		}

		try {
			$this->logger->info('Starting IONOS email account creation from web', [
				'userId' => $userId,
				'emailUser' => $emailUser,
				'accountName' => $accountName
			]);

			// Use the shared account creation service
			$account = $this->accountCreationService->createOrUpdateAccount($userId, $emailUser, $accountName);

			$this->logger->info('Account creation completed successfully', [
				'accountId' => $account->getId(),
				'emailAddress' => $account->getEmail(),
				'userId' => $userId,
			]);

			return new JSONResponse([
				'id' => $account->getId(),
				'accountName' => $account->getName(),
				'emailAddress' => $account->getEmail(),
			]);
		} catch (ServiceException $e) {
			return $this->buildServiceErrorResponse($e, 'account creation');
		} catch (\Exception $e) {
			$this->logger->error('Unexpected error during account creation: ' . $e->getMessage(), [
				'exception' => $e,
				'userId' => $userId,
			]);
			return MailJsonResponse::error('Could not create account');
		}
	}

	/**
	 * Get the current user ID or return error response
	 *
	 * @return string|JSONResponse User ID string or error response
	 */
	private function getUserIdOrFail(): string|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			$data = [
				'error' => self::ERR_IONOS_API_ERROR,
				'statusCode' => 401,
				'message' => 'No user session found',
			];
			$this->logger->error('No user session found during account creation', $data);
			return MailJsonResponse::fail($data);
		}
		return $user->getUID();
	}

	/**
	 * Build service error response
	 */
	private function buildServiceErrorResponse(ServiceException $e, string $context): JSONResponse {
		$data = [
			'error' => self::ERR_IONOS_API_ERROR,
			'statusCode' => $e->getCode(),
			'message' => $e->getMessage(),
		];
		$this->logger->error('IONOS service error during ' . $context . ': ' . $e->getMessage(), $data);
		return MailJsonResponse::fail($data);
	}
}
