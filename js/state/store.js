// Data layer. This is a simulated in-memory "database" for the demo.
// Everything else in the app reaches tokens/counters only through the
// functions exported here — so when a real backend exists, this is the
// one file that changes (seed/mkToken/waiting/serving become fetch()
// calls against a REST API) and nothing in domain/ or ui/ has to.

export const SERVICES = {
  ticket:{en:"Buy a ticket", lg:"Gula tikiti", prefix:"A"},
  collect:{en:"Collect pre-paid ticket", lg:"Ddira tikiti", prefix:"B"},
  enquiry:{en:"Enquiry or refund", lg:"Ebibuuzo", prefix:"C"}
};
export const COUNTERS = [1,2,3,4];

export let state;
let seq;

function now(){ return Date.now(); }

export function seed(){
  state = { tokens:[], counterOpen:{1:true,2:true,3:true,4:false}, servedToday:0, serviceSecs:[], startedAt: now() };
  seq = {A:0,B:0,C:0};
  // a handful of people already in line so the dashboard isn't empty
  var backdate = [15,13,11,8,5,2];
  backdate.forEach(function(minsAgo,i){
    var svc = i%3===0 ? "collect" : "ticket";
    var t = mkToken(svc, now() - minsAgo*60000);
    if(i<2){ t.status="done"; t.t2=t.t1+90000; t.t3=t.t2+170000; t.counter=1+(i%2);
             state.servedToday++; state.serviceSecs.push(170); }
  });
}

export function mkToken(svc, at){
  seq[SERVICES[svc].prefix] += 1;
  var tok = {
    id: SERVICES[svc].prefix + "-" + String(seq[SERVICES[svc].prefix]).padStart(2,"0"),
    svc: svc, status:"waiting", counter:null,
    t1: at || now(), t2:null, t3:null
  };
  state.tokens.push(tok);
  return tok;
}

export function waiting(){ return state.tokens.filter(function(t){return t.status==="waiting";}); }
export function serving(){ return state.tokens.filter(function(t){return t.status==="serving";}); }

// "Open" is an administrative decision (a supervisor allocates the counter for
// use today) — distinct from a clerk actually logging in and serving from it.
export function isCounterOpen(c){ return !!state.counterOpen[c]; }
export function openCounters(){ return COUNTERS.filter(function(c){ return state.counterOpen[c]; }); }
export function openCounter(c){ state.counterOpen[c] = true; }
export function closeCounter(c){
  var busy = serving().some(function(t){ return t.counter===c; });
  if(busy) return false;   // can't close a counter mid-service
  state.counterOpen[c] = false;
  return true;
}
