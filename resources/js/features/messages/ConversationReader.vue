<script setup lang="ts">
import { onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { getConversation, type ConversationItem, type ReadChange } from '../../api/messages';
import type { AccountSummary } from '../../api/accounts';
import MessageReader from './MessageReader.vue';

const props = defineProps<{
    messageId: number | null;
    accounts: AccountSummary[];
    refreshVersion?: number;
}>();
defineEmits<{ close: []; readChanged: [change: ReadChange] }>();
const items = shallowRef<ConversationItem[]>([]);
const expanded = ref(new Set<number>());
const cursor = ref<string | null>(null);
const loading = ref(false);
const error = ref(false);
let controller: AbortController | undefined;
let selected: number | null = null;
async function load(more = false) {
    if (more && loading.value) return;
    if (!more) {
        controller?.abort();
        controller = new AbortController();
        if (selected !== props.messageId) {
            items.value = [];
            expanded.value = new Set(props.messageId === null ? [] : [props.messageId]);
        }
        selected = props.messageId;
        cursor.value = null;
    }
    const active = controller;
    if (props.messageId === null || !active) return;
    loading.value = true;
    error.value = false;
    try {
        const page = await getConversation(
            props.messageId,
            more ? cursor.value : null,
            active.signal,
        );
        if (active.signal.aborted) return;
        if (!more) items.value = [];
        const known = new Set(items.value.map((item) => item.id));
        items.value = [...items.value, ...page.data.filter((item) => !known.has(item.id))];
        cursor.value = page.next_cursor;
    } catch {
        if (!active.signal.aborted) error.value = true;
    } finally {
        if (!active.signal.aborted) loading.value = false;
    }
}
function toggle(id: number) {
    if (expanded.value.has(id)) expanded.value.delete(id);
    else expanded.value.add(id);
}
watch(
    () => [props.messageId, props.refreshVersion],
    () => void load(),
    { immediate: true },
);
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <div class="conversation-reader">
        <div v-if="messageId !== null" class="reader-toolbar">
            <span>Conversation · oldest first</span>
            <button class="text-button" type="button" @click="$emit('close')">Close message</button>
        </div>
        <p v-if="loading" class="conversation-status" role="status">Loading conversation…</p>
        <p v-if="error" class="conversation-status" role="alert">
            Unable to load the conversation.
            <button class="text-button" type="button" @click="load(!!items.length)">
                Try again
            </button>
        </p>
        <section
            v-for="item in items"
            :key="item.id"
            class="conversation-message"
            :class="{ 'conversation-selected': item.id === messageId }"
        >
            <button
                type="button"
                class="conversation-summary"
                :aria-expanded="expanded.has(item.id)"
                @click="toggle(item.id)"
            >
                <strong>{{ item.from_name || item.from_address || 'Unknown sender' }}</strong>
                <span>{{ item.subject || '(No subject)' }}</span>
                <small
                    >{{ new Date(item.sort_date).toLocaleString()
                    }}<template v-if="item.id === messageId"> · Selected message</template
                    ><template v-if="item.remote_status === 'removed'">
                        · Removed remotely</template
                    ></small
                >
            </button>
            <MessageReader
                v-if="expanded.has(item.id)"
                :message-id="item.id"
                :accounts="accounts"
                :refresh-version="refreshVersion"
                embedded
                @read-changed="$emit('readChanged', $event)"
            />
        </section>
        <button
            v-if="cursor"
            class="small-button conversation-more"
            :disabled="loading"
            @click="load(true)"
        >
            Load more conversation messages
        </button>
        <!-- Selected content remains usable while membership loads, fails, or is on a later page. -->
        <MessageReader
            v-if="
                messageId === null ||
                ((!loading || items.length > 0) && !items.some((item) => item.id === messageId))
            "
            :key="messageId ?? 'empty'"
            :message-id="messageId"
            :accounts="accounts"
            :refresh-version="refreshVersion"
            :embedded="messageId !== null"
            @close="$emit('close')"
            @read-changed="$emit('readChanged', $event)"
        />
    </div>
</template>
