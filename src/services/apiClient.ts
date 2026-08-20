let csrfToken = '';
let unauthorizedHandler: (() => void) | null = null;

// Menyimpan CSRF token hanya di memory browser agar tidak terekspos melalui persistent storage.
export function setCsrfToken(token: string): void { csrfToken = token; }

// Memasang callback global ketika backend menyatakan session tidak lagi berlaku.
export function setUnauthorizedHandler(handler: (() => void) | null): void { unauthorizedHandler = handler; }

// Mengirim request API dengan cookie session dan CSRF untuk seluruh request mutasi.
export async function apiFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
  const headers = new Headers(init.headers);
  const method = (init.method ?? 'GET').toUpperCase();
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && csrfToken) headers.set('X-CSRF-Token', csrfToken);
  const response = await fetch(input, { ...init, headers, credentials: 'same-origin' });
  if (response.status === 401) unauthorizedHandler?.();
  return response;
}
