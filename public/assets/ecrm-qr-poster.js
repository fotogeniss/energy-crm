/* Energy CRM — η αφίσα «Σκάναρε εδώ» για τον πάγκο του καταστήματος (344).
 *
 * Μια αυτόνομη σελίδα HTML που ανοίγει σε νέο παράθυρο και τυπώνεται από τον
 * browser. Το QR είναι ο σύνδεσμος του πωλητή με `via=qr`· φτιάχνεται εδώ,
 * χωρίς υπηρεσία τρίτων (βλ. ecrm-qr.js). Η κάτω γραμμή αλλάζει με κλικ πριν
 * την εκτύπωση. Mockup: docs/UI-QR-AND-RENEWAL-CALL.html.
 */

import { esc } from '@energy-crm/util';
import { qrSvg } from '@energy-crm/qr';

/**
 * @param {string} url Ο σύνδεσμος, ήδη με via=qr.
 * @param {{name?: string, company?: string}} owner
 * @returns {string}
 */
export function qrPosterHtml(url, owner) {
	var foot = [owner.company, owner.name].filter(function (s) { return s; }).join(' · ');

	return '<!doctype html><html lang="el"><head><meta charset="utf-8">' +
		'<meta name="viewport" content="width=device-width,initial-scale=1">' +
		'<title>QR code</title><style>' +
		'@page{size:A5;margin:0}' +
		'*{box-sizing:border-box}html,body{margin:0;background:#eee;color:#1d1d1b;' +
		'font-family:Manrope,-apple-system,"Segoe UI",Roboto,sans-serif}' +
		'.bar{display:flex;gap:8px;justify-content:center;align-items:center;padding:12px;font-size:13px}' +
		'.bar button{font:700 14px inherit;padding:8px 16px;border-radius:9px;border:1px solid #bbb;background:#fff;cursor:pointer}' +
		'.bar button.p{background:#16c217;border-color:#16c217;color:#0b3d0c}' +
		'.sheet{width:148mm;height:210mm;margin:0 auto 24px;background:#fff;padding:16mm 14mm 12mm;' +
		'display:flex;flex-direction:column;align-items:center;text-align:center;box-shadow:0 8px 30px #0002}' +
		'body.a4 .sheet{width:210mm;height:297mm;padding:24mm 20mm 16mm}' +
		'.k{font-size:11pt;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#0b690c}' +
		'h1{font-size:30pt;line-height:1.1;margin:5mm 0 4mm}' +
		'body.a4 h1{font-size:42pt}' +
		'.lede{font-size:13pt;color:#444;margin:0 0 7mm;max-width:110mm}' +
		'.qr{width:78mm;height:78mm}body.a4 .qr{width:115mm;height:115mm}.qr svg{width:100%;height:100%;display:block}' +
		'.steps{display:flex;gap:6mm;margin-top:7mm;font-size:11pt;color:#333;width:100%}' +
		'.steps span{flex:1}.steps i{display:block;font-style:normal;font-weight:800;font-size:18pt;color:#0b690c}' +
		'.foot{margin-top:auto;font-size:11pt;color:#555;padding:2mm 4mm;border-radius:4px;outline:none;min-width:40mm}' +
		'.foot:hover,.foot:focus{background:#fff6d6}' +
		'@media print{html,body{background:#fff}.bar{display:none}.sheet{box-shadow:none;margin:0}.foot{background:none!important}}' +
		'</style></head><body>' +
		'<div class="bar"><button type="button" data-size="a5">A5</button><button type="button" data-size="a4">A4</button>' +
		'<button type="button" class="p" data-print>Εκτύπωση</button>' +
		'<span>Η κάτω γραμμή αλλάζει αν την πατήσεις.</span></div>' +
		'<div class="sheet">' +
		'<div class="k">Φθηνότερο ρεύμα &amp; αέριο</div>' +
		'<h1>Πληρώνεις πολλά;<br>Σκάναρε εδώ.</h1>' +
		'<p class="lede">Βγάλε φωτογραφία τον λογαριασμό σου. Σε παίρνουμε εμείς τηλέφωνο με την καλύτερη πρόταση.</p>' +
		'<div class="qr">' + qrSvg(url) + '</div>' +
		'<div class="steps"><span><i>1</i>Σκάναρε</span><span><i>2</i>Φωτογράφισε τον λογαριασμό</span><span><i>3</i>Σε καλούμε</span></div>' +
		'<div class="foot" contenteditable="true" spellcheck="false">' + esc(foot) + '</div>' +
		'</div>' +
		'<script>' +
		'document.querySelectorAll("[data-size]").forEach(function(b){b.onclick=function(){' +
		'var a4=b.getAttribute("data-size")==="a4";document.body.classList.toggle("a4",a4);' +
		'var s=document.getElementById("pg");s.textContent="@page{size:"+(a4?"A4":"A5")+";margin:0}";};});' +
		'var st=document.createElement("style");st.id="pg";st.textContent="@page{size:A5;margin:0}";document.head.appendChild(st);' +
		'document.querySelector("[data-print]").onclick=function(){window.print();};' +
		'</' + 'script></body></html>';
}
