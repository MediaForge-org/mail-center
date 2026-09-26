<script setup lang="ts">
import MessageReader from '../messages/MessageReader.vue';
import MessageList from '../messages/MessageList.vue';
import type { MailboxCounts, MailboxView, ReadChange } from '../../api/messages';
import type { AccountSummary } from '../../api/accounts';
import { statusLabels, statusTone } from '../accounts/statusLabel';

withDefaults(
    defineProps<{
        userName: string;
        readChange?: ReadChange | null;
        accounts?: AccountSummary[];
        section?: 'mail' | 'accounts';
        view?: MailboxView;
        accountId?: number | null;
        counts?: MailboxCounts | null;
        refreshVersion?: number;
        selectedMessageId?: number | null;
    }>(),
    { accounts: () => [], section: 'mail', view: 'all' },
);
defineEmits<{
    signOut: [];
    readChanged: [change: ReadChange];
    openAccount: [id: number];
    mailboxLoaded: [];
    refreshMailbox: [];
    navigate: [section: MailboxView | 'accounts'];
    select: [id: number | null];
}>();

const navigation: { view: MailboxView; label: string }[] = [
    { view: 'all', label: 'All Mail' },
    { view: 'inbox', label: 'Inbox' },
    { view: 'unread', label: 'Unread' },
];
const unavailable = ['Starred', 'Important', 'Completed'];
const folders = ['Reloads', 'Support', 'Withdrawals', 'Verification', 'Done'];
</script>

<template>
    <div class="workspace">
        <header class="workspace-header">
            <div class="identity">
                <div class="brand-mark brand-mark-small" aria-hidden="true">M</div>
                <span class="brand-name">MailCenter</span>
                <span class="phase-label">FOUNDATION</span>
            </div>
            <div class="header-actions">
                <span class="user-name">{{ userName }}</span>
                <button class="text-button" type="button" @click="$emit('signOut')">
                    Sign out
                </button>
            </div>
        </header>

        <div class="workspace-grid" :class="{ 'workspace-grid-accounts': section === 'accounts' }">
            <aside class="sidebar" aria-label="Navigation" data-testid="left-pane">
                <div class="sidebar-inner">
                    <p class="section-label">Workspace</p>
                    <nav aria-label="Views">
                        <button
                            v-for="item in navigation"
                            :key="item.view"
                            type="button"
                            class="nav-row nav-button"
                            :class="{
                                'nav-row-current':
                                    section === 'mail' && accountId == null && view === item.view,
                            }"
                            :aria-current="
                                section === 'mail' && accountId == null && view === item.view
                                    ? 'page'
                                    : undefined
                            "
                            @click="$emit('navigate', item.view)"
                        >
                            <span class="nav-glyph" aria-hidden="true">◇</span>
                            {{ item.label }}
                            <span
                                v-if="counts?.views[item.view]"
                                class="mailbox-count"
                                :title="`${counts.views[item.view].unread} unread`"
                                >{{ counts.views[item.view].total }}</span
                            >
                        </button>
                        <button
                            v-for="item in unavailable"
                            :key="item"
                            type="button"
                            class="nav-row nav-button"
                            disabled
                        >
                            {{ item }} <span class="nav-later">Coming later</span>
                        </button>
                    </nav>
                    <div class="sidebar-divider"></div>
                    <p class="section-label">Accounts</p>
                    <div v-if="accounts.length === 0" class="sidebar-hint">
                        No account connected
                    </div>
                    <button
                        type="button"
                        v-for="account in accounts"
                        :key="account.id"
                        class="nav-row nav-button account-nav-row"
                        :class="{
                            'nav-row-current': section === 'mail' && accountId === account.id,
                            'account-disabled': !account.enabled,
                        }"
                        :aria-current="
                            section === 'mail' && accountId === account.id ? 'page' : undefined
                        "
                        @click="$emit('openAccount', account.id)"
                        :title="statusLabels[account.sync_status]"
                    >
                        <span
                            class="status-dot"
                            :class="`tone-${statusTone(account.sync_status)}`"
                            aria-hidden="true"
                        ></span>
                        <span class="account-nav-label"
                            >{{ account.display_name
                            }}<small v-if="!account.enabled">Disabled</small></span
                        >
                        <span
                            v-if="counts?.accounts[account.id]"
                            class="mailbox-count"
                            :title="`${counts.accounts[account.id].unread} unread`"
                            >{{ counts.accounts[account.id].total
                            }}<small>{{ counts.accounts[account.id].unread }} unread</small></span
                        >
                    </button>
                    <button
                        class="nav-row nav-button"
                        :class="{ 'nav-row-current': section === 'accounts' }"
                        type="button"
                        :aria-current="section === 'accounts' ? 'page' : undefined"
                        @click="$emit('navigate', 'accounts')"
                    >
                        Manage accounts
                    </button>
                    <div class="sidebar-divider"></div>
                    <p class="section-label">Folders</p>
                    <div v-for="folder in folders" :key="folder" class="nav-row nav-row-muted">
                        <span class="folder-dot" aria-hidden="true"></span>
                        {{ folder }}
                    </div>
                    <p class="sidebar-caption">Folder names are layout examples.</p>
                </div>
                <div class="sidebar-footer">MailCenter · M2</div>
            </aside>

            <section
                v-if="section === 'accounts'"
                class="accounts-pane"
                aria-label="Mail accounts"
                data-testid="accounts-pane"
            >
                <slot name="accounts" />
            </section>

            <section
                v-if="section === 'mail'"
                class="message-list-pane"
                aria-label="Message list"
                data-testid="center-pane"
            >
                <div class="pane-toolbar">
                    <div>
                        <p class="eyebrow">WORKSPACE</p>
                        <h1>
                            {{
                                accountId != null
                                    ? accounts.find((account) => account.id === accountId)
                                          ?.display_name || 'Account mailbox'
                                    : navigation.find((item) => item.view === view)?.label
                            }}
                        </h1>
                        <small
                            v-if="
                                accountId != null &&
                                accounts.find((account) => account.id === accountId)?.enabled ===
                                    false
                            "
                            >Disabled account · retained mail</small
                        >
                    </div>
                </div>
                <button
                    type="button"
                    class="text-button mailbox-refresh"
                    @click="$emit('refreshMailbox')"
                >
                    Refresh mailbox
                </button>
                <MessageList
                    :read-change="readChange"
                    :view="view"
                    :account-id="accountId"
                    :refresh-version="refreshVersion"
                    @loaded="$emit('mailboxLoaded')"
                    :accounts="accounts"
                    :selected-message-id="selectedMessageId"
                    @select="$emit('select', $event)"
                />
            </section>

            <section
                v-if="section === 'mail'"
                class="reader-pane"
                aria-label="Message viewer"
                data-testid="right-pane"
            >
                <MessageReader
                    @read-changed="$emit('readChanged', $event)"
                    :message-id="selectedMessageId ?? null"
                    :accounts="accounts"
                    @close="$emit('select', null)"
                />
            </section>
        </div>
    </div>
</template>
