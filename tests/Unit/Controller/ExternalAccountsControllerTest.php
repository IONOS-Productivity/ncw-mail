<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Controller;

use OCA\Mail\Account;
use OCA\Mail\Controller\ExternalAccountsController;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Exception\ProviderServiceException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Provider\MailAccountProvider\Dto\MailboxInfo;
use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\ProviderCapabilities;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCA\Mail\Service\AccountProviderService;
use OCA\Mail\Service\AccountService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ExternalAccountsControllerTest extends TestCase {
	private string $appName = 'mail';
	private IRequest&MockObject $request;
	private ProviderRegistryService&MockObject $providerRegistry;
	private AccountProviderService&MockObject $accountProviderService;
	private AccountService&MockObject $accountService;
	private IUserSession&MockObject $userSession;
	private IUserManager&MockObject $userManager;
	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;
	private ExternalAccountsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->providerRegistry = $this->createMock(ProviderRegistryService::class);
		$this->accountProviderService = $this->createMock(AccountProviderService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ExternalAccountsController(
			$this->appName,
			$this->request,
			$this->providerRegistry,
			$this->accountProviderService,
			$this->accountService,
			$this->userSession,
			$this->userManager,
			$this->config,
			$this->logger,
		);
	}

	public function testCreateWithNoUserSession(): void {
		$this->userSession->method('getUser')
			->willReturn(null);

		$response = $this->controller->create('test-provider');

		// getUserIdOrFail throws ServiceException with code 401
		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
	}

	public function testCreateWithProviderNotFound(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['param1' => 'value1']);

		$this->providerRegistry->method('getProvider')
			->with('nonexistent')
			->willReturn(null);

		$response = $this->controller->create('nonexistent');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('PROVIDER_NOT_FOUND', $data['data']['error']);
	}

	public function testCreateWithDisabledProvider(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['param1' => 'value1']);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(false);

		$this->providerRegistry->method('getProvider')
			->with('disabled-provider')
			->willReturn($provider);

		$response = $this->controller->create('disabled-provider');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('PROVIDER_NOT_AVAILABLE', $data['data']['error']);
	}

	public function testCreateWithProviderNotAvailableForUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['param1' => 'value1']);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('isAvailableForUser')
			->with('testuser')
			->willReturn(false);
		$provider->method('getExistingAccountEmail')
			->willReturn(null);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->create('test-provider');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('PROVIDER_NOT_AVAILABLE', $data['data']['error']);
		$this->assertStringContainsString('not available for this user', $data['data']['message']);
		$this->assertArrayNotHasKey('existingEmail', $data['data']);
	}

	public function testCreateWithProviderNotAvailableForUserWithExistingEmail(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['param1' => 'value1']);

		// Create an anonymous class that extends the provider interface with getExistingAccountEmail
		$provider = new class implements IMailAccountProvider {
			public function getId(): string {
				return 'test-provider';
			}

			public function getName(): string {
				return 'Test Provider';
			}

			public function getCapabilities(): \OCA\Mail\Provider\MailAccountProvider\IProviderCapabilities {
				return new ProviderCapabilities(false, false);
			}

			public function isEnabled(): bool {
				return true;
			}

			public function isAvailableForUser(string $userId): bool {
				return false;
			}

			public function createAccount(string $userId, array $parameters): Account {
				throw new \RuntimeException('Should not be called');
			}

			public function updateAccount(string $userId, int $accountId, array $parameters): Account {
				throw new \RuntimeException('Should not be called');
			}

			public function deleteAccount(string $userId, string $email): bool {
				throw new \RuntimeException('Should not be called');
			}

			public function managesEmail(string $userId, string $email): bool {
				return false;
			}

			public function getProvisionedEmail(string $userId): ?string {
				return null;
			}

			public function generateAppPassword(string $userId): string {
				throw new \RuntimeException('Should not be called');
			}

			public function getExistingAccountEmail(string $userId): ?string {
				return 'existing@example.com';
			}

			public function getMailboxes(): array {
				return [];
			}

			public function updateMailbox(string $userId, string $currentEmail, string $newLocalpart): MailboxInfo {
				return new MailboxInfo(
					userId: $userId,
					email: 'test@example.com',
					userExists: true,
					mailAppAccountId: null,
					mailAppAccountName: null,
					mailAppAccountExists: false,
				);
			}
		};

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->create('test-provider');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('PROVIDER_NOT_AVAILABLE', $data['data']['error']);
		$this->assertStringContainsString('not available for this user', $data['data']['message']);
		$this->assertEquals('existing@example.com', $data['data']['existingEmail']);
	}

	public function testCreateSuccess(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'_route' => 'some-route',
				'emailUser' => 'user',
				'accountName' => 'Test Account',
			]);

		$mailAccount = new MailAccount();
		$mailAccount->setId(123);
		$mailAccount->setEmail('user@example.com');
		$account = new Account($mailAccount);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('isAvailableForUser')
			->with('testuser')
			->willReturn(true);
		$provider->method('createAccount')
			->with('testuser', [
				'emailUser' => 'user',
				'accountName' => 'Test Account',
			])
			->willReturn($account);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$accountJson = $account->jsonSerialize();
		$enhancedJson = array_merge($accountJson, [
			'managedByProvider' => 'test-provider',
			'providerCapabilities' => [
				'multipleAccounts' => true,
				'appPasswords' => true,
				'passwordReset' => false,
				'emailDomain' => 'example.com',
			],
		]);

		$this->accountProviderService->expects($this->once())
			->method('addProviderMetadata')
			->with($accountJson, 'testuser', 'user@example.com')
			->willReturn($enhancedJson);

		$response = $this->controller->create('test-provider');

		$this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertArrayHasKey('managedByProvider', $data['data']);
		$this->assertEquals('test-provider', $data['data']['managedByProvider']);
		$this->assertArrayHasKey('providerCapabilities', $data['data']);
	}

	public function testCreateWithInvalidArgumentException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['param1' => 'value1']);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('isAvailableForUser')
			->willReturn(true);
		$provider->method('createAccount')
			->willThrowException(new \InvalidArgumentException('Missing required parameter'));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->create('test-provider');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('INVALID_PARAMETERS', $data['data']['error']);
	}

	public function testCreateWithServiceException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['param1' => 'value1']);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('isAvailableForUser')
			->willReturn(true);
		$provider->method('createAccount')
			->willThrowException(new ServiceException('Service error', 500));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->create('test-provider');

		// Verify HTTP status matches exception code
		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals(500, $data['data']['statusCode']);
	}

	public function testCreateWithProviderServiceException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['param1' => 'value1']);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('isAvailableForUser')
			->willReturn(true);
		$provider->method('createAccount')
			->willThrowException(new ProviderServiceException('IONOS error', 503, ['detail' => 'API unavailable']));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->create('test-provider');

		// Verify HTTP status matches exception code
		$this->assertEquals(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals(503, $data['data']['statusCode']);
		$this->assertEquals('API unavailable', $data['data']['detail']);
	}

	public function testCreateWithServiceExceptionInvalidCode(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['param1' => 'value1']);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('isAvailableForUser')
			->willReturn(true);
		$provider->method('createAccount')
			->willThrowException(new ServiceException('Service error', 999));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->create('test-provider');

		// Invalid exception code should default to 400
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals(999, $data['data']['statusCode']);
	}

	public function testCreateWithServiceExceptionCodeZero(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['param1' => 'value1']);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('isAvailableForUser')
			->willReturn(true);
		$provider->method('createAccount')
			->willThrowException(new ServiceException('Service error', 0));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->create('test-provider');

		// Exception code 0 should default to 400
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
	}

	public function testCreateWithServiceExceptionCode404(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['param1' => 'value1']);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('isAvailableForUser')
			->willReturn(true);
		$provider->method('createAccount')
			->willThrowException(new ServiceException('Resource not found', 404));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->create('test-provider');

		// Verify HTTP status matches exception code
		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals(404, $data['data']['statusCode']);
	}

	public function testGetProviders(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$capabilities = new ProviderCapabilities(
			multipleAccounts: true,
			appPasswords: true,
			passwordReset: false,
			creationParameterSchema: [
				'param1' => ['type' => 'string', 'required' => true],
			],
			emailDomain: 'example.com',
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('test-provider');
		$provider->method('getName')->willReturn('Test Provider');
		$provider->method('getCapabilities')->willReturn($capabilities);

		$this->providerRegistry->method('getAvailableProvidersForUser')
			->with('testuser')
			->willReturn(['test-provider' => $provider]);

		$response = $this->controller->getProviders();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertArrayHasKey('providers', $data['data']);
		$this->assertCount(1, $data['data']['providers']);

		$providerInfo = $data['data']['providers'][0];
		$this->assertEquals('test-provider', $providerInfo['id']);
		$this->assertEquals('Test Provider', $providerInfo['name']);
		$this->assertTrue($providerInfo['capabilities']['multipleAccounts']);
		$this->assertTrue($providerInfo['capabilities']['appPasswords']);
		$this->assertFalse($providerInfo['capabilities']['passwordReset']);
		$this->assertEquals('example.com', $providerInfo['capabilities']['emailDomain']);
	}

	public function testGetProvidersWithNoUserSession(): void {
		$this->userSession->method('getUser')
			->willReturn(null);

		$response = $this->controller->getProviders();

		$data = $response->getData();
		$this->assertEquals('error', $data['status']);
	}

	public function testGetProvidersWithException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->providerRegistry->method('getAvailableProvidersForUser')
			->with('testuser')
			->willThrowException(new \Exception('Registry error'));

		$this->logger->expects($this->once())
			->method('error')
			->with('Error getting available providers', $this->anything());

		$response = $this->controller->getProviders();

		$data = $response->getData();
		$this->assertEquals('error', $data['status']);
	}

	public function testGetEnabledProviders(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$capabilities = new ProviderCapabilities(
			multipleAccounts: true,
			appPasswords: true,
			passwordReset: false,
			creationParameterSchema: [
				'param1' => ['type' => 'string', 'required' => true],
			],
			emailDomain: 'example.com',
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('test-provider');
		$provider->method('getName')->willReturn('Test Provider');
		$provider->method('getCapabilities')->willReturn($capabilities);

		$this->providerRegistry->method('getEnabledProviders')
			->willReturn(['test-provider' => $provider]);

		$this->logger->expects($this->once())
			->method('debug')
			->with('Getting enabled providers for admin', $this->anything());

		$response = $this->controller->getEnabledProviders();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertArrayHasKey('providers', $data['data']);
		$this->assertCount(1, $data['data']['providers']);

		$providerInfo = $data['data']['providers'][0];
		$this->assertEquals('test-provider', $providerInfo['id']);
		$this->assertEquals('Test Provider', $providerInfo['name']);
		$this->assertTrue($providerInfo['capabilities']['multipleAccounts']);
		$this->assertTrue($providerInfo['capabilities']['appPasswords']);
		$this->assertFalse($providerInfo['capabilities']['passwordReset']);
		$this->assertEquals('example.com', $providerInfo['capabilities']['emailDomain']);
	}

	public function testGetEnabledProvidersWithNoUserSession(): void {
		$this->userSession->method('getUser')
			->willReturn(null);

		$response = $this->controller->getEnabledProviders();

		$data = $response->getData();
		$this->assertEquals('error', $data['status']);
	}

	public function testGetEnabledProvidersWithException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->providerRegistry->method('getEnabledProviders')
			->willThrowException(new \Exception('Registry error'));

		$this->logger->expects($this->once())
			->method('error')
			->with('Error getting enabled providers', $this->anything());

		$response = $this->controller->getEnabledProviders();

		$data = $response->getData();
		$this->assertEquals('error', $data['status']);
	}


	public function testGeneratePasswordWithNoAccountId(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('accountId')
			->willReturn(null);

		$response = $this->controller->generatePassword('test-provider');

		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
	}

	public function testGeneratePasswordWithNoUserSession(): void {
		$this->userSession->method('getUser')
			->willReturn(null);

		$this->request->method('getParam')
			->with('accountId')
			->willReturn(123);

		$response = $this->controller->generatePassword('test-provider');

		// getUserIdOrFail throws ServiceException with code 401
		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
	}


	public function testGeneratePasswordWithProviderNotFound(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('accountId')
			->willReturn(123);

		$this->providerRegistry->method('getProvider')
			->with('nonexistent')
			->willReturn(null);

		$response = $this->controller->generatePassword('nonexistent');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('PROVIDER_NOT_FOUND', $data['data']['error']);
	}

	public function testGeneratePasswordWithProviderNotSupporting(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('accountId')
			->willReturn(123);

		$capabilities = new ProviderCapabilities(
			appPasswords: false,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getCapabilities')
			->willReturn($capabilities);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->generatePassword('test-provider');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('NOT_SUPPORTED', $data['data']['error']);
	}

	public function testGeneratePasswordSuccess(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('accountId')
			->willReturn(123);

		$capabilities = new ProviderCapabilities(
			appPasswords: true,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getCapabilities')
			->willReturn($capabilities);
		$provider->method('generateAppPassword')
			->with('testuser')
			->willReturn('generated-app-password-123');

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->generatePassword('test-provider');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertEquals('generated-app-password-123', $data['data']['password']);
	}

	public function testGeneratePasswordWithServiceException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('accountId')
			->willReturn(123);

		$capabilities = new ProviderCapabilities(
			appPasswords: true,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getCapabilities')
			->willReturn($capabilities);
		$provider->method('generateAppPassword')
			->willThrowException(new ServiceException('Service error', 500));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->generatePassword('test-provider');

		// Verify HTTP status matches exception code
		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals(500, $data['data']['statusCode']);
	}

	public function testGeneratePasswordWithServiceExceptionInvalidCode(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('accountId')
			->willReturn(123);

		$capabilities = new ProviderCapabilities(
			appPasswords: true,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getCapabilities')
			->willReturn($capabilities);
		$provider->method('generateAppPassword')
			->willThrowException(new ServiceException('Service error', 200));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->generatePassword('test-provider');

		// Exception code outside 400-599 range should default to 400
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
	}

	public function testGeneratePasswordWithProviderServiceException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('accountId')
			->willReturn(123);

		$capabilities = new ProviderCapabilities(
			appPasswords: true,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getCapabilities')
			->willReturn($capabilities);
		$provider->method('generateAppPassword')
			->willThrowException(new ProviderServiceException('Provider error', 403, ['reason' => 'quota exceeded']));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->generatePassword('test-provider');

		// Verify HTTP status matches exception code
		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals(403, $data['data']['statusCode']);
		$this->assertEquals('quota exceeded', $data['data']['reason']);
	}

	/**
	 * @dataProvider sanitizeErrorMessageProvider
	 */
	public function testCreateSanitizesErrorMessagesWithUrls(string $errorMessage, string $expectedSanitized): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['param1' => 'value1']);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('isAvailableForUser')
			->willReturn(true);
		$provider->method('createAccount')
			->willThrowException(new ServiceException($errorMessage, 500));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->create('test-provider');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertStringContainsString($expectedSanitized, $data['data']['message']);
		$this->assertStringNotContainsString('internal.server.com', $data['data']['message']);
		$this->assertStringNotContainsString('api.example.org', $data['data']['message']);
	}

	public static function sanitizeErrorMessageProvider(): array {
		return [
			'HTTP URL with path' => [
				'Connection failed to http://internal.server.com/api/v1/endpoint',
				'http://[SERVER]/api/v1/endpoint',
			],
			'HTTPS URL with path' => [
				'Error from https://api.example.org/v2/users',
				'https://[SERVER]/v2/users',
			],
			'URL with port' => [
				'Failed to connect to https://internal.server.com:8443/admin',
				'https://[SERVER]/admin',
			],
			'URL with port and no path' => [
				'Timeout connecting to http://api.example.org:3000',
				'http://[SERVER]',
			],
			'Multiple URLs in message' => [
				'Redirect from http://old.server.com/path to https://new.server.com/newpath failed',
				'http://[SERVER]/path',
			],
			'URL without path' => [
				'Cannot reach https://internal.server.com',
				'https://[SERVER]',
			],
			'Message without URL' => [
				'Generic error message without URLs',
				'Generic error message without URLs',
			],
		];
	}

	public function testGeneratePasswordSanitizesErrorMessagesWithUrls(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('accountId')
			->willReturn(123);

		$capabilities = new ProviderCapabilities(
			appPasswords: true,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getCapabilities')
			->willReturn($capabilities);
		$provider->method('generateAppPassword')
			->willThrowException(new ServiceException('API error at https://api.internal.example.com/v1/passwords', 500));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->generatePassword('test-provider');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertStringContainsString('https://[SERVER]/v1/passwords', $data['data']['message']);
		$this->assertStringNotContainsString('api.internal.example.com', $data['data']['message']);
	}

	public function testIndexMailboxesWithNoUserSession(): void {
		$this->userSession->method('getUser')
			->willReturn(null);

		$response = $this->controller->indexMailboxes('test-provider');

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
	}

	public function testIndexMailboxesWithProviderNotFound(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->providerRegistry->method('getProvider')
			->with('nonexistent')
			->willReturn(null);

		$response = $this->controller->indexMailboxes('nonexistent');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('PROVIDER_NOT_FOUND', $data['data']['error']);
	}

	public function testIndexMailboxesSuccess(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$mailboxes = [
			new MailboxInfo(
				userId: 'user1',
				email: 'user1@example.com',
				userExists: true,
				mailAppAccountId: 1,
				mailAppAccountName: 'User 1 Mail',
				mailAppAccountExists: true,
			),
			new MailboxInfo(
				userId: 'user2',
				email: 'user2@example.com',
				userExists: false,
				mailAppAccountId: null,
				mailAppAccountName: null,
				mailAppAccountExists: false,
			),
		];

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getMailboxes')
			->willReturn($mailboxes);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$mockUser1 = $this->createMock(IUser::class);
		$mockUser1->method('getDisplayName')->willReturn('User One Display');

		$this->userManager->method('get')
			->willReturnCallback(function ($userId) use ($mockUser1) {
				if ($userId === 'user1') {
					return $mockUser1;
				}
				return null;
			});

		$this->config->method('getSystemValue')
			->with('debug', false)
			->willReturn(false);

		$this->logger->expects($this->once())
			->method('debug')
			->with('Listing mailboxes for provider', $this->anything());

		$response = $this->controller->indexMailboxes('test-provider');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertArrayHasKey('mailboxes', $data['data']);
		$this->assertCount(2, $data['data']['mailboxes']);

		// Check first mailbox has userName
		$this->assertEquals('User One Display', $data['data']['mailboxes'][0]['userName']);
		$this->assertEquals('user1@example.com', $data['data']['mailboxes'][0]['email']);

		// Check second mailbox has null userName (user doesn't exist)
		$this->assertNull($data['data']['mailboxes'][1]['userName']);
		$this->assertEquals('user2@example.com', $data['data']['mailboxes'][1]['email']);

		// Check debug flag
		$this->assertArrayHasKey('debug', $data['data']);
		$this->assertFalse($data['data']['debug']);
	}

	public function testIndexMailboxesWithUserExistsButNoUserName(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$mailboxes = [
			new MailboxInfo(
				userId: 'user1',
				email: 'user1@example.com',
				userExists: true,
				mailAppAccountId: null,
				mailAppAccountName: null,
				mailAppAccountExists: false,
			),
		];

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getMailboxes')
			->willReturn($mailboxes);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		// User manager returns null even though userExists is true
		// (edge case: user was deleted between provider check and user manager lookup)
		$this->userManager->method('get')
			->with('user1')
			->willReturn(null);

		$this->config->method('getSystemValue')
			->with('debug', false)
			->willReturn(false);

		$response = $this->controller->indexMailboxes('test-provider');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertArrayHasKey('mailboxes', $data['data']);
		$this->assertCount(1, $data['data']['mailboxes']);

		// userName should be null if user manager can't find the user
		$this->assertNull($data['data']['mailboxes'][0]['userName']);
	}

	public function testIndexMailboxesWithDebugEnabled(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$mailboxes = [];

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getMailboxes')
			->willReturn($mailboxes);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$this->config->method('getSystemValue')
			->with('debug', false)
			->willReturn(true);

		$response = $this->controller->indexMailboxes('test-provider');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertTrue($data['data']['debug']);
	}

	public function testIndexMailboxesWithServiceException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getMailboxes')
			->willThrowException(new ServiceException('Service error', 500));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->indexMailboxes('test-provider');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals(500, $data['data']['statusCode']);
	}

	public function testIndexMailboxesWithProviderServiceException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getMailboxes')
			->willThrowException(new ProviderServiceException('Provider error', 503, ['reason' => 'API timeout']));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->indexMailboxes('test-provider');

		$this->assertEquals(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals(503, $data['data']['statusCode']);
		$this->assertEquals('API timeout', $data['data']['reason']);
	}

	public function testIndexMailboxesWithGenericException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getMailboxes')
			->willThrowException(new \Exception('Unexpected error'));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$this->logger->expects($this->atLeastOnce())
			->method('error')
			->with('Unexpected error listing mailboxes', $this->anything());

		$response = $this->controller->indexMailboxes('test-provider');

		$data = $response->getData();
		$this->assertEquals('error', $data['status']);
		$this->assertStringContainsString('Could not list mailboxes', $data['message']);
	}

	public function testIndexMailboxesSanitizesErrorMessagesWithUrls(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($user);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getMailboxes')
			->willThrowException(new ServiceException('API error at https://api.internal.example.com/v1/mailboxes', 500));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->indexMailboxes('test-provider');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertStringContainsString('https://[SERVER]/v1/mailboxes', $data['data']['message']);
		$this->assertStringNotContainsString('api.internal.example.com', $data['data']['message']);
	}

	public function testDestroyMailboxWithNoUserSession(): void {
		$this->userSession->method('getUser')
			->willReturn(null);

		$this->request->method('getParam')
			->with('email')
			->willReturn('test@example.com');

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
	}

	public function testDestroyMailboxWithProviderNotFound(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('email')
			->willReturn('test@example.com');

		$this->providerRegistry->method('getProvider')
			->with('nonexistent')
			->willReturn(null);

		$response = $this->controller->destroyMailbox('nonexistent', 'testuser');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('PROVIDER_NOT_FOUND', $data['data']['error']);
	}

	public function testDestroyMailboxWithMissingEmail(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('email')
			->willReturn(null);

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('INVALID_PARAMETERS', $data['data']['error']);
		$this->assertEquals('Email parameter is required', $data['data']['message']);
	}

	public function testDestroyMailboxWithEmptyEmail(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('email')
			->willReturn('');

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('INVALID_PARAMETERS', $data['data']['error']);
		$this->assertEquals('Email parameter is required', $data['data']['message']);
	}

	public function testDestroyMailboxWithInvalidEmailFormat(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('email')
			->willReturn('not-an-email');

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('INVALID_PARAMETERS', $data['data']['error']);
		$this->assertEquals('Invalid email format', $data['data']['message']);
	}

	public function testDestroyMailboxSuccessWithoutMailAppAccount(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('email')
			->willReturn('test@example.com');

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('deleteAccount')
			->with('testuser', 'test@example.com')
			->willReturn(true);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		// No mail app account exists
		$this->accountService->method('findByUserIdAndAddress')
			->with('testuser', 'test@example.com')
			->willReturn([]);

		$this->logger->expects($this->exactly(2))
			->method('info')
			->withConsecutive(
				['Deleting mailbox', $this->anything()],
				['Mailbox deleted successfully', $this->callback(function ($context) {
					return $context['userId'] === 'testuser' && $context['deletedMailAppAccount'] === false;
				})]
			);

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertTrue($data['data']['deleted']);
	}

	public function testDestroyMailboxSuccessWithMailAppAccountDeleted(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('email')
			->willReturn('test@example.com');

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('deleteAccount')
			->with('testuser', 'test@example.com')
			->willReturn(true);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		// Mail app account exists
		$mailAccount = new MailAccount();
		$mailAccount->setId(123);
		$mailAccount->setEmail('test@example.com');
		$account = new Account($mailAccount);

		$this->accountService->method('findByUserIdAndAddress')
			->with('testuser', 'test@example.com')
			->willReturn([$account]);

		$this->accountService->expects($this->once())
			->method('delete')
			->with('testuser', 123);

		$this->logger->expects($this->exactly(3))
			->method('info')
			->withConsecutive(
				['Deleting mailbox', $this->anything()],
				['Deleted associated mail app account', $this->callback(function ($context) {
					return $context['userId'] === 'testuser'
						&& $context['accountId'] === 123
						&& $context['email'] === 'test@example.com';
				})],
				['Mailbox deleted successfully', $this->callback(function ($context) {
					return $context['userId'] === 'testuser' && $context['deletedMailAppAccount'] === true;
				})]
			);

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertTrue($data['data']['deleted']);
	}

	public function testDestroyMailboxSuccessWhenMailAppAccountDeletionFails(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('email')
			->willReturn('test@example.com');

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('deleteAccount')
			->with('testuser', 'test@example.com')
			->willReturn(true);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		// Mail app account exists
		$mailAccount = new MailAccount();
		$mailAccount->setId(123);
		$mailAccount->setEmail('test@example.com');
		$account = new Account($mailAccount);

		$this->accountService->method('findByUserIdAndAddress')
			->with('testuser', 'test@example.com')
			->willReturn([$account]);

		// Mail app account deletion fails
		$this->accountService->method('delete')
			->willThrowException(new \Exception('Account deletion failed'));

		// Should log warning but still succeed
		$this->logger->expects($this->once())
			->method('warning')
			->with('Could not delete associated mail app account', $this->anything());

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertTrue($data['data']['deleted']);
	}

	public function testDestroyMailboxSuccessWhenFindingMailAppAccountFails(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('email')
			->willReturn('test@example.com');

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('deleteAccount')
			->with('testuser', 'test@example.com')
			->willReturn(true);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		// Finding mail app account throws exception
		$this->accountService->method('findByUserIdAndAddress')
			->willThrowException(new \Exception('Database error'));

		// Should log warning but still succeed
		$this->logger->expects($this->once())
			->method('warning')
			->with('Could not retrieve mail app account before deletion', $this->anything());

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertTrue($data['data']['deleted']);
	}

	public function testDestroyMailboxWhenProviderDeleteAccountReturnsFalse(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('email')
			->willReturn('test@example.com');

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('deleteAccount')
			->with('testuser', 'test@example.com')
			->willReturn(false);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$this->accountService->method('findByUserIdAndAddress')
			->willReturn([]);

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals('Failed to delete mailbox', $data['data']['message']);
	}

	public function testDestroyMailboxWithServiceException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('email')
			->willReturn('test@example.com');

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('deleteAccount')
			->willThrowException(new ServiceException('Service error', 500));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$this->accountService->method('findByUserIdAndAddress')
			->willReturn([]);

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals(500, $data['data']['statusCode']);
	}

	public function testDestroyMailboxWithProviderServiceException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('email')
			->willReturn('test@example.com');

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('deleteAccount')
			->willThrowException(new ProviderServiceException('Provider error', 503, ['reason' => 'API unavailable']));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$this->accountService->method('findByUserIdAndAddress')
			->willReturn([]);

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals(503, $data['data']['statusCode']);
		$this->assertEquals('API unavailable', $data['data']['reason']);
	}

	public function testDestroyMailboxWithGenericException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('email')
			->willReturn('test@example.com');

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('deleteAccount')
			->willThrowException(new \Exception('Unexpected error'));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$this->accountService->method('findByUserIdAndAddress')
			->willReturn([]);

		$this->logger->expects($this->atLeastOnce())
			->method('error')
			->with('Unexpected error deleting mailbox', $this->anything());

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$data = $response->getData();
		$this->assertEquals('error', $data['status']);
		$this->assertStringContainsString('Could not delete mailbox', $data['message']);
	}

	public function testDestroyMailboxSanitizesErrorMessagesWithUrls(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->with('email')
			->willReturn('test@example.com');

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('deleteAccount')
			->willThrowException(new ServiceException('API error at https://api.internal.example.com/v1/delete', 500));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$this->accountService->method('findByUserIdAndAddress')
			->willReturn([]);

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertStringContainsString('https://[SERVER]/v1/delete', $data['data']['message']);
		$this->assertStringNotContainsString('api.internal.example.com', $data['data']['message']);
	}

	public function testDestroyMailboxWithEncodedEmail(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		// Email with URL-encoded @ symbol
		$this->request->method('getParam')
			->with('email')
			->willReturn('test%40example.com');

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('deleteAccount')
			->with('testuser', 'test@example.com')  // Should be decoded
			->willReturn(true);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$this->accountService->method('findByUserIdAndAddress')
			->with('testuser', 'test@example.com')  // Should be decoded
			->willReturn([]);

		$response = $this->controller->destroyMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertTrue($data['data']['deleted']);
	}

	public function testUpdateMailboxWithNoUserSession(): void {
		$this->userSession->method('getUser')
			->willReturn(null);

		$this->request->method('getParams')
			->willReturn(['localpart' => 'newuser']);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
	}

	public function testUpdateMailboxWithProviderNotFound(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['localpart' => 'newuser']);

		$this->providerRegistry->method('getProvider')
			->with('nonexistent')
			->willReturn(null);

		$response = $this->controller->updateMailbox('nonexistent', 'testuser');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('PROVIDER_NOT_FOUND', $data['data']['error']);
	}

	public function testUpdateMailboxWithEmptyDisplayName(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'mailAppAccountName' => '',
			]);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('INVALID_PARAMETERS', $data['data']['error']);
		$this->assertEquals('Display name cannot be empty', $data['data']['message']);
	}

	public function testUpdateMailboxWithWhitespaceOnlyDisplayName(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'mailAppAccountName' => '   ',
			]);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('INVALID_PARAMETERS', $data['data']['error']);
		$this->assertEquals('Display name cannot be empty', $data['data']['message']);
	}

	public function testUpdateMailboxWithEmptyLocalpart(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'localpart' => '',
			]);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('INVALID_PARAMETERS', $data['data']['error']);
		$this->assertEquals('Localpart cannot be empty', $data['data']['message']);
	}

	public function testUpdateMailboxWithWhitespaceOnlyLocalpart(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'localpart' => '   ',
			]);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('INVALID_PARAMETERS', $data['data']['error']);
		$this->assertEquals('Localpart cannot be empty', $data['data']['message']);
	}

	/**
	 * @dataProvider invalidLocalpartProvider
	 */
	public function testUpdateMailboxWithInvalidLocalpartCharacters(string $localpart): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'localpart' => $localpart,
			]);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('INVALID_PARAMETERS', $data['data']['error']);
		$this->assertEquals('Localpart contains invalid characters', $data['data']['message']);
	}

	public static function invalidLocalpartProvider(): array {
		return [
			'with @ symbol' => ['user@domain'],
			'with space' => ['user name'],
			'with exclamation' => ['user!'],
			'with hash' => ['user#123'],
			'with percent' => ['user%20'],
			'with ampersand' => ['user&name'],
			'with asterisk' => ['user*'],
			'with plus' => ['user+tag'],
			'with equals' => ['user=name'],
		];
	}

	/**
	 * @dataProvider validLocalpartProvider
	 */
	public function testUpdateMailboxWithValidLocalpartFormats(string $localpart): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'localpart' => $localpart,
			]);

		$updatedMailbox = new MailboxInfo(
			userId: 'testuser',
			email: $localpart . '@example.com',
			userExists: true,
			mailAppAccountId: null,
			mailAppAccountName: null,
			mailAppAccountExists: false,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getProvisionedEmail')
			->with('testuser')
			->willReturn('olduser@example.com');
		$provider->method('updateMailbox')
			->with('testuser', 'olduser@example.com', $localpart)
			->willReturn($updatedMailbox);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getDisplayName')->willReturn('Test User');

		$this->userManager->method('get')
			->with('testuser')
			->willReturn($mockUser);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertEquals($localpart . '@example.com', $data['data']['email']);
	}

	public static function validLocalpartProvider(): array {
		return [
			'simple alphanumeric' => ['user123'],
			'with dot' => ['user.name'],
			'with hyphen' => ['user-name'],
			'with underscore' => ['user_name'],
			'multiple special chars' => ['user.name-123_test'],
			'starting with number' => ['123user'],
			'all uppercase' => ['USERNAME'],
			'mixed case' => ['UserName'],
		];
	}

	public function testUpdateMailboxWithLocalpartOnly(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'localpart' => 'newuser',
			]);

		$updatedMailbox = new MailboxInfo(
			userId: 'testuser',
			email: 'newuser@example.com',
			userExists: true,
			mailAppAccountId: null,
			mailAppAccountName: null,
			mailAppAccountExists: false,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getProvisionedEmail')
			->with('testuser')
			->willReturn('olduser@example.com');
		$provider->method('updateMailbox')
			->with('testuser', 'olduser@example.com', 'newuser')
			->willReturn($updatedMailbox);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getDisplayName')->willReturn('Test User');

		$this->userManager->method('get')
			->with('testuser')
			->willReturn($mockUser);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertEquals('newuser@example.com', $data['data']['email']);
		$this->assertEquals('testuser', $data['data']['userId']);
		$this->assertEquals('Test User', $data['data']['userName']);
	}

	public function testUpdateMailboxWithDisplayNameOnly(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'mailAppAccountName' => 'New Display Name',
			]);

		$mailAccount = new MailAccount();
		$mailAccount->setId(123);
		$mailAccount->setEmail('test@example.com');
		$mailAccount->setName('Old Name');
		$account = new Account($mailAccount);

		$updatedMailbox = new MailboxInfo(
			userId: 'testuser',
			email: 'test@example.com',
			userExists: true,
			mailAppAccountId: 123,
			mailAppAccountName: 'Old Name',
			mailAppAccountExists: true,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getProvisionedEmail')
			->with('testuser')
			->willReturn('test@example.com');
		$provider->method('updateMailbox')
			->with('testuser', 'test@example.com', '')
			->willReturn($updatedMailbox);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$this->accountService->method('find')
			->with('testuser', 123)
			->willReturn($account);

		$updatedMailAccount = new MailAccount();
		$updatedMailAccount->setId(123);
		$updatedMailAccount->setEmail('test@example.com');
		$updatedMailAccount->setName('New Display Name');

		$this->accountService->method('update')
			->willReturn($updatedMailAccount);

		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getDisplayName')->willReturn('Test User');

		$this->userManager->method('get')
			->with('testuser')
			->willReturn($mockUser);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertEquals('test@example.com', $data['data']['email']);
		$this->assertEquals('New Display Name', $data['data']['mailAppAccountName']);
	}

	public function testUpdateMailboxWithBothLocalpartAndDisplayName(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'localpart' => 'newuser',
				'mailAppAccountName' => 'New Display Name',
			]);

		$mailAccount = new MailAccount();
		$mailAccount->setId(123);
		$mailAccount->setEmail('newuser@example.com');
		$mailAccount->setName('Old Name');
		$account = new Account($mailAccount);

		$updatedMailbox = new MailboxInfo(
			userId: 'testuser',
			email: 'newuser@example.com',
			userExists: true,
			mailAppAccountId: 123,
			mailAppAccountName: 'Old Name',
			mailAppAccountExists: true,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getProvisionedEmail')
			->with('testuser')
			->willReturn('olduser@example.com');
		$provider->method('updateMailbox')
			->with('testuser', 'olduser@example.com', 'newuser')
			->willReturn($updatedMailbox);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$this->accountService->method('find')
			->with('testuser', 123)
			->willReturn($account);

		$updatedMailAccount = new MailAccount();
		$updatedMailAccount->setId(123);
		$updatedMailAccount->setEmail('newuser@example.com');
		$updatedMailAccount->setName('New Display Name');

		$this->accountService->method('update')
			->willReturn($updatedMailAccount);

		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getDisplayName')->willReturn('Test User');

		$this->userManager->method('get')
			->with('testuser')
			->willReturn($mockUser);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertEquals('newuser@example.com', $data['data']['email']);
		$this->assertEquals('New Display Name', $data['data']['mailAppAccountName']);
	}

	public function testUpdateMailboxWithoutMailAppAccount(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'localpart' => 'newuser',
				'mailAppAccountName' => 'New Display Name',
			]);

		$updatedMailbox = new MailboxInfo(
			userId: 'testuser',
			email: 'newuser@example.com',
			userExists: true,
			mailAppAccountId: null,
			mailAppAccountName: null,
			mailAppAccountExists: false,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getProvisionedEmail')
			->with('testuser')
			->willReturn('olduser@example.com');
		$provider->method('updateMailbox')
			->with('testuser', 'olduser@example.com', 'newuser')
			->willReturn($updatedMailbox);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		// accountService should not be called since mailAppAccountId is null
		$this->accountService->expects($this->never())
			->method('find');

		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getDisplayName')->willReturn('Test User');

		$this->userManager->method('get')
			->with('testuser')
			->willReturn($mockUser);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertEquals('newuser@example.com', $data['data']['email']);
		// mailAppAccountName should be null since no mail account exists
		$this->assertNull($data['data']['mailAppAccountName']);
	}

	public function testUpdateMailboxWithServiceException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'localpart' => 'newuser',
			]);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getProvisionedEmail')
			->with('testuser')
			->willReturn('olduser@example.com');
		$provider->method('updateMailbox')
			->willThrowException(new ServiceException('Service error', 500));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals(500, $data['data']['statusCode']);
	}

	public function testUpdateMailboxWithProviderServiceException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'localpart' => 'newuser',
			]);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getProvisionedEmail')
			->with('testuser')
			->willReturn('olduser@example.com');
		$provider->method('updateMailbox')
			->willThrowException(new ProviderServiceException('Provider error', 503, ['reason' => 'API unavailable']));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals(503, $data['data']['statusCode']);
		$this->assertEquals('API unavailable', $data['data']['reason']);
	}

	public function testUpdateMailboxWithInvalidArgumentException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'localpart' => 'newuser',
			]);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getProvisionedEmail')
			->with('testuser')
			->willReturn('olduser@example.com');
		$provider->method('updateMailbox')
			->willThrowException(new \InvalidArgumentException('Invalid localpart format'));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('INVALID_PARAMETERS', $data['data']['error']);
		$this->assertEquals('Invalid localpart format', $data['data']['message']);
	}

	public function testUpdateMailboxWithGenericException(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'localpart' => 'newuser',
			]);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getProvisionedEmail')
			->with('testuser')
			->willReturn('olduser@example.com');
		$provider->method('updateMailbox')
			->willThrowException(new \Exception('Unexpected error'));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$this->logger->expects($this->atLeastOnce())
			->method('error')
			->with('Unexpected error updating mailbox', $this->anything());

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$data = $response->getData();
		$this->assertEquals('error', $data['status']);
		$this->assertStringContainsString('Could not update mailbox', $data['message']);
	}

	public function testUpdateMailboxWhenMailAccountUpdateFails(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'localpart' => 'newuser',
				'mailAppAccountName' => 'New Display Name',
			]);

		$updatedMailbox = new MailboxInfo(
			userId: 'testuser',
			email: 'newuser@example.com',
			userExists: true,
			mailAppAccountId: 123,
			mailAppAccountName: 'Old Name',
			mailAppAccountExists: true,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getProvisionedEmail')
			->with('testuser')
			->willReturn('olduser@example.com');
		$provider->method('updateMailbox')
			->with('testuser', 'olduser@example.com', 'newuser')
			->willReturn($updatedMailbox);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		// Mail account update fails
		$this->accountService->method('find')
			->willThrowException(new \Exception('Account not found'));

		$this->logger->expects($this->once())
			->method('warning')
			->with('Could not update mail account display name', $this->anything());

		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getDisplayName')->willReturn('Test User');

		$this->userManager->method('get')
			->with('testuser')
			->willReturn($mockUser);

		// Should still succeed even if mail account update fails
		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertEquals('newuser@example.com', $data['data']['email']);
		// Display name should remain unchanged since update failed
		$this->assertEquals('Old Name', $data['data']['mailAppAccountName']);
	}

	public function testUpdateMailboxCleansRequestParams(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')
			->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'providerId' => 'test-provider',
				'userId' => 'testuser',
				'_route' => 'some-route',
				'localpart' => 'newuser',
				'otherParam' => 'value',
			]);

		$updatedMailbox = new MailboxInfo(
			userId: 'testuser',
			email: 'newuser@example.com',
			userExists: true,
			mailAppAccountId: null,
			mailAppAccountName: null,
			mailAppAccountExists: false,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('isEnabled')
			->willReturn(true);
		$provider->method('getProvisionedEmail')
			->with('testuser')
			->willReturn('olduser@example.com');
		// Verify that only userId, currentEmail, and newLocalpart are passed
		$provider->method('updateMailbox')
			->with('testuser', 'olduser@example.com', 'newuser')
			->willReturn($updatedMailbox);

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getDisplayName')->willReturn('Test User');

		$this->userManager->method('get')
			->with('testuser')
			->willReturn($mockUser);

		$response = $this->controller->updateMailbox('test-provider', 'testuser');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}
}
