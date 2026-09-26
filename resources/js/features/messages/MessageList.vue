<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import {
    listMessages,
    type MailboxView,
    type MessageListItem,
    type ReadChange,
} from '../../api/messages';
import type { AccountSummary } from '../../api/accounts';

const props = defineProps<{
    view: MailboxView;
    readChange?: ReadChange | null;
    accountId?: number | null;
    refreshVersion?: number;
    accounts: AccountSummary[];
    selectedMessageId?: number | null;
}>();
const messages = shallowRef<MessageListItem[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(false);
const error = ref(false);
const emit = defineEmits<{ select: [id: number]; loaded: [] }>();
const accountMap = computed(() => new Map(props.accounts.map((account) => [account.id, account])));
const emptyText = computed(
    () =>
        ({ all: 'No messages yet.', inbox: 'Your Inbox is empty.', unread: 'No unread messages.' })[
            props.view
        ],
);
let controller: AbortController;
let seen = new Set<number>();

const readOverrides = new Map<number, boolean>();
watch(
    () => props.readChange,
    (change) => {
        if (!change) return;
        readOverrides.set(change.id, change.is_read);
        messages.value = messages.value
            .map((m) => (m.id === change.id ? { ...m, is_read: change.is_read } : m))
            .filter((m) => props.view !== 'unread' || !m.is_read);
    },
);
async function loadPage() {
    if (loading.value) return;
    const active = controller;
    loading.value = true;
    error.value = false;
    try {
        const firstPage = cursor.value === null;
        const page = await listMessages(props.view, cursor.value, active.signal, props.accountId);
        if (active.signal.aborted) return;
        const additions = page.data
            .map((message) =>
                readOverrides.has(message.id)
                    ? { ...message, is_read: readOverrides.get(message.id)! }
                    : message,
            )
            .filter((message) => {
                if (props.view === 'unread' && message.is_read) return false;
                if (seen.has(message.id)) return false;
                seen.add(message.id);
                return true;
            });
        messages.value = messages.value.concat(additions);
        cursor.value = page.next_cursor;
        if (firstPage) emit('loaded');
    } catch {
        if (!active.signal.aborted) error.value = true;
    } finally {
        if (!active.signal.aborted) loading.value = false;
    }
}

watch(
    () => [props.view, props.accountId, props.refreshVersion],
    () => {
        controller?.abort();
        readOverrides.clear();
        controller = new AbortController();
        messages.value = [];
        cursor.value = null;
        seen = new Set();
        loading.value = false;
        void loadPage();
    },
    { immediate: true },
);
// Also cancel when switching to Accounts or leaving the workspace.
onBeforeUnmount(() => controller.abort());

function dateLabel(value: string): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Unknown date';
    const now = new Date();
    return date.toDateString() === now.toDateString()
        ? date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
        : date.toLocaleDateString(undefined, {
              month: 'short',
              day: 'numeric',
              ...(date.getFullYear() !== now.getFullYear() ? { year: 'numeric' as const } : {}),
          });
}
</script>

<template>
    <div class="message-feed" :aria-busy="loading">
        <ul v-if="messages.length" class="message-rows" aria-label="Messages">
            <li v-for="message in messages" :key="message.id">
                <button
                    type="button"
                    class="message-row"
                    :class="{
                        'message-unread': !message.is_read,
                        'message-selected': selectedMessageId === message.id,
                    }"
                    :aria-pressed="selectedMessageId === message.id"
                    @click="$emit('select', message.id)"
                >
                    <span class="message-sender" :title="message.from_address">{{
                        message.from_name || message.from_address || 'Unknown sender'
                    }}</span>
                    <span class="message-subject">{{ message.subject || '(No subject)' }}</span>
                    <time
                        class="message-time"
                        :datetime="message.sort_date"
                        :title="new Date(message.sort_date).toLocaleString()"
                        >{{ dateLabel(message.sort_date) }}</time
                    >
                    <span
                        class="message-account"
                        :title="`${accountMap.get(message.mail_account_id)?.display_name || 'Mail account'} · ${accountMap.get(message.mail_account_id)?.email_address || ''}`"
                    >
                        <span class="account-marker" aria-hidden="true"></span
                        >{{
                            accountMap.get(message.mail_account_id)?.short_label ||
                            accountMap.get(message.mail_account_id)?.display_name ||
                            'Mail'
                        }}
                    </span>
                    <span class="message-snippet">{{ message.snippet }}</span>
                    <span class="message-meta">
                        <span v-if="!message.is_read" class="unread-dot" aria-label="Unread"
                            ><span class="sr-only">Unread</span></span
                        >
                        <span
                            v-if="message.has_attachments"
                            aria-label="Has attachments"
                            title="Attachments"
                            >⌁</span
                        >
                        <span v-if="message.is_starred" title="Starred"
                            >★<span class="sr-only">Starred</span></span
                        >
                        <span v-if="message.is_important" title="Important"
                            >!<span class="sr-only">Important</span></span
                        >
                        <span v-if="message.is_done" title="Done"
                            >✓<span class="sr-only">Done</span></span
                        >
                    </span>
                </button>
            </li>
        </ul>
        <div class="message-feed-footer" aria-live="polite">
            <p v-if="loading">{{ messages.length ? 'Loading more…' : 'Loading mailbox…' }}</p>
            <template v-else-if="error">
                <p role="alert">
                    {{
                        messages.length
                            ? 'Unable to load more messages.'
                            : 'Unable to load this mailbox.'
                    }}
                </p>
                <button class="small-button" type="button" @click="loadPage">Try again</button>
            </template>
            <p v-else-if="!messages.length">{{ emptyText }}</p>
            <button v-else-if="cursor" class="small-button" type="button" @click="loadPage">
                Load more
            </button>
            <p v-else>End of messages</p>
        </div>
    </div>
</template>
