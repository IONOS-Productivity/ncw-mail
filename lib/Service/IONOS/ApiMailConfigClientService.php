<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Mail\Service\IONOS;

use GuzzleHttp\ClientInterface;
use IONOS\MailConfigurationAPI\Client\Api\MailConfigurationAPIApi;

class ApiMailConfigClientService {

	/**
	 * Create a new client
	 *
	 * @param array $config
	 * @return ClientInterface
	 */
	public function newClient(array $config): ClientInterface {
		return new \GuzzleHttp\Client($config);
	}

	/**
	 * Create a new MailConfigurationAPIApi
	 *
	 * @param ClientInterface $client
	 * @param string $apiBaseUrl
	 * @return MailConfigurationAPIApi
	 */
	public function newMailConfigurationAPIApi(ClientInterface $client, string $apiBaseUrl): MailConfigurationAPIApi {

		if (empty($apiBaseUrl)) {
			throw new \InvalidArgumentException('API base URL is required');
		}

		$apiClient = new MailConfigurationAPIApi(
			$client,
		);

		$apiClient->getConfig()->setHost($apiBaseUrl);

		return $apiClient;
	}
}
