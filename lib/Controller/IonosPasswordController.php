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
use OCA\Mail\Service\IONOS\IonosMailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class IonosPasswordController extends Controller {

	// Error message constants
	private const ERR_ACCOUNT_ID_REQUIRED = 'Account ID is required';
	private const ERR_IONOS_API_ERROR = 'IONOS_API_ERROR';

	public function __construct(
		string $appName,
		IRequest $request,
		private IonosMailService $ionosMailService,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Generate an IONOS app password for IMAP access
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int|null $accountId The account ID
	 * @return JSONResponse
	 */
	#[TrapError]
	public function generate(?int $accountId = null): JSONResponse {
		if ($accountId === null) {
			$this->logger->error('Account ID is required for app password generation');
			return MailJsonResponse::fail(['error' => self::ERR_ACCOUNT_ID_REQUIRED]);
		}

		try {
			$this->logger->info('Generating IONOS app password', [
				'accountId' => $accountId,
			]);

			$password = $this->ionosMailService->generateUserAppPassword();

			$this->logger->info('IONOS app password generated successfully', [
				'accountId' => $accountId,
			]);

			return MailJsonResponse::success([
				'password' => $password,
			]);
		} catch (ServiceException $e) {
			$data = [
				'error' => self::ERR_IONOS_API_ERROR,
				'statusCode' => $e->getCode(),
			];
			$this->logger->error('IONOS service error: ' . $e->getMessage(), $data);

			return MailJsonResponse::fail($data);
		} catch (\Exception $e) {
			$this->logger->error('Unexpected error generating app password', [
				'exception' => $e,
				'accountId' => $accountId,
			]);
			return MailJsonResponse::error('Could not generate app password');
		}
	}
}
