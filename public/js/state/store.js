// Data layer: a small local cache kept in sync with the real backend
// (src/Controllers/*.php) via api.js. Nothing here computes queueing
// numbers any more - the server does that (src/Services/QueueingService.php)
// and this just holds whatever it last returned.
import { api, setCsrfToken } from './api.js';

export const cache = {
  auth: null, // { id, email, role } | null
  services: [],
  counters: [], // [{ number, is_open }]
  board: null,
  queue: null, // clerk/supervisor only
  dashboard: null, // supervisor only
};

export function isLoggedIn() {
  return cache.auth !== null;
}

export function hasRole(roles) {
  return cache.auth !== null && roles.includes(cache.auth.role);
}

export function isCounterOpen(number) {
  const c = cache.counters.find((c) => c.number === number);
  return !!(c && c.is_open);
}

export function openCounterNumbers() {
  return cache.counters.filter((c) => c.is_open).map((c) => c.number);
}

export async function refreshAuth() {
  try {
    const data = await api.me();
    cache.auth = data.user;
    setCsrfToken(data.csrfToken);
  } catch (e) {
    cache.auth = null;
  }
}

export async function refreshPublic() {
  const [services, counters, board] = await Promise.all([api.services(), api.counters(), api.board()]);
  cache.services = services.services;
  cache.counters = counters.counters;
  cache.board = board;
}

export async function refreshQueue() {
  if (!hasRole(['clerk', 'supervisor', 'admin'])) {
    cache.queue = null;
    return;
  }
  try {
    cache.queue = await api.queue();
  } catch (e) {
    cache.queue = null;
  }
}

export async function refreshDashboard() {
  if (!hasRole(['supervisor', 'admin'])) {
    cache.dashboard = null;
    return;
  }
  try {
    cache.dashboard = await api.dashboard();
  } catch (e) {
    cache.dashboard = null;
  }
}

export async function refreshAll() {
  await refreshAuth();
  await refreshPublic();
  await Promise.all([refreshQueue(), refreshDashboard()]);
}

export async function login(email, password) {
  const data = await api.login(email, password);
  cache.auth = data.user;
  setCsrfToken(data.csrfToken);
}

export async function logout() {
  await api.logout();
  cache.auth = null;
  cache.queue = null;
  cache.dashboard = null;
}
