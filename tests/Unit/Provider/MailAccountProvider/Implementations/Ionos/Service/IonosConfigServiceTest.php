<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Provider\MailAccountProvider\Implementations\Ionos\Service;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\AppInfo\Application;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosConfigService;
use OCP\Exceptions\AppConfigException;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosConfigServiceTest extends TestCase {
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;
	private IonosConfigService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new IonosConfigService(
			$this->config,
			$this->appConfig,
			$this->logger,
		);
	}

	public function testAppNameConstantExists(): void {
		$this->assertSame('NEXTCLOUD_WORKSPACE', IonosConfigService::APP_NAME);
	}

	public function testAppNameUserConstantExists(): void {
		$this->assertSame('NEXTCLOUD_WORKSPACE_USER', IonosConfigService::APP_PASSWORD_NAME_USER);
	}

	public function testGetExternalReferenceSuccess(): void {
		$this->config->method('getSystemValue')
			->with('ncw.ext_ref')
			->willReturn('test-ext-ref');

		$result = $this->service->getExternalReference();
		$this->assertEquals('test-ext-ref', $result);
	}

	public function testGetExternalReferenceMissing(): void {
		$this->config->method('getSystemValue')
			->with('ncw.ext_ref')
			->willReturn('');

		$this->logger->expects($this->once())
			->method('error')
			->with('No external reference is configured');

		$this->expectException(AppConfigException::class);
		$this->expectExceptionMessage('No external reference configured');

		$this->service->getExternalReference();
	}

	public function testGetApiBaseUrlSuccess(): void {
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, 'ionos_mailconfig_api_base_url')
			->willReturn('https://api.example.com');

		$result = $this->service->getApiBaseUrl();
		$this->assertEquals('https://api.example.com', $result);
	}

	public function testGetApiBaseUrlMissing(): void {
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, 'ionos_mailconfig_api_base_url')
			->willReturn('');

		$this->logger->expects($this->once())
			->method('error')
			->with('No mailconfig service url is configured');

		$this->expectException(AppConfigException::class);
		$this->expectExceptionMessage('No mailconfig service url configured');

		$this->service->getApiBaseUrl();
	}

	public function testGetAllowInsecure(): void {
		$this->appConfig->method('getValueBool')
			->with(Application::APP_ID, 'ionos_mailconfig_api_allow_insecure', false)
			->willReturn(true);

		$result = $this->service->getAllowInsecure();
		$this->assertTrue($result);
	}

	public function testGetBasicAuthUserSuccess(): void {
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, 'ionos_mailconfig_api_auth_user')
			->willReturn('testuser');

		$result = $this->service->getBasicAuthUser();
		$this->assertEquals('testuser', $result);
	}

	public function testGetBasicAuthUserMissing(): void {
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, 'ionos_mailconfig_api_auth_user')
			->willReturn('');

		$this->logger->expects($this->once())
			->method('error')
			->with('No mailconfig service user is configured');

		$this->expectException(AppConfigException::class);
		$this->expectExceptionMessage('No mailconfig service user configured');

		$this->service->getBasicAuthUser();
	}

	public function testGetBasicAuthPasswordSuccess(): void {
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, 'ionos_mailconfig_api_auth_pass')
			->willReturn('testpass');

		$result = $this->service->getBasicAuthPassword();
		$this->assertEquals('testpass', $result);
	}

	public function testGetBasicAuthPasswordMissing(): void {
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, 'ionos_mailconfig_api_auth_pass')
			->willReturn('');

		$this->logger->expects($this->once())
			->method('error')
			->with('No mailconfig service password is configured');

		$this->expectException(AppConfigException::class);
		$this->expectExceptionMessage('No mailconfig service password configured');

		$this->service->getBasicAuthPassword();
	}

	public function testGetApiConfigSuccess(): void {
		$this->config->method('getSystemValue')
			->with('ncw.ext_ref')
			->willReturn('test-ext-ref');

		$this->appConfig->method('getValueString')
			->willReturnCallback(function ($appId, $key) {
				$values = [
					'ionos_mailconfig_api_base_url' => 'https://api.example.com',
					'ionos_mailconfig_api_auth_user' => 'testuser',
					'ionos_mailconfig_api_auth_pass' => 'testpass',
				];
				return $values[$key] ?? '';
			});

		$this->appConfig->method('getValueBool')
			->with(Application::APP_ID, 'ionos_mailconfig_api_allow_insecure', false)
			->willReturn(false);

		$result = $this->service->getApiConfig();

		$this->assertEquals([
			'extRef' => 'test-ext-ref',
			'apiBaseUrl' => 'https://api.example.com',
			'allowInsecure' => false,
			'basicAuthUser' => 'testuser',
			'basicAuthPass' => 'testpass',
		], $result);
	}

	public function testGetMailDomainWithValidDomain(): void {
		$this->config->method('getSystemValue')
			->with('ncw.customerDomain', '')
			->willReturn('mail.example.com');

		$result = $this->service->getMailDomain();
		$this->assertEquals('example.com', $result);
	}

	public function testGetMailDomainWithEmptyDomain(): void {
		$this->config->method('getSystemValue')
			->with('ncw.customerDomain', '')
			->willReturn('');

		$result = $this->service->getMailDomain();
		$this->assertEquals('', $result);
	}

	public function testGetMailDomainWithMultiLevelTld(): void {
		$this->config->method('getSystemValue')
			->with('ncw.customerDomain', '')
			->willReturn('mail.test.co.uk');

		$result = $this->service->getMailDomain();
		$this->assertEquals('test.co.uk', $result);
	}

	public function testGetMailDomainWithSubdomain(): void {
		$this->config->method('getSystemValue')
			->with('ncw.customerDomain', '')
			->willReturn('foo.bar.lol');

		$result = $this->service->getMailDomain();
		$this->assertEquals('bar.lol', $result);
	}

	public function testGetMailDomainWithSimpleDomain(): void {
		$this->config->method('getSystemValue')
			->with('ncw.customerDomain', '')
			->willReturn('example.com');

		$result = $this->service->getMailDomain();
		$this->assertEquals('example.com', $result);
	}

	public function testIsMailConfigEnabledWhenEnabled(): void {
		$this->config->method('getAppValue')
			->with('mail', 'ionos-mailconfig-enabled', 'no')
			->willReturn('yes');

		$result = $this->service->isMailConfigEnabled();
		$this->assertTrue($result);
	}

	public function testIsMailConfigEnabledWhenDisabled(): void {
		$this->config->method('getAppValue')
			->with('mail', 'ionos-mailconfig-enabled', 'no')
			->willReturn('no');

		$result = $this->service->isMailConfigEnabled();
		$this->assertFalse($result);
	}

	public function testIsIonosIntegrationEnabledWhenFullyConfigured(): void {
		$this->config->method('getAppValue')
			->with('mail', 'ionos-mailconfig-enabled', 'no')
			->willReturn('yes');

		$this->config->method('getSystemValue')
			->with('ncw.ext_ref')
			->willReturn('test-ext-ref');

		$this->appConfig->method('getValueString')
			->willReturnCallback(function ($appId, $key) {
				$values = [
					'ionos_mailconfig_api_base_url' => 'https://api.example.com',
					'ionos_mailconfig_api_auth_user' => 'testuser',
					'ionos_mailconfig_api_auth_pass' => 'testpass',
				];
				return $values[$key] ?? '';
			});

		$this->appConfig->method('getValueBool')
			->with(Application::APP_ID, 'ionos_mailconfig_api_allow_insecure', false)
			->willReturn(false);

		$result = $this->service->isIonosIntegrationEnabled();
		$this->assertTrue($result);
	}

	public function testIsIonosIntegrationEnabledWhenFeatureDisabled(): void {
		$this->config->method('getAppValue')
			->with('mail', 'ionos-mailconfig-enabled', 'no')
			->willReturn('no');

		$result = $this->service->isIonosIntegrationEnabled();
		$this->assertFalse($result);
	}

	public function testIsIonosIntegrationEnabledWhenConfigurationMissing(): void {
		$this->config->method('getAppValue')
			->with('mail', 'ionos-mailconfig-enabled', 'no')
			->willReturn('yes');

		$this->config->method('getSystemValue')
			->with('ncw.ext_ref')
			->willReturn('');

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS integration not available - configuration error', $this->callback(function ($context) {
				return isset($context['exception']) && $context['exception'] instanceof AppConfigException;
			}));

		$result = $this->service->isIonosIntegrationEnabled();
		$this->assertFalse($result);
	}
}
