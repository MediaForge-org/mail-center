export type CurrentUser = { id: number; name: string; email: string };

async function request(path: string, init: RequestInit = {}): Promise<Response> {
    const token = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    return fetch(path, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
            ...init.headers,
        },
    });
}

export async function currentUser(): Promise<CurrentUser | null> {
    const response = await request('/api/me');
    if (response.status === 401) return null;
    if (!response.ok) throw new Error('Unable to load your session.');
    return (await response.json()) as CurrentUser;
}

export async function login(email: string, password: string): Promise<void> {
    const csrf = await request('/sanctum/csrf-cookie');
    if (!csrf.ok) throw new Error('Unable to start a secure session.');

    const response = await request('/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password }),
    });
    if (response.status === 422 || response.status === 429) {
        throw new Error('Check your credentials or try again shortly.');
    }
    if (!response.ok) throw new Error('Unable to sign in right now.');
}

export async function logout(): Promise<void> {
    const response = await request('/logout', { method: 'POST' });
    if (!response.ok) throw new Error('Unable to sign out right now.');
}
