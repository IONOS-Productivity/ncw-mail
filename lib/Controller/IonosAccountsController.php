<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2014-2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\Mail\Controller;

use Horde_Imap_Client;
use OCA\Mail\Account;
use OCA\Mail\AppInfo\Application;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\Contracts\IMailTransmission;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Exception\ClientException;
use OCA\Mail\Exception\CouldNotConnectException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Http\JsonResponse as MailJsonResponse;
use OCA\Mail\Http\TrapError;
use OCA\Mail\IMAP\MailboxSync;
use OCA\Mail\Model\NewMessageData;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\AliasesService;
use OCA\Mail\Service\SetupService;
use OCA\Mail\Service\Sync\SyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\IRemoteHostValidator;
use Psr\Log\LoggerInterface;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class IonosAccountsController extends Controller {
	private AccountService $accountService;
	private string $currentUserId;
	private LoggerInterface $logger;
	private IL10N $l10n;
	private AliasesService $aliasesService;
	private IMailTransmission $mailTransmission;
	private SetupService $setup;
	private IMailManager $mailManager;
	private SyncService $syncService;
	private IConfig $config;
	private IRemoteHostValidator $hostValidator;
	private MailboxSync $mailboxSync;

	public function __construct(string $appName,
		IRequest $request,
		AccountService $accountService,
		$UserId,
		LoggerInterface $logger,
		IL10N $l10n,
		AliasesService $aliasesService,
		IMailTransmission $mailTransmission,
		SetupService $setup,
		IMailManager $mailManager,
		SyncService $syncService,
		IConfig $config,
		IRemoteHostValidator $hostValidator,
		MailboxSync $mailboxSync,
	) {
		parent::__construct($appName, $request);
		$this->accountService = $accountService;
		$this->currentUserId = $UserId;
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->aliasesService = $aliasesService;
		$this->mailTransmission = $mailTransmission;
		$this->setup = $setup;
		$this->mailManager = $mailManager;
		$this->syncService = $syncService;
		$this->config = $config;
		$this->hostValidator = $hostValidator;
		$this->mailboxSync = $mailboxSync;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $accountName
	 * @param string $emailAddress
	 * @param string|null $imapHost
	 * @param int|null $imapPort
	 * @param string|null $imapSslMode
	 * @param string|null $imapUser
	 * @param string|null $imapPassword
	 * @param string|null $smtpHost
	 * @param int|null $smtpPort
	 * @param string|null $smtpSslMode
	 * @param string|null $smtpUser
	 * @param string|null $smtpPassword
	 * @param string $authMethod
	 *
	 * @return JSONResponse
	 */
	#[TrapError]
	public function create(string $accountName,
		string $emailAddress,
		?string $imapHost = null,
		?int $imapPort = null,
		?string $imapSslMode = null,
		?string $imapUser = null,
		?string $imapPassword = null,
		?string $smtpHost = null,
		?int $smtpPort = null,
		?string $smtpSslMode = null,
		?string $smtpUser = null,
		?string $smtpPassword = null,
		string $authMethod = 'password'): JSONResponse {



		// call ionos api to create the account
		// configure local client account with the data from ionos
		// response with the created account data or error



//		$ac = new AccountsController();
//		$ac->create();
//
//		return MailJsonResponse::success(
//			$account, Http::STATUS_CREATED
//		);

		return new JSONResponse([
			'id' => 12345678
		]);
	}

}
