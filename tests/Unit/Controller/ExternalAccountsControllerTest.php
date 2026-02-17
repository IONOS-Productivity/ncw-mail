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
use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\ProviderCapabilities;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCA\Mail\Service\AccountProviderService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ExternalAccountsControllerTest extends TestCase {
	private string $appName = 'mail';
	private IRequest&MockObject $request;
	private ProviderRegistryService&MockObject $providerRegistry;
	private AccountProviderService&MockObject $accountProviderService;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private ExternalAccountsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->providerRegistry = $this->createMock(ProviderRegistryService::class);
		$this->accountProviderService = $this->createMock(AccountProviderService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ExternalAccountsController(
			$this->appName,
			$this->request,
			$this->providerRegistry,
			$this->accountProviderService,
			$this->userSession,
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
}
