import { request } from './http';

export type Folder = {
    id: number;
    name: string;
    system_role: 'inbox' | 'sent' | 'archive' | null;
    position: number;
};

async function errorMessage(response: Response, fallback: string): Promise<string> {
    try {
        const body = (await response.json()) as { message?: string };
        return body.message || fallback;
    } catch {
        return fallback;
    }
}

export async function listFolders(): Promise<Folder[]> {
    const response = await request('/api/folders');
    if (!response.ok) throw new Error('Unable to load folders.');
    return ((await response.json()) as { data: Folder[] }).data;
}

export async function createFolder(name: string): Promise<Folder> {
    const response = await request('/api/folders', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name }),
    });
    if (!response.ok)
        throw new Error(await errorMessage(response, 'Unable to create this folder.'));
    return ((await response.json()) as { data: Folder }).data;
}

export async function renameFolder(id: number, name: string): Promise<Folder> {
    const response = await request(`/api/folders/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name }),
    });
    if (!response.ok)
        throw new Error(await errorMessage(response, 'Unable to rename this folder.'));
    return ((await response.json()) as { data: Folder }).data;
}

export async function reorderFolders(folderIds: number[]): Promise<void> {
    const response = await request('/api/folders/order', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ folder_ids: folderIds }),
    });
    if (!response.ok) throw new Error(await errorMessage(response, 'Unable to reorder folders.'));
}

export type FolderDeleteResult = { id: number; moved_count: number; inbox_folder_id: number };
export async function deleteFolder(id: number): Promise<FolderDeleteResult> {
    const response = await request(`/api/folders/${id}`, { method: 'DELETE' });
    if (!response.ok)
        throw new Error(await errorMessage(response, 'Unable to delete this folder.'));
    return ((await response.json()) as { data: FolderDeleteResult }).data;
}
