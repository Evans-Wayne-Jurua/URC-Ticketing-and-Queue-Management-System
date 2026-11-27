// Entry point / controller layer: wires DOM events to the API-backed state
// layer and bootstraps the app. Nothing here holds business logic - it only
// calls out to state/ (which talks to the real backend) and ui/.
import { $, fmtMin } from "./ui/dom.js";
import { api } from "./state/api.js";
import {
  cache, refreshAll, refreshPublic, refreshQueue, refreshDashboard,
  login, logout, isCounterOpen,
} from "./state/store.js";
import { COPY, lang, setLang } from "./domain/i18n.js";
import { render, activeCounter, setActiveCounter } from "./ui/render.js";

var autoTimer = null;

/* ---------------- deep-linking: one URL per screen ---------------- */
var SCREENS = ["kiosk", "board", "clerk", "dash"];

function screenFromHash() {
  var h = location.hash.replace("#", "");
  return SCREENS.indexOf(h) !== -1 ? h : "kiosk";
}

function showScreen(name) {
  document.querySelectorAll(".tab").forEach(function (t) {
    t.setAttribute("aria-selected", String(t.dataset.screen === name));
  });
  SCREENS.forEach(function (s) { $("screen-" + s).hidden = (s !== name); });
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

/* ---------------- 1 kiosk ---------------- */
document.querySelectorAll(".svc").forEach(function (b) {
  b.addEventListener("click", function () {
    api.takeToken(b.dataset.svc).then(function (data) {
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
      alert(e.message || "Could not take a number right now.");
    });
  });
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
$("btnDone").addEventListener("click", function () {
  api.complete(activeCounter)
    .then(function () { return Promise.all([refreshQueue(), refreshPublic()]); })
    .then(render)
    .catch(function (e) { alert(e.message); });
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
function tick() {
  refreshPublic()
    .then(function () { return Promise.all([refreshQueue(), refreshDashboard()]); })
    .then(render);
}

showScreen(screenFromHash());
applyLang();
saleTotal();
refreshAll().then(render).then(function () {
  setInterval(tick, 2000);
});
