import axios from '@nextcloud/axios'
/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { generateUrl } from '@nextcloud/router'

/**
 * @typedef AppPasswordResponse
 * @property {string} password the generated app password
 */

/**
 * Generate an app password for a provider-managed account
 *
 * @param {string} providerId provider identifier (e.g., 'ionos')
 * @param {number} accountId id of account
 * @return {Promise<AppPasswordResponse>}
 */
export async function generateAppPassword(providerId, accountId) {
	const url = generateUrl('/apps/mail/api/providers/{providerId}/password', { providerId })

	return axios.post(url, { accountId }).then((resp) => resp.data)
}
