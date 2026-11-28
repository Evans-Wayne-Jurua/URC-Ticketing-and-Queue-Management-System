// UI layer: reads the local cache (kept in sync with the API by state/store.js)
// and writes it into the DOM. Kiosk, board, clerk and dashboard all update here.
import { $, fmtMin } from "./dom.js";
import { cache, hasRole, isLocallyCompleted } from "../state/store.js";
import { isOffline } from "../state/offline.js";
import { count as pendingSalesCount } from "../state/pendingSales.js";
import { lang } from "../domain/i18n.js";
import { drawChart } from "./chart.js";

export let activeCounter = null;
export function setActiveCounter(c) {
  activeCounter = c;
}

function serviceLabel(serviceId) {
  var s = cache.services.find(function (s) { return s.id === serviceId; });
  if (!s) return "";
  return lang === "lg" ? s.labelLg : s.labelEn;
}

export function render() {
  renderKiosk();
  renderBoard();
  renderClerk();
  renderDashboard();
}

function renderKiosk() {
  if (cache.board) {
    $("kioskQ").textContent = cache.board.waitingCount;
  }
}

function renderBoard() {
  var b = cache.board;
  if (!b) return;

  var rows = b.nowServing.map(function (row) {
    return '<div class="serve-row">'
      + '<div class="serve-num tnum' + (row.code ? '' : ' idle') + '">' + (row.code || "—") + '</div>'
      + '<div class="serve-ctr"><div class="k">Counter</div><div class="v tnum">' + row.counter + '</div></div></div>';
  }).join("") || "<p style='opacity:.6'>No counters open.</p>";

  $("boardServing").innerHTML = '<h3 style="font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;opacity:.65;margin-bottom:6px">Now serving</h3>' + rows;
  $("boardNext").innerHTML = b.nextCodes.map(function (c) { return "<span class='tnum'>" + c + "</span>"; }).join("")
    || "<span class='tnum' style='color:#5a6880'>Queue empty</span>";
  $("boardWait").textContent = b.nextCodes.length ? fmtMin(b.typicalWaitMinutes) : "No wait";

  var d = new Date();
  $("boardClock").textContent = String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0");
}

function renderClerk() {
  var loggedIn = hasRole(["clerk", "supervisor", "admin"]);
  $("clerkLogin").hidden = loggedIn;
  $("clerkApp").hidden = !loggedIn;
  if (!loggedIn) return;

  $("clerkWhoAmI").textContent = cache.auth.email + " (" + cache.auth.role + ")";

  var open = cache.counters.filter(function (c) { return c.is_open; }).map(function (c) { return c.number; });
  if (open.indexOf(activeCounter) === -1) {
    activeCounter = open.length ? open[0] : null;
  }
  var pickEl = $("ctrPick");
  pickEl.innerHTML = open.length
    ? open.map(function (c) { return "<option value='" + c + "'>Counter " + c + "</option>"; }).join("")
    : "<option value=''>No counters open</option>";
  pickEl.value = activeCounter || "";
  $("ctrLabel").textContent = activeCounter || "—";

  var q = cache.queue || { waiting: [], serving: [] };
  var completedLocally = activeCounter && isLocallyCompleted(activeCounter);
  var mine = (activeCounter && !completedLocally)
    ? q.serving.find(function (t) { return t.counter === activeCounter; })
    : undefined;
  var offline = isOffline();

  var nEl = $("clerkNum");
  nEl.textContent = mine ? mine.code : "No one";
  nEl.className = "n tnum" + (mine ? "" : " empty");
  $("clerkMeta").textContent = !activeCounter
    ? "No counters are open — ask a supervisor to open one."
    : completedLocally
      ? "Sale recorded on this machine — waiting to sync."
      : mine
        ? serviceLabel(mine.serviceId) + " · waited " + fmtMin(mine.waitedMinutes) + " · serving " + mine.servingMinutes + " min"
        : (q.waiting.length ? q.waiting.length + " waiting — press “Call next”" : "Queue is empty");

  // "Call next"/"no-show" need live shared state - blocked offline.
  // "Complete sale" stays available: it finalizes something that already
  // physically happened at a counter this clerk already owns, and gets
  // queued locally instead (see state/pendingSales.js).
  $("btnNext").disabled = !activeCounter || !!mine || !q.waiting.length || offline;
  $("btnDone").disabled = !mine;
  $("btnNoShow").disabled = !mine || offline;
  $("clerkCount").textContent = q.waiting.length + " in queue";
  $("clerkQueue").innerHTML = q.waiting.length ? q.waiting.slice(0, 10).map(function (t) {
    return "<li><span class='qnum tnum'>" + t.code + "</span>"
      + "<span class='qsvc'>" + serviceLabel(t.serviceId) + "</span>"
      + "<span class='pill " + (t.waitMinutes >= 5 ? "pill-wait" : "pill-done") + " tnum'>" + t.waitMinutes + " min</span></li>";
  }).join("") : "<li><div class='empty-state'>Nobody waiting. Take a number on the kiosk screen.</div></li>";

  var pending = pendingSalesCount();
  var offlineEl = $("clerkOffline");
  if (offlineEl) {
    offlineEl.hidden = !offline && pending === 0;
    offlineEl.querySelector("[data-offline-text]").textContent = offline
      ? "Network down — sales are saved on this machine, will sync" + (pending ? " (" + pending + " pending)" : "")
      : pending + " sale" + (pending === 1 ? "" : "s") + " synced just now, finishing up…";
  }
}

function renderDashboard() {
  var allowed = hasRole(["supervisor", "admin"]);
  $("dashLogin").hidden = allowed;
  $("dashApp").hidden = !allowed;
  if (!allowed) return;

  $("dashWhoAmI").textContent = cache.auth.email + " (" + cache.auth.role + ")";

  var d = cache.dashboard;
  if (!d) return;

  $("kQ").textContent = d.inQueue;
  $("kW").innerHTML = d.averageWaitMinutes + '<span class="u"> min</span>';
  $("kL").innerHTML = d.longestWaitMinutes + '<span class="u"> min</span>';
  $("kS").textContent = d.servedToday;
  $("fLam").textContent = d.lambda;
  $("fMu").textContent = d.mu;

  var rec = d.recommendation;
  $("recN").textContent = (rec.short ? "Open all " : "Open ") + rec.c + (rec.c === 1 ? " counter" : " counters");
  $("recS").textContent = rec.r.wq === null
    ? "Even with every counter open, arrivals outrun service — the queue grows. Add a sales point or speed up the sale."
    : (rec.short ? "Target of 5 min cannot be met with " + rec.c + " counters. Best achievable is "
      : "Predicted wait ")
      + fmtMin(Math.round(rec.r.wq * 60)) + " at λ=" + d.lambda + "/hr, μ=" + d.mu + "/hr per counter.";

  var maxPhysicalCounters = cache.counters.length;
  $("erlangRows").innerHTML = d.erlangRows.map(function (r) {
    var wq = r.wq === null ? "—" : (r.wq * 60).toFixed(1) + " min";
    var tag = r.counters > maxPhysicalCounters ? " <span style='font-weight:500;color:var(--muted)'>(would need a new window)</span>" : "";
    return "<tr class='" + (r.counters === rec.c ? "pick" : "") + "'><td class='tnum'>" + r.counters + tag + "</td>"
      + "<td class='n tnum'>" + (r.rho >= 1 ? "over 1" : r.rho.toFixed(2)) + "</td>"
      + "<td class='n tnum'>" + (r.rho >= 1 ? "certain" : Math.round(r.pWait * 100) + "%") + "</td>"
      + "<td class='n tnum'>" + wq + "</td></tr>";
  }).join("");

  $("ctrStatus").innerHTML = d.counterStatus.map(function (c) {
    var pill = !c.isOpen ? "<span class='pill pill-done'>Closed</span>"
      : c.serving ? "<span class='pill pill-serve tnum'>" + c.serving + "</span>"
        : (d.inQueue ? "<span class='pill pill-wait'>Idle — queue waiting</span>" : "<span class='pill pill-idle'>Idle</span>");
    var toggle = "<button class='ctrtoggle' data-toggle-counter='" + c.number + "'" + (c.isOpen && c.serving ? " disabled" : "") + ">"
      + (c.isOpen ? "Close" : "Open") + "</button>";
    return "<li><span class='qnum'>" + c.number + "</span><span class='qsvc'>"
      + (!c.isOpen ? "Not staffed" : (c.serving ? "Serving — " + c.serving : "Free, waiting for next")) + "</span>"
      + "<span class='ctr-actions'>" + pill + toggle + "</span></li>";
  }).join("");

  drawChart(d.inQueue);

  var userListEl = $("userList");
  if (userListEl) {
    var users = cache.users || [];
    userListEl.innerHTML = users.length ? users.map(function (u) {
      return "<li><span class='qsvc'>" + u.email + "</span>"
        + "<span class='pill pill-idle'>" + u.role + "</span></li>";
    }).join("") : "<li><div class='empty-state'>No staff accounts yet.</div></li>";
  }
}
