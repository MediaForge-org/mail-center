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
    accountId?: number | null,
): Promise<MessagePage> {
    const params = new URLSearchParams({ view, limit: '50' });
    if (accountId != null) params.set('account_id', String(accountId));
    if (cursor) params.set('cursor', cursor);
    const response = await request(`/api/messages?${params}`, { signal });
    if (!response.ok) throw new Error('Unable to load this mailbox.');
    return (await response.json()) as MessagePage;
}

export type Attachment = {
    id: number;
    filename: string;
    content_type: string;
    size_bytes: number;
    inline: boolean;
    downloadable: boolean;
};
export type MessageDetail = Omit<MessageListItem, 'snippet' | 'sort_date'> & {
    cc: MessageListItem['to'];
    bcc: MessageListItem['to'];
    reply_to: MessageListItem['to'];
    date_header: string | null;
    remote_status: 'present' | 'missing' | 'removed';
    read_writeback: 'pending' | 'processing' | 'failed' | null;
    attachments: Attachment[];
    html_available: boolean;
    remote_content_count: number;
    remote_images_always?: boolean;
    body_status: 'available' | 'unavailable';
    text_plain: string | null;
};
export class MessageDetailError extends Error {
    constructor(public readonly notFound: boolean) {
        super('Unable to load this message.');
    }
}
export async function getMessage(id: number, signal: AbortSignal): Promise<MessageDetail> {
    const response = await request(`/api/messages/${id}`, { signal });
    if (!response.ok) throw new MessageDetailError(response.status === 404);
    return ((await response.json()) as { data: MessageDetail }).data;
}

export type MailboxCounts = {
    views: Record<MailboxView, { total: number; unread: number }>;
    accounts: Record<string, { total: number; unread: number }>;
};
export async function getMailboxCounts(signal: AbortSignal): Promise<MailboxCounts> {
    const response = await request('/api/mailbox-counts', { signal });
    if (!response.ok) throw new Error('Counts unavailable.');
    const data = (await response.json()) as MailboxCounts;
    if (!data.views || !data.accounts) throw new Error('Counts unavailable.');
    return data;
}

export type ReadChange = {
    id: number;
    is_read: boolean;
    read_writeback: MessageDetail['read_writeback'];
};
export async function setMessageRead(id: number, is_read: boolean): Promise<ReadChange> {
    const response = await request(`/api/messages/${id}/read`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ is_read }),
    });
    if (!response.ok) throw new Error('Unable to change read state. Please try again.');
    return ((await response.json()) as { data: ReadChange }).data;
}

export async function remoteImageConsent(id: number, mode: 'once' | 'always' | 'block') {
    const response = await request(`/api/messages/${id}/remote-images`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ mode }),
    });
    if (!response.ok) throw new Error('Unable to update image preference.');
    return (await response.json()) as { grant: string | null; always: boolean };
}
export async function revokeRemoteImageConsent(grant: string) {
    await request('/api/remote-image-consent', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ grant }),
    }).catch(() => {});
}
