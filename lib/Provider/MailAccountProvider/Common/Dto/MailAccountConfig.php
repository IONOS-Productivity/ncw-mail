<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider\Common\Dto;

/**
 * Data transfer object for complete mail account configuration
 *
 * Generic DTO that can be used by any mail account provider (IONOS, Office365, Google, etc.)
 */
class MailAccountConfig {
	public function __construct(
		private readonly string $email,
		private readonly MailServerConfig $imap,
		private readonly MailServerConfig $smtp,
	) {
	}

	public function getEmail(): string {
		return $this->email;
	}

	public function getImap(): MailServerConfig {
		return $this->imap;
	}

	public function getSmtp(): MailServerConfig {
		return $this->smtp;
	}

	/**
	 * Create a new instance with updated passwords for both IMAP and SMTP
	 *
	 * @param string $newPassword The new password to use
	 * @return self New instance with updated passwords
	 */
	public function withPassword(string $newPassword): self {
		return new self(
			email: $this->email,
			imap: $this->imap->withPassword($newPassword),
			smtp: $this->smtp->withPassword($newPassword),
		);
	}

	/**
	 * Convert to array format for backwards compatibility
	 *
	 * @return array{email: string, imap: array, smtp: array}
	 */
	public function toArray(): array {
		return [
			'email' => $this->email,
			'imap' => $this->imap->toArray(),
			'smtp' => $this->smtp->toArray(),
		];
	}
}
