/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
module.exports = {
	extends: [
		'@nextcloud'
	],
	plugins: [
		'perfectionist'
	],
	overrides: [
		{
			files: ['l10n/*.json'],
			parser: 'jsonc-eslint-parser',
			plugins: ['jsonc'],
			rules: {
				// Catch trailing commas (the class of bug that broke translations in commit 48cc7ad)
				'jsonc/comma-dangle': ['error', 'never'],
				// Disable JS-style rules inherited from @nextcloud that produce noise on auto-generated files
				'comma-spacing': 'off',
				'eol-last': 'off',
				'no-irregular-whitespace': 'off',
			},
		},
	],
	globals: {
		expect: true,
		OC: true,
		OCA: true,
		OCP: true,
		t: true,
		__webpack_public_path__: true,
		__webpack_nonce__: true,
	},
	rules: {
		'comma-dangle': 'error',
		'jsdoc/no-undefined-types': 'error',
		'jsdoc/require-jsdoc': 'off',
		'perfectionist/sort-enums': 'error',
		'perfectionist/sort-interfaces': 'error',
		'perfectionist/sort-object-types': 'error',
	},
}
