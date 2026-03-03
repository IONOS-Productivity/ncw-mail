/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
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
	const url = generateUrl('/apps/mail/api/admin/providers/{providerId}/mailboxes', {
		providerId,
	})
	return axios.get(url).then((resp) => resp.data)
}

/**
 * Delete a mailbox
 *
 * @param {string} providerId The provider ID
 * @param {string} userId The user ID
 * @param {string} email The email address
 * @return {Promise}
 */
export const deleteMailbox = (providerId, userId, email) => {
	const url = generateUrl('/apps/mail/api/admin/providers/{providerId}/mailboxes/{userId}?email={email}', {
		providerId,
		userId,
		email,
	})
	return axios.delete(url).then((resp) => resp.data)
}

/**
 * Update a mailbox (admin only)
 *
 * @param {string} providerId The provider ID
 * @param {string} userId The user ID
 * @param {object} data Update data (e.g., { localpart: 'newuser', name: 'New Name' })
 * @return {Promise}
 */
export const updateMailbox = (providerId, userId, data) => {
	const url = generateUrl('/apps/mail/api/admin/providers/{providerId}/mailboxes/{userId}', {
		providerId,
		userId,
	})
	return axios.put(url, data).then((resp) => resp.data)
}

/**
 * Get all enabled providers (admin only)
 *
 * Returns all enabled providers regardless of user availability.
 * Used by admins to manage mailboxes across all providers.
 *
 * @return {Promise}
 */
export const getEnabledProviders = () => {
	const url = generateUrl('/apps/mail/api/admin/providers')
	return axios.get(url).then((resp) => resp.data)
}
