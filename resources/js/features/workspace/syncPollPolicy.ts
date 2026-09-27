import type { AccountSummary } from '../../api/accounts';

/** Bounded acceleration after an accepted manual request; durable versions remain authoritative. */
export function syncPollPolicy(clock: () => number = Date.now) {
    const requests = new Map<number, number>();
    let until = 0;
    return {
        accepted(id: number, generation: number) {
            requests.set(id, generation);
            until = clock() + 30000;
        },
        delay(accounts: AccountSummary[]) {
            for (const [id, generation] of requests) {
                const account = accounts.find((a) => a.id === id);
                if (!account || (account.sync_request?.completed_generation ?? -1) >= generation)
                    requests.delete(id);
            }
            if (clock() < until && requests.size) return 400;
            requests.clear();
            if (
                accounts.some(
                    (a) => a.enabled && a.sync_enabled && a.realtime?.state === 'watching',
                )
            )
                return 500;
            return accounts.some(
                (a) =>
                    a.enabled &&
                    a.sync_enabled &&
                    (a.sync_status === 'syncing' || a.sync_request?.state === 'queued'),
            )
                ? 1000
                : 30000;
        },
    };
}
