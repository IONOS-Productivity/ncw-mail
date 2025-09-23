<template>
	<div>
		<NcInputField id="auto-name"
			v-model="localAccountName"
			:label="translate('mail', 'Name')"
			type="text"
			:placeholder="translate('mail', 'Name')"
			:disabled="loading"
			autofocus />
		<NcInputField id="auto-address"
			v-model="localEmailAddress"
			:label="translate('mail', 'Mail addressss')"
			type="email"
			:placeholder="translate('mail', 'Mail address')"
			:disabled="loading"
			required
			@change="clearFeedback" />
		<span class="email-domain-hint">@myworkspace.com</span>
		<NcPasswordField id="auto-password"
			v-model="localPassword"
			:disabled="loading"
			type="password"
			:label="translate('mail', 'Password')"
			:placeholder="translate('mail', 'Password')"
			:required="!hasPasswordAlternatives" />
		<div class="account-form__submit-buttons">
			<NcButton class="account-form__submit-button"
				type="primary"
				:disabled="localLoading || !localAccountName || !isValidEmail(localEmailAddress) || !localPassword"
				@click="submitForm">
				<template #icon>
					<IconLoading v-if="localLoading" :size="20" />
					<IconCheck v-else :size="20" />
				</template>
				{{ buttonText }}
			</NcButton>
		</div>
		<div v-if="localFeedback" class="account-form--feedback">
			{{ localFeedback }}
		</div>
	</div>
</template>

<script>
import { NcInputField, NcPasswordField, NcButton, NcLoadingIcon as IconLoading } from '@nextcloud/vue'
import IconCheck from 'vue-material-design-icons/Check.vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { mapStores } from 'pinia'
// import { useMainStore } from '../../store/mainStore/index.js'
import useMainStore from '../../store/mainStore.js'

export default {
	name: 'NewEmailAddressTab',
	components: {
		NcInputField,
		NcPasswordField,
		NcButton,
		IconLoading,
		IconCheck,
	},
	props: {
		loading: {
			type: Boolean,
			default: false,
		},
		hasPasswordAlternatives: {
			type: Boolean,
			default: false,
		},
		clearFeedback: {
			type: Function,
			default: () => {},
		},
		isValidEmail: {
			type: Function,
			default: () => true,
		},
		translate: {
			type: Function,
			default: (a, b) => b,
		},
	},
	data() {
		return {
			localAccountName: '',
			localEmailAddress: '',
			localPassword: '',
			localLoading: false,
			localFeedback: null,
			currentStep: null, // Track current step for button text
		}
	},
	computed: {
		...mapStores(useMainStore),

		buttonText() {
			if (this.localLoading && this.currentStep) {
				return this.currentStep
			}
			return this.translate('mail', 'Create & Connect')
		},
	},
	methods: {
		async submitForm() {
			this.clearLocalFeedback()
			this.localLoading = true

			const { localAccountName, localEmailAddress, localPassword } = this

			// Basic validation
			if (!localAccountName || !localEmailAddress || !localPassword) {
				this.localFeedback = this.translate('mail', 'Please fill all fields')
				this.localLoading = false
				return
			}

			if (!this.isValidEmail(localEmailAddress)) {
				this.localFeedback = this.translate('mail', 'Please enter a valid email address')
				this.localLoading = false
				return
			}

			try {
				/* =============================================================================
				 * OPTION 1: MOCK SIMULATION (CURRENT - FOR TESTING REDIRECT)
				 * Remove this section when implementing real API calls
				 * ============================================================================= */

				// TEMPORARY: Skip actual account creation for testing redirect
				// This will just simulate the flow and trigger the redirect

				// Step 1: Simulate creating email address
				this.currentStep = this.translate('mail', 'Creating email address...')
				await new Promise(resolve => setTimeout(resolve, 1000))

				// Step 2: Simulate configuring account
				this.currentStep = this.translate('mail', 'Configuring mail account...')
				await new Promise(resolve => setTimeout(resolve, 1000))

				// Step 3: Simulate testing authentication
				this.currentStep = this.translate('mail', 'Testing authentication...')
				await new Promise(resolve => setTimeout(resolve, 1000))

				// Step 4: Simulate loading account
				this.currentStep = this.translate('mail', 'Loading account...')
				await new Promise(resolve => setTimeout(resolve, 1000))

				// Step 5: Success - Create a mock account object and trigger redirect
				this.localFeedback = this.translate('mail', 'Account created for ') + localEmailAddress

				// Create a mock account object to satisfy the redirect
				const mockAccount = {
					id: Date.now(), // Use timestamp as fake ID
					accountName: localAccountName,
					emailAddress: localEmailAddress,
					mailboxes: [],
				}

				// Trigger the redirect by emitting account-created
				this.$emit('account-created', mockAccount)

				/* =============================================================================
				 * OPTION 2: REAL API IMPLEMENTATION (UNCOMMENT WHEN READY)
				 * Replace the mock simulation above with this implementation
				 * ============================================================================= */

				/*
				// CALL 1: Create new email address via IONOS API
				this.currentStep = this.translate('mail', 'Creating email address...')
				const emailCreationResponse = await this.createNewEmailAddress({
					emailAddress: localEmailAddress,
					password: localPassword,
					name: localAccountName,
				})

				// CALL 2: Extract SMTP/IMAP configuration and connect account
				const { smtpConfig, imapConfig } = emailCreationResponse

				// Step 3: Create mail account using the configuration
				this.currentStep = this.translate('mail', 'Configuring mail account...')
				const accountData = {
					accountName: localAccountName,
					emailAddress: localEmailAddress,
					// IMAP Configuration from IONOS response
					imapHost: imapConfig.host,
					imapPort: imapConfig.port,
					imapSslMode: imapConfig.security, // 'ssl', 'tls', or 'none'
					imapUser: imapConfig.username || localEmailAddress,
					imapPassword: localPassword,
					// SMTP Configuration from IONOS response
					smtpHost: smtpConfig.host,
					smtpPort: smtpConfig.port,
					smtpSslMode: smtpConfig.security,
					smtpUser: smtpConfig.username || localEmailAddress,
					smtpPassword: localPassword,
					authMethod: 'password',
				}

				// Step 4: Test authentication and create account
				this.currentStep = this.translate('mail', 'Testing authentication...')
				const account = await this.mainStore.startAccountSetup(accountData)

				// Step 5: Finalize account setup
				this.currentStep = this.translate('mail', 'Loading account...')
				await this.mainStore.finishAccountSetup({ account })

				// Step 6: Success - this will trigger redirect
				this.localFeedback = this.translate('mail', 'Account created for ') + localEmailAddress
				this.$emit('account-created', account)
				*/

			} catch (error) {
				console.error('Account creation failed:', error)

				/* =============================================================================
				 * ERROR HANDLING FOR REAL API (UNCOMMENT WHEN USING REAL API)
				 * ============================================================================= */

				/*
				// Handle specific error types from IONOS API
				if (error.response?.status === 409) {
					this.localFeedback = this.translate('mail', 'Email address already exists')
				} else if (error.response?.status === 400) {
					this.localFeedback = error.response.data?.message || this.translate('mail', 'Invalid email configuration')
				} else if (error.data?.error === 'CONNECTION_ERROR') {
					if (error.data.service === 'IMAP') {
						this.localFeedback = this.translate('mail', 'IMAP server is not reachable')
					} else if (error.data.service === 'SMTP') {
						this.localFeedback = this.translate('mail', 'SMTP server is not reachable')
					}
				} else if (error.data?.error === 'AUTHENTICATION') {
					if (error.data.service === 'IMAP') {
						this.localFeedback = this.translate('mail', 'IMAP username or password is wrong')
					} else if (error.data.service === 'SMTP') {
						this.localFeedback = this.translate('mail', 'SMTP username or password is wrong')
					}
				} else {
					this.localFeedback = this.translate('mail', 'There was an error while setting up your account')
				}
				*/

				// TEMPORARY: Simple error handling for mock
				this.localFeedback = this.translate('mail', 'There was an error while setting up your account')
			} finally {
				this.localLoading = false
			}
		},

		async createNewEmailAddress({ emailAddress, password, name }) {
			/* =============================================================================
			 * IONOS EMAIL CREATION API CALL
			 * This method handles CALL 1: Creating the new email address
			 * ============================================================================= */

			/* REAL IMPLEMENTATION (UNCOMMENT WHEN READY):

			// Backend endpoint - you'll need to create this
			const url = generateUrl('/apps/mail/api/ionos/create-email')

			try {
				const response = await axios.post(url, {
					emailAddress,        // e.g., "user@myworkspace.com"
					password,           // Password for the new email account
					displayName: name,  // Display name for the account
					// Additional IONOS-specific parameters:
					// domain: 'myworkspace.com',
					// quota: '5GB',
					// etc. - based on IONOS API requirements
				})

				// Expected response format from your backend:
				return {
					success: true,
					message: 'Email account created successfully',
					smtpConfig: {
						host: 'smtp.ionos.com',      // IONOS SMTP server
						port: 587,                   // or 465 for SSL
						security: 'tls',             // or 'ssl'
						username: emailAddress       // usually the email address
					},
					imapConfig: {
						host: 'imap.ionos.com',      // IONOS IMAP server
						port: 993,                   // or 143 for non-SSL
						security: 'ssl',             // or 'tls'
						username: emailAddress       // usually the email address
					}
				}

			} catch (error) {
				// Handle IONOS API specific errors
				if (error.response?.status === 409) {
					throw new Error('Email address already exists')
				} else if (error.response?.status === 402) {
					throw new Error('Insufficient quota or billing issue')
				} else if (error.response?.status === 400) {
					throw new Error(error.response.data?.message || 'Invalid request')
				}
				throw error
			}

			*/

			// TEMPORARY: Mock response for testing (REMOVE WHEN IMPLEMENTING REAL API)
			console.log('Mock: Creating email address:', { emailAddress, password, name })

			// Simulate API delay
			await new Promise(resolve => setTimeout(resolve, 2000))

			// Mock response that matches expected format
			return {
				success: true,
				message: 'Mock: Email account created successfully',
				smtpConfig: {
					host: 'smtp.ionos.com',
					port: 587,
					security: 'tls',
					username: emailAddress,
				},
				imapConfig: {
					host: 'imap.ionos.com',
					port: 993,
					security: 'ssl',
					username: emailAddress,
				},
			}
		},

		clearLocalFeedback() {
			this.localFeedback = null
		},
	},
}
</script>

<style scoped>
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
</style>

<style>
.tabs-component-panels :deep(.input-field) {
	margin: calc(var(--default-grid-baseline, 4px) * 3) 0;
}

.tabs-component-panels input {
	width: 100%;
	box-sizing: border-box;
}
</style>
