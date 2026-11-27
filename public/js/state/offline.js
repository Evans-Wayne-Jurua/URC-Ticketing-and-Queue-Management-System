// Tracks whether we can currently reach the API, based on real fetch
// outcomes - not navigator.onLine, which only reflects the OS network
// interface and says nothing about whether OUR server is reachable.
let offline = false;
const listeners = [];

export function isOffline() {
  return offline;
}

export function onStatusChange(fn) {
  listeners.push(fn);
}

export function markOnline() {
  if (offline) {
    offline = false;
    listeners.forEach((fn) => fn(false));
  }
}

export function markOffline() {
  if (!offline) {
    offline = true;
    listeners.forEach((fn) => fn(true));
  }
}

// api.js throws a plain Error (no .status) when fetch() itself fails
// (server unreachable), and a .status-bearing Error for a real HTTP
// response (4xx/5xx). Only the former means "we're offline."
export function isNetworkError(err) {
  return !!err && typeof err.status === 'undefined';
}
