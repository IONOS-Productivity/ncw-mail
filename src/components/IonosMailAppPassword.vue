<!--
  - SPDX-FileCopyrightText: 2025 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div>
		<p class="ionos-app-password-description">
			{{ t('mail', 'To access your mailbox via IMAP, you need to generate an app-specific password. This password allows IMAP clients to connect to your account.') }}
		</p>

		<div class="ionos-email-display">
			<strong>{{ t('mail', 'Email:') }}</strong>
			<span class="ionos-email-address">{{ email }}</span>
		</div>

		<div class="ionos-password-controls">
			<ButtonVue v-if="!generatedPassword"
				type="primary"
				:aria-label="t('mail', 'Generate password')"
				:disabled="loading"
				@click="generatePassword">
				<template #icon>
					<IconLoading v-if="loading" :size="20" />
					<IconKey v-else :size="20" />
				</template>
				{{ t('mail', 'Generate password') }}
			</ButtonVue>
		</div>

		<div v-if="generatedPassword" class="ionos-password-display">
			<div class="ionos-password-field">
				<strong>{{ t('mail', 'Password:') }}</strong>
				<code class="ionos-password-value">{{ generatedPassword }}</code>
				<ButtonVue type="tertiary"
					:aria-label="t('mail', 'Copy password')"
					@click="copyPassword">
					<template #icon>
						<IconContentCopy :size="20" />
					</template>
				</ButtonVue>
			</div>
			<p class="ionos-password-notice">
				{{ t('mail', 'Please save this password now. For security reasons, it will not be shown again.') }}
			</p>
		</div>

		<div v-if="error" class="ionos-error-message">
			{{ error }}
		</div>
	</div>
</template>

<script>
import { NcButton as ButtonVue, NcLoadingIcon as IconLoading } from '@nextcloud/vue'
import IconKey from 'vue-material-design-icons/Key.vue'
import IconContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import logger from '../logger.js'
import { generateAppPassword } from '../service/IonosMailAppPasswordService.js'

// JSend response statuses
const JSEND_STATUS = {
	SUCCESS: 'success',
	FAIL: 'fail',
	ERROR: 'error',
}

// Error types
const ERROR_TYPES = {
	IONOS_API_ERROR: 'IONOS_API_ERROR',
	ACCOUNT_ID_REQUIRED: 'Account ID is required',
}

// HTTP status codes
const HTTP_STATUS = {
	NOT_FOUND: 404,
	SERVER_ERROR: 500,
}

export default {
	name: 'IonosMailAppPassword',
	components: {
		ButtonVue,
		IconLoading,
		IconKey,
		IconContentCopy,
	},
	props: {
		account: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			generatedPassword: '',
			error: '',
		}
	},
	computed: {
		email() {
			return this.account.emailAddress || ''
		},
		providerId() {
			// Get provider ID from account metadata (e.g., 'ionos', 'office365')
			// Defaults to 'ionos' for backward compatibility
			return this.account.managedByProvider || 'ionos'
		},
	},
	methods: {
		async generatePassword() {
			this.loading = true
			this.error = ''
			this.generatedPassword = ''

			try {
				const response = await generateAppPassword(this.account.id, this.providerId)

				logger.debug('App password generated successfully', {
					accountId: this.account.id,
					providerId: this.providerId,
					response,
				})

				this.handleSuccessResponse(response)
				showSuccess(t('mail', 'App password generated successfully'))
			} catch (error) {
				this.handleErrorResponse(error)
			} finally {
				this.loading = false
			}
		},

		handleSuccessResponse(response) {
			// Response structure: { status: 'success', data: { password: '...' } }
			this.generatedPassword = response.data?.password || ''

			if (!this.generatedPassword) {
				throw new Error('No password in response')
			}
		},

		handleErrorResponse(error) {
			logger.error('Failed to generate app password', {
				accountId: this.account.id,
				error,
				response: error.response?.data,
			})

			this.error = this.getErrorMessage(error)
			showError(this.error)
		},

		getErrorMessage(error) {
			const responseData = error.response?.data
			const status = responseData?.status
			const errorData = responseData?.data

			// Handle JSend 'fail' responses
			if (status === JSEND_STATUS.FAIL) {
				return this.getFailErrorMessage(errorData)
			}

			// Handle JSend 'error' responses
			if (status === JSEND_STATUS.ERROR) {
				return responseData?.message || t('mail', 'Server error. Please try again later.')
			}

			// Handle HTTP status codes
			return this.getHttpErrorMessage(error.response?.status)
		},

		getFailErrorMessage(errorData) {
			if (errorData?.error === ERROR_TYPES.IONOS_API_ERROR) {
				const statusCode = errorData?.statusCode
				if (statusCode === HTTP_STATUS.NOT_FOUND) {
					return t('mail', 'Email service not found. Please contact support.')
				}
				return t('mail', 'Provider API error. Please try again later.')
			}

			if (errorData?.error === ERROR_TYPES.ACCOUNT_ID_REQUIRED) {
				return t('mail', 'Invalid account. Please refresh the page.')
			}

			return t('mail', 'Failed to generate app password. Please try again.')
		},

		getHttpErrorMessage(statusCode) {
			if (statusCode === HTTP_STATUS.NOT_FOUND) {
				return t('mail', 'Email service not found. Please contact support.')
			}

			if (statusCode === HTTP_STATUS.SERVER_ERROR) {
				return t('mail', 'Server error. Please try again later.')
			}

			return t('mail', 'Failed to generate app password. Please try again.')
		},

		async copyPassword() {
			try {
				await navigator.clipboard.writeText(this.generatedPassword)
				showSuccess(t('mail', 'Password copied to clipboard'))
			} catch (error) {
				logger.error('Failed to copy password to clipboard', { error })
				showError(t('mail', 'Failed to copy password to clipboard'))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.ionos-app-password-description {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.ionos-email-display {
	margin-bottom: 16px;
	padding: 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);

	strong {
		margin-right: 8px;
	}

	.ionos-email-address {
		font-family: monospace;
		color: var(--color-main-text);
	}
}

.ionos-password-controls {
	margin-bottom: 16px;
}

.ionos-password-display {
	margin-top: 16px;
	padding: 16px;
	background-color: var(--color-success-background);
	border: 1px solid var(--color-success);
	border-radius: var(--border-radius);

	.ionos-password-field {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 8px;

		strong {
			flex-shrink: 0;
		}

		.ionos-password-value {
			flex: 1;
			padding: 8px 12px;
			background-color: var(--color-background-dark);
			border-radius: var(--border-radius);
			font-family: monospace;
			font-size: 14px;
			word-break: break-all;
		}
	}

	.ionos-password-notice {
		margin: 8px 0 0 0;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
		font-style: italic;
	}
}

.ionos-error-message {
	margin-top: 16px;
	padding: 12px;
	background-color: var(--color-error-background);
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius);
	color: var(--color-error-text);
}

.button-vue:deep() {
	display: inline-block !important;
}
</style>
