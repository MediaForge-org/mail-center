import type { SyncStatus } from '../../api/accounts';

export const statusLabels: Record<SyncStatus, string> = {
    never_synced: 'Waiting for first sync',
    syncing: 'Syncing…',
    idle: 'Up to date',
    backing_off: 'Retrying after an error',
    auth_failed: 'Password rejected',
    error: 'Needs attention',
};

export function statusTone(status: SyncStatus): 'ok' | 'busy' | 'bad' {
    if (status === 'idle') return 'ok';
    if (status === 'syncing' || status === 'never_synced') return 'busy';
    return 'bad';
}
