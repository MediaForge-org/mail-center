<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { getMessage, MessageDetailError, type MessageDetail } from '../../api/messages';
import type { AccountSummary } from '../../api/accounts';

const props = defineProps<{ messageId: number | null; accounts: AccountSummary[] }>();
defineEmits<{ close: [] }>();
const detail = shallowRef<MessageDetail | null>(null);
const plainMode = ref(false);
const state = ref<'idle' | 'loading' | 'ready' | 'missing' | 'error'>('idle');
const account = computed(() =>
    props.accounts.find((item) => item.id === detail.value?.mail_account_id),
);
const date = computed(() => detail.value?.date_header || detail.value?.received_at);
let controller: AbortController | undefined;

function sizeLabel(bytes: number): string {
    if (bytes < 1024) return bytes.toLocaleString() + ' B';
    if (bytes < 1024 * 1024)
        return (bytes / 1024).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' KB';
    return (bytes / (1024 * 1024)).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' MB';
}

async function load() {
    controller?.abort();
    const active = new AbortController();
    controller = active;
    detail.value = null;
    plainMode.value = false;
    if (props.messageId === null) {
        state.value = 'idle';
        return;
    }
    state.value = 'loading';
    try {
        const result = await getMessage(props.messageId, active.signal);
        if (active.signal.aborted) return;
        detail.value = result;
        state.value = 'ready';
    } catch (cause) {
        if (!active.signal.aborted)
            state.value =
                cause instanceof MessageDetailError && cause.notFound ? 'missing' : 'error';
    }
}
watch(() => props.messageId, load, { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <div class="reader-toolbar">
        <span>Message</span>
        <button v-if="messageId !== null" type="button" class="text-button" @click="$emit('close')">
            Close message
        </button>
    </div>
    <div class="reader-content" :aria-busy="state === 'loading'">
        <div v-if="state === 'idle'" class="reader-placeholder">
            <h2>Select a message</h2>
            <p>Its content will appear here.</p>
        </div>
        <p v-else-if="state === 'loading'" role="status">Loading message…</p>
        <div v-else-if="state === 'missing' || state === 'error'" role="alert">
            <p>
                {{
                    state === 'missing'
                        ? 'This message is unavailable or no longer accessible.'
                        : 'Unable to load this message. Please try again.'
                }}
            </p>
            <button v-if="state === 'error'" class="small-button" type="button" @click="load">
                Try again
            </button>
        </div>
        <article v-else-if="detail" class="message-detail">
            <header class="message-detail-header">
                <span class="message-account" :title="account?.email_address">{{
                    account?.display_name || 'Mail account'
                }}</span>
                <h2>{{ detail.subject || '(No subject)' }}</h2>
                <p class="reader-sender">
                    <strong>{{
                        detail.from_name || detail.from_address || 'Unknown sender'
                    }}</strong>
                    <span v-if="detail.from_name && detail.from_address">
                        &lt;{{ detail.from_address }}&gt;</span
                    >
                </p>
                <time v-if="date" :datetime="date">{{ new Date(date).toLocaleString() }}</time>
                <dl class="reader-recipients">
                    <template
                        v-for="field in ['to', 'cc', 'bcc', 'reply_to'] as const"
                        :key="field"
                    >
                        <template v-if="detail[field].length">
                            <dt>
                                {{
                                    { to: 'To', cc: 'Cc', bcc: 'Bcc', reply_to: 'Reply to' }[field]
                                }}
                            </dt>
                            <dd>
                                {{
                                    detail[field]
                                        .map((person) =>
                                            person.name
                                                ? person.name + ' <' + person.address + '>'
                                                : person.address,
                                        )
                                        .join(', ')
                                }}
                            </dd>
                        </template>
                    </template>
                </dl>
                <div class="reader-status">
                    <span>{{ detail.is_read ? 'Read' : 'Unread' }}</span>
                    <span v-if="detail.is_starred">★ Starred</span>
                    <span v-if="detail.is_important">! Important</span>
                    <span v-if="detail.is_done">✓ Done</span>
                    <span v-if="detail.has_attachments">⌁ Has attachments</span>
                </div>
            </header>
            <section
                v-if="detail.attachments?.length"
                class="reader-attachments"
                aria-label="Attachments"
            >
                <h3>Attachments</h3>
                <ul>
                    <li v-for="attachment in detail.attachments" :key="attachment.id">
                        <div class="attachment-description">
                            <span class="attachment-name" :title="attachment.filename">{{
                                attachment.filename
                            }}</span>
                            <span class="attachment-info"
                                >{{ sizeLabel(attachment.size_bytes) }} ·
                                {{
                                    attachment.content_type === 'application/octet-stream'
                                        ? 'File'
                                        : attachment.content_type
                                }}<template v-if="attachment.inline"> · Inline part</template></span
                            >
                        </div>
                        <a
                            v-if="attachment.downloadable"
                            :href="`/api/messages/${detail.id}/attachments/${attachment.id}`"
                            download
                            referrerpolicy="no-referrer"
                            :aria-label="`Download ${attachment.filename}`"
                            >Download</a
                        >
                    </li>
                </ul>
            </section>
            <div v-if="detail.html_available" class="reader-format">
                <button type="button" class="text-button" @click="plainMode = !plainMode">
                    {{ plainMode ? 'Show HTML' : 'Show plain text' }}
                </button>
                <span v-if="detail.remote_content_count"
                    >{{ detail.remote_content_count }} remote images blocked</span
                >
            </div>
            <iframe
                v-if="detail.html_available && !plainMode"
                :key="detail.id"
                class="reader-html"
                :src="`/api/messages/${detail.id}/render`"
                title="Email content"
                sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                referrerpolicy="no-referrer"
            ></iframe>
            <div v-else-if="detail.body_status === 'available'" class="reader-body">
                {{ detail.text_plain }}
            </div>
            <p v-else class="reader-body-unavailable">
                Plain-text content is unavailable for this message.
            </p>
        </article>
    </div>
</template>
