<script setup lang="ts">
import type { MailboxView } from '../../api/messages';
import type { AccountSummary } from '../../api/accounts';
import { statusLabels, statusTone } from '../accounts/statusLabel';

withDefaults(
    defineProps<{
        userName: string;
        accounts?: AccountSummary[];
        section?: 'mail' | 'accounts';
        view?: MailboxView;
        mailboxState?: 'loading' | 'ready' | 'empty' | 'error';
    }>(),
    { accounts: () => [], section: 'mail', view: 'all', mailboxState: 'ready' },
);
defineEmits<{ signOut: []; navigate: [section: MailboxView | 'accounts'] }>();

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
                            :class="{ 'nav-row-current': section === 'mail' && view === item.view }"
                            :aria-current="
                                section === 'mail' && view === item.view ? 'page' : undefined
                            "
                            @click="$emit('navigate', item.view)"
                        >
                            <span class="nav-glyph" aria-hidden="true">◇</span>
                            {{ item.label }}
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
                    <span
                        v-for="account in accounts"
                        :key="account.id"
                        class="nav-row account-nav-row"
                        :title="statusLabels[account.sync_status]"
                    >
                        <span
                            class="status-dot"
                            :class="`tone-${statusTone(account.sync_status)}`"
                            aria-hidden="true"
                        ></span>
                        {{ account.display_name }}
                    </span>
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
                        <h1>{{ navigation.find((item) => item.view === view)?.label }}</h1>
                    </div>
                </div>
                <div class="list-placeholder">
                    <div class="placeholder-icon" aria-hidden="true">✉</div>
                    <div aria-live="polite">
                        <p v-if="mailboxState === 'loading'">Loading mailbox…</p>
                        <p v-else-if="mailboxState === 'error'" role="alert">
                            Unable to load this mailbox. Try another view or return here to retry.
                        </p>
                        <p v-else-if="mailboxState === 'empty'">No messages in this view.</p>
                        <template v-else
                            ><h2>Your messages will appear here</h2>
                            <p>Mailbox connected. Message display is coming next.</p></template
                        >
                    </div>
                </div>
            </section>

            <section
                v-if="section === 'mail'"
                class="reader-pane"
                aria-label="Message viewer"
                data-testid="right-pane"
            >
                <div class="reader-toolbar">
                    <span>Message viewer</span><span class="toolbar-dots">···</span>
                </div>
                <div class="reader-placeholder">
                    <div class="reader-lines" aria-hidden="true"><i></i><i></i><i></i></div>
                    <h2>A place for the conversation</h2>
                    <p>Selecting a message will open it here when mail features arrive.</p>
                </div>
            </section>
        </div>
    </div>
</template>
