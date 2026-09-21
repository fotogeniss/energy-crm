<?php

/**
 * Ποιους παρόχους βλέπει κάθε χρήστης (278) -- από άκρη σε άκρη, μέσα από τις
 * πραγματικές διαδρομές.
 *
 * Τα καθαρά κομμάτια (τομή, αντικατάσταση λίστας) τα ελέγχουν τα unit tests του
 * `tests/Unit/Providers/`. Εδώ ελέγχεται ότι οι κανόνες ισχύουν ΕΚΕΙ ΠΟΥ
 * ΜΕΤΡΑΝΕ: στον κατάλογο της φόρμας, στην αποθήκευση αίτησης, στην ανανέωση,
 * στις διαδρομές που αλλάζουν τις λίστες. Μια φόρμα που κρύβει τον πάροχο αλλά
 * μια αποθήκευση που τον δέχεται δεν θα ήταν περιορισμός, θα ήταν διακόσμηση.
 *
 * Οι πάροχοι του fixture είναι πραγματικές γραμμές (τα προγράμματα έχουν
 * foreign key προς αυτούς) -- και οι έλεγχοι κοιτάζουν μόνο ΤΟΥΣ ΔΙΚΟΥΣ ΤΟΥΣ
 * παρόχους στην απάντηση, όχι το πλήθος: η βάση δοκιμών έχει και τους
 * πραγματικούς σπόρους (Volton, Orizon κτλ).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Schema\Migrations\SeedProviderGrants;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Persistence\TeamRepository;
use EnergyCRM\Providers\Persistence\ProviderGrantRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ProviderAccessTest extends IntegrationTestCase
{
    private const VALID_AFM = '090003373';

    private TeamRepository $team;

    private ProviderGrantRepository $grants;

    private int $alpha;

    private int $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team   = new TeamRepository();
        $this->grants = new ProviderGrantRepository();
        $this->alpha  = $this->makeProvider('alpha');
        $this->beta   = $this->makeProvider('beta');
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        remove_all_filters('pre_wp_mail');

        parent::tearDown();
    }

    // --- τι βλέπει ο καθένας ---------------------------------------------------

    public function testAnAdministratorSeesEveryProvider(): void
    {
        wp_set_current_user($this->makeAdministrator());

        $ids = $this->catalogueProviderIds();

        self::assertContains($this->alpha, $ids);
        self::assertContains($this->beta, $ids);
    }

    public function testASellerSeesOnlyWhatWasGiven(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $this->grants->set($seller, [$this->alpha]);
        wp_set_current_user($seller);

        $data = $this->catalogue();
        $ids  = $this->providerIds($data);

        self::assertContains($this->alpha, $ids);
        self::assertNotContains($this->beta, $ids, 'Ο κατάλογος έδειξε πάροχο που δεν του δόθηκε.');

        $programOwners = array_map(
            static fn (array $row): int => (int) $row['provider_id'],
            (array) $data['programs']
        );

        self::assertContains($this->alpha, $programOwners);
        self::assertNotContains($this->beta, $programOwners, 'Τα προγράμματα ενός κρυφού παρόχου διέρρευσαν.');
    }

    /** Χρήστης που φτιάχτηκε έξω από τη ροή μας (π.χ. wp-admin): κανένας πάροχος, όχι όλοι. */
    public function testSomeoneWithNoListSeesNothing(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $ids = $this->catalogueProviderIds();

        self::assertNotContains($this->alpha, $ids);
        self::assertNotContains($this->beta, $ids);
    }

    /** Ο κανόνας όλου του (278): κανείς δεν βλέπει κάτι που δεν βλέπει ο από πάνω του. */
    public function testNobodySeesMoreThanTheirManager(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        $seller  = $this->makeCrmUser(Roles::SELLER);
        $this->team->attach($seller, $manager);

        $this->grants->set($manager, [$this->alpha]);
        $this->grants->set($seller, [$this->alpha, $this->beta]);

        wp_set_current_user($seller);

        $ids = $this->catalogueProviderIds();

        self::assertContains($this->alpha, $ids);
        self::assertNotContains(
            $this->beta,
            $ids,
            'Ο πωλητής είδε πάροχο που δεν έχει ο manager του -- η γραμμένη λίστα του δεν αρκεί, μετράει η τομή.'
        );
    }

    // --- αποθήκευση αίτησης -------------------------------------------------------

    public function testANewContractWithAHiddenProviderIsRefused(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $this->grants->set($seller, [$this->alpha]);
        wp_set_current_user($seller);

        $before = $this->contractCountOf($seller);

        $response = $this->saveContract(['provider_id' => $this->beta]);

        self::assertSame(403, $response->get_status());
        self::assertSame('provider_id', $response->get_data()['field'] ?? null);
        self::assertSame($before, $this->contractCountOf($seller), 'Η άρνηση άφησε πίσω της αίτηση.');
    }

    public function testANewContractWithAVisibleProviderIsSaved(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $this->grants->set($seller, [$this->alpha]);
        wp_set_current_user($seller);

        self::assertSame(200, $this->saveContract(['provider_id' => $this->alpha])->get_status());
    }

    /**
     * Απόφαση ιδιοκτήτη 21/09: ο περιορισμός είναι για ΝΕΕΣ αιτήσεις. Μια παλιά
     * αίτηση σε πάροχο που αφαιρέθηκε μένει επεξεργάσιμη -- αλλά δεν γίνεται να
     * αλλάξει μια άλλη ΠΡΟΣ αυτόν.
     */
    public function testAnOldContractKeepsItsProviderAfterItWasTakenAway(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $this->grants->set($seller, []);
        wp_set_current_user($seller);

        $mine  = $this->contractOf($seller, $this->alpha);
        $other = $this->contractOf($seller, 0);

        $kept = $this->saveContract([
            'contract_id' => $mine,
            'provider_id' => $this->alpha,
            'notes'       => 'Διόρθωση μετά την αφαίρεση του παρόχου',
        ]);

        self::assertSame(200, $kept->get_status(), 'Η επεξεργασία μιας παλιάς αίτησης κλείδωσε.');

        $moved = $this->saveContract(['contract_id' => $other, 'provider_id' => $this->alpha]);

        self::assertSame(403, $moved->get_status(), 'Μια άλλη αίτηση άλλαξε ΠΡΟΣ πάροχο που δεν βλέπει.');
    }

    /** Η φόρμα επεξεργασίας παίρνει πίσω τον πάροχο της αίτησης -- μόνο της δικής του αίτησης. */
    public function testTheCatalogueKeepsTheProviderOfAContractBeingEdited(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $this->grants->set($seller, []);
        $mine = $this->contractOf($seller, $this->alpha);

        $stranger = $this->makeCrmUser(Roles::SELLER);
        $theirs   = $this->contractOf($stranger, $this->beta);

        wp_set_current_user($seller);

        $data = $this->catalogue($mine);

        self::assertContains($this->alpha, $this->providerIds($data));
        self::assertSame($this->alpha, $data['kept_provider']);

        $probe = $this->catalogue($theirs);

        self::assertNotContains(
            $this->beta,
            $this->providerIds($probe),
            'Ενα ξένο contract id άνοιξε πάροχο -- το ?contract= δεν πρέπει να είναι παραθυράκι.'
        );
        self::assertNull($probe['kept_provider']);
    }

    public function testARenewalIntoAHiddenProviderIsRefused(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $this->grants->set($seller, []);
        wp_set_current_user($seller);

        $contract = $this->contractOf($seller, $this->alpha);
        $before   = $this->contractCountOf($seller);

        $response = rest_do_request(new WP_REST_Request('POST', '/ecrm/v1/contracts/' . $contract . '/renew'));

        self::assertSame(403, $response->get_status());
        self::assertSame($before, $this->contractCountOf($seller));
    }

    // --- ποιος αλλάζει τις λίστες ------------------------------------------------

    public function testAManagerCannotGiveWhatTheyDoNotHave(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        $seller  = $this->makeCrmUser(Roles::SELLER);
        $this->team->attach($seller, $manager);
        $this->grants->set($manager, [$this->alpha]);
        $this->grants->set($seller, [$this->alpha]);

        wp_set_current_user($manager);

        $response = $this->setProviders($seller, [$this->alpha, $this->beta]);

        self::assertSame(422, $response->get_status());
        self::assertSame([$this->alpha], $this->grants->grantsOf($seller), 'Η άρνηση πείραξε τη λίστα.');
    }

    public function testOnlyTheDirectManagerChangesTheList(): void
    {
        $top    = $this->makeCrmUser(Roles::PARTNER);
        $middle = $this->makeCrmUser(Roles::PARTNER);
        $seller = $this->makeCrmUser(Roles::SELLER);
        $this->team->attach($middle, $top);
        $this->team->attach($seller, $middle);

        foreach ([$top, $middle, $seller] as $user) {
            $this->grants->set($user, [$this->alpha, $this->beta]);
        }

        wp_set_current_user($top);
        self::assertSame(403, $this->setProviders($seller, [$this->alpha])->get_status());

        wp_set_current_user($middle);
        self::assertSame(200, $this->setProviders($seller, [$this->alpha])->get_status());
        self::assertSame([$this->alpha], $this->grants->grantsOf($seller));
    }

    /** Ο,τι αφαιρείται από κάποιον σβήνεται και από όλους κάτω του -- να μη «αναστηθεί» αν ξαναδοθεί. */
    public function testTakingAProviderAwayReachesEveryoneBelow(): void
    {
        $admin  = $this->makeAdministrator();
        $top    = $this->makeCrmUser(Roles::PARTNER);
        $middle = $this->makeCrmUser(Roles::PARTNER);
        $seller = $this->makeCrmUser(Roles::SELLER);
        $this->team->attach($top, $admin);
        $this->team->attach($middle, $top);
        $this->team->attach($seller, $middle);

        foreach ([$top, $middle, $seller] as $user) {
            $this->grants->set($user, [$this->alpha, $this->beta]);
        }

        wp_set_current_user($admin);

        self::assertSame(200, $this->setProviders($top, [$this->alpha])->get_status());

        self::assertSame([$this->alpha], $this->grants->grantsOf($top));
        self::assertSame(
            [$this->alpha],
            $this->grants->grantsOf($middle),
            'Ο υπο-manager κράτησε γραμμένο τον πάροχο.'
        );
        self::assertSame([$this->alpha], $this->grants->grantsOf($seller), 'Ο πωλητής κράτησε γραμμένο τον πάροχο.');
    }

    /** Το «σε όλους» πιάνει μόνο τους άμεσους -- ο υπο-manager αποφασίζει για τους δικούς του. */
    public function testGivingToEveryoneReachesOnlyDirectReports(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        $middle  = $this->makeCrmUser(Roles::PARTNER);
        $seller  = $this->makeCrmUser(Roles::SELLER);
        $this->team->attach($middle, $manager);
        $this->team->attach($seller, $middle);

        $this->grants->set($manager, [$this->alpha, $this->beta]);
        $this->grants->set($middle, [$this->alpha]);
        $this->grants->set($seller, [$this->alpha]);

        wp_set_current_user($manager);

        $request = new WP_REST_Request('POST', '/ecrm/v1/team/providers');
        $request->set_body_params(['provider_id' => $this->beta, 'op' => 'grant']);

        self::assertSame(200, rest_do_request($request)->get_status());
        self::assertSame([$this->alpha, $this->beta], $this->grants->grantsOf($middle));
        self::assertSame(
            [$this->alpha],
            $this->grants->grantsOf($seller),
            'Το «σε όλους» πέρασε πάνω από τον υπο-manager.'
        );
    }

    // --- νέο μέλος ------------------------------------------------------------------

    public function testANewMemberGetsTheManagersProvidersByDefault(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        $this->grants->set($manager, [$this->alpha]);
        wp_set_current_user($manager);
        $this->silenceMail();

        $response = $this->createMember('default@example.test', null);

        self::assertSame(200, $response->get_status());

        $created = (int) $response->get_data()['id'];

        self::assertContains($this->alpha, $this->grants->grantsOf($created));
        self::assertNotContains($this->beta, $this->grants->grantsOf($created));
    }

    public function testANewMemberCannotBeGivenWhatTheManagerLacks(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        $this->grants->set($manager, [$this->alpha]);
        wp_set_current_user($manager);
        $this->silenceMail();

        $response = $this->createMember('refused@example.test', [$this->beta]);

        self::assertSame(422, $response->get_status());
        self::assertFalse(email_exists('refused@example.test'), 'Η άρνηση άφησε πίσω της λογαριασμό.');
    }

    // --- η μέρα της εγκατάστασης ------------------------------------------------

    /** 0037: οι υπάρχοντες χρήστες παίρνουν κάθε πάροχο· όποιος έχει ήδη λίστα μένει ανέγγιχτος. */
    public function testTheSeedGivesExistingUsersEveryProviderOnce(): void
    {
        $old     = $this->makeCrmUser(Roles::SELLER);
        $decided = $this->makeCrmUser(Roles::SELLER);
        $this->grants->set($decided, []);

        (new SeedProviderGrants())->apply(new SchemaInspector());

        self::assertContains($this->alpha, $this->grants->grantsOf($old));
        self::assertContains($this->beta, $this->grants->grantsOf($old));
        self::assertSame(
            [],
            $this->grants->grantsOf($decided),
            'Το γέμισμα πάτησε πάνω σε απόφαση που είχε ήδη παρθεί.'
        );
    }

    // --- fixtures ------------------------------------------------------------------

    private function makeProvider(string $slug): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'slug'       => 'ecrm-access-' . $slug . '-' . wp_generate_password(6, false),
            'name'       => 'Πάροχος ' . $slug,
            'active'     => 1,
            'sort_order' => 0,
        ]);

        $providerId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $providerId, 'Το provider fixture δεν μπήκε.');

        $wpdb->insert(Tables::name(Tables::PROGRAMS), [
            'provider_id' => $providerId,
            'name'        => 'Πρόγραμμα ' . $slug,
            'energy_type' => 'power',
            'category'    => 'home',
            'price_type'  => 'fixed',
            'active'      => 1,
            'sort_order'  => 0,
        ]);

        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'Το program fixture δεν μπήκε.');

        return $providerId;
    }

    private function makeAdministrator(): int
    {
        $userId = $this->makePartner();
        $user   = get_user_by('id', $userId);
        self::assertNotFalse($user);
        $user->set_role('administrator');

        return $userId;
    }

    private function contractOf(int $ownerId, int $providerId): int
    {
        $fields = ['status' => 'draft', 'energy_type' => 'power'];

        if ($providerId > 0) {
            $fields['provider_id'] = $providerId;
        }

        $id = (new ContractRepository())->create($fields, UserScope::forSelf($ownerId));
        self::assertGreaterThan(0, $id, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $id;
    }

    private function contractCountOf(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE partner_user_id = %d',
            Tables::name(Tables::CONTRACTS),
            $userId
        ));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function saveContract(array $params): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts');
        $request->set_body_params($params + [
            'energy_type' => 'power',
            'status'      => 'draft',
            'afm'         => self::VALID_AFM,
            'first_name'  => 'Κωνσταντίνος',
            'last_name'   => 'Νίκας',
        ]);

        return rest_do_request($request);
    }

    /**
     * @param list<int> $providerIds
     */
    private function setProviders(int $member, array $providerIds): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/team/' . $member . '/providers');
        $request->set_body_params(['provider_ids' => $providerIds]);

        return rest_do_request($request);
    }

    /**
     * @param list<int>|null $providerIds null = χωρίς το πεδίο, η προεπιλογή.
     */
    private function createMember(string $email, ?array $providerIds): WP_REST_Response
    {
        $params = ['name' => 'Νέο Μέλος', 'email' => $email, 'role' => Roles::SELLER];

        if ($providerIds !== null) {
            $params['provider_ids'] = $providerIds;
        }

        $request = new WP_REST_Request('POST', '/ecrm/v1/team');
        $request->set_body_params($params);

        return rest_do_request($request);
    }

    /** Η πρόσκληση στέλνει email· εδώ δεν μας αφορά, και δεν πρέπει να φύγει. */
    private function silenceMail(): void
    {
        add_filter('pre_wp_mail', static fn (): bool => true);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogue(int $contractId = 0): array
    {
        $request = new WP_REST_Request('GET', '/ecrm/v1/providers');

        if ($contractId > 0) {
            $request->set_query_params(['contract' => $contractId]);
        }

        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());

        /** @var array<string, mixed> $data */
        $data = $response->get_data();

        return $data;
    }

    /**
     * @return list<int>
     */
    private function catalogueProviderIds(): array
    {
        return $this->providerIds($this->catalogue());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<int>
     */
    private function providerIds(array $data): array
    {
        return array_values(array_map(
            static fn (array $row): int => (int) $row['id'],
            (array) $data['providers']
        ));
    }
}
