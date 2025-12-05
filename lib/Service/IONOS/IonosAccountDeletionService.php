<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\IONOS;

use OCA\Mail\Db\MailAccount;
use Psr\Log\LoggerInterface;

/**
 * Service for handling IONOS mailbox deletion when mail accounts are deleted
 */
class IonosAccountDeletionService {
	public function __construct(
		private readonly IonosMailService $ionosMailService,
		private readonly IonosConfigService $ionosConfigService,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Check if the mail account is an IONOS account and delete the IONOS mailbox
	 *
	 * This method determines if an account belongs to IONOS by:
	 * 1. Checking if the email domain matches the configured IONOS mail domain
	 * 2. Verifying that the account email matches the IONOS provisioned email for this user
	 *
	 * This two-step verification prevents accidentally deleting the wrong IONOS mailbox
	 * when a user has multiple accounts or manually configured an account with an IONOS domain.
	 *
	 * @param MailAccount $mailAccount The mail account being deleted
	 * @return void
	 */
	public function handleMailAccountDeletion(MailAccount $mailAccount): void {
		// Check if IONOS integration is enabled
		if (!$this->ionosConfigService->isIonosIntegrationEnabled()) {
			return;
		}

		try {
			if (!$this->shouldDeleteIonosMailbox($mailAccount)) {
				return;
			}

			$this->logger->info('Detected IONOS mail account deletion, attempting to delete IONOS mailbox', [
				'email' => $mailAccount->getEmail(),
				'userId' => $mailAccount->getUserId(),
				'accountId' => $mailAccount->getId(),
			]);

			// Use tryDeleteEmailAccount to avoid throwing exceptions
			$this->ionosMailService->tryDeleteEmailAccount($mailAccount->getUserId());
		} catch (\Exception $e) {
			// Log but don't throw - account deletion in Nextcloud should proceed
			$this->logger->error('Error checking/deleting IONOS mailbox during account deletion', [
				'exception' => $e,
				'accountId' => $mailAccount->getId(),
			]);
		}
	}

	/**
	 * Check if the mail account is an IONOS-managed account that should be deleted
	 *
	 * @param MailAccount $mailAccount The mail account to check
	 * @return bool True if this is an IONOS-managed account that should be deleted
	 */
	private function shouldDeleteIonosMailbox(MailAccount $mailAccount): bool {
		$email = $mailAccount->getEmail();
		$userId = $mailAccount->getUserId();
		$accountId = $mailAccount->getId();
		$ionosMailDomain = $this->ionosConfigService->getMailDomain();

		// Check if the account's email domain matches the IONOS mail domain
		if (empty($ionosMailDomain) || !$this->isIonosEmail($email, $ionosMailDomain)) {
			return false;
		}

		// Get the IONOS provisioned email for this user
		$ionosProvisionedEmail = $this->ionosMailService->getIonosEmailForUser($userId);

		// If no IONOS account exists for this user, skip deletion
		if ($ionosProvisionedEmail === null) {
			$this->logger->debug('No IONOS provisioned account found for user, skipping deletion', [
				'email' => $email,
				'userId' => $userId,
				'accountId' => $accountId,
			]);
			return false;
		}

		// Verify that the account being deleted matches the IONOS provisioned email
		if (strcasecmp($email, $ionosProvisionedEmail) !== 0) {
			$this->logger->warning('Mail account email does not match IONOS provisioned email, skipping deletion', [
				'accountEmail' => $email,
				'ionosEmail' => $ionosProvisionedEmail,
				'userId' => $userId,
				'accountId' => $accountId,
			]);
			return false;
		}

		return true;
	}

	/**
	 * Check if an email address belongs to the IONOS mail domain
	 *
	 * @param string $email The email address to check
	 * @param string $ionosMailDomain The IONOS mail domain
	 * @return bool True if the email belongs to the IONOS domain
	 */
	private function isIonosEmail(string $email, string $ionosMailDomain): bool {
		if (empty($email) || empty($ionosMailDomain)) {
			return false;
		}

		// Extract domain from email address
		$atPosition = strrpos($email, '@');
		if ($atPosition === false) {
			return false;
		}

		$emailDomain = substr($email, $atPosition + 1);
		if ($emailDomain === '') {
			return false;
		}

		return strcasecmp($emailDomain, $ionosMailDomain) === 0;
	}
}
