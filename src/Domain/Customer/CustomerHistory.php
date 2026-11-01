<?php

/**
 * (346) Ιστορικό πελάτη σε μία οθόνη — docs/UI-CUSTOMER-HISTORY.html.
 *
 * Παίρνει τις γραμμές που ήδη γράφει το CRM σε έξι διαφορετικούς πίνακες
 * (γεγονότα συμβάσεων, εργασίες, σημειώσεις, αλλαγές στοιχείων, έγγραφα,
 * επαφή προέλευσης) και τις κάνει ΜΙΑ χρονολογική λίστα. Τίποτα δεν
 * διαβάζεται από εδώ: ο καλών φέρνει τις γραμμές, ήδη περιορισμένες σε ό,τι
 * βλέπει ο χρήστης. Εδώ αποφασίζεται μόνο το «πώς διαβάζεται» κάθε γραμμή.
 *
 * ## Τι ΔΕΝ μπαίνει, επίτηδες
 *
 * - Τεχνικές καταγραφές (`doc_kind_ai`, `sla_escalation`, ό,τι άγνωστο):
 *   θόρυβος για τον πωλητή. Λίστα επιτρεπτών, όχι απαγορευμένων — ένας νέος
 *   τύπος γεγονότος δεν εμφανίζεται εδώ μέχρι να αποφασίσει κάποιος πώς
 *   διαβάζεται.
 * - Τιμές πεδίων: στις αλλαγές στοιχείων φαίνεται μόνο ΠΟΙΟ πεδίο άλλαξε.
 *   Οι τιμές μένουν στο υπάρχον «Ιστορικό αλλαγών» με τη μάσκα του.
 * - Τηλέφωνα παραληπτών: ο τίτλος μιας αποστολής χτίζεται από τον τύπο του
 *   γεγονότος, όχι από το μήνυμα (που γράφει «προς 69…»).
 * - Ποσά: κανένα, για κανέναν ρόλο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Customer;

use EnergyCRM\Domain\Contract\CloseReason;
use EnergyCRM\Domain\Contract\ContractStatus;

final class CustomerHistory
{
    /** Πάνω όριο γραμμών ανά απάντηση. Πέρα από αυτό: `truncated`. */
    public const MAX_ITEMS = 300;

    /** Τα φίλτρα της κάρτας, με τη σειρά που φαίνονται. */
    public const KINDS = ['contract', 'sign', 'task', 'note', 'doc', 'edit'];

    /** Ίδιες ετικέτες με το FIELD_LABEL της ecrm-view-customer-card.js. */
    private const FIELD_LABELS = [
        'first_name' => 'Όνομα', 'last_name' => 'Επώνυμο', 'father_name' => 'Πατρώνυμο',
        'company_name' => 'Επωνυμία', 'afm' => 'Α.Φ.Μ.', 'doy' => 'Δ.Ο.Υ.', 'adt' => 'Α.Δ.Τ.',
        'birth_date' => 'Ημ. Γέννησης', 'street' => 'Οδός', 'street_no' => 'Αριθμός',
        'postal_code' => 'Τ.Κ.', 'city' => 'Πόλη', 'region' => 'Περιοχή',
        'phone' => 'Τηλέφωνο', 'mobile' => 'Κινητό', 'email' => 'Email',
        'cross_sell_gas' => 'Πρόταση αερίου', 'cross_sell_power' => 'Πρόταση ρεύματος',
    ];

    private const ENERGY_LABELS = ['power' => 'Ρεύμα', 'gas' => 'Αέριο', 'mobile' => 'Κινητή'];

    /** Μέσα στο ίδιο δευτερόλεπτο: η αρχή πάει κάτω, ό,τι ακολούθησε πάνω. */
    private const WEIGHT_LEAD    = 0;
    private const WEIGHT_CREATED = 1;
    private const WEIGHT_DOC     = 2;
    private const WEIGHT_OTHER   = 3;

    /**
     * @param array{
     *     contracts?: list<array<string, mixed>>,
     *     events?: list<array<string, mixed>>,
     *     tasks?: list<array<string, mixed>>,
     *     notes?: list<array<string, mixed>>,
     *     edits?: list<array<string, mixed>>,
     *     files?: list<array<string, mixed>>,
     *     leads?: list<array<string, mixed>>
     * } $rows
     * @param array<string, string> $docKinds    slug => ετικέτα (ECRM_Docs::kinds()).
     * @param array<string, string> $leadSources slug => ετικέτα (ECRM_Leads::sources()).
     *
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>, truncated: bool}
     */
    public static function compose(array $rows, array $docKinds = [], array $leadSources = []): array
    {
        $refs  = self::contractRefs($rows['contracts'] ?? []);
        $items = [];

        foreach ($rows['events'] ?? [] as $event) {
            $item = self::fromEvent($event, $refs);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        foreach ($rows['tasks'] ?? [] as $task) {
            array_push($items, ...self::fromTask($task, $refs));
        }

        foreach ($rows['notes'] ?? [] as $note) {
            $items[] = self::item('note', 'note', (string) ($note['created_at'] ?? ''), 'Σημείωση', [
                'detail'   => self::clip((string) ($note['body'] ?? '')),
                'actor_id' => (int) ($note['partner_user_id'] ?? 0),
            ]);
        }

        array_push($items, ...self::fromEdits($rows['edits'] ?? []));

        foreach ($rows['files'] ?? [] as $file) {
            $kind    = (string) ($file['doc_kind'] ?? '');
            $title   = 'Ανέβηκε έγγραφο: ' . ($docKinds[$kind] ?? 'Άλλο');
            $items[] = self::item('doc', 'doc', (string) ($file['created_at'] ?? ''), $title, [
                'contract_id' => (int) ($file['contract_id'] ?? 0),
                'weight'      => self::WEIGHT_DOC,
            ], $refs);
        }

        foreach ($rows['leads'] ?? [] as $lead) {
            $source     = (string) ($lead['source'] ?? '');
            $contractId = (int) ($lead['contract_id'] ?? 0);
            $items[]    = self::item('lead', 'lead', (string) ($lead['created_at'] ?? ''), 'Ήρθε ως επαφή'
                . (isset($leadSources[$source]) ? ': ' . $leadSources[$source] : ''), [
                'detail'      => isset($refs[$contractId]) ? 'Έγινε πελάτης με τη ' . $refs[$contractId]['code'] : '',
                'actor_id'    => (int) ($lead['partner_user_id'] ?? 0),
                'contract_id' => $contractId,
                'weight'      => self::WEIGHT_LEAD,
            ], $refs);
        }

        $items = array_values(array_filter($items, static fn (array $i): bool => $i['at'] !== ''));

        usort($items, static function (array $a, array $b): int {
            return [$b['at'], $b['weight']] <=> [$a['at'], $a['weight']];
        });

        $counts = array_fill_keys(self::KINDS, 0);

        foreach ($items as $item) {
            if (isset($counts[$item['kind']])) {
                $counts[$item['kind']]++;
            }
        }

        $counts['all'] = count($items);
        $truncated     = count($items) > self::MAX_ITEMS;
        $items         = array_slice($items, 0, self::MAX_ITEMS);

        foreach ($items as $index => $item) {
            unset($items[$index]['weight']);
        }

        return ['items' => $items, 'counts' => $counts, 'truncated' => $truncated];
    }

    /**
     * @param list<array<string, mixed>> $contracts
     *
     * @return array<int, array{code: string, label: string}>
     */
    private static function contractRefs(array $contracts): array
    {
        $refs = [];

        foreach ($contracts as $c) {
            $id   = (int) ($c['id'] ?? 0);
            $code = trim((string) ($c['code'] ?? ''));
            $code = $code !== '' ? '#' . $code : 'Πρόχειρο';

            $parts = array_filter([
                self::ENERGY_LABELS[(string) ($c['energy_type'] ?? '')] ?? '',
                trim((string) ($c['provider_name'] ?? '')),
            ]);

            $refs[$id] = [
                'code'  => $code,
                'label' => $parts === [] ? $code : $code . ' · ' . implode(' ', $parts),
            ];
        }

        return $refs;
    }

    /**
     * @param array<string, mixed>                              $e
     * @param array<int, array{code: string, label: string}> $refs
     *
     * @return array<string, mixed>|null
     */
    private static function fromEvent(array $e, array $refs): ?array
    {
        $type    = (string) ($e['type'] ?? '');
        $at      = (string) ($e['created_at'] ?? '');
        $message = trim((string) ($e['message'] ?? ''));
        $base    = [
            'actor_id'    => (int) ($e['user_id'] ?? 0),
            'contract_id' => (int) ($e['contract_id'] ?? 0),
        ];

        if ($type === 'created') {
            return self::item('contract', 'contract', $at, 'Νέα σύμβαση', $base + [
                'detail' => $message,
                'weight' => self::WEIGHT_CREATED,
            ], $refs);
        }

        if ($type === 'status_change') {
            return self::statusChange($e, $at, $message, $base, $refs);
        }

        if ($type === 'reassigned') {
            return self::item('contract', 'contract', $at, 'Άλλαξε υπεύθυνος συνεργάτης', $base + [
                'detail' => $message,
            ], $refs);
        }

        if ($type === 'note') {
            return self::item('note', 'note', $at, 'Σημείωση σύμβασης', $base + [
                'detail' => self::clip($message),
            ], $refs);
        }

        if ($type === 'field_change') {
            return self::item('edit', 'edit', $at, 'Άλλαξαν στοιχεία της σύμβασης', $base, $refs);
        }

        if (str_starts_with($type, 'sign_sent_')) {
            return self::item('sign', 'sign', $at, self::signTitle(substr($type, 10), $message, true), $base, $refs);
        }

        if (str_starts_with($type, 'sign_failed_')) {
            return self::item('sign', 'bad', $at, self::signTitle(substr($type, 12), $message, false), $base + [
                'detail' => self::afterDash($message),
            ], $refs);
        }

        if ($type === 'sign_expired') {
            return self::item('sign', 'bad', $at, 'Έληξε ο σύνδεσμος υπογραφής', $base, $refs);
        }

        if ($type === 'sms' || $type === 'sms_failed') {
            return self::item(
                'sign',
                $type === 'sms' ? 'sign' : 'bad',
                $at,
                $type === 'sms' ? 'Στάλθηκε SMS' : 'Αποτυχία αποστολής SMS',
                $base,
                $refs
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed>                              $e
     * @param array<string, int>                                $base
     * @param array<int, array{code: string, label: string}> $refs
     *
     * @return array<string, mixed>
     */
    private static function statusChange(array $e, string $at, string $message, array $base, array $refs): array
    {
        $from   = (string) ($e['from_status'] ?? '');
        $to     = (string) ($e['to_status'] ?? '');
        $target = ContractStatus::tryFromSlug($to);
        $title  = self::statusLabel($from) . ' → ' . self::statusLabel($to);
        $reason = (string) ($e['reason'] ?? '');

        if ($reason !== '') {
            $title .= ' · ' . CloseReason::label($reason);
        }

        $tone = match ($target) {
            ContractStatus::Active => 'ok',
            ContractStatus::Cancelled, ContractStatus::ThalisRejected, ContractStatus::MnpReject => 'bad',
            default => 'contract',
        };

        return self::item('contract', $tone, $at, $title, $base + ['detail' => self::clip($message)], $refs);
    }

    private static function statusLabel(string $slug): string
    {
        if ($slug === '') {
            return '—';
        }

        return ContractStatus::tryFromSlug($slug)?->label() ?? $slug;
    }

    private static function signTitle(string $channel, string $message, bool $ok): string
    {
        // Το SMS μπορεί να έφυγε ως Viber (Apifon): το κανάλι που πήγε στ'
        // αλήθεια το γράφει μόνο το μήνυμα.
        $name = match ($channel) {
            'sms'   => stripos($message, 'viber') !== false ? 'Viber' : 'SMS',
            'email' => 'email',
            default => '',
        };

        if ($channel === 'link') {
            return 'Ο σύνδεσμος υπογραφής αντιγράφηκε για χειροκίνητη αποστολή';
        }

        if ($ok) {
            return 'Στάλθηκε σύνδεσμος υπογραφής' . ($name !== '' ? ' με ' . $name : '');
        }

        return 'Ο σύνδεσμος υπογραφής δεν στάλθηκε' . ($name !== '' ? ' με ' . $name : '');
    }

    /**
     * @param array<string, mixed>                              $t
     * @param array<int, array{code: string, label: string}> $refs
     *
     * @return list<array<string, mixed>>
     */
    private static function fromTask(array $t, array $refs): array
    {
        // Οι κλιμακώσεις SLA είναι εσωτερική δουλειά του υπευθύνου ομάδας,
        // όχι κάτι που έγινε με τον πελάτη.
        if ((string) ($t['note'] ?? '') === 'sla_escalation' || (string) ($t['kind'] ?? '') === 'sla_escalation') {
            return [];
        }

        $title    = trim((string) ($t['title'] ?? ''));
        $assigned = (int) ($t['assigned_to'] ?? 0);
        $base     = ['contract_id' => (int) ($t['contract_id'] ?? 0)];
        $due      = (string) ($t['due_at'] ?? '');
        $done     = (string) ($t['done_at'] ?? '');
        $isDone   = (string) ($t['status'] ?? '') === 'done';

        $items = [
            self::item('task', 'task', (string) ($t['created_at'] ?? ''), 'Εργασία: «' . $title . '»', $base + [
                'detail'   => trim(($due !== '' ? 'προθεσμία ' . self::shortDate($due) : '')
                    . ($isDone ? '' : ($due !== '' ? ' · ' : '') . 'Ανοιχτή')),
                'actor_id' => (int) ($t['created_by'] ?? 0) ?: $assigned,
            ], $refs),
        ];

        if ($isDone && $done !== '') {
            $items[] = self::item('task', 'ok', $done, 'Ολοκληρώθηκε: «' . $title . '»', $base + [
                'actor_id' => $assigned,
            ], $refs);
        }

        return $items;
    }

    /**
     * Μία αποθήκευση γράφει μία γραμμή ανά πεδίο· εδώ ξαναγίνεται μία.
     *
     * @param list<array<string, mixed>> $edits
     *
     * @return list<array<string, mixed>>
     */
    private static function fromEdits(array $edits): array
    {
        $groups = [];

        foreach ($edits as $edit) {
            $key   = (string) ($edit['created_at'] ?? '') . '|' . (int) ($edit['partner_user_id'] ?? 0);
            $field = (string) ($edit['field'] ?? '');
            $label = self::FIELD_LABELS[$field] ?? $field;

            $groups[$key] ??= [
                'at'     => (string) ($edit['created_at'] ?? ''),
                'actor'  => (int) ($edit['partner_user_id'] ?? 0),
                'fields' => [],
            ];

            if ($label !== '' && ! in_array($label, $groups[$key]['fields'], true)) {
                $groups[$key]['fields'][] = $label;
            }
        }

        $items = [];

        foreach ($groups as $g) {
            $items[] = self::item('edit', 'edit', $g['at'], 'Άλλαξαν στοιχεία: ' . implode(', ', $g['fields']), [
                'actor_id' => $g['actor'],
            ]);
        }

        return $items;
    }

    /**
     * @param array<string, mixed>                              $extra
     * @param array<int, array{code: string, label: string}> $refs
     *
     * @return array<string, mixed>
     */
    private static function item(
        string $kind,
        string $tone,
        string $at,
        string $title,
        array $extra = [],
        array $refs = []
    ): array {
        $contractId = (int) ($extra['contract_id'] ?? 0);
        $ref        = $refs[$contractId] ?? null;

        return [
            'kind'         => $kind,
            'tone'         => $tone,
            'at'           => $at,
            'title'        => $title,
            'detail'       => (string) ($extra['detail'] ?? ''),
            'actor_id'     => (int) ($extra['actor_id'] ?? 0),
            'contract_id'  => $ref !== null ? $contractId : 0,
            'contract_ref' => $ref['label'] ?? '',
            'contract_code' => $ref['code'] ?? '',
            'weight'       => (int) ($extra['weight'] ?? self::WEIGHT_OTHER),
        ];
    }

    /** Δύο γραμμές στην οθόνη, όχι ολόκληρο κείμενο. */
    private static function clip(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 160 ? rtrim(mb_substr($text, 0, 159)) . '…' : $text;
    }

    /** «… ΔΕΝ στάλθηκε με SMS προς 69… — Ο αριθμός δεν απάντησε» → ο λόγος. */
    private static function afterDash(string $message): string
    {
        $pos = mb_strrpos($message, ' — ');

        return $pos === false ? '' : self::clip(mb_substr($message, $pos + 3));
    }

    private static function shortDate(string $datetime): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $datetime, $m) !== 1) {
            return $datetime;
        }

        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }
}
