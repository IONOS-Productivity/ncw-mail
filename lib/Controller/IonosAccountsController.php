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
use OCA\Mail\Service\IONOS\Dto\MailAccountConfig;
use OCA\Mail\Service\IONOS\IonosMailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class IonosAccountsController extends Controller {

	// Error message constants
	private const ERR_ALL_FIELDS_REQUIRED = 'All fields are required';
	private const ERR_IONOS_API_ERROR = 'IONOS_API_ERROR';

	public function __construct(
		string $appName,
		IRequest $request,
		private IonosMailService $ionosMailService,
		private AccountsController $accountsController,
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

		try {
			$this->logger->info('Starting IONOS email account creation', [ 'emailAddress' => $emailUser, 'accountName' => $accountName ]);
			$ionosResponse = $this->ionosMailService->createEmailAccount($emailUser);

			$this->logger->info('IONOS email account created successfully', [ 'emailAddress' => $ionosResponse->getEmail() ]);
			return $this->createNextcloudMailAccount($accountName, $ionosResponse);
		} catch (ServiceException $e) {
			$data = [
				'error' => self::ERR_IONOS_API_ERROR,
				'statusCode' => $e->getCode(),
			];
			$this->logger->error('IONOS service error: ' . $e->getMessage(), $data);

			return MailJsonResponse::fail($data);
		} catch (\Exception $e) {
			return MailJsonResponse::error('Could not create account');
		}
	}

	private function createNextcloudMailAccount(string $accountName, MailAccountConfig $mailConfig): JSONResponse {
		$imap = $mailConfig->getImap();
		$smtp = $mailConfig->getSmtp();

		return $this->accountsController->create(
			$accountName,
			$mailConfig->getEmail(),
			$imap->getHost(),
			$imap->getPort(),
			$imap->getSecurity(),
			$imap->getUsername(),
			$imap->getPassword(),
			$smtp->getHost(),
			$smtp->getPort(),
			$smtp->getSecurity(),
			$smtp->getUsername(),
			$smtp->getPassword(),
		);
	}
}
