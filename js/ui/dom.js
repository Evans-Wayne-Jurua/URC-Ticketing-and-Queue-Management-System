// Tiny DOM/formatting helpers shared across the UI layer.
export function $(id){ return document.getElementById(id); }
export function mins(ms){ return Math.max(0, Math.round(ms/60000)); }
export function fmtMin(m){ return m < 1 ? "under a minute" : m + (m===1?" minute":" minutes"); }
