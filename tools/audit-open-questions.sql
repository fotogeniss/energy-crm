-- Energy CRM — οι ίδιες ερωτήσεις χωρίς PHP.
--
-- Εφεδρικό του tools/audit-open-questions.php, για όταν το wp eval-file δεν
-- συνεργάζεται. Απαντά τα πάντα ΕΚΤΟΣ από τη ζωντανή δοκιμή του afm_hash, που
-- χρειάζεται το κλειδί για να αποκρυπτογραφήσει — ένα SELECT δεν μπορεί.
--
-- Μόνο SELECT. Καμία τιμή προσωπικού δεδομένου δεν επιστρέφεται.
--
--   wp db query < wp-content/plugins/energy-crm/tools/audit-open-questions.sql
--
-- ή επικόλληση στο Adminer (Local -> Database -> Open Adminer -> SQL command).
-- Το prefix είναι wp_ σε αυτή την εγκατάσταση.

SELECT '--- [6] signatures ---' AS ereuthma;

SELECT COUNT(*)                          AS synolo,
       SUM(signed_at IS NULL)            AS zontana_tokens,
       MAX(created_at)                   AS neoteri
FROM wp_ecrm_signatures;

SELECT '--- [10] tasks μόνο με πελάτη ---' AS ereuthma;

SELECT COUNT(*) AS aorates_se_gdpr
FROM wp_ecrm_tasks
WHERE customer_id IS NOT NULL AND contract_id IS NULL;

SELECT '--- [11] afm_hash ---' AS ereuthma;

SELECT COUNT(*)                                                        AS pelates,
       SUM(afm IS NOT NULL AND afm <> '')                              AS me_afm,
       SUM(afm LIKE 'ecrm1:%')                                         AS kryptografimeno,
       SUM(afm LIKE 'ecrm1:%' AND afm_hash IS NOT NULL AND afm_hash <> '') AS krypto_kai_hash,
       SUM(afm IS NOT NULL AND afm <> '' AND (afm_hash IS NULL OR afm_hash = '')) AS xoris_hash
FROM wp_ecrm_customers;

SELECT '--- [πλαίσιο] ---' AS ereuthma;

SELECT (SELECT COUNT(*) FROM wp_ecrm_commission_rules WHERE active = 1) AS kanones_energoi,
       (SELECT COUNT(*) FROM wp_ecrm_payouts)                          AS ekkatharuseis,
       (SELECT COUNT(*) FROM wp_ecrm_payouts WHERE status = 'paid')     AS pliromenes,
       (SELECT COUNT(*) FROM wp_ecrm_contracts
         WHERE payout_id IS NOT NULL AND payout_id > 0
           AND status IN ('cancelled','terminated'))                    AS pliromenes_akyromenes,
       (SELECT COUNT(*) FROM wp_ecrm_contracts)                         AS symvaseis,
       (SELECT COUNT(*) FROM wp_ecrm_files)                             AS arxeia,
       (SELECT COUNT(*) FROM wp_ecrm_files WHERE contract_id IS NULL)   AS arxeia_orfana;
