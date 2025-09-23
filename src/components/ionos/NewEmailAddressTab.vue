<!--
  - SPDX-FileCopyrightText: 2025 STRATO GmbH
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
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
			:label="translate('mail', 'Mail address')"
			type="email"
			:placeholder="translate('mail', 'Mail address')"
			:disabled="loading"
			required
			@change="clearFeedback" />
		<p v-if="localEmailAddress && !isValidEmail(localEmailAddress)" class="account-form--error">
			{{ translate('mail', 'Please enter an email of the format name@example.com') }}
		</p>
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
			default: (email) => {
				// Fallback email validation if not provided
				// This should match AccountForm.vue's validation
				if (!email) return true // Don't validate empty emails
				const regExpEmail = /^(([^<>()[\]\\.,;:\s@"]+(\.[^<>()[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/
				return regExpEmail.test(email)
			},
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
				// Step 1: Call IONOS API
				this.currentStep = this.translate('mail', 'Creating account...')
				const response = await this.callIonosAPI({
					accountName: localAccountName,
					emailAddress: localEmailAddress,
					password: localPassword,
				})

				// Step 2: Success - Just show message, no redirect
				this.localFeedback = response.data.message || this.translate('mail', 'Account created successfully')

				// Note: No redirect for stub - just shows success message

			} catch (error) {
				console.error('Account creation failed:', error)

				if (error.response?.status === 400) {
					this.localFeedback = error.response.data?.message || this.translate('mail', 'Invalid request')
				} else {
					this.localFeedback = this.translate('mail', 'There was an error while setting up your account')
				}
			} finally {
				this.localLoading = false
				this.currentStep = null
			}
		},

		async callIonosAPI({ accountName, emailAddress, password }) {
			const url = generateUrl('/apps/mail/api/ionos/accounts')

			const response = await axios.post(url, {
				accountName,
				emailAddress,
				password,
			})

			return response
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
.account-form--error {
	text-align: start;
	font-size: 14px;
	color: var(--color-error);
	margin-top: -8px;
	margin-bottom: 8px;
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
