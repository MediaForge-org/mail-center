import { request } from './http';

export type MailboxView = 'all' | 'inbox' | 'unread';
export type MessageListItem = {
    id: number;
    mail_account_id: number;
    subject: string;
    from_name: string;
    from_address: string;
    to: { name: string; address: string }[];
    snippet: string;
    sort_date: string;
    received_at: string | null;
    is_read: boolean;
    is_starred: boolean;
    is_important: boolean;
    is_done: boolean;
    has_attachments: boolean;
    direction: 'inbound' | 'outbound';
};
export type MessagePage = { data: MessageListItem[]; next_cursor: string | null };

export async function listMessages(
    view: MailboxView,
    cursor: string | null,
    signal: AbortSignal,
): Promise<MessagePage> {
    const params = new URLSearchParams({ view, limit: '50' });
    if (cursor) params.set('cursor', cursor);
    const response = await request(`/api/messages?${params}`, { signal });
    if (!response.ok) throw new Error('Unable to load this mailbox.');
    return (await response.json()) as MessagePage;
}
