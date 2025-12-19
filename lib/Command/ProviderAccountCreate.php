<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Command;

use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ProviderAccountCreate extends Command {
	public const ARGUMENT_PROVIDER_ID = 'provider-id';
	public const ARGUMENT_USER_ID = 'user-id';
	public const OPTION_PARAM = 'param';
	public const OPTION_OUTPUT = 'output';

	public function __construct(
		private ProviderRegistryService $providerRegistry,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('mail:provider:create');
		$this->setDescription('Create a mail account using an external provider');
		$this->addArgument(self::ARGUMENT_PROVIDER_ID, InputArgument::REQUIRED, 'Provider ID (e.g., ionos, office365)');
		$this->addArgument(self::ARGUMENT_USER_ID, InputArgument::REQUIRED, 'Nextcloud user ID');
		$this->addOption(self::OPTION_PARAM, 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Provider parameters in key=value format');
		$this->addOption(self::OPTION_OUTPUT, 'o', InputOption::VALUE_OPTIONAL, 'Output format (json, json_pretty)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$providerId = $input->getArgument(self::ARGUMENT_PROVIDER_ID);
		$userId = $input->getArgument(self::ARGUMENT_USER_ID);
		$paramStrings = $input->getOption(self::OPTION_PARAM);
		$outputFormat = $input->getOption(self::OPTION_OUTPUT);
		$isJsonOutput = in_array($outputFormat, ['json', 'json_pretty'], true);

		// Validate user exists
		if (!$this->userManager->userExists($userId)) {
			if ($isJsonOutput) {
				$this->outputJson($output, [
					'success' => false,
					'error' => 'User does not exist',
					'userId' => $userId,
				], $outputFormat);
			} else {
				$io->error("User '$userId' does not exist");
			}
			return 1;
		}

		// Get provider
		$provider = $this->providerRegistry->getProvider($providerId);
		if ($provider === null) {
			if ($isJsonOutput) {
				$this->outputJson($output, [
					'success' => false,
					'error' => 'Provider not found',
					'providerId' => $providerId,
					'availableProviders' => array_keys($this->providerRegistry->getAllProviders()),
				], $outputFormat);
			} else {
				$io->error("Provider '$providerId' not found");
				$io->note('Available providers: ' . implode(', ', array_keys($this->providerRegistry->getAllProviders())));
			}
			return 1;
		}

		// Check if provider is enabled
		if (!$provider->isEnabled()) {
			if ($isJsonOutput) {
				$this->outputJson($output, [
					'success' => false,
					'error' => 'Provider not enabled',
					'providerId' => $providerId,
				], $outputFormat);
			} else {
				$io->error("Provider '$providerId' is not enabled or not properly configured");
			}
			return 1;
		}

		// Check if provider is available for user
		if (!$provider->isAvailableForUser($userId)) {
			if ($isJsonOutput) {
				$this->outputJson($output, [
					'success' => false,
					'error' => 'Provider not available for user',
					'providerId' => $providerId,
					'userId' => $userId,
				], $outputFormat);
			} else {
				$io->error("Provider '$providerId' is not available for user '$userId'");
				$io->note('This may be because the user already has an account with this provider');
			}
			return 1;
		}

		// Parse parameters
		$params = $this->parseParams($paramStrings);

		// Show parameter schema if no params provided
		if (empty($params) && !$isJsonOutput) {
			$io->section('Provider: ' . $provider->getName());
			$io->writeln('Required parameters:');
			$schema = $provider->getCapabilities()->getCreationParameterSchema();
			foreach ($schema as $paramName => $paramSchema) {
				$required = $paramSchema['required'] ? ' (required)' : ' (optional)';
				$io->writeln("  --param $paramName=<value>$required - " . $paramSchema['description']);
			}
			return 1;
		}

		// Validate required parameters
		$schema = $provider->getCapabilities()->getCreationParameterSchema();
		$missingParams = [];
		foreach ($schema as $paramName => $paramSchema) {
			if ($paramSchema['required'] && !isset($params[$paramName])) {
				$missingParams[] = $paramName;
			}
		}

		if (!empty($missingParams)) {
			if ($isJsonOutput) {
				$this->outputJson($output, [
					'success' => false,
					'error' => 'Missing required parameters',
					'missingParameters' => $missingParams,
				], $outputFormat);
			} else {
				$io->error('Missing required parameters: ' . implode(', ', $missingParams));
			}
			return 1;
		}

		// Create account
		if (!$isJsonOutput) {
			$io->section('Creating account');
			$io->writeln("Provider: {$provider->getName()}");
			$io->writeln("User: $userId");
			$io->writeln('Parameters:');
			foreach ($params as $key => $value) {
				// Don't show password values
				$displayValue = (str_contains(strtolower($key), 'password')) ? '***' : $value;
				$io->writeln("  $key: $displayValue");
			}
			$io->newLine();
		}

		try {
			$account = $provider->createAccount($userId, $params);

			if ($isJsonOutput) {
				$this->outputJson($output, [
					'success' => true,
					'account' => [
						'id' => $account->getId(),
						'userId' => $account->getUserId(),
						'name' => $account->getName(),
						'email' => $account->getEmail(),
					],
				], $outputFormat);
			} else {
				$io->success('Account created successfully');
				$io->definitionList(
					['Account ID' => $account->getId()],
					['Email' => $account->getEmail()],
					['Name' => $account->getName()],
				);
			}

			return 0;
		} catch (\Exception $e) {
			$this->logger->error('Failed to create account via provider', [
				'providerId' => $providerId,
				'userId' => $userId,
				'exception' => $e,
			]);

			if ($isJsonOutput) {
				$this->outputJson($output, [
					'success' => false,
					'error' => 'Account creation failed',
					'message' => $e->getMessage(),
				], $outputFormat);
			} else {
				$io->error('Failed to create account: ' . $e->getMessage());
			}

			return 1;
		}
	}

	/**
	 * Parse parameter strings in key=value format
	 *
	 * @param array<string> $paramStrings
	 * @return array<string, string>
	 */
	private function parseParams(array $paramStrings): array {
		$params = [];
		foreach ($paramStrings as $paramString) {
			$parts = explode('=', $paramString, 2);
			if (count($parts) === 2) {
				$params[$parts[0]] = $parts[1];
			}
		}
		return $params;
	}

	/**
	 * Output data as JSON
	 *
	 * @param OutputInterface $output
	 * @param array<string, mixed> $data
	 * @param string|null $format
	 */
	private function outputJson(OutputInterface $output, array $data, ?string $format): void {
		$options = $format === 'json_pretty' ? JSON_PRETTY_PRINT : 0;
		$output->writeln(json_encode($data, $options));
	}
}
