<?php
/**
 * Public e-signature page.
 *
 * When the front-end is hit with ?ecrm_sign=TOKEN, output a standalone,
 * login-free page with a signature pad. Submission posts to the public
 * REST endpoint, which validates the token and stores the signature.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Sign_Page {

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ] );
	}

	public static function maybe_render(): void {
		$token = isset( $_GET['ecrm_sign'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', wp_unslash( $_GET['ecrm_sign'] ) ) : '';
		if ( ! $token ) {
			return;
		}

		// Unified flow: legacy ?ecrm_sign= links are mapped back to their contract
		// and redirected to the single tracking/sign page, so every signature link
		// — old or new — lands on the same screen. Already-sent emails keep working.
		if ( class_exists( 'ECRM_Tracking' ) && class_exists( 'ECRM_DB' ) ) {
			global $wpdb;
			$cid = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT contract_id FROM " . ECRM_DB::table( 'signatures' ) . " WHERE token = %s LIMIT 1",
				$token
			) );
			if ( $cid ) {
				wp_safe_redirect( ECRM_Tracking::url( $cid ), 302 );
				exit;
			}
		}

		$rest  = esc_url_raw( rest_url( \EnergyCRM\Http\Router::NAMESPACE . '/sign/' . $token ) );
		$accent = class_exists( 'ECRM_Admin' )
			? (string) ECRM_Admin::get( 'accent_color', ECRM_Admin::DEFAULT_ACCENT )
			: '#0e8610';
		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name' ) : '';

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="el">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Υπογραφή Σύμβασης</title>
<?php // Η γραμματοσειρά σερβίρεται ΑΠΟ ΤΟ PLUGIN. Εδώ έμπαινε Inter από το CDN
// της Google — σε σελίδα που βλέπει ο ΠΕΛΑΤΗΣ, μέσα σε ροή προσωπικών
// δεδομένων. Δες EnergyCRM\Infrastructure\LocalFonts και CHANGELOG (15).
echo \EnergyCRM\Infrastructure\LocalFonts::styleTag( ECRM_URL ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- σταθερό CSS με URL που φτιάχνει η ίδια η κλάση.
?>
<style>
	/* ---- Ταυτότητα 2026-08-18 -----------------------------------------
	 * Ήταν navy #0a1f3d με amber gradient: η παλέτα ΠΡΙΝ από το restyle των
	 * πέντε βημάτων, που δεν άγγιξε ποτέ τις σελίδες του πελάτη. Ο πελάτης που
	 * υπέγραφε έβλεπε άλλο προϊόν από τον συνεργάτη που του πούλησε.
	 *
	 * Το --accent έρχεται από τις ρυθμίσεις, με προεπιλογή ECRM_Admin::DEFAULT_ACCENT. */
	:root {
		--accent: <?php echo esc_html( $accent ?: '#0e8610' ); ?>;
		--page:#141412; --chrome:#1a1a18; --surface:#fff;
		--ink:#2a2926; --ink2:#5c5a55; --ink3:#a3a099;
		--line:#e9e8e4; --line2:#dedcd7; --fill:#f8f8f6;
		--ok:#0f5f29; --ok-bg:#e1f0e6; --err:#c42a47; --err-bg:#fceaee;
	}
	* { box-sizing: border-box; }
	body { margin:0; font-family:<?php echo \EnergyCRM\Infrastructure\LocalFonts::STACK; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- σταθερά κλάσης, όχι είσοδος. Το esc_html() θα μετέτρεπε τα εισαγωγικά του «"Manrope"» σε &quot; και θα ΕΣΠΑΓΕ το CSS. ?>;
		background:var(--page); min-height:100vh; min-height:100dvh;
		display:flex; align-items:center; justify-content:center; padding:20px; color:var(--ink); }
	.card { background:var(--surface); border-radius:16px; box-shadow:0 24px 60px -24px rgba(0,0,0,.55);
		width:100%; max-width:520px; overflow:hidden; }
	.head { background:var(--chrome); color:#fff; padding:20px 24px; }
	.head h1 { margin:0; font-size:18px; font-weight:700; letter-spacing:-.02em; }
	.head p { margin:5px 0 0; color:#a3a099; font-size:13px; }
	.body { padding:22px 24px; }
	.row { display:flex; justify-content:space-between; gap:14px; padding:9px 0; border-bottom:1px solid var(--line); font-size:14px; }
	.row:last-of-type { border-bottom:0; }
	.row span { color:var(--ink2); } .row b { color:var(--ink); font-weight:600; text-align:right; }
	.foot { text-align:center; font-size:12px; color:var(--ink3); padding:14px; border-top:1px solid var(--line); }
	label { display:block; font-size:13px; font-weight:600; margin:18px 0 6px; color:var(--ink); }
	input[type=text] { width:100%; height:44px; border:1px solid var(--line2); border-radius:8px;
		padding:0 13px; font-size:15px; font-family:inherit; color:var(--ink); outline:none; transition:border-color .15s; }
	input[type=text]:hover { border-color:var(--ink3); }
	input[type=text]:focus { border-color:var(--accent); }
	.padwrap { margin-top:8px; border:2px dashed var(--line2); border-radius:12px; background:var(--fill); position:relative; }
	canvas { width:100%; height:200px; display:block; touch-action:none; border-radius:12px; }
	.clear { position:absolute; top:8px; right:10px; background:var(--surface); border:1px solid var(--line2);
		border-radius:7px; padding:6px 11px; font-size:12px; font-weight:600; color:var(--ink2); cursor:pointer; font-family:inherit; }
	.clear:hover { border-color:var(--ink3); }
	/* Το κουμπί που πατάει ο πελάτης για να υπογράψει. Λευκό σε --accent, και
	   γι' αυτό το DEFAULT_ACCENT είναι το σκούρο πράσινο: 4,61:1. */
	.btn { width:100%; height:50px; margin-top:18px; border:none; border-radius:10px;
		background:var(--accent); color:#fff; font-weight:700; font-size:16px; cursor:pointer; font-family:inherit;
		transition:filter .15s; }
	.btn:hover:not(:disabled) { filter:brightness(1.08); }
	.btn:disabled { opacity:.5; cursor:not-allowed; }
	.msg { margin-top:14px; font-size:14px; text-align:center; }
	.ok { color:var(--ok); } .err { color:var(--err); }
	.done { text-align:center; padding:40px 24px; }
	.done .check { width:60px; height:60px; border-radius:50%; background:var(--ok-bg); color:var(--ok);
		display:grid; place-items:center; font-size:30px; margin:0 auto 16px; }
</style>
</head>
<body>
<div class="card">
	<div class="head">
		<h1>Υπογραφή Σύμβασης</h1>
		<p><?php echo esc_html( $company ?: 'Energy CRM' ); ?></p>
	</div>
	<div id="content" class="body"><p>Φόρτωση…</p></div>
	<div class="foot">Ασφαλής σύνδεσμος υπογραφής</div>
</div>

<script>
(function(){
	var REST = <?php echo wp_json_encode( $rest ); ?>;
	var content = document.getElementById('content');

	fetch(REST).then(function(r){return r.json();}).then(function(d){
		if(!d||!d.ok){ content.innerHTML='<p class="err">Ο σύνδεσμος δεν είναι έγκυρος ή έχει λήξει.</p>'; return; }
		if(d.signed){ done(); return; }
		render(d);
	}).catch(function(){ content.innerHTML='<p class="err">Σφάλμα φόρτωσης.</p>'; });

	function done(){
		content.innerHTML='<div class="done"><div class="check">✓</div><h2 style="margin:0 0 6px">Ευχαριστούμε!</h2><p style="color:#64748b;margin:0">Η σύμβαση υπεγράφη με επιτυχία.</p></div>';
	}

	function render(d){
		content.innerHTML =
			'<div class="row"><span>Κωδικός</span><b>'+(d.code||'—')+'</b></div>'+
			'<div class="row"><span>Πάροχος</span><b>'+(d.provider||'—')+'</b></div>'+
			'<div class="row"><span>Πελάτης</span><b>'+(d.customer||'—')+'</b></div>'+
			'<label>Ονοματεπώνυμο υπογράφοντος</label><input type="text" id="signer" placeholder="Όπως στην ταυτότητα">'+
			'<label>Υπογραφή</label><div class="padwrap"><button type="button" class="clear" id="clear">Καθαρισμός</button><canvas id="pad"></canvas></div>'+
			'<button class="btn" id="submit">Υπογράφω</button>'+
			'<div class="msg" id="msg"></div>';
		initPad();
	}

	function initPad(){
		var canvas=document.getElementById('pad'), ctx=canvas.getContext('2d'), drawing=false, dirty=false;
		function resize(){ var r=canvas.getBoundingClientRect(); canvas.width=r.width*2; canvas.height=r.height*2; ctx.scale(2,2); ctx.lineWidth=2.2; ctx.lineCap='round'; ctx.strokeStyle='#0f172a'; }
		resize();
		function pos(e){ var r=canvas.getBoundingClientRect(); var t=e.touches?e.touches[0]:e; return {x:t.clientX-r.left, y:t.clientY-r.top}; }
		function start(e){ drawing=true; dirty=true; var p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); e.preventDefault(); }
		function move(e){ if(!drawing)return; var p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); e.preventDefault(); }
		function end(){ drawing=false; }
		canvas.addEventListener('mousedown',start); canvas.addEventListener('mousemove',move); window.addEventListener('mouseup',end);
		canvas.addEventListener('touchstart',start,{passive:false}); canvas.addEventListener('touchmove',move,{passive:false}); canvas.addEventListener('touchend',end);
		document.getElementById('clear').addEventListener('click',function(){ ctx.clearRect(0,0,canvas.width,canvas.height); dirty=false; });

		document.getElementById('submit').addEventListener('click',function(){
			var msg=document.getElementById('msg'); var name=document.getElementById('signer').value.trim();
			if(!name){ msg.className='msg err'; msg.textContent='Συμπλήρωσε το ονοματεπώνυμο.'; return; }
			if(!dirty){ msg.className='msg err'; msg.textContent='Υπόγραψε στο πλαίσιο.'; return; }
			var btn=this; btn.disabled=true; msg.className='msg'; msg.textContent='Αποστολή…';
			fetch(REST,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:name,image:canvas.toDataURL('image/png')})})
				.then(function(r){return r.json();}).then(function(d){
					if(d&&d.ok){ done(); } else { btn.disabled=false; msg.className='msg err'; msg.textContent=(d&&d.error)||'Αποτυχία.'; }
				}).catch(function(){ btn.disabled=false; msg.className='msg err'; msg.textContent='Σφάλμα δικτύου.'; });
		});
	}
})();
</script>
</body>
</html>
		<?php
		exit;
	}
}
