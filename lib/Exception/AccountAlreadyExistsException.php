<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Exception;

/**
 * Exception thrown when attempting to create or update a mail account
 * with an email address that already exists for another user
 */
class AccountAlreadyExistsException extends ServiceException {
}
