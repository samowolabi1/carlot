// JSON requests outside Inertia (photo uploads, VIN decode). Laravel reads the CSRF
// token from the X-XSRF-TOKEN header, which mirrors the XSRF-TOKEN cookie.

export class HttpError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function json<T>(method: string, url: string, body?: unknown): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const errors = (data.errors ?? {}) as Record<string, string[]>;
        const first = Object.values(errors)[0]?.[0];
        throw new HttpError(first ?? data.message ?? 'Something went wrong. Try again.', response.status, errors);
    }

    return data as T;
}

/** Upload with progress (fetch has no upload progress events). */
export function send(
    method: string,
    url: string,
    body: Blob | FormData,
    headers: Record<string, string>,
    onProgress: (fraction: number) => void,
): Promise<unknown> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open(method, url);
        Object.entries(headers).forEach(([name, value]) => xhr.setRequestHeader(name, value));
        xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded / e.total);
        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(xhr.responseText ? safeParse(xhr.responseText) : null);
            } else {
                const data = safeParse(xhr.responseText) as { message?: string; errors?: Record<string, string[]> } | null;
                reject(new HttpError(Object.values(data?.errors ?? {})[0]?.[0] ?? data?.message ?? 'Upload failed.', xhr.status));
            }
        };
        xhr.onerror = () => reject(new HttpError('Upload failed. Check your connection and try again.', 0));
        xhr.send(body);
    });
}

function safeParse(text: string): unknown {
    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
}
