<!--
  - SPDX-FileCopyrightText: 2025 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<!-- Provider Selection (if multiple providers available) -->
		<NcSelect v-if="providers.length > 1"
			v-model="selectedProvider"
			:options="providers"
			label="name"
			:placeholder="t('mail', 'Select email provider')"
			:disabled="loading || localLoading"
			class="provider-selector"
			@input="onProviderChange">
			<template #selected-option="{ name }">
				{{ name }}
			</template>
		</NcSelect>

		<!-- Dynamic Form Fields based on Provider Schema -->
		<div v-if="selectedProvider">
			<div v-for="(schema, paramName) in selectedProvider.parameterSchema"
				:key="paramName"
				class="field-wrapper">
				<NcInputField :id="`provider-${selectedProvider.id}-${paramName}`"
					v-model="formData[paramName]"
					:label="t('mail', schema.description)"
					:type="schema.type === 'password' ? 'password' : 'text'"
					:placeholder="t('mail', schema.description)"
					:required="schema.required"
					:disabled="loading || localLoading"
					:error="paramName === 'emailUser' && isEmailValid === false"
					@update:value="onFieldUpdate(paramName, $event)"
					@change="clearAllFeedback" />
				<!-- Email domain preview (shown next to emailUser field) -->
				<div v-if="paramName === 'emailUser' && emailPreview"
					class="email-domain-hint"
					:class="{ 'email-domain-hint--error': isEmailValid === false }">
					{{ emailPreview }}
					<span v-if="isEmailValid === false" class="email-validation-error">
						{{ t('mail', 'Invalid email address') }}
					</span>
				</div>
			</div>

			<!-- Submit Button -->
			<div class="account-form__submit-buttons">
				<NcButton class="account-form__submit-button"
					type="primary"
					:disabled="!isFormValid || localLoading"
					@click="submitForm">
					<template #icon>
						<IconLoading v-if="localLoading" :size="20" />
						<IconCheck v-else :size="20" />
					</template>
					{{ buttonText }}
				</NcButton>
			</div>

			<!-- Feedback Message -->
			<div v-if="feedback" class="account-form--feedback">
				{{ feedback }}
			</div>
		</div>

		<!-- No Providers Available Message -->
		<div v-else-if="!providersLoading && providers.length === 0" class="no-providers">
			<p>{{ t('mail', 'No email providers are currently available.') }}</p>
		</div>

		<!-- Loading State -->
		<div v-else-if="providersLoading" class="providers-loading">
			<IconLoading :size="32" />
			<p>{{ t('mail', 'Loading providers...') }}</p>
		</div>
	</div>
</template>

<script>
import { NcInputField, NcButton, NcSelect, NcLoadingIcon as IconLoading } from '@nextcloud/vue'
import IconCheck from 'vue-material-design-icons/Check.vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import logger from '../logger.js'
import { fixAccountId } from '../service/AccountService.js'
import { mapStores } from 'pinia'
import useMainStore from '../store/mainStore.js'

export default {
	name: 'ExternalProviderTab',
	components: {
		NcInputField,
		NcButton,
		NcSelect,
		IconLoading,
		IconCheck,
	},
	props: {
		loading: {
			type: Boolean,
			default: false,
		},
		clearFeedback: {
			type: Function,
			default: () => {
			},
		},
		isValidEmail: {
			type: Function,
			default: () => {
			},
		},
	},
	data() {
		return {
			providers: [],
			selectedProvider: null,
			formData: {}, // Reactive empty object
			localLoading: false,
			providersLoading: true,
			feedback: null,
		}
	},
	computed: {
		...mapStores(useMainStore),

		fullEmailAddress() {
			if (!this.selectedProvider?.capabilities?.emailDomain) {
				return ''
			}
			const emailUser = this.formData.emailUser || ''
			if (!emailUser) {
				return ''
			}
			return `${emailUser}@${this.selectedProvider.capabilities.emailDomain}`
		},

		emailPreview() {
			if (!this.selectedProvider?.capabilities?.emailDomain) {
				return null
			}
			const emailUser = this.formData.emailUser || ''
			return emailUser
				? `${emailUser}@${this.selectedProvider.capabilities.emailDomain}`
				: `@${this.selectedProvider.capabilities.emailDomain}`
		},

		isEmailValid() {
			if (!this.fullEmailAddress) {
				return null // No validation when email is empty
			}
			return this.isValidEmail(this.fullEmailAddress)
		},

		isFormValid() {
			if (!this.selectedProvider) {
				logger.debug('isFormValid: no provider selected')
				return false
			}

			if (!this.selectedProvider.parameterSchema) {
				logger.debug('isFormValid: no parameterSchema', { provider: this.selectedProvider })
				return false
			}

			// Check all required fields are filled
			for (const [paramName, schema] of Object.entries(this.selectedProvider.parameterSchema)) {
				if (schema.required && !this.formData[paramName]) {
					logger.debug('isFormValid: missing required field', {
						paramName,
						value: this.formData[paramName],
					})
					return false
				}
			}

			// Validate email if provider has emailDomain
			if (this.fullEmailAddress && !this.isEmailValid) {
				logger.debug('isFormValid: invalid email address', { email: this.fullEmailAddress })
				return false
			}

			logger.debug('isFormValid: all fields valid', { formData: this.formData })
			return true
		},

		buttonText() {
			return this.localLoading
				? t('mail', 'Creating account...')
				: t('mail', 'Create & Connect')
		},
	},
	async mounted() {
		await this.loadProviders()
	},
	methods: {
		async loadProviders() {
			this.providersLoading = true
			try {
				const url = generateUrl('/apps/mail/api/providers')
				const response = await axios.get(url)
				this.providers = response.data.data?.providers || []

				logger.debug('Loaded mail account providers', {
					count: this.providers.length,
					providers: this.providers.map(p => p.id),
				})

				// Auto-select if only one provider
				if (this.providers.length === 1) {
					this.selectedProvider = this.providers[0]
					logger.debug('Auto-selected provider', { provider: this.selectedProvider })
					this.initializeFormData()
				}
			} catch (error) {
				logger.error('Failed to load providers', { error })
				this.feedback = t('mail', 'Failed to load email providers')
			} finally {
				this.providersLoading = false
			}
		},

		onProviderChange() {
			this.initializeFormData()
			this.clearAllFeedback()
			logger.debug('Provider changed', {
				providerId: this.selectedProvider?.id,
				capabilities: this.selectedProvider?.capabilities,
			})
		},

		onFieldUpdate(paramName, value) {
			logger.debug('Field updated', {
				paramName,
				value,
			})
			// Ensure reactivity by setting the value explicitly
			this.$set(this.formData, paramName, value)
		},

		initializeFormData() {
			if (!this.selectedProvider) {
				logger.debug('initializeFormData: no provider selected')
				return
			}

			logger.debug('initializeFormData: initializing', {
				parameterSchema: this.selectedProvider.parameterSchema,
			})

			// Initialize form data with default values
			// Use Vue.set or create new object to ensure reactivity
			const newFormData = {}
			for (const [paramName, schema] of Object.entries(this.selectedProvider.parameterSchema || {})) {
				newFormData[paramName] = schema.default || ''
			}
			this.formData = newFormData

			logger.debug('initializeFormData: completed', { formData: this.formData })
		},

		async submitForm() {
			this.clearAllFeedback()
			this.localLoading = true

			try {
				const account = await this.callProviderAPI(this.selectedProvider.id, this.formData)

				logger.debug(`Account ${account.id} created via provider ${this.selectedProvider.id}`, { account })

				this.feedback = t('mail', 'Account created successfully')

				this.loadingMessage = t('mail', 'Loading account')
				await this.mainStore.finishAccountSetup({ account })
				this.$emit('account-created', account)
			} catch (error) {
				logger.error('Account creation failed', { error })
				this.handleError(error)
			} finally {
				this.localLoading = false
			}
		},

		async callProviderAPI(providerId, parameters) {
			const url = generateUrl(`/apps/mail/api/providers/${providerId}/accounts`)

			return axios
				.post(url, parameters)
				.then((resp) => resp.data.data)
				.then(fixAccountId)
				.catch((e) => {
					if (e.response && e.response.data) {
						throw e.response.data
					}
					throw e
				})
		},

		handleError(error) {
			const errorData = error.data || {}
			const statusCode = errorData.statusCode
			const existingEmail = errorData.existingEmail || ''

			// Provider-specific error handling
			if (errorData.error === 'SERVICE_ERROR' || errorData.error === 'IONOS_API_ERROR') {
				switch (statusCode) {
				case 400:
					this.feedback = t('mail', 'Invalid email address or account data provided')
					break
				case 404:
					this.feedback = t('mail', 'Email service not found. Please contact support')
					break
				case 409:
					if (existingEmail) {
						this.feedback = t('mail', 'You already have an email address with this provider: {email}', { email: existingEmail })
					} else {
						this.feedback = t('mail', 'An account with this email already exists')
					}
					break
				case 412:
					this.feedback = t('mail', 'Account state conflict. Please try again later')
					break
				case 500:
					this.feedback = t('mail', 'Server error. Please try again later')
					break
				default:
					this.feedback = t('mail', 'There was an error while setting up your account')
				}
			} else if (errorData.error === 'PROVIDER_NOT_FOUND') {
				this.feedback = t('mail', 'Email provider not found')
			} else if (errorData.error === 'PROVIDER_NOT_AVAILABLE') {
				if (errorData.existingEmail) {
					this.feedback = t('mail', 'You already have email {email} configured for this provider. Please navigate to your inbox', { email: errorData.existingEmail })
				} else {
					this.feedback = t('mail', 'This email provider is not available for your account')
				}
			} else if (errorData.error === 'INVALID_PARAMETERS') {
				this.feedback = errorData.message || t('mail', 'Invalid parameters provided')
			} else {
				this.feedback = t('mail', 'There was an error while setting up your account')
			}
		},

		clearLocalFeedback() {
			this.feedback = null
		},

		clearAllFeedback() {
			this.clearLocalFeedback()
			this.clearFeedback()
		},
	},
}
</script>

<style scoped>
.provider-selector {
	margin-bottom: 16px;
}

.field-wrapper {
	position: relative;
	margin-bottom: 16px;
}

.account-form__submit-buttons {
	display: flex;
	justify-content: center;
	margin-top: 16px;
}

.email-domain-hint {
	display: block;
	margin-top: -8px;
	margin-bottom: 8px;
	font-size: 13px;
	color: #888;
}

.email-domain-hint--error {
	color: var(--color-error, #e9322d);
}

.email-validation-error {
	display: block;
	margin-top: 4px;
	font-size: 12px;
	font-weight: normal;
	color: var(--color-error, #e9322d);
}

.account-form--feedback {
	text-align: center;
	margin-top: 16px;
	padding: 8px;
}

.no-providers,
.providers-loading {
	text-align: center;
	padding: 32px;
	color: var(--color-text-maxcontrast);
}

.providers-loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
}

.tabs-component-panels :deep(.input-field) {
	margin: calc(var(--default-grid-baseline, 4px) * 3) 0;
}

.tabs-component-panels input {
	width: 100%;
	box-sizing: border-box;
}
</style>
