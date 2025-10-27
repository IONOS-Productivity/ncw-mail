<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\IONOS\Dto;

/**
 * Data transfer object for complete mail account configuration
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
