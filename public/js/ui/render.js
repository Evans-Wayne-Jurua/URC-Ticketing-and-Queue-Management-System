// UI layer: pulls data from state/ and domain/, writes it into the DOM.
// This is the only place that touches both "what the data says" and
// "what the screen shows" — kiosk, board, clerk and dashboard all update here.
import { $, mins, fmtMin } from "./dom.js";
import { state, waiting, serving, SERVICES, COUNTERS, isCounterOpen, openCounters } from "../state/store.js";
import { lambdaHat, muHat, recommend, erlangC } from "../domain/queueing.js";
import { lang } from "../domain/i18n.js";
import { drawChart } from "./chart.js";

export let activeCounter = 1;
export function setActiveCounter(c){ activeCounter = c; }

export function render(){
  var w = waiting(), s = serving();
  var lam = lambdaHat(), mu = muHat();
  var open = openCounters();

  /* kiosk */
  $("kioskQ").textContent = w.length;

  /* board */
  var bs = open.map(function(c){
    var t = s.filter(function(x){return x.counter===c;})[0];
    return '<div class="serve-row">'
      + '<div class="serve-num tnum'+(t?'':' idle')+'">'+(t?t.id:"—")+'</div>'
      + '<div class="serve-ctr"><div class="k">Counter</div><div class="v tnum">'+c+'</div></div></div>';
  }).join("") || "<p style='opacity:.6'>No counters open.</p>";
  $("boardServing").innerHTML = '<h3 style="font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;opacity:.65;margin-bottom:6px">Now serving</h3>' + bs;
  $("boardNext").innerHTML = w.slice(0,6).map(function(t){ return "<span class='tnum'>"+t.id+"</span>"; }).join("") || "<span class='tnum' style='color:#5a6880'>Queue empty</span>";
  var typical = w.length ? mins(Date.now() - w[0].t1) : 0;
  $("boardWait").textContent = w.length ? fmtMin(typical) : "No wait";
  var d = new Date();
  $("boardClock").textContent = String(d.getHours()).padStart(2,"0")+":"+String(d.getMinutes()).padStart(2,"0");

  /* clerk — the picker only ever offers counters a supervisor has opened */
  var pickEl = $("ctrPick");
  if(open.indexOf(activeCounter) === -1) activeCounter = open.length ? open[0] : null;
  pickEl.innerHTML = open.length
    ? open.map(function(c){ return "<option value='"+c+"'>Counter "+c+"</option>"; }).join("")
    : "<option value=''>No counters open</option>";
  pickEl.value = activeCounter || "";
  $("ctrLabel").textContent = activeCounter || "—";

  var mine = activeCounter ? s.filter(function(t){return t.counter===activeCounter;})[0] : undefined;
  var nEl = $("clerkNum");
  nEl.textContent = mine ? mine.id : "No one";
  nEl.className = "n tnum" + (mine ? "" : " empty");
  $("clerkMeta").textContent = !activeCounter
    ? "No counters are open — ask a supervisor to open one."
    : mine
      ? SERVICES[mine.svc][lang] + " · waited " + fmtMin(mins(mine.t2-mine.t1)) + " · serving " + mins(Date.now()-mine.t2) + " min"
      : (w.length ? w.length + " waiting — press “Call next”" : "Queue is empty");
  $("btnNext").disabled = !activeCounter || !!mine || !w.length;
  $("btnDone").disabled = !mine; $("btnNoShow").disabled = !mine;
  $("clerkCount").textContent = w.length + " in queue";
  $("clerkQueue").innerHTML = w.length ? w.slice(0,10).map(function(t){
    var m = mins(Date.now()-t.t1);
    return "<li><span class='qnum tnum'>"+t.id+"</span>"
      + "<span class='qsvc'>"+SERVICES[t.svc][lang]+"</span>"
      + "<span class='pill "+(m>=5?"pill-wait":"pill-done")+" tnum'>"+m+" min</span></li>";
  }).join("") : "<li><div class='empty-state'>Nobody waiting. Take a number on the kiosk screen.</div></li>";

  /* dashboard */
  $("kQ").textContent = w.length;
  var waitsNow = w.map(function(t){return mins(Date.now()-t.t1);});
  var avg = waitsNow.length ? Math.round(waitsNow.reduce(function(a,b){return a+b;},0)/waitsNow.length) : 0;
  $("kW").innerHTML = avg + '<span class="u"> min</span>';
  $("kL").innerHTML = (waitsNow.length?Math.max.apply(null,waitsNow):0) + '<span class="u"> min</span>';
  $("kS").textContent = state.servedToday;
  $("fLam").textContent = lam; $("fMu").textContent = mu;

  var rec = recommend(lam, mu, 5);
  $("recN").textContent = (rec.short ? "Open all " : "Open ") + rec.c + (rec.c===1?" counter":" counters");
  $("recS").textContent = rec.r.wq===Infinity
    ? "Even with every counter open, arrivals outrun service — the queue grows. Add a sales point or speed up the sale."
    : (rec.short ? "Target of 5 min cannot be met with " + rec.c + " counters. Best achievable is "
                 : "Predicted wait ")
      + fmtMin(Math.round(rec.r.wq*60)) + " at λ=" + lam + "/hr, μ=" + mu + "/hr per counter.";

  var rows = "";
  for (var c=1;c<=5;c++){
    var r = erlangC(lam,mu,c);
    var wq = r.wq===Infinity ? "—" : (r.wq*60).toFixed(1)+" min";
    var tag = c > COUNTERS.length ? " <span style='font-weight:500;color:var(--muted)'>(would need a new window)</span>" : "";
    rows += "<tr class='"+(c===rec.c?"pick":"")+"'><td class='tnum'>"+c+tag+"</td>"
      + "<td class='n tnum'>"+(r.rho>=1?"over 1":r.rho.toFixed(2))+"</td>"
      + "<td class='n tnum'>"+(r.rho>=1?"certain":Math.round(r.pWait*100)+"%")+"</td>"
      + "<td class='n tnum'>"+wq+"</td></tr>";
  }
  $("erlangRows").innerHTML = rows;

  $("ctrStatus").innerHTML = COUNTERS.map(function(c){
    var t = s.filter(function(x){return x.counter===c;})[0];
    var isOpen = isCounterOpen(c);
    // amber is reserved for "a passenger is waiting" — an idle counter with an empty
    // queue is neutral, but an idle counter while people wait is the problem state
    var pill = !isOpen ? "<span class='pill pill-done'>Closed</span>"
      : t ? "<span class='pill pill-serve tnum'>"+t.id+"</span>"
          : (w.length ? "<span class='pill pill-wait'>Idle — queue waiting</span>"
                      : "<span class='pill pill-idle'>Idle</span>");
    // closing is blocked while someone is being served there — opening is always allowed
    var toggle = "<button class='ctrtoggle' data-toggle-counter='"+c+"'"+(isOpen&&t?" disabled":"")+">"
      + (isOpen ? "Close" : "Open") + "</button>";
    return "<li><span class='qnum'>"+c+"</span><span class='qsvc'>"
      + (!isOpen?"Not staffed":(t?"Serving — "+mins(Date.now()-t.t2)+" min":"Free, waiting for next"))
      + "</span><span class='ctr-actions'>"+pill+toggle+"</span></li>";
  }).join("");

  drawChart();
}
