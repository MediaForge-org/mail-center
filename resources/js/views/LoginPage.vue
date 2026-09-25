<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { login } from '../api/auth';

const router = useRouter();
const email = ref('');
const password = ref('');
const pending = ref(false);
const error = ref('');

async function submit() {
    pending.value = true;
    error.value = '';
    try {
        await login(email.value, password.value);
        await router.push('/mail');
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : 'Unable to sign in.';
    } finally {
        pending.value = false;
    }
}
</script>

<template>
    <main class="auth-page">
        <section class="auth-card" aria-labelledby="login-heading">
            <div class="brand-mark" aria-hidden="true">M</div>
            <p class="eyebrow">MAILCENTER</p>
            <h1 id="login-heading">Your workspace for mail.</h1>
            <p class="muted">Sign in to your private workspace.</p>
            <form class="auth-form" @submit.prevent="submit">
                <label for="email">Email</label>
                <input id="email" v-model="email" autocomplete="username" type="email" required />
                <label for="password">Password</label>
                <input
                    id="password"
                    v-model="password"
                    autocomplete="current-password"
                    type="password"
                    required
                />
                <p v-if="error" role="alert" class="form-error">{{ error }}</p>
                <button class="primary-button" :disabled="pending" type="submit">
                    {{ pending ? 'Signing in…' : 'Sign in' }}
                </button>
            </form>
            <p class="auth-footnote">Accounts are created by the workspace operator.</p>
        </section>
    </main>
</template>
