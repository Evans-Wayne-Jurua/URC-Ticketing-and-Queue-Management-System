// Domain layer: translation content and the current language selection.
export const COPY = {
  en:{title:"Welcome to Kampala Station", sub:"Choose what you need and take a number",
      t1:"Buy a ticket", s1:"Ordinary or executive coach",
      t2:"Collect a ticket paid by phone", s2:"Have your code ready",
      t3:"Enquiry or refund", s3:"Times, changes, money back",
      yourNumber:"Your number is", estWait:"Estimated wait",
      watch:"Watch the screen. Your number will be called."},
  lg:{title:"Tukwaniriza ku Steegi ya Kampala", sub:"Londa ky'oyagala otwale namba",
      t1:"Gula tikiti", s1:"Ekibiina ekya bulijjo oba ekya waggulu",
      t2:"Ddira tikiti gy'osasulidde ku ssimu", s2:"Beera n'akoodi yo",
      t3:"Ebibuuzo oba okuzzibwayo ssente", s3:"Obudde, enkyukakyuka, ssente",
      yourNumber:"Namba yo eri", estWait:"Obudde bw'olindirira",
      watch:"Tunuulira ekiwandiiko. Namba yo ejja kuyitibwa."}
};

export let lang = "en";
export function setLang(l){ lang = l; }
