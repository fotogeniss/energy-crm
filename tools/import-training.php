<?php
/**
 * Εισαγωγή των μαθημάτων της «Εκπαίδευσης» από docs/training-lessons.json.
 *
 * ΓΙΑΤΙ script και όχι η φόρμα import του admin:
 *
 * Η φόρμα έχει checkbox «replace» που εκτελεί `DELETE FROM {kb_entries}` —
 * ΟΛΟΚΛΗΡΟ τον πίνακα, δηλαδή μαζί και τις καρτέλες παρόχων. Ένα λάθος κλικ
 * εκεί σβήνει δουλειά μηνών, και δεν υπάρχει undo. Εδώ ο καθαρισμός είναι
 * περιορισμένος ρητά σε `section = 'training'` και σε τίποτε άλλο, οπότε το
 * script ξανατρέχει με ασφάλεια όσες φορές χρειαστεί: πάντα καταλήγει με
 * ακριβώς τα μαθήματα του αρχείου, χωρίς διπλοεγγραφές και χωρίς να αγγίξει
 * ούτε μία καρτέλα παρόχου.
 *
 * ΠΡΟΣΟΧΗ: ό,τι μάθημα έχει γραφτεί χειροκίνητα από τον admin (δηλαδή με
 * Ενότητα «Εκπαίδευση») θα αντικατασταθεί κι αυτό. Αν υπάρχουν τέτοια,
 * εξήγαγέ τα πρώτα από τη φόρμα export.
 *
 * Τρέξιμο από τη ρίζα του plugin, στο Site Shell:
 *
 *     wp eval-file tools/import-training.php
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$file = dirname( __DIR__ ) . '/docs/training-lessons.json';

if ( ! file_exists( $file ) ) {
	echo "ΣΦΑΛΜΑ: δεν βρέθηκε {$file}\n";
	exit( 1 );
}

$rows = json_decode( (string) file_get_contents( $file ), true );

if ( ! is_array( $rows ) || ! $rows ) {
	echo "ΣΦΑΛΜΑ: μη έγκυρο ή άδειο JSON.\n";
	exit( 1 );
}

$table = ECRM_DB::table( 'kb_entries' );

// Δίχτυ ασφαλείας: αν το αρχείο περιέχει έστω μία εγγραφή που ΔΕΝ είναι
// μάθημα, σταματάμε πριν γράψουμε τίποτα. Αλλιώς ένα λάθος αρχείο θα
// περνούσε καρτέλες παρόχων μέσα από τη διαδρομή των μαθημάτων.
foreach ( $rows as $i => $r ) {
	if ( ( $r['section'] ?? '' ) !== ECRM_KB::SECTION_TRAINING ) {
		echo "ΣΦΑΛΜΑ: η εγγραφή #{$i} δεν έχει section='training'. Δεν γράφτηκε τίποτα.\n";
		exit( 1 );
	}
	if ( empty( $r['title'] ) ) {
		echo "ΣΦΑΛΜΑ: η εγγραφή #{$i} δεν έχει τίτλο. Δεν γράφτηκε τίποτα.\n";
		exit( 1 );
	}
}

$before = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE section = %s", ECRM_KB::SECTION_TRAINING )
);
$others = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE section <> %s", ECRM_KB::SECTION_TRAINING )
);

$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE section = %s", ECRM_KB::SECTION_TRAINING ) );

$n = 0;
foreach ( $rows as $r ) {
	$wpdb->insert(
		$table,
		[
			'provider_id'   => null,
			'provider_name' => null,
			'energy_type'   => null,
			'section'       => ECRM_KB::SECTION_TRAINING,
			'customer_type' => null,
			'title'         => sanitize_text_field( (string) $r['title'] ),
			'body'          => wp_kses_post( (string) ( $r['body'] ?? '' ) ),
			'sort_order'    => (int) ( $r['sort_order'] ?? 0 ),
			'active'        => 1,
		]
	);
	$n++;
}

$after_others = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE section <> %s", ECRM_KB::SECTION_TRAINING )
);

echo "Μαθήματα πριν:  {$before}\n";
echo "Μαθήματα τώρα:  {$n}\n";
echo "Καρτέλες παρόχων πριν/τώρα: {$others} / {$after_others}";
echo ( $others === $after_others ) ? "  (αμετάβλητες, σωστό)\n" : "  <<< ΠΡΟΣΟΧΗ, ΑΛΛΑΞΑΝ\n";
