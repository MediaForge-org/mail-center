<script setup lang="ts">
import { computed } from 'vue';
import type { AccountSummary } from '../../api/accounts';
import { statusLabels } from './statusLabel';
const props = defineProps<{ account: AccountSummary; compact?: boolean }>();
const active = computed(() => props.account.enabled && props.account.sync_enabled);
const label = computed(() =>
    !props.account.enabled
        ? 'Account disabled'
        : !props.account.sync_enabled
          ? 'Auto sync paused'
          : statusLabels[props.account.sync_status],
);
const interval = computed(() => {
    const seconds = props.account.sync_interval_seconds;
    if (!Number.isFinite(seconds) || seconds <= 0) return 'Automatic sync';
    return seconds % 60 === 0
        ? `Auto sync every ${seconds / 60} min`
        : `Auto sync every ${seconds} sec`;
});
const time = (value: string) =>
    new Date(value).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
const next = computed(() => {
    if (!active.value || props.account.sync_status === 'syncing' || !props.account.next_sync_at)
        return null;
    if (new Date(props.account.next_sync_at).getTime() <= Date.now())
        return 'Due · waiting for scheduler';
    return `${props.account.sync_status === 'backing_off' || props.account.sync_status === 'error' ? 'Retry' : 'Next sync'} ~${time(props.account.next_sync_at)}`;
});
</script>
<template>
    <div class="sync-summary" :class="{ 'sync-summary-compact': compact }" role="status">
        <strong>{{ label }}</strong>
        <span v-if="active">{{ interval }}</span>
        <span
            v-if="account.last_successful_sync_at"
            :title="new Date(account.last_successful_sync_at).toLocaleString()"
            >Last synced {{ time(account.last_successful_sync_at) }}</span
        >
        <span v-else>No completed sync yet</span>
        <span
            v-if="next"
            title="The scheduler checks due accounts every minute; queued work may start later"
            >{{ next }}</span
        >
    </div>
</template>
