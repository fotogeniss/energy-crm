<?php
// Loader χωρίς declare(strict_types=1) στο δικό του top-level, ώστε το
// `wp eval-file` (που τρέχει το περιεχόμενο μέσω eval()) να μη σκοντάφτει
// στο strict_types declaration του πραγματικού script — το require από εδώ
// μεταγλωττίζει το preflight-encryption.php ξεχωριστά, όπου το declare του
// γίνεται σεβαστό κανονικά.
require __DIR__ . '/preflight-encryption.php';
