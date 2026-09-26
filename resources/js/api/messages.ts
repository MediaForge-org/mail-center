import { request } from './http';

export type MailboxView = 'all' | 'inbox' | 'unread';

/** Only establishes mailbox availability; no partial-page counts are presented. */
export async function mailboxHasMessages(view: MailboxView, signal: AbortSignal): Promise<boolean> {
    const response = await request(`/api/messages?view=${view}&limit=1`, { signal });
    if (!response.ok) throw new Error('Unable to load this mailbox.');
    const page = (await response.json()) as { data: unknown[] };
    return page.data.length > 0;
}
