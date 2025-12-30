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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command to generate an app password for a provider-managed account
 */
final class ProviderGenerateAppPassword extends ProviderCommandBase {
	public const ARGUMENT_PROVIDER_ID = 'provider-id';
	public const ARGUMENT_USER_ID = 'user-id';

	public function __construct(
		ProviderRegistryService $providerRegistry,
		IUserManager $userManager,
	) {
		parent::__construct($providerRegistry, $userManager);
	}

	protected function configure(): void {
		$this->setName('mail:provider:generate-app-password');
		$this->setDescription('Generate a new app password for a provider-managed mail account');
		$this->addArgument(
			self::ARGUMENT_PROVIDER_ID,
			InputArgument::REQUIRED,
			'Provider ID (e.g., "ionos")'
		);
		$this->addArgument(
			self::ARGUMENT_USER_ID,
			InputArgument::REQUIRED,
			'User ID to generate app password for'
		);
		$this->addOutputOption();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$providerId = $input->getArgument(self::ARGUMENT_PROVIDER_ID);
		$userId = $input->getArgument(self::ARGUMENT_USER_ID);
		$outputFormat = $this->getOutputFormat($input, $output);

		$provider = $this->getProviderOrFail($providerId, $output);
		if ($provider === null) {
			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$this->outputData(['error' => sprintf('Provider "%s" not found', $providerId)], $outputFormat, $output);
			}
			return 1;
		}

		if (!$this->validateUserExists($userId, $output)) {
			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$this->outputData(['error' => sprintf('User "%s" not found', $userId)], $outputFormat, $output);
			}
			return 1;
		}

		if (!$this->checkAppPasswordSupport($provider, $output, $outputFormat)) {
			return 1;
		}

		if (!$this->checkProviderEnabled($provider, $output)) {
			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$this->outputData(['error' => sprintf('Provider "%s" is not enabled', $providerId)], $outputFormat, $output);
			}
			return 1;
		}

		$provisionedEmail = $this->getProvisionedEmailOrFail($provider, $userId, $output);
		if ($provisionedEmail === null) {
			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$this->outputData(['error' => 'No provisioned account found for this user'], $outputFormat, $output);
			} else {
				$output->writeln('<comment>Create an account first using mail:provider:create-account</comment>');
			}
			return 1;
		}

		return $this->generateAndDisplayAppPassword($provider, $userId, $provisionedEmail, $output, $outputFormat);
	}

	/**
	 * Check if provider supports app password generation
	 */
	protected function checkAppPasswordSupport(IMailAccountProvider $provider, OutputInterface $output, string $outputFormat): bool {
		$capabilities = $provider->getCapabilities();
		if (!$capabilities->supportsAppPasswords()) {
			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$this->outputData([
					'error' => sprintf('Provider "%s" does not support app password generation', $provider->getId()),
				], $outputFormat, $output);
			} else {
				$output->writeln(sprintf(
					'<error>Provider "%s" does not support app password generation.</error>',
					$provider->getId()
				));
			}
			return false;
		}
		return true;
	}

	/**
	 * Generate app password and display result
	 */
	protected function generateAndDisplayAppPassword(
		IMailAccountProvider $provider,
		string $userId,
		string $provisionedEmail,
		OutputInterface $output,
		string $outputFormat,
	): int {
		if ($outputFormat === self::OUTPUT_FORMAT_PLAIN) {
			$output->writeln(sprintf(
				'<info>Generating app password for user "%s" (email: %s)...</info>',
				$userId,
				$provisionedEmail
			));
			$output->writeln('');
		}

		try {
			$appPassword = $provider->generateAppPassword($userId);

			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$this->outputData([
					'success' => true,
					'appPassword' => $appPassword,
					'userId' => $userId,
					'email' => $provisionedEmail,
				], $outputFormat, $output);
			} else {
				$output->writeln('<info>App password generated successfully!</info>');
				$output->writeln('');
				$output->writeln(sprintf('<comment>New App Password: %s</comment>', $appPassword));
				$output->writeln('');
				$output->writeln('<comment>IMPORTANT: This password will only be shown once. Make sure to save it securely.</comment>');
				$output->writeln('<comment>The mail account in Nextcloud has been automatically updated with the new password.</comment>');
				$output->writeln('');
			}
			return 0;
		} catch (\Exception $e) {
			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$this->outputData(['error' => $e->getMessage()], $outputFormat, $output);
			} else {
				$output->writeln('<error>Failed to generate app password:</error>');
				$output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
			}
			return 1;
		}
	}
}
