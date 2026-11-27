// Entry point / controller layer: wires DOM events to the API-backed state
// layer and bootstraps the app. Nothing here holds business logic - it only
// calls out to state/ (which talks to the real backend) and ui/.
import { $, fmtMin } from "./ui/dom.js";
import { api } from "./state/api.js";
import {
  cache, refreshAll, refreshPublic, refreshQueue, refreshDashboard,
  login, logout, isCounterOpen, markLocallyCompleted, clearLocallyCompleted,
} from "./state/store.js";
import { isOffline, markOnline, markOffline, isNetworkError } from "./state/offline.js";
import * as pendingSales from "./state/pendingSales.js";
import { COPY, lang, setLang } from "./domain/i18n.js";
import { render, activeCounter, setActiveCounter } from "./ui/render.js";

var autoTimer = null;

/* ---------------- deep-linking: one URL per screen ---------------- */
var SCREENS = ["kiosk", "board", "clerk", "dash"];

function screenFromHash() {
  var h = location.hash.replace("#", "");
  return SCREENS.indexOf(h) !== -1 ? h : "kiosk";
}

// Subtle crossfade between screens - opacity/transform only (GPU-cheap, no
// layout work), ~160ms each way, skipped entirely for prefers-reduced-motion.
var SCREEN_TRANSITION_MS = 160;
var screenTransitioning = false;
var reducedMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

function showScreen(name) {
  document.querySelectorAll(".tab").forEach(function (t) {
    t.setAttribute("aria-selected", String(t.dataset.screen === name));
  });

  var next = $("screen-" + name);
  var current = SCREENS.map(function (s) { return $("screen-" + s); })
    .find(function (el) { return !el.hidden; });

  function snap() {
    SCREENS.forEach(function (s) { $("screen-" + s).hidden = (s !== name); });
  }

  if (!current || current === next || reducedMotion) {
    snap();
    return;
  }

  if (screenTransitioning) {
    current.classList.remove("screen-leave");
    next.classList.remove("screen-enter");
    snap();
    screenTransitioning = false;
    return;
  }

  screenTransitioning = true;
  current.classList.add("screen-leave");
  setTimeout(function () {
    current.hidden = true;
    current.classList.remove("screen-leave");
    next.hidden = false;
    next.classList.add("screen-enter");
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        next.classList.remove("screen-enter");
        screenTransitioning = false;
      });
    });
  }, SCREEN_TRANSITION_MS);
}

document.querySelectorAll(".tab").forEach(function (t) {
  t.addEventListener("click", function () { location.hash = t.dataset.screen; });
});
window.addEventListener("hashchange", function () { showScreen(screenFromHash()); });

/* ---------------- i18n ---------------- */
function applyLang() {
  var c = COPY[lang];
  $("kioskTitle").textContent = c.title;
  $("kioskSub").textContent = c.sub;
  document.querySelectorAll("[data-i18n]").forEach(function (el) {
    var k = el.getAttribute("data-i18n");
    if (c[k]) el.textContent = c[k];
  });
}
document.querySelectorAll(".lang button").forEach(function (b) {
  b.addEventListener("click", function () {
    setLang(b.dataset.lang);
    document.querySelectorAll(".lang button").forEach(function (x) { x.setAttribute("aria-pressed", String(x === b)); });
    applyLang();
    render();
  });
});

/* ---------------- 1 kiosk ----------------
 * Offline design: a ticket number is the mechanism for fair ordering, so
 * issuing a fake/provisional one offline (that doesn't reflect real
 * arrival order against everyone else) would break fairness once synced.
 * Instead: retry automatically and transparently until the real request
 * succeeds, rather than queuing a guess. See claudeSecure.md. */
function takeToken(serviceCode) {
  api.takeToken(serviceCode).then(function (data) {
    markOnline();
    $("kioskRetryMsg").hidden = true;
    $("issuedNum").textContent = data.token.code;
    $("issuedWait").textContent = fmtMin(data.estWaitMinutes);
    $("issuedAhead").textContent = data.ahead === 0 ? "You are next" : data.ahead + " ahead of you";
    $("kioskPick").hidden = true;
    $("kioskIssued").hidden = false;
    return refreshPublic();
  }).then(function () {
    render();
    setTimeout(function () { $("kioskPick").hidden = false; $("kioskIssued").hidden = true; }, 5000);
  }).catch(function (e) {
    if (isNetworkError(e)) {
      markOffline();
      $("kioskRetryMsg").hidden = false;
      setTimeout(function () { takeToken(serviceCode); }, 3000);
    } else {
      alert(e.message || "Could not take a number right now.");
    }
  });
}
document.querySelectorAll(".svc").forEach(function (b) {
  b.addEventListener("click", function () { takeToken(b.dataset.svc); });
});

/* ---------------- login / logout (shared pattern for clerk + supervisor) ---------------- */
function wireLoginForm(form) {
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var email = form.email.value.trim();
    var password = form.password.value;
    var errEl = form.querySelector("[data-error]");
    errEl.textContent = "";
    login(email, password).then(function () {
      form.reset();
      return Promise.all([refreshQueue(), refreshDashboard()]);
    }).then(function () {
      render();
    }).catch(function (err) {
      errEl.textContent = err.message || "Sign in failed";
    });
  });
}
wireLoginForm($("clerkLoginForm"));
wireLoginForm($("dashLoginForm"));

document.querySelectorAll("[data-logout]").forEach(function (btn) {
  btn.addEventListener("click", function () {
    logout().then(function () { render(); });
  });
});

/* ---------------- 3 clerk ---------------- */
$("ctrPick").addEventListener("change", function (e) {
  var n = parseInt(e.target.value, 10);
  setActiveCounter(isNaN(n) ? null : n);
  render();
});
$("btnNext").addEventListener("click", function () {
  api.callNext(activeCounter).then(refreshQueue).then(render).catch(function (e) { alert(e.message); });
});

/* "Complete sale" is the one clerk action allowed to queue offline - see
 * claudeSecure.md: it finalizes something that already physically
 * happened at a counter this clerk already owns, unlike "call next" /
 * "no-show" which depend on live shared queue state. */
$("btnDone").addEventListener("click", function () {
  var q = cache.queue || { serving: [] };
  var mine = activeCounter ? q.serving.find(function (t) { return t.counter === activeCounter; }) : undefined;
  var ticketService = cache.services.find(function (s) { return s.code === "ticket"; });
  var isTicketSale = !!(mine && ticketService && mine.serviceId === ticketService.id);

  var saleBody;
  if (isTicketSale) {
    saleBody = {
      trip: $("saleTrip").value,
      classFee: parseInt($("saleClass").value, 10),
      qty: parseInt($("saleQty").value, 10) || 1,
      paymentMethod: $("salePay").value,
    };
  }

  function queueLocally() {
    pendingSales.add({ counter: activeCounter, sale: saleBody || null });
    markLocallyCompleted(activeCounter);
    render();
  }

  if (isOffline()) {
    queueLocally();
    return;
  }

  api.complete(activeCounter, saleBody)
    .then(function () { return Promise.all([refreshQueue(), refreshPublic()]); })
    .then(render)
    .catch(function (e) {
      if (isNetworkError(e)) {
        markOffline();
        queueLocally();
      } else {
        alert(e.message);
      }
    });
});

$("btnNoShow").addEventListener("click", function () {
  api.noShow(activeCounter)
    .then(function () { return Promise.all([refreshQueue(), refreshPublic()]); })
    .then(render)
    .catch(function (e) { alert(e.message); });
});

function saleTotal() {
  var p = parseInt($("saleClass").value, 10) * parseInt($("saleQty").value || 1, 10);
  $("saleTotal").textContent = "Shs " + p.toLocaleString("en-US");
}
["saleClass", "saleQty"].forEach(function (id) {
  $(id).addEventListener("input", saleTotal);
  $(id).addEventListener("change", saleTotal);
});

/* ---------------- 4 supervisor: open/close counters ---------------- */
$("ctrStatus").addEventListener("click", function (e) {
  var btn = e.target.closest("[data-toggle-counter]");
  if (!btn || btn.disabled) return;
  var c = parseInt(btn.dataset.toggleCounter, 10);
  var action = isCounterOpen(c) ? api.closeCounter(c) : api.openCounter(c);
  action
    .then(function () { return Promise.all([refreshPublic(), refreshDashboard()]); })
    .then(render)
    .catch(function (e) { alert(e.message); });
});

/* ---------------- simulate arrivals: drives the real API, for demos ---------------- */
$("autoArr").addEventListener("change", function (e) {
  if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
  if (e.target.checked) {
    autoTimer = setInterval(function () {
      if (Math.random() < 0.55) {
        var k = Math.random();
        api.takeToken(k < 0.7 ? "ticket" : (k < 0.9 ? "collect" : "enquiry"))
          .then(refreshPublic)
          .then(render)
          .catch(function () { /* transient error from the simulator - ignore */ });
      }
    }, 4000);
  }
});

/* ---------------- bootstrap ---------------- */
function flushPendingSales() {
  var items = pendingSales.list();
  if (!items.length) return Promise.resolve();

  var chain = Promise.resolve();
  items.forEach(function (item) {
    chain = chain.then(function () {
      return api.complete(item.counter, item.sale || undefined).then(function () {
        pendingSales.remove(item.id);
        clearLocallyCompleted(item.counter);
      }).catch(function (e) {
        if (isNetworkError(e)) return; // still offline - leave it queued, retry next tick
        // state diverged server-side - don't retry forever, but say so loudly
        pendingSales.remove(item.id);
        clearLocallyCompleted(item.counter);
        alert("A queued sale for counter " + item.counter + " could not be synced automatically (" + e.message + ") — please check with a supervisor.");
      });
    });
  });
  return chain;
}

function tick() {
  refreshPublic()
    .then(function () {
      markOnline();
      if (pendingSales.list().length > 0) return flushPendingSales();
    })
    .then(function () { return Promise.all([refreshQueue(), refreshDashboard()]); })
    .then(render)
    .catch(function (e) {
      if (isNetworkError(e)) { markOffline(); render(); }
    });
}

showScreen(screenFromHash());
applyLang();
saleTotal();
refreshAll().then(render).then(function () {
  setInterval(tick, 2000);
});
