// UI layer: renders the arrivals bar chart (SVG) into #chart.
// The 12-block shape is a fixed demo profile (see note below) - only the
// "right now" block is driven by live data, passed in by the caller.
import { $ } from "./dom.js";

export function drawChart(liveCount) {
  var svg = $("chart"), W=720, H=240, padL=34, padR=8, padT=14, padB=30;
  // 12 blocks of 15 min leading to the 17:30 departure; the last block is
  // nudged up to at least the current live queue length.
  var base = [4,5,6,8,9,12,15,19,28,24,14,8];
  if (typeof liveCount === "number") {
    base[11] = Math.max(base[11], liveCount);
  }
  var labels = ["14:00","","15:00","","16:00","","17:00","","17:30","","18:00",""];
  var max = Math.max.apply(null, base) * 1.12;
  var bw = (W-padL-padR)/base.length, gap = 8;
  var peak = base.indexOf(Math.max.apply(null,base));
  var parts = [];
  [0,0.5,1].forEach(function(f){
    var y = padT + (H-padT-padB)*(1-f);
    parts.push('<line x1="'+padL+'" y1="'+y.toFixed(1)+'" x2="'+(W-padR)+'" y2="'+y.toFixed(1)+'" stroke="#e2e6ed" stroke-width="1"/>');
    parts.push('<text x="'+(padL-8)+'" y="'+(y+4).toFixed(1)+'" text-anchor="end" font-size="11" fill="#5b6475">'+Math.round(max*f)+'</text>');
  });
  base.forEach(function(v,i){
    var h = (H-padT-padB) * (v/max);
    var x = padL + i*bw + gap/2, y = H-padB-h, w = bw-gap;
    var fill = i===peak ? "#1d63ed" : "#a9c4f7";
    parts.push('<rect class="bar" data-i="'+i+'" x="'+x.toFixed(1)+'" y="'+y.toFixed(1)+'" width="'+w.toFixed(1)+'" height="'+h.toFixed(1)+'" rx="4" fill="'+fill+'"/>');
    if(labels[i]) parts.push('<text x="'+(x+w/2).toFixed(1)+'" y="'+(H-10)+'" text-anchor="middle" font-size="11" fill="#5b6475">'+labels[i]+'</text>');
    if(i===peak) parts.push('<text x="'+(x+w/2).toFixed(1)+'" y="'+(y-6).toFixed(1)+'" text-anchor="middle" font-size="12" font-weight="700" fill="#10151f">'+v+'</text>');
  });
  svg.innerHTML = parts.join("");
  var tip = $("tip"), wrap = $("chartWrap");
  svg.querySelectorAll(".bar").forEach(function(r){
    r.addEventListener("mouseenter", function(e){
      var i = +r.dataset.i, rect = r.getBoundingClientRect(), wr = wrap.getBoundingClientRect();
      tip.textContent = base[i] + " arrivals · " + (labels[i]||"15 min block");
      tip.style.left = (rect.left - wr.left + rect.width/2) + "px";
      tip.style.top  = (rect.top  - wr.top) + "px";
      tip.style.opacity = 1;
    });
    r.addEventListener("mouseleave", function(){ tip.style.opacity = 0; });
  });
}
