/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Get all mailboxes for a provider
 *
 * @param {string} providerId The provider ID
 * @return {Promise}
 */
export const getMailboxes = (providerId) => {
	const url = generateUrl('/apps/mail/api/providers/{providerId}/mailboxes', {
		providerId,
	})
	return axios.get(url).then((resp) => resp.data)
}

/**
 * Update a mailbox
 *
 * @param {string} providerId The provider ID
 * @param {string} userId The user ID
 * @param {object} data Update data (localpart, name, etc.)
 * @return {Promise}
 */
export const updateMailbox = (providerId, userId, data) => {
	const url = generateUrl('/apps/mail/api/providers/{providerId}/mailboxes/{userId}', {
		providerId,
		userId,
	})
	return axios.put(url, data).then((resp) => resp.data)
}

/**
 * Delete a mailbox
 *
 * @param {string} providerId The provider ID
 * @param {string} userId The user ID
 * @return {Promise}
 */
export const deleteMailbox = (providerId, userId) => {
	const url = generateUrl('/apps/mail/api/providers/{providerId}/mailboxes/{userId}', {
		providerId,
		userId,
	})
	return axios.delete(url).then((resp) => resp.data)
}

/**
 * Get available providers
 *
 * @return {Promise}
 */
export const getProviders = () => {
	const url = generateUrl('/apps/mail/api/providers')
	return axios.get(url).then((resp) => resp.data)
}
