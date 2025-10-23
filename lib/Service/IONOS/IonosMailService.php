<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\IONOS;

use OCA\Mail\Exception\ServiceException;

/**
 * Service for managing IONOS email account creation
 */
class IonosMailService {
	public function __construct() {
	}

	/**
	 * Create an IONOS email account via API
	 *
	 * @param string $emailAddress
	 * @return array|null Response with mail configuration
	 * @throws ServiceException
	 */
	public function createEmailAccount(string $emailAddress): ?array {
		$atPosition = strrchr($emailAddress, '@');
		if ($atPosition === false) {
			throw new ServiceException('Invalid email address: unable to extract domain');
		}
		$domain = substr($atPosition, 1);
		if ($domain === '') {
			throw new ServiceException('Invalid email address: unable to extract domain');
		}
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
	}
}
