// Thin fetch() wrapper over the PHP backend. Every call goes through here so
// credentials (session cookie) and the CSRF header are never forgotten on a
// state-changing request.
let csrfToken = null;

export function setCsrfToken(token) {
  csrfToken = token;
}

async function request(method, path, body) {
  const opts = { method, credentials: 'include', headers: {} };

  if (body !== undefined) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  if (method !== 'GET' && csrfToken) {
    opts.headers['X-CSRF-Token'] = csrfToken;
  }

  const res = await fetch('/api' + path, opts);
  const text = await res.text();
  const data = text ? JSON.parse(text) : null;

  if (!res.ok) {
    const err = new Error((data && data.error) || ('HTTP ' + res.status));
    err.status = res.status;
    throw err;
  }
  return data;
}

export const api = {
  services: () => request('GET', '/services'),
  board: () => request('GET', '/board'),
  counters: () => request('GET', '/counters'),
  queue: () => request('GET', '/queue'),
  dashboard: () => request('GET', '/dashboard'),

  takeToken: (service) => request('POST', '/tokens', { service }),
  callNext: (counter) => request('POST', `/counters/${counter}/call-next`),
  complete: (counter, sale) => request('POST', `/counters/${counter}/complete`, sale),
  noShow: (counter) => request('POST', `/counters/${counter}/no-show`),
  openCounter: (counter) => request('POST', `/counters/${counter}/open`),
  closeCounter: (counter) => request('POST', `/counters/${counter}/close`),

  login: (email, password) => request('POST', '/auth/login', { email, password }),
  logout: () => request('POST', '/auth/logout'),
  me: () => request('GET', '/auth/me'),
};
