import { describe, expect, it } from 'vitest';
import { syncPollPolicy } from '../../resources/js/features/workspace/syncPollPolicy';
import type { AccountSummary } from '../../resources/js/api/accounts';

describe('manual freshness polling', () => {
    it('accelerates accepted requests and stops on completion or the bounded deadline', () => {
        let now = 1000;
        const policy = syncPollPolicy(() => now);
        const account = {
            id: 1,
            enabled: true,
            sync_enabled: true,
            sync_status: 'idle',
            sync_request: { generation: 1, completed_generation: 0, state: 'queued' },
        } as AccountSummary;
        policy.accepted(1, 1);
        expect(policy.delay([account])).toBe(400);
        account.sync_request!.completed_generation = 1;
        account.sync_request!.state = 'complete';
        expect(policy.delay([account])).toBe(30000);
        policy.accepted(1, 2);
        expect(policy.delay([account])).toBe(400);
        now += 30001;
        expect(policy.delay([account])).toBe(30000);
    });
    it('uses active realtime cadence without claiming polling-only accounts are realtime', () => {
        const policy = syncPollPolicy();
        const account = {
            id: 1,
            enabled: true,
            sync_enabled: true,
            sync_status: 'idle',
            realtime: { state: 'watching', folders: ['Inbox', 'Sent'] },
        } as AccountSummary;
        expect(policy.delay([account])).toBe(500);
        account.realtime!.state = 'polling';
        expect(policy.delay([account])).toBe(30000);
    });
});
