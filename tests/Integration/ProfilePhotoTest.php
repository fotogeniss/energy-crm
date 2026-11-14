<?php

/**
 * (382) Φωτογραφία προφίλ: σύνδεσμος, αφαίρεση, διαγραφή χρήστη, άρνηση
 * χωρίς αρχείο. Το ανέβασμα (move/GD) θέλει πραγματικό `is_uploaded_file()`,
 * οπότε εδώ το αρχείο μπαίνει με το χέρι στον προστατευμένο φάκελο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Infrastructure\ProfilePhoto;
use EnergyCRM\Persistence\DocumentStorage;
use WP_REST_Request;

final class ProfilePhotoTest extends IntegrationTestCase
{
    private DocumentStorage $storage;

    private ProfilePhoto $photos;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new DocumentStorage(\ECRM_Files::dir());
        $this->photos  = new ProfilePhoto($this->storage);
        $this->user    = $this->makeCrmUser();

        wp_set_current_user($this->user);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testNoPhotoMeansNoLink(): void
    {
        self::assertSame('', ProfilePhoto::url($this->user));
        self::assertNull($this->photos->path($this->user));
    }

    public function testALinkCarriesTheVersionAndTheNonce(): void
    {
        $this->givePhoto($this->user, 1234);

        // Με ή χωρίς «ωραία» permalinks: /wp-json/… ή ?rest_route=%2F…
        $url = urldecode(ProfilePhoto::url($this->user));

        self::assertStringContainsString('/ecrm/v1/photos/' . $this->user, $url);
        self::assertStringContainsString('v=1234', $url);
        self::assertStringContainsString('_wpnonce=', $url);
        self::assertNotNull($this->photos->path($this->user));
    }

    /** Στα Windows η διαδρομή έχει «\»· πρέπει να επιζεί από το user meta. */
    public function testTheStoredPathIsReadBackAsWritten(): void
    {
        $path = $this->givePhoto($this->user, 7);

        $read = $this->photos->path($this->user);

        self::assertNotNull($read, 'Η διαδρομή χάθηκε στο user meta.');
        self::assertSame(realpath($path), realpath($read));
    }

    public function testRemovingDeletesTheFile(): void
    {
        $path = $this->givePhoto($this->user, 1);

        $this->photos->remove($this->user);

        self::assertFileDoesNotExist($path);
        self::assertSame('', ProfilePhoto::url($this->user));
    }

    public function testDeletingTheUserDeletesThePhoto(): void
    {
        $other = $this->makeCrmUser();
        $path  = $this->givePhoto($other, 1);

        $this->photos->register();
        do_action('delete_user', $other);
        remove_action('delete_user', [$this->photos, 'remove']);

        self::assertFileDoesNotExist($path);
    }

    public function testAFileOutsideTheFolderIsNeverServedOrDeleted(): void
    {
        $outside = wp_tempnam('ecrm-photo-outside');
        $this->photos->remember($this->user, $outside, 1);

        self::assertNull($this->photos->path($this->user));

        $this->photos->remove($this->user);

        self::assertFileExists($outside);
        wp_delete_file($outside);
    }

    public function testUploadWithoutAFileIsRefused(): void
    {
        $response = rest_do_request(new WP_REST_Request('POST', '/ecrm/v1/me/photo'));

        self::assertSame(400, $response->get_status());
        self::assertFalse($response->get_data()['ok']);
    }

    public function testThePhotoInPdfChoiceIsSaved(): void
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/me/profile');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode(['photo_in_pdf' => true]));

        $data = rest_do_request($request)->get_data();

        self::assertTrue($data['profile']['photo_in_pdf']);
        self::assertSame('', $data['profile']['photo_url']);
    }

    private function givePhoto(int $userId, int $version): string
    {
        $path = $this->storage->newPath('jpg');
        file_put_contents($path, "\xFF\xD8\xFF\xE0 δοκιμή");
        $this->photos->remember($userId, $path, $version);

        return $path;
    }
}
