<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { listAccounts, type AccountSummary } from '../api/accounts';
import { mailboxHasMessages, type MailboxView } from '../api/messages';
import { currentUser, logout } from '../api/auth';
import AccountsPanel from '../features/accounts/AccountsPanel.vue';
import MailWorkspace from '../features/workspace/MailWorkspace.vue';

const router = useRouter();
const route = useRoute();
const accounts = ref<AccountSummary[]>([]);
const section = computed<'mail' | 'accounts'>(() =>
    route.params.view === 'accounts' ? 'accounts' : 'mail',
);
const view = computed<MailboxView>(() =>
    route.params.view === 'inbox' || route.params.view === 'unread' ? route.params.view : 'all',
);
const mailboxState = ref<'loading' | 'ready' | 'empty' | 'error'>('loading');
let timer: ReturnType<typeof setInterval> | undefined;

async function refreshAccounts() {
    try {
        accounts.value = await listAccounts();
    } catch {
        // Keep the last known list; the next poll retries.
    }
}
const name = ref('');
const loading = ref(true);
const error = ref('');

watch([loading, section, view], async ([opening, activeSection, activeView], _, onCleanup) => {
    if (opening || activeSection !== 'mail' || !name.value) return;
    const controller = new AbortController();
    onCleanup(() => controller.abort());
    mailboxState.value = 'loading';
    try {
        const hasMessages = await mailboxHasMessages(activeView, controller.signal);
        if (!controller.signal.aborted) mailboxState.value = hasMessages ? 'ready' : 'empty';
    } catch {
        if (!controller.signal.aborted) mailboxState.value = 'error';
    }
});

onMounted(async () => {
    try {
        const user = await currentUser();
        if (!user) {
            await router.replace('/login');
            return;
        }
        name.value = user.name;
        await refreshAccounts();
        timer = setInterval(() => {
            const busy = accounts.value.some((a) =>
                ['syncing', 'never_synced'].includes(a.sync_status),
            );
            if (busy || section.value === 'accounts') void refreshAccounts();
        }, 5000);
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : 'Unable to load the workspace.';
    } finally {
        loading.value = false;
    }
});

onBeforeUnmount(() => clearInterval(timer));

async function signOut() {
    try {
        await logout();
        await router.replace('/login');
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : 'Unable to sign out.';
    }
}
</script>

<template>
    <main v-if="loading" class="loading-screen">Opening workspace…</main>
    <main v-else-if="error" class="loading-screen" role="alert">{{ error }}</main>
    <MailWorkspace
        v-else
        :user-name="name"
        :accounts="accounts"
        :section="section"
        :view="view"
        :mailbox-state="mailboxState"
        @sign-out="signOut"
        @navigate="(target) => router.push(`/mail/${target}`)"
    >
        <template #accounts>
            <AccountsPanel :accounts="accounts" @changed="refreshAccounts" />
        </template>
    </MailWorkspace>
</template>
