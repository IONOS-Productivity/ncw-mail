/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateFilePath } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'
import '@nextcloud/dialogs/style.css'
import Vue from 'vue'

import ProviderMailboxAdmin from './components/provider/mailbox/ProviderMailboxAdmin.vue'
import Nextcloud from './mixins/Nextcloud.js'

// eslint-disable-next-line camelcase
__webpack_nonce__ = btoa(getRequestToken())
// eslint-disable-next-line camelcase
__webpack_public_path__ = generateFilePath('mail', '', 'js/')

Vue.mixin(Nextcloud)

const View = Vue.extend(ProviderMailboxAdmin)
new View().$mount('#mail-provider-account-overview')
