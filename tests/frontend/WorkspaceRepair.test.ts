import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import MailWorkspace from '../../resources/js/features/workspace/MailWorkspace.vue';
import SyncStatus from '../../resources/js/features/accounts/SyncStatus.vue';
import type { AccountSummary } from '../../resources/js/api/accounts';
const wrappers: ReturnType<typeof mount>[] = [];
afterEach(() => {
    wrappers.forEach((w) => w.unmount());
    wrappers.length = 0;
    localStorage.clear();
    vi.useRealTimers();
});
function workspace() {
    const w = mount(MailWorkspace, {
        props: { userName: 'Operator' },
        global: { stubs: { MessageList: true, MessageReader: true } },
    });
    wrappers.push(w);
    return w;
}
it('resizes with keyboard and pointer, clamps panes, and restores persisted widths', async () => {
    localStorage.setItem('mailcenter.pane-widths.v1', JSON.stringify({ sidebar: 200, list: 340 }));
    const w = workspace();
    await flushPromises();
    const dividers = w.findAll('[role="separator"]');
    expect(dividers).toHaveLength(2);
    expect(dividers[0].attributes('aria-valuenow')).toBe('200');
    await dividers[0].trigger('keydown', { key: 'ArrowRight' });
    expect(dividers[0].attributes('aria-valuenow')).toBe('220');
    await dividers[1].trigger('keydown', { key: 'End' });
    expect(Number(dividers[1].attributes('aria-valuenow'))).toBeLessThanOrEqual(
        window.innerWidth - 220 - 372,
    );
    await dividers[0].trigger('pointerdown', { button: 0, clientX: 220, pointerId: 1 });
    window.dispatchEvent(Object.assign(new Event('pointermove'), { clientX: 250, pointerId: 1 }));
    window.dispatchEvent(Object.assign(new Event('pointerup'), { pointerId: 1 }));
    await flushPromises();
    expect(dividers[0].attributes('aria-valuenow')).toBe('250');
    const restored = workspace();
    await flushPromises();
    expect(restored.find('[role="separator"]').attributes('aria-valuenow')).toBe('250');
    await dividers[0].trigger('keydown', { key: 'Home' });
    expect(dividers[0].attributes('aria-valuenow')).toBe('180');
});
it('ignores corrupt preferences and hides future navigation', async () => {
    localStorage.setItem('mailcenter.pane-widths.v1', '{bad');
    const w = workspace();
    await flushPromises();
    expect(w.text()).toContain('Global workspace');
    for (const label of [
        'Coming later',
        'Starred',
        'Important',
        'Completed',
        'Reloads',
        'Support',
        'Withdrawals',
        'Verification',
        'Done',
    ])
        expect(w.text()).not.toContain(label);
    expect(w.findAll('[role="separator"]')).toHaveLength(2);
});
it('shows authoritative interval/last/next timestamps and distinct paused, running and error states', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-26T12:00:00Z'));
    const account = {
        enabled: true,
        sync_enabled: true,
        sync_status: 'idle',
        sync_interval_seconds: 180,
        last_successful_sync_at: '2026-09-26T11:59:00Z',
        next_sync_at: '2026-09-26T12:02:00Z',
    } as AccountSummary;
    const w = mount(SyncStatus, { props: { account } });
    wrappers.push(w);
    expect(w.text()).toContain('Up to date');
    expect(w.text()).toContain('Auto sync every 3 min');
    expect(w.text()).toContain('Last synced');
    expect(w.text()).toContain('Next sync ~');
    await w.setProps({ account: { ...account, sync_status: 'syncing' } });
    expect(w.text()).toContain('Syncing…');
    expect(w.text()).not.toContain('Next sync');
    await w.setProps({ account: { ...account, sync_enabled: false } });
    expect(w.text()).toContain('Auto sync paused');
    expect(w.text()).not.toContain('Next sync');
    await w.setProps({ account: { ...account, sync_status: 'backing_off' } });
    expect(w.text()).toContain('Retrying after an error');
    expect(w.text()).toContain('Retry ~');
    await w.setProps({ account: { ...account, sync_status: 'auth_failed', next_sync_at: null } });
    expect(w.text()).toContain('Password rejected');
    expect(w.text()).not.toContain('Next sync');
    await w.setProps({ account: { ...account, enabled: false } });
    expect(w.text()).toContain('Account disabled');
});
