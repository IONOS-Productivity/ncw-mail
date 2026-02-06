<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 IONOS SE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Controller;

use OCA\Mail\AppInfo\Application;
use OCA\Mail\Service\MailboxAdminService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for mailbox administration endpoints
 */
class MailboxAdminController extends Controller {
	public function __construct(
		IRequest $request,
		private MailboxAdminService $mailboxAdminService,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * List all IONOS mailboxes with linked users
	 *
	 * @return JSONResponse
	 * @NoCSRFRequired
	 * @AuthorizedAdminSetting(settings=OCA\Mail\Settings\AdminSettings)
	 */
	public function listMailboxes(): JSONResponse {
		try {
			$mailboxes = $this->mailboxAdminService->listAllMailboxes();
			return new JSONResponse([
				'mailboxes' => $mailboxes,
			]);
		} catch (\Exception $e) {
			$this->logger->error('Failed to list mailboxes', [
				'exception' => $e,
			]);
			return new JSONResponse([
				'error' => 'Failed to list mailboxes: ' . $e->getMessage(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update a mailbox email address (change localpart)
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $newLocalpart The new local part of the email (before @)
	 * @return JSONResponse
	 * @NoCSRFRequired
	 * @AuthorizedAdminSetting(settings=OCA\Mail\Settings\AdminSettings)
	 */
	public function updateMailbox(string $userId, string $newLocalpart): JSONResponse {
		try {
			$result = $this->mailboxAdminService->updateMailboxEmail($userId, $newLocalpart);
			
			if ($result['success']) {
				return new JSONResponse([
					'success' => true,
					'email' => $result['email'],
					'message' => 'Mailbox updated successfully',
				]);
			} else {
				return new JSONResponse([
					'success' => false,
					'error' => $result['error'],
				], Http::STATUS_BAD_REQUEST);
			}
		} catch (\Exception $e) {
			$this->logger->error('Failed to update mailbox', [
				'userId' => $userId,
				'exception' => $e,
			]);
			return new JSONResponse([
				'error' => 'Failed to update mailbox: ' . $e->getMessage(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Delete a mailbox
	 *
	 * @param string $userId The Nextcloud user ID
	 * @return JSONResponse
	 * @NoCSRFRequired
	 * @AuthorizedAdminSetting(settings=OCA\Mail\Settings\AdminSettings)
	 */
	public function deleteMailbox(string $userId): JSONResponse {
		try {
			$success = $this->mailboxAdminService->deleteMailbox($userId);
			
			if ($success) {
				return new JSONResponse([
					'success' => true,
					'message' => 'Mailbox deleted successfully',
				]);
			} else {
				return new JSONResponse([
					'success' => false,
					'error' => 'Failed to delete mailbox',
				], Http::STATUS_INTERNAL_SERVER_ERROR);
			}
		} catch (\Exception $e) {
			$this->logger->error('Failed to delete mailbox', [
				'userId' => $userId,
				'exception' => $e,
			]);
			return new JSONResponse([
				'error' => 'Failed to delete mailbox: ' . $e->getMessage(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
