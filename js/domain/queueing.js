// Domain layer: pure queueing-theory math (Erlang C). No DOM access here,
// so these functions are straightforward to unit test on their own.
import { state, COUNTERS } from "../state/store.js";

export function erlangC(lam, mu, c){
  var a = lam/mu, rho = lam/(c*mu);
  if (c*mu <= lam) return { a:a, rho:rho, pWait:1, wq:Infinity };
  var sum = 0, term = 1;                 // term = a^k / k!
  for (var k=0;k<c;k++){ if(k>0) term = term * a / k; sum += term; }
  var last = term * a / c;               // a^c / c!
  var num  = last * (c/(c-a));
  var pWait = num/(sum+num);
  return { a:a, rho:rho, pWait:pWait, wq: pWait/(c*mu - lam) };   // wq in hours
}

export function lambdaHat(){
  // arrivals per hour, from the T1 stamps inside a 20-minute window.
  // the divisor is the span the window actually covers, not the clock since page load.
  var since = Date.now() - 20*60000;
  var inWin = state.tokens.filter(function(t){ return t.t1 >= since; });
  if(!inWin.length) return 12;
  var oldest = inWin.reduce(function(m,t){ return Math.min(m,t.t1); }, Date.now());
  var spanMin = Math.max(8, (Date.now()-oldest)/60000);
  return Math.max(10, Math.round(inWin.length * 60 / spanMin));
}

export function muHat(){
  // sales per hour per counter, from T3 − T2
  if(!state.serviceSecs.length) return 12;
  var avg = state.serviceSecs.reduce(function(a,b){return a+b;},0)/state.serviceSecs.length;
  return Math.max(4, Math.round(3600/avg));
}

// the station physically has COUNTERS.length sales points — never recommend more than exist
export function recommend(lam, mu, targetMin){
  var maxC = COUNTERS.length;
  for (var c=1;c<=maxC;c++){
    var r = erlangC(lam,mu,c);
    if (r.wq*60 <= targetMin) return {c:c, r:r, short:false};
  }
  return {c:maxC, r:erlangC(lam,mu,maxC), short:true};
}
