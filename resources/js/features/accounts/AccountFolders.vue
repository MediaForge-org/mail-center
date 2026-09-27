<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { listRemoteFolders, setFolderSync, type RemoteFolder } from '../../api/accounts';

const props = defineProps<{ accountId: number }>();
const emit = defineEmits<{ changed: [] }>();
const folders = ref<RemoteFolder[]>([]);
const loading = ref(true);
const error = ref('');
const busy = ref<number | null>(null);

const purpose: Record<string, string> = {
    inbox: 'Received mail',
    sent: 'Mail sent from this account',
};
const roleLabel = (role: string) => (role === 'inbox' ? 'Inbox' : role === 'sent' ? 'Sent' : role);
const ordered = computed(() =>
    [...folders.value].sort(
        (a, b) =>
            Number(b.role === 'inbox') - Number(a.role === 'inbox') ||
            Number(b.role === 'sent') - Number(a.role === 'sent') ||
            a.name.localeCompare(b.name),
    ),
);

onMounted(async () => {
    try {
        folders.value = await listRemoteFolders(props.accountId);
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : 'Unable to load folders.';
    } finally {
        loading.value = false;
    }
});

async function toggle(folder: RemoteFolder) {
    busy.value = folder.id;
    error.value = '';
    try {
        const updated = await setFolderSync(folder.id, !folder.sync_enabled);
        folders.value = folders.value.map((item) => (item.id === folder.id ? updated : item));
        emit('changed');
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : 'Unable to update this folder.';
    } finally {
        busy.value = null;
    }
}
</script>
<template>
    <section class="account-folders" aria-label="Synchronized folders">
        <p class="muted">Choose which remote IMAP folders MailCenter synchronizes.</p>
        <p v-if="loading" class="muted">Loading folders…</p>
        <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>
        <p v-else-if="!folders.length" class="muted">
            No folders discovered yet. They appear after the first sync.
        </p>
        <ul v-else class="folder-sync-list">
            <li v-for="folder in ordered" :key="folder.id">
                <label>
                    <input
                        type="checkbox"
                        :checked="folder.sync_enabled"
                        :disabled="busy !== null || !folder.selectable"
                        @change="toggle(folder)"
                    />
                    <strong>{{ folder.name }}</strong>
                    <span class="muted">
                        {{ roleLabel(folder.role) }}
                        <template v-if="purpose[folder.role]">
                            — {{ purpose[folder.role] }}</template
                        >
                        <template v-else> — optional sync folder</template>
                        · {{ folder.sync_enabled ? 'Syncing' : 'Not synced' }}
                    </span>
                </label>
            </li>
        </ul>
    </section>
</template>
