<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Controller;

use OCA\Mail\Http\TrapError;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class IonosAccountsController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $accountName Display name for the account
	 * @param string $emailAddress Email address to create
	 * @param string $password Password for the new email account
	 *
	 * @return JSONResponse
	 */
	#[TrapError]
	public function create(string $accountName, string $emailAddress, string $password): JSONResponse {
		// Stub implementation for development
		// TODO: Implement actual IONOS API integration

		// Basic validation
		if (empty($accountName) || empty($emailAddress) || empty($password)) {
			return new JSONResponse([
				'status' => 'error',
				'message' => 'All fields are required',
			], 400);
		}

		// Simple stub implementation
		if ($password === '1111') {
			return new JSONResponse([
				'status' => 'success',
				'message' => 'Account created successfully',
			]);
		} else {
			return new JSONResponse([
				'status' => 'error',
				'message' => 'Invalid password. Use "1111" for testing.'
			], 400);
		}
	}
}
