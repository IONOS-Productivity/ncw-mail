<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Mail\Controller;

use OCA\Mail\AppInfo\Application;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Http\JsonResponse as MailJsonResponse;
use OCA\Mail\Http\TrapError;
use OCA\Mail\Service\IONOS\ApiMailConfigClientService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Exceptions\AppConfigException;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class IonosAccountsController extends Controller {
	public const BRAND = 'IONOS';

	// Error message constants
	private const ERR_ALL_FIELDS_REQUIRED = 'All fields are required';
	private const ERR_CREATE_EMAIL_FAILED = 'Failed to create email account';
	private const ERR_IONOS_API_ERROR = 'IONOS_API_ERROR';

	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
		private ApiMailConfigClientService $apiMailConfigClientService,
		private AccountsController $accountsController,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	// Helper: input validation
	private function validateInput(string $accountName, string $emailAddress): ?JSONResponse {
		if ($accountName === '' || $emailAddress === '') {
			return new JSONResponse(['success' => false, 'message' => self::ERR_ALL_FIELDS_REQUIRED, 'error' => self::ERR_IONOS_API_ERROR], 400);
		}
		if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
			return new JSONResponse(['success' => false, 'message' => 'Invalid email address format', 'error' => self::ERR_IONOS_API_ERROR], 400);
		}
		return null;
	}

	/**
	 * @NoAdminRequired
	 */
	#[TrapError]
	public function create(string $accountName, string $emailAddress): JSONResponse {
		if ($error = $this->validateInput($accountName, $emailAddress)) {
			return $error;
		}

		try {
			$this->logger->info('Starting IONOS email account creation', [ 'emailAddress' => $emailAddress, 'accountName' => $accountName ]);
			$mailConfig = $this->createIonosEmailAccount($accountName, $emailAddress);

			$this->logger->info('IONOS email account created successfully', [ 'emailAddress' => $emailAddress ]);
			return $this->createNextcloudMailAccount($accountName, $emailAddress, $mailConfig);
		} catch (ServiceException $e) {

			$data = [
				'emailAddress' => $emailAddress,
				'error' => self::ERR_IONOS_API_ERROR,
			];
			$this->logger->error('IONOS service error: ' . $e->getMessage(), $data);

			return MailJsonResponse::fail($data);
		} catch (\Exception $e) {
			return MailJsonResponse::error('Could not create account');
		}
	}

	/**
	 * @throws ServiceException
	 */
	private function createIonosEmailAccount(string $accountName, string $emailAddress): array {

		// simulate error response
		if ($accountName == 'error') {
			throw new ServiceException(self::ERR_CREATE_EMAIL_FAILED);
		}

		$ionosResponse = $this->callIonosCreateEmailAPI($emailAddress);
		if ($ionosResponse === null || !($ionosResponse['success'] ?? false)) {
			$this->logger->error('Failed to create IONOS email account', [ 'emailAddress' => $emailAddress, 'response' => $ionosResponse ]);
			throw new ServiceException(self::ERR_CREATE_EMAIL_FAILED);
		}
		$mailConfig = $ionosResponse['mailConfig'] ?? null;
		if (!is_array($mailConfig)) {
			$this->logger->error('IONOS API response missing mailConfig', [ 'emailAddress' => $emailAddress, 'response' => $ionosResponse ]);
			throw new ServiceException('Invalid IONOS API response: missing mail configuration');
		}
		return $mailConfig;
	}

	private function createNextcloudMailAccount(string $accountName, string $emailAddress, array $mailConfig): JSONResponse {
		$imap = $mailConfig['imap'];
		$smtp = $mailConfig['smtp'];

		return $this->accountsController->create(
			$accountName,
			$emailAddress,
			(string)$imap['host'],
			(int)$imap['port'],
			(string)$imap['security'],
			(string)($imap['username'] ?? $emailAddress),
			(string)($imap['password'] ?? ''),
			(string)$smtp['host'],
			(int)$smtp['port'],
			(string)$smtp['security'],
			(string)($smtp['username'] ?? $emailAddress),
			(string)($smtp['password'] ?? ''),
		);
	}

	/**
	 * @throws ServiceException|AppConfigException
	 */
	protected function callIonosCreateEmailAPI(string $emailAddress): ?array {
		$apiBaseUrl = $this->appConfig->getValueString(Application::APP_ID, 'ionos_mailconfig_api_base_url');
		$allowInsecure = $this->appConfig->getValueBool(Application::APP_ID, 'ionos_mailconfig_api_allow_insecure');
		$basicAuthUser = $this->appConfig->getValueString(Application::APP_ID, 'ionos_mailconfig_api_auth_user');
		$basicAuthPass = $this->appConfig->getValueString(Application::APP_ID, 'ionos_mailconfig_api_auth_pass');

		$this->logger->debug('send', [
			'emailAddress' => $emailAddress,
			'apiBaseUrl' => $apiBaseUrl
		]);

		if (empty($apiBaseUrl)) {
			$this->logger->error('No mailconfig service url is configured');
			throw new AppConfigException('No mailconfig service configured');
		}

		if (empty($basicAuthUser)) {
			$this->logger->error('No mailconfig service user is configured');
			throw new AppConfigException('No mailconfig user configured');
		}

		if (empty($basicAuthPass)) {
			$this->logger->error('No mailconfig service pass is configured');
			throw new AppConfigException('No mailconfig service pass configured');
		}

		$atPosition = strrchr($emailAddress, '@');
		if ($atPosition === false) {
			throw new ServiceException('Invalid email address: unable to extract domain');
		}
		$domain = substr($atPosition, 1);
		if ($domain === '') {
			throw new ServiceException('Invalid email address: unable to extract domain');
		}

		$client = $this->apiMailConfigClientService->newClient([
			'auth' => [$basicAuthUser, $basicAuthPass],
			'verify' => !$allowInsecure,
		]);

		$apiInstance = $this->apiMailConfigClientService->newEventAPIApi($client, $apiBaseUrl);

		$extRef = 'extRef_example'; // string
		$mailCreateData = new \IONOS\MailConfigurationAPI\Client\Model\MailCreateData();
		$mailCreateData->setNextcloudUserId('foo');
		$mailCreateData->setMailaddress($emailAddress);

		try {
			$this->logger->debug('Send message to mailconfig service', ['data' => $mailCreateData]);
			$result = $apiInstance->createMailbox(self::BRAND, $extRef, $mailCreateData);

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
		} catch (\Exception $e) {
			$this->logger->error('Exception when calling MailConfigurationAPIApi->processShareByLinkEvent', ['exception' => $e]);
			throw new ServiceException('Failed to create ionos mail', $e);
		}
	}
}
