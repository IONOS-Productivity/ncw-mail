<!--
  - SPDX-FileCopyrightText: 2026 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<tr class="header">
		<th class="header__cell header__cell--email"
			data-cy-mailbox-list-header-email
			scope="col">
			<strong>{{ t('mail', 'Email Address') }}</strong>
		</th>
		<th class="header__cell header__cell--displayname"
			data-cy-mailbox-list-header-displayname
			scope="col">
			<span>{{ t('mail', 'Display Name') }}</span>
		</th>
		<th class="header__cell header__cell--linked-user header__cell--large"
			data-cy-mailbox-list-header-linked-user
			scope="col">
			<span>{{ t('mail', 'Linked User') }}</span>
		</th>
		<!-- DEV ONLY: this column is for debugging purposes and will not be visible in production -->
		<th v-if="debug"
			class="header__cell header__cell--status header__cell--dev"
			data-cy-mailbox-list-header-status
			scope="col"
			title="Development only — will not be visible in production">
			<span>{{ t('mail', 'Status') }}</span>
			<span class="dev-badge">DEV</span>
		</th>
		<th class="header__cell header__cell--actions"
			data-cy-mailbox-list-header-actions
			scope="col">
			<span class="hidden-visually">{{ t('mail', 'Actions') }}</span>
		</th>
	</tr>
</template>

<script>
export default {
	name: 'MailboxListHeader',

	props: {
		debug: {
			type: Boolean,
			default: false,
		},
	},
}
</script>

<style lang="scss" scoped>
@use './styles';

.header {
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-dark);

	@include styles.row;
	@include styles.cell;

	// Header-specific overrides
	&__cell {
		font-weight: 600;

		span,
		strong {
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}

		&--dev {
			background-color: color-mix(in srgb, var(--color-warning) 12%, transparent);
			border-inline: 1px dashed var(--color-warning);
			flex-direction: row;
			align-items: center;
			gap: 6px;

			.dev-badge {
				display: inline-flex;
				align-items: center;
				padding: 1px 5px;
				border-radius: 3px;
				font-size: 10px;
				font-weight: 700;
				letter-spacing: 0.05em;
				background-color: var(--color-warning);
				color: var(--color-main-background);
				flex-shrink: 0;
			}
		}
	}
}
</style>
