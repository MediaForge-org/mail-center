<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { listMessages, type MailboxView, type MessageListItem } from '../../api/messages';
import type { AccountSummary } from '../../api/accounts';

const props = defineProps<{ view: MailboxView; accounts: AccountSummary[] }>();
const messages = shallowRef<MessageListItem[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(false);
const error = ref(false);
const selected = ref<number | null>(null);
const accountMap = computed(() => new Map(props.accounts.map((account) => [account.id, account])));
const emptyText = computed(
    () =>
        ({ all: 'No messages yet.', inbox: 'Your Inbox is empty.', unread: 'No unread messages.' })[
            props.view
        ],
);
let controller: AbortController;
let seen = new Set<number>();

async function loadPage() {
    if (loading.value) return;
    const active = controller;
    loading.value = true;
    error.value = false;
    try {
        const page = await listMessages(props.view, cursor.value, active.signal);
        if (active.signal.aborted) return;
        const additions = page.data.filter((message) => {
            if (seen.has(message.id)) return false;
            seen.add(message.id);
            return true;
        });
        messages.value = messages.value.concat(additions);
        cursor.value = page.next_cursor;
    } catch {
        if (!active.signal.aborted) error.value = true;
    } finally {
        if (!active.signal.aborted) loading.value = false;
    }
}

watch(
    () => props.view,
    () => {
        controller?.abort();
        controller = new AbortController();
        messages.value = [];
        cursor.value = null;
        selected.value = null;
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
                        'message-selected': selected === message.id,
                    }"
                    :aria-pressed="selected === message.id"
                    @click="selected = message.id"
                >
                    <span class="message-topline">
                        <span class="message-sender" :title="message.from_address">{{
                            message.from_name || message.from_address || 'Unknown sender'
                        }}</span>
                        <time
                            :datetime="message.sort_date"
                            :title="new Date(message.sort_date).toLocaleString()"
                            >{{ dateLabel(message.sort_date) }}</time
                        >
                    </span>
                    <span class="message-subject">{{ message.subject || '(No subject)' }}</span>
                    <span class="message-snippet">{{ message.snippet }}</span>
                    <span class="message-meta">
                        <span
                            class="message-account"
                            :title="accountMap.get(message.mail_account_id)?.email_address"
                            >{{
                                accountMap.get(message.mail_account_id)?.display_name ||
                                'Mail account'
                            }}</span
                        >
                        <span v-if="!message.is_read" class="message-status">Unread</span>
                        <span
                            v-if="message.has_attachments"
                            class="message-status"
                            aria-label="Has attachments"
                            >⌁ Attachment</span
                        >
                        <span v-if="message.is_starred" class="message-status">★ Starred</span>
                        <span v-if="message.is_important" class="message-status">! Important</span>
                        <span v-if="message.is_done" class="message-status">✓ Done</span>
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
