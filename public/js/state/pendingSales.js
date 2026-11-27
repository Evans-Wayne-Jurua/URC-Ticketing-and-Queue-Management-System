// A small localStorage-backed outbox for "Complete sale" actions made while
// offline. Only "complete" is ever queued here - deliberately NOT
// "call next" or "no-show". See claudeSecure.md for the reasoning: a
// completed sale finalizes something that already physically happened at
// a counter this clerk already owns (safe to record later), whereas
// "call next" depends on the live shared waiting list and "no-show" on
// live shared serving state - queuing those blindly risks double-serving
// or skipping someone once synced.
const KEY = 'urc:pendingSales';

function readAll() {
  try {
    const raw = localStorage.getItem(KEY);
    return raw ? JSON.parse(raw) : [];
  } catch (e) {
    return []; // storage unavailable (private browsing, quota, etc.) - degrade quietly
  }
}

function writeAll(items) {
  try {
    localStorage.setItem(KEY, JSON.stringify(items));
  } catch (e) {
    // can't persist - the queue still works in-memory for this page load
  }
}

export function list() {
  return readAll();
}

export function count() {
  return readAll().length;
}

export function add(entry) {
  const items = readAll();
  items.push(Object.assign({ id: Date.now() + '-' + Math.random().toString(36).slice(2) }, entry));
  writeAll(items);
}

export function remove(id) {
  writeAll(readAll().filter((i) => i.id !== id));
}
