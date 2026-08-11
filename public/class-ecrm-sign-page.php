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
		$accent = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'accent_color', '#f59e0b' ) : '#f59e0b';
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
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
<style>
	:root { --accent: <?php echo esc_html( $accent ?: '#f59e0b' ); ?>; --navy:#0a1f3d; }
	* { box-sizing: border-box; }
	body { margin:0; font-family:"Inter",system-ui,sans-serif; background:
		radial-gradient(ellipse at 20% 10%, rgba(245,158,11,.12), transparent 50%),
		linear-gradient(160deg,#061429,var(--navy) 50%,#14304f); min-height:100vh;
		display:flex; align-items:center; justify-content:center; padding:20px; color:#0f172a; }
	.card { background:#fff; border-radius:18px; box-shadow:0 30px 70px -20px rgba(0,0,0,.5); width:100%; max-width:520px; overflow:hidden; }
	.head { background:var(--navy); color:#fff; padding:22px 24px; }
	.head h1 { margin:0; font-size:19px; }
	.head p { margin:6px 0 0; color:#cbd5e1; font-size:13px; }
	.body { padding:24px; }
	.row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f1f5f9; font-size:14px; }
	.row span { color:#64748b; } .row b { color:#0f172a; }
	label { display:block; font-size:13px; font-weight:600; margin:18px 0 6px; }
	input[type=text] { width:100%; height:44px; border:1.5px solid #e2e8f0; border-radius:10px; padding:0 12px; font-size:15px; font-family:inherit; }
	.padwrap { margin-top:8px; border:2px dashed #cbd5e1; border-radius:12px; background:#fafbfc; position:relative; }
	canvas { width:100%; height:200px; display:block; touch-action:none; border-radius:12px; }
	.clear { position:absolute; top:8px; right:10px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:5px 10px; font-size:12px; cursor:pointer; }
	.btn { width:100%; height:50px; margin-top:18px; border:none; border-radius:12px; background:linear-gradient(135deg,var(--accent),var(--accent)); color:var(--navy); font-weight:800; font-size:16px; cursor:pointer; font-family:inherit; }
	.btn:disabled { opacity:.6; }
	.msg { margin-top:14px; font-size:14px; text-align:center; }
	.ok { color:#15803d; } .err { color:#b91c1c; }
	.done { text-align:center; padding:40px 24px; }
	.done .check { width:64px; height:64px; border-radius:50%; background:#dcfce7; color:#15803d; display:grid; place-items:center; font-size:32px; margin:0 auto 16px; }
	.foot { text-align:center; font-size:12px; color:#94a3b8; padding:14px; }
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
