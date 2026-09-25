export async function request(path: string, init: RequestInit = {}): Promise<Response> {
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
