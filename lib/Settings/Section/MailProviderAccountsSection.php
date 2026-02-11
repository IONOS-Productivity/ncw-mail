<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Settings\Section;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Section for mail provider account administration
 */
class MailProviderAccountsSection implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function getID(): string {
		return 'mail-provider-accounts';
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('Email Provider Accounts');
	}

	#[\Override]
	public function getPriority(): int {
		return 55;
	}

	#[\Override]
	public function getIcon(): string {
		return $this->urlGenerator->imagePath('mail', 'mail.svg');
	}
}
