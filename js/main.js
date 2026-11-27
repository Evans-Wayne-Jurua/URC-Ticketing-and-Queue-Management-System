// Entry point / controller layer: wires DOM events to the state and
// UI layers, and bootstraps the app. Nothing here holds business logic —
// it only calls out to state/, domain/ and ui/.
import { $, fmtMin } from "./ui/dom.js";
import { seed, mkToken, waiting, serving, state, openCounters, isCounterOpen, openCounter, closeCounter } from "./state/store.js";
import { muHat } from "./domain/queueing.js";
import { COPY, lang, setLang } from "./domain/i18n.js";
import { render, activeCounter, setActiveCounter } from "./ui/render.js";

var autoTimer = null;

/* ---------------- 1 kiosk ---------------- */
function applyLang(){
  var c = COPY[lang];
  $("kioskTitle").textContent = c.title; $("kioskSub").textContent = c.sub;
  document.querySelectorAll("[data-i18n]").forEach(function(el){
    var k = el.getAttribute("data-i18n"); if(c[k]) el.textContent = c[k];
  });
}
document.querySelectorAll(".lang button").forEach(function(b){
  b.addEventListener("click", function(){
    setLang(b.dataset.lang);
    document.querySelectorAll(".lang button").forEach(function(x){ x.setAttribute("aria-pressed", String(x===b)); });
    applyLang();
  });
});

document.querySelectorAll(".svc").forEach(function(b){
  b.addEventListener("click", function(){
    var tok = mkToken(b.dataset.svc);
    var ahead = waiting().length - 1;
    var w = Math.max(1, Math.round(ahead / Math.max(1,openCounters().length) * (3600/muHat()) / 60));
    $("issuedNum").textContent = tok.id;
    $("issuedWait").textContent = fmtMin(w);
    $("issuedAhead").textContent = ahead === 0 ? "You are next" : ahead + " ahead of you";
    $("kioskPick").hidden = true; $("kioskIssued").hidden = false;
    render();
    setTimeout(function(){ $("kioskPick").hidden = false; $("kioskIssued").hidden = true; }, 5000);
  });
});

/* ---------------- 3 clerk ---------------- */
$("ctrPick").addEventListener("change", function(e){
  setActiveCounter(parseInt(e.target.value,10));
  render();
});
$("btnNext").addEventListener("click", function(){
  var mine = serving().filter(function(t){return t.counter===activeCounter;});
  if(mine.length) return;
  var q = waiting().sort(function(a,b){return a.t1-b.t1;});
  if(!q.length) return;
  var t = q[0]; t.status="serving"; t.counter=activeCounter; t.t2=Date.now();
  render();
});
$("btnDone").addEventListener("click", function(){
  var t = serving().filter(function(x){return x.counter===activeCounter;})[0];
  if(!t) return;
  t.status="done"; t.t3=Date.now();
  state.serviceSecs.push(Math.max(20,(t.t3-t.t2)/1000));
  state.servedToday++; render();
});
$("btnNoShow").addEventListener("click", function(){
  var t = serving().filter(function(x){return x.counter===activeCounter;})[0];
  if(!t) return;
  t.status="noshow"; t.t3=Date.now(); render();
});
function saleTotal(){
  var p = parseInt($("saleClass").value,10) * parseInt($("saleQty").value||1,10);
  $("saleTotal").textContent = "Shs " + p.toLocaleString("en-US");
}
["saleClass","saleQty"].forEach(function(id){ $(id).addEventListener("input", saleTotal); $(id).addEventListener("change", saleTotal); });

/* ---------------- 4 supervisor: open/close counters ---------------- */
$("ctrStatus").addEventListener("click", function(e){
  var btn = e.target.closest("[data-toggle-counter]");
  if(!btn || btn.disabled) return;
  var c = parseInt(btn.dataset.toggleCounter,10);
  if(isCounterOpen(c)) closeCounter(c); else openCounter(c);
  render();
});

/* ---------------- tabs / controls ---------------- */
document.querySelectorAll(".tab").forEach(function(t){
  t.addEventListener("click", function(){
    document.querySelectorAll(".tab").forEach(function(x){ x.setAttribute("aria-selected", String(x===t)); });
    ["kiosk","board","clerk","dash"].forEach(function(s){ $("screen-"+s).hidden = (s !== t.dataset.screen); });
  });
});
$("reset").addEventListener("click", function(){ seed(); render(); });
$("autoArr").addEventListener("change", function(e){
  if(autoTimer){ clearInterval(autoTimer); autoTimer=null; }
  if(e.target.checked){
    autoTimer = setInterval(function(){
      // Poisson-ish: ~1 arrival every 4 s of demo time
      if(Math.random() < 0.55){
        var k = Math.random(); mkToken(k<0.7?"ticket":(k<0.9?"collect":"enquiry"));
        render();
      }
    }, 4000);
  }
});

seed(); applyLang(); saleTotal(); render();
setInterval(render, 1000);
