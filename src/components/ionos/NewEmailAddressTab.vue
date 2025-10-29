<!--
  - SPDX-FileCopyrightText: 2025 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<NcInputField id="ionos-account-name"
			v-model="accountName"
			:label="t('mail', 'Name')"
			type="text"
			:placeholder="t('mail', 'Name')"
			:disabled="loading || localLoading"
			@change="clearAllFeedback"
			autofocus />
		<NcInputField id="ionos-email-address"
			v-model="emailAddress"
			:label="t('mail', 'Mail address')"
			type="email"
			:placeholder="t('mail', 'Mail address')"
			:disabled="loading || localLoading"
			required
			@change="clearAllFeedback" />
		<p v-if="emailAddress && !isValidEmail(emailAddress)" class="account-form--error">
			{{ t('mail', 'Please enter an email of the format name@example.com') }}
		</p>
		<span class="email-domain-hint">@{{ emailDomain }}</span>
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
		<div v-if="feedback" class="account-form--feedback">
			{{ feedback }}
		</div>
	</div>
</template>

<script>
import { NcInputField, NcButton, NcLoadingIcon as IconLoading } from '@nextcloud/vue'
import IconCheck from 'vue-material-design-icons/Check.vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import logger from '../../logger.js'
import { fixAccountId } from '../../service/AccountService.js'
import { mapStores } from 'pinia'
import useMainStore from '../../store/mainStore.js'

export default {
	name: 'NewEmailAddressTab',
	components: {
		NcInputField,
		NcButton,
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
			default: () => {},
		},
		isValidEmail: {
			type: Function,
			default: () => {},
		},
	},
	data() {
		return {
			accountName: '',
			emailAddress: '',
			localLoading: false,
			feedback: null,
		}
	},
	computed: {
		...mapStores(useMainStore),
		isFormValid() {
			return this.accountName
				&& this.isValidEmail(this.emailAddress)
		},

		buttonText() {
			return this.localLoading
				? t('mail', 'Creating account...')
				: t('mail', 'Create & Connect')
		},

		emailDomain() {
			return this.mainStore.getPreference('ionos-mailconfig-domain', 'myworkspace.com')
		},
	},
	methods: {
		async submitForm() {
			this.clearAllFeedback()
			this.localLoading = true

			try {
				const account = await this.callIonosAPI({
					accountName: this.accountName,
					emailAddress: this.emailAddress,
				})

				logger.debug(`account ${account.id} created`, { account })

				this.feedback = t('mail', 'Account created successfully')

				this.loadingMessage = t('mail', 'Loading account')
				await this.mainStore.finishAccountSetup({ account })
				this.$emit('account-created', account)
			} catch (error) {
				console.error('Account creation failed:', error)

				if (error.data?.error === 'IONOS_API_ERROR') {
					const statusCode = error.data?.statusCode

					switch (statusCode) {
					case 400:
						this.feedback = t('mail', 'Invalid email address or account data provided')
						break
					case 404:
						this.feedback = t('mail', 'Email service not found. Please contact support')
						break
					case 409:
						this.feedback = t('mail', 'This email address already exists')
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
				} else {
					this.feedback = t('mail', 'There was an error while setting up your account')
				}
			} finally {
				this.localLoading = false
			}
		},

		async callIonosAPI({ accountName, emailAddress }) {
			const url = generateUrl('/apps/mail/api/ionos/accounts')

			return axios
				.post(url, { accountName, emailAddress })
				.then((resp) => resp.data.data)
				.then(fixAccountId)
				.catch((e) => {
					if (e.response && e.response.data) {
						throw e.response.data
					}

					throw e
				})
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
