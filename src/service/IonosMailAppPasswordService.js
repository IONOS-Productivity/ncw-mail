/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

/**
 * @typedef AppPasswordResponse
 * @property {string} password the generated app password
 */

/**
 * Generate an app password for a provider-managed account
 *
 * This service supports both the generic provider system and legacy IONOS route.
 * It automatically determines which provider manages the account.
 *
 * @param {number} accountId id of account
 * @param {string} [providerId='ionos'] provider ID (defaults to 'ionos' for backward compatibility)
 * @return {Promise<AppPasswordResponse>}
 */
export const generateAppPassword = async (accountId, providerId = 'ionos') => {
	// Use generic provider endpoint - this works for all providers
	const url = generateUrl(`/apps/mail/api/providers/${providerId}/password`)

	return axios.post(url, { accountId }).then(resp => resp.data)
}
