<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Settings\Section;

use OCA\Mail\Settings\Section\MailProviderAccountsSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class MailProviderAccountsSectionTest extends TestCase {
	private IL10N&MockObject $l;
	private IURLGenerator&MockObject $urlGenerator;
	private MailProviderAccountsSection $section;

	protected function setUp(): void {
		parent::setUp();

		$this->l = $this->createMock(IL10N::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);

		$this->section = new MailProviderAccountsSection(
			$this->l,
			$this->urlGenerator,
		);
	}

	public function testGetID(): void {
		$result = $this->section->getID();

		$this->assertEquals('mail-provider-accounts', $result);
	}

	public function testGetName(): void {
		$this->l->expects($this->once())
			->method('t')
			->with('Email Provider Accounts')
			->willReturn('Email Provider Accounts');

		$result = $this->section->getName();

		$this->assertEquals('Email Provider Accounts', $result);
	}

	public function testGetPriority(): void {
		$result = $this->section->getPriority();

		$this->assertEquals(55, $result);
	}

	public function testGetIcon(): void {
		$this->urlGenerator->expects($this->once())
			->method('imagePath')
			->with('mail', 'mail.svg')
			->willReturn('/apps/mail/img/mail.svg');

		$result = $this->section->getIcon();

		$this->assertEquals('/apps/mail/img/mail.svg', $result);
	}
}
