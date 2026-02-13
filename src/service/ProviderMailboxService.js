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
 * @return {Promise}
 */
export const deleteMailbox = (providerId, userId) => {
	const url = generateUrl('/apps/mail/api/admin/providers/{providerId}/mailboxes/{userId}', {
		providerId,
		userId,
	})
	return axios.delete(url).then((resp) => resp.data)
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
