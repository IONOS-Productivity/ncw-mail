<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Command;

use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Abstract base class for provider-related commands
 * Provides common validation and helper methods to reduce code duplication
 */
abstract class ProviderCommandBase extends Command {
	public const OUTPUT_FORMAT_PLAIN = 'plain';
	public const OUTPUT_FORMAT_JSON = 'json';
	public const OUTPUT_FORMAT_JSON_PRETTY = 'json_pretty';

	public function __construct(
		protected ProviderRegistryService $providerRegistry,
		protected IUserManager $userManager,
	) {
		parent::__construct();
	}

	/**
	 * Add output format option to command configuration
	 */
	protected function addOutputOption(): void {
		$this->addOption(
			'output',
			null,
			InputOption::VALUE_REQUIRED,
			'Output format: plain, json, json_pretty',
			self::OUTPUT_FORMAT_PLAIN
		);
	}

	/**
	 * Get the output format from input
	 */
	protected function getOutputFormat(InputInterface $input, OutputInterface $output): string {
		$format = $input->getOption('output') ?? self::OUTPUT_FORMAT_PLAIN;
		if (!in_array($format, [self::OUTPUT_FORMAT_PLAIN, self::OUTPUT_FORMAT_JSON, self::OUTPUT_FORMAT_JSON_PRETTY])) {
			$output->writeln(sprintf('<error>Invalid output format: %s</error>', $format));
			$output->writeln(sprintf('<comment>Valid formats: %s, %s, %s</comment>', self::OUTPUT_FORMAT_PLAIN, self::OUTPUT_FORMAT_JSON, self::OUTPUT_FORMAT_JSON_PRETTY));
			return self::OUTPUT_FORMAT_PLAIN;
		}
		return $format;
	}

	/**
	 * Output data in the requested format
	 *
	 * @param array<string, mixed> $data
	 */
	protected function outputData(array $data, string $format, OutputInterface $output): void {
		if ($format === self::OUTPUT_FORMAT_JSON) {
			$output->writeln(json_encode($data));
		} elseif ($format === self::OUTPUT_FORMAT_JSON_PRETTY) {
			$output->writeln(json_encode($data, JSON_PRETTY_PRINT));
		}
		// For plain format, commands handle their own output
	}

	/**
	 * Get a provider by ID or display error and return null
	 */
	protected function getProviderOrFail(string $providerId, OutputInterface $output): ?IMailAccountProvider {
		$provider = $this->providerRegistry->getProvider($providerId);
		if ($provider === null) {
			$output->writeln(sprintf('<error>Provider "%s" not found.</error>', $providerId));
			$output->writeln('');
			$output->writeln('Available providers:');
			foreach ($this->providerRegistry->getAllProviders() as $p) {
				$output->writeln(sprintf('  - %s (%s)', $p->getId(), $p->getName()));
			}
		}
		return $provider;
	}

	/**
	 * Validate that a user exists or display error
	 *
	 * @return bool true if user exists, false otherwise
	 */
	protected function validateUserExists(string $userId, OutputInterface $output): bool {
		if (!$this->userManager->userExists($userId)) {
			$output->writeln(sprintf('<error>User "%s" does not exist.</error>', $userId));
			return false;
		}
		return true;
	}

	/**
	 * Check if provider is enabled or display error
	 *
	 * @return bool true if provider is enabled, false otherwise
	 */
	protected function checkProviderEnabled(IMailAccountProvider $provider, OutputInterface $output): bool {
		if (!$provider->isEnabled()) {
			$output->writeln(sprintf('<error>Provider "%s" is not enabled.</error>', $provider->getId()));
			$output->writeln('<comment>Check configuration and ensure provider is properly configured.</comment>');
			return false;
		}
		return true;
	}

	/**
	 * Check if user can create a new provider account
	 *
	 * User can create account if no existing Nextcloud account blocks creation.
	 * This aligns with frontend mail-providers-available logic.
	 *
	 * @return bool true if user can create account, false otherwise
	 */
	protected function checkCanCreateAccount(
		IMailAccountProvider $provider,
		string $userId,
		OutputInterface $output,
	): bool {
		$existingEmail = $provider->getExistingAccountEmail($userId);
		if ($existingEmail !== null) {
			$output->writeln(sprintf(
				'<error>Cannot create account for user "%s".</error>',
				$userId
			));
			$output->writeln('');
			$output->writeln(sprintf('<comment>User already has a Nextcloud account: %s</comment>', $existingEmail));
			$output->writeln('<comment>To create a provider account, delete the existing account first.</comment>');
			return false;
		}
		return true;
	}

	/**
	 * Get provisioned email for user or display error and return null
	 */
	protected function getProvisionedEmailOrFail(
		IMailAccountProvider $provider,
		string $userId,
		OutputInterface $output,
	): ?string {
		$provisionedEmail = $provider->getProvisionedEmail($userId);
		if ($provisionedEmail === null) {
			$output->writeln(sprintf(
				'<error>User "%s" does not have a provisioned account with provider "%s".</error>',
				$userId,
				$provider->getId()
			));
		}
		return $provisionedEmail;
	}
}
