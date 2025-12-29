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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command to create a mail account via an external provider
 */
final class ProviderCreateAccount extends ProviderCommandBase {
	public const ARGUMENT_PROVIDER_ID = 'provider-id';
	public const ARGUMENT_USER_ID = 'user-id';
	public const OPTION_PARAM = 'param';

	public function __construct(
		ProviderRegistryService $providerRegistry,
		IUserManager $userManager,
	) {
		parent::__construct($providerRegistry, $userManager);
	}

	protected function configure(): void {
		$this->setName('mail:provider:create-account');
		$this->setDescription('Create a mail account via an external provider');
		$this->addArgument(
			self::ARGUMENT_PROVIDER_ID,
			InputArgument::REQUIRED,
			'Provider ID (e.g., "ionos")'
		);
		$this->addArgument(
			self::ARGUMENT_USER_ID,
			InputArgument::REQUIRED,
			'User ID to create the account for'
		);
		$this->addOption(
			self::OPTION_PARAM,
			'p',
			InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
			'Parameters in key=value format (e.g., -p emailUser=john -p accountName="John Doe")'
		);
		$this->addOutputOption();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$providerId = $input->getArgument(self::ARGUMENT_PROVIDER_ID);
		$userId = $input->getArgument(self::ARGUMENT_USER_ID);
		$paramStrings = $input->getOption(self::OPTION_PARAM);
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

		if (!$this->checkProviderEnabled($provider, $output)) {
			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$this->outputData(['error' => sprintf('Provider "%s" is not enabled', $providerId)], $outputFormat, $output);
			}
			return 1;
		}

		if (!$this->checkCanCreateAccount($provider, $userId, $output)) {
			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$this->outputData(['error' => sprintf('Cannot create account for user "%s"', $userId)], $outputFormat, $output);
			}
			return 1;
		}

		$parameters = $this->parseParameters($paramStrings, $output, $outputFormat);
		if ($parameters === null) {
			return 1;
		}

		if (!$this->validateRequiredParameters($provider, $parameters, $output, $outputFormat)) {
			return 1;
		}

		return $this->createAccount($provider, $userId, $parameters, $output, $outputFormat);
	}

	/**
	 * Parse parameter strings into key-value array
	 *
	 * @param string[] $paramStrings
	 * @return array<string, string>|null Array of parameters or null on error
	 */
	protected function parseParameters(array $paramStrings, OutputInterface $output, string $outputFormat): ?array {
		$parameters = [];
		foreach ($paramStrings as $paramString) {
			$parts = explode('=', $paramString, 2);
			if (count($parts) !== 2) {
				if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
					$this->outputData(['error' => sprintf('Invalid parameter format: %s', $paramString)], $outputFormat, $output);
				} else {
					$output->writeln(sprintf('<error>Invalid parameter format: %s</error>', $paramString));
					$output->writeln('<comment>Use format: -p key=value</comment>');
				}
				return null;
			}
			$parameters[trim($parts[0])] = trim($parts[1]);
		}
		return $parameters;
	}

	/**
	 * Validate that all required parameters are present
	 *
	 * @param array<string, string> $parameters
	 */
	protected function validateRequiredParameters(
		IMailAccountProvider $provider,
		array $parameters,
		OutputInterface $output,
		string $outputFormat,
	): bool {
		$capabilities = $provider->getCapabilities();
		$creationSchema = $capabilities->getCreationParameterSchema();

		$missingParams = [];
		foreach ($creationSchema as $key => $schema) {
			$required = $schema['required'] ?? false;
			if ($required && !isset($parameters[$key])) {
				$missingParams[] = $key;
			}
		}

		if (!empty($missingParams)) {
			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$missingDetails = [];
				foreach ($missingParams as $param) {
					$missingDetails[] = [
						'parameter' => $param,
						'description' => $creationSchema[$param]['description'] ?? '',
					];
				}
				$this->outputData([
					'error' => 'Missing required parameters',
					'missingParameters' => $missingDetails,
				], $outputFormat, $output);
			} else {
				$output->writeln('<error>Missing required parameters:</error>');
				foreach ($missingParams as $param) {
					$description = $creationSchema[$param]['description'] ?? '';
					$output->writeln(sprintf('  - %s: %s', $param, $description));
				}
				$output->writeln('');
				$output->writeln('Usage example:');
				foreach ($creationSchema as $key => $schema) {
					$required = $schema['required'] ?? false;
					if ($required) {
						$output->writeln(sprintf('  -p %s=<value>', $key));
					}
				}
			}
			return false;
		}

		return true;
	}

	/**
	 * Create the account and display result
	 *
	 * @param array<string, string> $parameters
	 */
	protected function createAccount(
		IMailAccountProvider $provider,
		string $userId,
		array $parameters,
		OutputInterface $output,
		string $outputFormat,
	): int {
		if ($outputFormat === self::OUTPUT_FORMAT_PLAIN) {
			$output->writeln(sprintf(
				'<info>Creating account for user "%s" via provider "%s"...</info>',
				$userId,
				$provider->getId()
			));
			$output->writeln('');
		}

		try {
			$account = $provider->createAccount($userId, $parameters);

			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$this->outputData([
					'success' => true,
					'account' => [
						'id' => $account->getId(),
						'email' => $account->getEmail(),
						'name' => $account->getName(),
						'imapHost' => $account->getMailAccount()->getInboundHost(),
						'imapPort' => $account->getMailAccount()->getInboundPort(),
						'smtpHost' => $account->getMailAccount()->getOutboundHost(),
						'smtpPort' => $account->getMailAccount()->getOutboundPort(),
					],
				], $outputFormat, $output);
			} else {
				$output->writeln('<info>Account created successfully!</info>');
				$output->writeln('');
				$output->writeln(sprintf('Account ID: %d', $account->getId()));
				$output->writeln(sprintf('Email: %s', $account->getEmail()));
				$output->writeln(sprintf('Name: %s', $account->getName()));
				$output->writeln(sprintf(
					'IMAP Host: %s:%d',
					$account->getMailAccount()->getInboundHost(),
					$account->getMailAccount()->getInboundPort()
				));
				$output->writeln(sprintf(
					'SMTP Host: %s:%d',
					$account->getMailAccount()->getOutboundHost(),
					$account->getMailAccount()->getOutboundPort()
				));
				$output->writeln('');
			}
			return 0;
		} catch (\Exception $e) {
			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$this->outputData(['error' => $e->getMessage()], $outputFormat, $output);
			} else {
				$output->writeln('<error>Failed to create account:</error>');
				$output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
			}
			return 1;
		}
	}
}
