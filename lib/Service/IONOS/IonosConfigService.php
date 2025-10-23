<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\IONOS;

use OCA\Mail\AppInfo\Application;
use OCP\Exceptions\AppConfigException;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for managing IONOS API configuration
 */
class IonosConfigService {
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
}
