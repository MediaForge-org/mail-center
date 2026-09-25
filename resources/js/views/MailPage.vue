<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { currentUser, logout } from '../api/auth';
import MailWorkspace from '../features/workspace/MailWorkspace.vue';

const router = useRouter();
const name = ref('');
const loading = ref(true);
const error = ref('');

onMounted(async () => {
    try {
        const user = await currentUser();
        if (!user) {
            await router.replace('/login');
            return;
        }
        name.value = user.name;
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : 'Unable to load the workspace.';
    } finally {
        loading.value = false;
    }
});

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
    <MailWorkspace v-else :user-name="name" @sign-out="signOut" />
</template>
