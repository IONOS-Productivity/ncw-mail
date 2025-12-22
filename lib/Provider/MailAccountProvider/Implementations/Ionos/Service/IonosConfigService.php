<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service;

use OCA\Mail\AppInfo\Application;
use OCP\Exceptions\AppConfigException;
use OCP\IAppConfig;
use OCP\IConfig;
use Pdp\Domain;
use Pdp\Rules;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for managing IONOS API configuration
 */
class IonosConfigService {
	/**
	 * Application name used for IONOS app password management
	 */
	public const APP_NAME = 'NEXTCLOUD_WORKSPACE';

	public function __construct(
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Get the external reference from system config
	 *
	 * @throws AppConfigException
	 */
	public function getExternalReference(): string {
		$extRef = $this->config->getSystemValue('ncw.ext_ref');

		if (empty($extRef)) {
			$this->logger->error('No external reference is configured');
			throw new AppConfigException('No external reference configured');
		}

		return $extRef;
	}

	/**
	 * Get the API base URL
	 *
	 * @throws AppConfigException
	 */
	public function getApiBaseUrl(): string {
		$apiBaseUrl = $this->appConfig->getValueString(
			Application::APP_ID,
			'ionos_mailconfig_api_base_url'
		);

		if (empty($apiBaseUrl)) {
			$this->logger->error('No mailconfig service url is configured');
			throw new AppConfigException('No mailconfig service url configured');
		}

		return $apiBaseUrl;
	}

	/**
	 * Get whether to allow insecure connections
	 */
	public function getAllowInsecure(): bool {
		return $this->appConfig->getValueBool(
			Application::APP_ID,
			'ionos_mailconfig_api_allow_insecure',
			false
		);
	}

	/**
	 * Get the basic auth username
	 *
	 * @throws AppConfigException
	 */
	public function getBasicAuthUser(): string {
		$basicAuthUser = $this->appConfig->getValueString(
			Application::APP_ID,
			'ionos_mailconfig_api_auth_user'
		);

		if (empty($basicAuthUser)) {
			$this->logger->error('No mailconfig service user is configured');
			throw new AppConfigException('No mailconfig service user configured');
		}

		return $basicAuthUser;
	}

	/**
	 * Get the basic auth password
	 *
	 * @throws AppConfigException
	 */
	public function getBasicAuthPassword(): string {
		$basicAuthPass = $this->appConfig->getValueString(
			Application::APP_ID,
			'ionos_mailconfig_api_auth_pass'
		);

		if (empty($basicAuthPass)) {
			$this->logger->error('No mailconfig service password is configured');
			throw new AppConfigException('No mailconfig service password configured');
		}

		return $basicAuthPass;
	}

	/**
	 * Validate and retrieve all API configuration
	 *
	 * @return array{extRef: string, apiBaseUrl: string, allowInsecure: bool, basicAuthUser: string, basicAuthPass: string}
	 * @throws AppConfigException
	 */
	public function getApiConfig(): array {
		return [
			'extRef' => $this->getExternalReference(),
			'apiBaseUrl' => $this->getApiBaseUrl(),
			'allowInsecure' => $this->getAllowInsecure(),
			'basicAuthUser' => $this->getBasicAuthUser(),
			'basicAuthPass' => $this->getBasicAuthPassword(),
		];
	}

	/**
	 * Check if IONOS mail configuration feature is enabled
	 */
	public function isMailConfigEnabled(): bool {
		return $this->config->getAppValue('mail', 'ionos-mailconfig-enabled', 'no') === 'yes';
	}

	/**
	 * Check if IONOS integration is fully enabled and configured
	 *
	 * Returns true only if:
	 * 1. The mail config feature is enabled
	 * 2. All required API configuration is valid
	 *
	 * @return bool True if IONOS integration is enabled and configured, false otherwise
	 */
	public function isIonosIntegrationEnabled(): bool {
		try {
			// Check if feature is enabled
			if (!$this->isMailConfigEnabled()) {
				return false;
			}

			// Verify all required API configuration is valid
			$this->getApiConfig();

			return true;
		} catch (AppConfigException $e) {
			// Configuration is missing or invalid
			$this->logger->debug('IONOS integration not available - configuration error', [
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Get the mail domain from customer domain
	 *
	 * Extracts the registrable domain (mail domain) from the customer domain
	 * configured in system settings.
	 */
	public function getMailDomain(): string {
		$customerDomain = $this->config->getSystemValue('ncw.customerDomain', '');
		return $this->extractMailDomain($customerDomain);
	}

	/**
	 * Extract the registrable domain (mail domain) from a customer domain.
	 *
	 * Uses the Public Suffix List via Pdp library to properly extract the
	 * registrable domain, handling multi-level TLDs like .co.uk correctly.
	 *
	 * Examples:
	 * - foo.bar.lol -> bar.lol
	 * - mail.test.co.uk -> test.co.uk
	 * - sub.domain.example.com -> example.com
	 *
	 * @param string $customerDomain The full customer domain
	 * @return string The extracted mail domain, or empty string if input is empty
	 */
	private function extractMailDomain(string $customerDomain): string {
		if (empty($customerDomain)) {
			return '';
		}

		try {
			$publicSuffixList = Rules::fromPath(__DIR__ . '/../../../../../../resources/public_suffix_list.dat');
			$domain = Domain::fromIDNA2008($customerDomain);
			$result = $publicSuffixList->resolve($domain);
			return $result->registrableDomain()->toString();
		} catch (Throwable $e) {
			// Fallback to simple extraction if Pdp fails
			$parts = explode('.', $customerDomain);
			if (count($parts) >= 2) {
				return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
			}
			return $customerDomain;
		}
	}
}
