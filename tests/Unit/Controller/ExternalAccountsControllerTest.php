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
use OCA\Mail\Exception\IonosServiceException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\ProviderCapabilities;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
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
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private ExternalAccountsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->providerRegistry = $this->createMock(ProviderRegistryService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ExternalAccountsController(
			$this->appName,
			$this->request,
			$this->providerRegistry,
			$this->userSession,
			$this->logger,
		);
	}

	public function testCreateWithNoUserSession(): void {
		$this->userSession->method('getUser')
			->willReturn(null);

		$response = $this->controller->create('test-provider');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
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

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->create('test-provider');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('PROVIDER_NOT_AVAILABLE', $data['data']['error']);
		$this->assertStringContainsString('not available for this user', $data['data']['message']);
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

		$response = $this->controller->create('test-provider');

		$this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
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

		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
	}

	public function testCreateWithIonosServiceException(): void {
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
			->willThrowException(new IonosServiceException('IONOS error', 503, null, ['detail' => 'API unavailable']));

		$this->providerRegistry->method('getProvider')
			->with('test-provider')
			->willReturn($provider);

		$response = $this->controller->create('test-provider');

		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('SERVICE_ERROR', $data['data']['error']);
		$this->assertEquals('API unavailable', $data['data']['detail']);
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
}
