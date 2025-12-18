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
 * Generate an IONOS app password for IMAP access
 *
 * @param {number} accountId id of account
 * @return {Promise<AppPasswordResponse>}
 */
export const generateAppPassword = async (accountId) => {
	const url = generateUrl('/apps/mail/api/ionos/password')

	return axios.post(url, { accountId }).then(resp => resp.data)
}
