<!--
  - SPDX-FileCopyrightText: 2026 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<tr class="mailbox-list-item">
		<td class="email-column">
			<span class="email-address">{{ mailbox.email }}</span>
		</td>
		<td class="displayname-column">
			<!-- Show nothing if mail app account doesn't exist -->
			<div v-if="!mailbox.mailAppAccountExists" class="no-account">
				<!-- Empty cell -->
			</div>

			<!-- Show display name if account exists -->
			<div v-else class="displayname-content">
				<!-- View Mode: Show name -->
				<span class="display-name">{{ mailbox.mailAppAccountName || t('mail', 'No name') }}</span>
			</div>
		</td>
		<td class="user-column">
			<div class="user-info">
				<!-- Show avatar if user exists, otherwise show icon -->
				<NcAvatar v-if="mailbox.userExists"
					:user="mailbox.userId"
					:size="32"
					:display-name="mailbox.userName || mailbox.userId" />
				<div v-else class="user-icon-placeholder">
					<IconAccountOff :size="32" />
				</div>

				<div class="user-details">
					<!-- Display: userName (userId) -->
					<span v-if="mailbox.userName" class="user-display">
						{{ mailbox.userName }} <span class="user-id-inline">({{ mailbox.userId }})</span>
					</span>
					<span v-else class="user-display">{{ mailbox.userId }}</span>
				</div>
			</div>
		</td>
		<td v-if="debug" class="status-column">
			<div class="status-indicators">
				<!-- User exists status -->
				<div class="status-item" :class="userStatusClass">
					<component :is="userStatusIcon" :size="16" />
					<span class="status-label">{{ userStatusLabel }}</span>
				</div>

				<!-- Mail app account status -->
				<div class="status-item" :class="accountStatusClass">
					<component :is="accountStatusIcon" :size="16" />
					<span class="status-label">{{ accountStatusLabel }}</span>
				</div>
			</div>
		</td>
		<td class="actions-column">
			<div class="actions">
				<NcActions :inline="1">
					<!-- Delete Button (only in view mode) -->
					<NcActionButton @click="$emit('delete', mailbox)">
						<template #icon>
							<IconDelete :size="20" />
						</template>
						{{ t('mail', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</div>
		</td>
	</tr>
</template>

<script>
import { NcAvatar, NcActions, NcActionButton } from '@nextcloud/vue'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconCheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import IconAlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import IconAccountOff from 'vue-material-design-icons/AccountOff.vue'
import IconEmailOff from 'vue-material-design-icons/EmailOff.vue'

export default {
	name: 'ProviderMailboxListItem',
	components: {
		NcAvatar,
		NcActions,
		NcActionButton,
		IconDelete,
		IconCheck,
		IconCheckCircle,
		IconAlertCircle,
		IconAccountOff,
		IconEmailOff,
	},
	props: {
		mailbox: {
			type: Object,
			required: true,
		},
		providerId: {
			type: String,
			required: true,
		},
		debug: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['delete'],
	computed: {
		// User status
		userStatusIcon() {
			return this.mailbox.userExists ? 'IconCheckCircle' : 'IconAccountOff'
		},
		userStatusClass() {
			return this.mailbox.userExists ? 'status-ok' : 'status-error'
		},
		userStatusLabel() {
			return this.mailbox.userExists
				? this.t('mail', 'User exists')
				: this.t('mail', 'User deleted')
		},
		// Mail app account status
		accountStatusIcon() {
			return this.mailbox.mailAppAccountExists ? 'IconCheckCircle' : 'IconEmailOff'
		},
		accountStatusClass() {
			return this.mailbox.mailAppAccountExists ? 'status-ok' : 'status-warning'
		},
		accountStatusLabel() {
			return this.mailbox.mailAppAccountExists
				? this.t('mail', 'Mail configured')
				: this.t('mail', 'Not configured')
		},
	},
}
</script>

<style scoped lang="scss">
.mailbox-list-item {
	td {
		padding: 12px;
		vertical-align: middle;
	}

	.email-column {
		.email-address {
			font-family: monospace;
			font-size: 14px;
		}

		.mailbox-field.localpart-field {
			width: 100%;
			max-width: 300px;

			:deep(.helper-text) {
				.domain-hint {
					color: var(--color-text-lighter);
					font-size: 12px;
					font-family: monospace;
				}
			}
		}
	}

	.user-column {
		.user-info {
			display: flex;
			align-items: center;
			gap: 12px;

			.user-icon-placeholder {
				width: 32px;
				height: 32px;
				display: flex;
				align-items: center;
				justify-content: center;
				color: var(--color-text-lighter);
			}

			.user-details {
				.user-display {
					font-weight: 500;
					font-size: 14px;

					.user-id-inline {
						font-weight: normal;
						color: var(--color-text-lighter);
						font-size: 13px;
					}
				}
			}
		}
	}

	.displayname-column {
		.displayname-content {
			.display-name {
				font-size: 14px;
			}

			.mailbox-field.displayname-field {
				width: 100%;
				max-width: 300px;
			}
		}

		.no-account {
			// Empty cell when no mail app account exists
		}
	}

	.status-column {
		.status-indicators {
			display: flex;
			flex-direction: column;
			gap: 8px;

			.status-item {
				display: flex;
				align-items: center;
				gap: 6px;
				font-size: 13px;

				&.status-ok {
					color: var(--color-success);

					.status-label {
						color: var(--color-text-lighter);
					}
				}

				&.status-warning {
					color: var(--color-warning);

					.status-label {
						color: var(--color-text-lighter);
					}
				}

				&.status-error {
					color: var(--color-error);

					.status-label {
						color: var(--color-text-lighter);
					}
				}

				.status-label {
					font-size: 12px;
				}
			}
		}
	}

	.actions-column {
		text-align: right;

		.actions {
			display: flex;
			justify-content: flex-end;
		}
	}
}
</style>
