<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import {
    getMessage,
    setMessageRead,
    MessageDetailError,
    type MessageDetail,
    type ReadChange,
} from '../../api/messages';
import type { AccountSummary } from '../../api/accounts';

const props = defineProps<{ messageId: number | null; accounts: AccountSummary[] }>();
const emit = defineEmits<{ close: []; readChanged: [change: ReadChange] }>();
const savingRead = ref(false);
const readError = ref('');
const detail = shallowRef<MessageDetail | null>(null);
const plainMode = ref(false);
const resourceError = ref(false);
const frameHeight = ref(240);
let frameObserver: ResizeObserver | undefined;
function frameLoaded(event: Event) {
    const frame = event.target as HTMLIFrameElement;
    if (frame.getAttribute('src') !== `/api/messages/${detail.value?.id}/render`) return;
    const document = frame.contentDocument;
    resourceError.value = !!document && document.contentType !== 'text/html';
    frameObserver?.disconnect();
    if (resourceError.value || !document?.body) return;
    const measure = () => {
        frameHeight.value = Math.max(
            100,
            Math.min(2000, Math.ceil(document.body.getBoundingClientRect().height + 32)),
        );
    };
    measure();
    if (typeof ResizeObserver !== 'undefined') {
        frameObserver = new ResizeObserver(measure);
        frameObserver.observe(document.body);
    }
}
const state = ref<'idle' | 'loading' | 'ready' | 'missing' | 'error'>('idle');
const account = computed(() =>
    props.accounts.find((item) => item.id === detail.value?.mail_account_id),
);
const date = computed(() => detail.value?.date_header || detail.value?.received_at);
let controller: AbortController | undefined;

async function changeRead(desired: boolean) {
    if (!detail.value || savingRead.value) return;
    const id = detail.value.id;
    const active = controller;
    savingRead.value = true;
    readError.value = '';
    try {
        const change = await setMessageRead(id, desired);
        if (controller === active && detail.value?.id === id)
            detail.value = { ...detail.value, ...change };
        emit('readChanged', change);
    } catch {
        if (controller === active)
            readError.value = 'Unable to change read state. Please try again.';
    } finally {
        if (controller === active) savingRead.value = false;
    }
}

function sizeLabel(bytes: number): string {
    if (bytes < 1024) return bytes.toLocaleString() + ' B';
    if (bytes < 1024 * 1024)
        return (bytes / 1024).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' KB';
    return (bytes / (1024 * 1024)).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' MB';
}

async function load() {
    frameObserver?.disconnect();
    frameHeight.value = 240;
    controller?.abort();
    const active = new AbortController();
    controller = active;
    detail.value = null;
    savingRead.value = false;
    readError.value = '';
    plainMode.value = false;
    resourceError.value = false;
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
onBeforeUnmount(() => {
    controller?.abort();
    frameObserver?.disconnect();
});
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
                <h2>{{ detail.subject || '(No subject)' }}</h2>
                <p class="reader-sender">
                    <strong>{{
                        detail.from_name || detail.from_address || 'Unknown sender'
                    }}</strong>
                    <span v-if="detail.from_name && detail.from_address"
                        >&lt;{{ detail.from_address }}&gt;</span
                    >
                    <span class="reader-to">
                        →
                        {{
                            detail.to.map((person) => person.name || person.address).join(', ') ||
                            'Undisclosed recipients'
                        }}</span
                    >
                </p>
                <details class="recipient-details">
                    <summary>Recipient details</summary>
                    <dl class="reader-recipients">
                        <template
                            v-for="field in ['to', 'cc', 'bcc', 'reply_to'] as const"
                            :key="field"
                        >
                            <template v-if="detail[field].length">
                                <dt>
                                    {{
                                        { to: 'To', cc: 'Cc', bcc: 'Bcc', reply_to: 'Reply to' }[
                                            field
                                        ]
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
                </details>
                <div class="reader-header-meta">
                    <time v-if="date" :datetime="date">{{ new Date(date).toLocaleString() }}</time
                    ><span class="message-account" :title="account?.email_address">{{
                        account?.display_name || 'Mail account'
                    }}</span>
                </div>
                <div class="reader-read-action">
                    <span class="reader-current-state">{{
                        detail.is_read ? 'Read' : 'Unread'
                    }}</span>
                    <button
                        class="small-button"
                        type="button"
                        :disabled="savingRead"
                        @click="changeRead(!detail.is_read)"
                    >
                        {{ savingRead ? 'Saving…' : detail.is_read ? 'Mark unread' : 'Mark read' }}
                    </button>
                    <span
                        v-if="
                            detail.read_writeback === 'pending' ||
                            detail.read_writeback === 'processing'
                        "
                        class="muted"
                        >Remote read update pending</span
                    >
                    <template v-if="detail.read_writeback === 'failed'">
                        <span class="muted">Local read state saved; remote update failed.</span>
                        <button
                            class="text-button"
                            type="button"
                            :disabled="savingRead"
                            @click="changeRead(detail.is_read)"
                        >
                            Retry remote update
                        </button>
                    </template>
                    <p v-if="readError" role="alert" class="form-error">{{ readError }}</p>
                </div>
                <div class="reader-status">
                    <span v-if="detail.is_starred">★ Starred</span>
                    <span v-if="detail.is_important">! Important</span>
                    <span v-if="detail.is_done">✓ Done</span>
                    <span v-if="detail.has_attachments">⌁ Has attachments</span>
                </div>
            </header>
            <div v-if="detail.html_available" class="reader-format">
                <button type="button" class="text-button" @click="plainMode = !plainMode">
                    {{ plainMode ? 'Show HTML' : 'Show plain text' }}
                </button>
                <span v-if="detail.remote_content_count"
                    >{{ detail.remote_content_count }} remote images blocked</span
                >
            </div>
            <p v-if="resourceError && !plainMode" class="form-error" role="alert">
                Unable to open HTML content. Your session may have expired. Reopen the message or
                use plain text.
            </p>
            <iframe
                v-if="detail.html_available && !plainMode && !resourceError"
                @load="frameLoaded"
                :key="detail.id"
                class="reader-html"
                :style="{ height: `${frameHeight}px` }"
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
                            target="_blank"
                            rel="noopener noreferrer"
                            referrerpolicy="no-referrer"
                            :aria-label="`Download ${attachment.filename}`"
                            >Download</a
                        >
                    </li>
                </ul>
            </section>
        </article>
    </div>
</template>
