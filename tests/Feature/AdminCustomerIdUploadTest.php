<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Valid ID photo upload on admin customer registration: the image is
 * validated, stored through the shared document service, linked to the new
 * customer record, and surfaced in the verification modal — or the
 * registration fails cleanly with a clear message.
 */
class AdminCustomerIdUploadTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    /** Minimal 1x1 transparent PNG (no GD extension required). */
    private const TINY_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function realPngUpload(string $name = 'valid-id.png', int $padBytes = 0): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'idtest') . '.png';
        file_put_contents(
            $path,
            base64_decode(self::TINY_PNG_BASE64) . str_repeat("\0", $padBytes)
        );

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Id Upload Customer',
            'email' => 'id-upload-' . uniqid() . '@pedalya.test',
            'phoneNumber' => '09171234567',
            'address' => 'Azuela Cove, Davao City',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    public function test_registration_with_valid_id_image_links_it_to_the_customer(): void
    {
        Storage::fake('public');
        $admin = $this->makeAdmin();

        $response = $this->from(route('admin.riders.create'))
            ->actingAs($admin)
            ->post(route('admin.riders.store'), array_merge(
                $this->validPayload(),
                ['id_image' => $this->realPngUpload()]
            ));

        $response->assertRedirect(route('admin.riders.index'));
        $response->assertSessionHasNoErrors();

        $rider = User::where('role', 'rider')->latest('id')->first();
        $this->assertNotNull($rider);
        $this->assertTrue((bool) $rider->idUploaded);
        $this->assertSame('pending', $rider->idVerification['status'] ?? null);
        $this->assertNotEmpty($rider->idVerification['id_path'] ?? null);
        $this->assertNotEmpty($rider->idVerification['id_url'] ?? null);
        $this->assertNotEmpty($rider->idVerification['submitted_at'] ?? null);
        Storage::disk('public')->assertExists($rider->idVerification['id_path']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'rider_created']);
        $log = AuditLog::where('action', 'rider_created')->latest('id')->first();
        $this->assertTrue($log->details['idUploaded']);
    }

    public function test_registration_without_id_image_still_succeeds(): void
    {
        Storage::fake('public');
        $admin = $this->makeAdmin();

        $this->from(route('admin.riders.create'))
            ->actingAs($admin)
            ->post(route('admin.riders.store'), $this->validPayload())
            ->assertRedirect(route('admin.riders.index'))
            ->assertSessionHasNoErrors();

        $rider = User::where('role', 'rider')->latest('id')->first();
        $this->assertFalse((bool) $rider->idUploaded);
        $this->assertNull($rider->idVerification);
    }

    public function test_registration_rejects_non_image_files_with_clear_message(): void
    {
        Storage::fake('public');
        $admin = $this->makeAdmin();

        $this->from(route('admin.riders.create'))
            ->actingAs($admin)
            ->post(route('admin.riders.store'), array_merge(
                $this->validPayload(),
                ['id_image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')]
            ))
            ->assertSessionHasErrors('id_image');

        $this->assertSame(0, User::where('role', 'rider')->count());
        $this->assertSame(0, AuditLog::where('action', 'rider_created')->count());
    }

    public function test_registration_rejects_oversized_images(): void
    {
        Storage::fake('public');
        $admin = $this->makeAdmin();

        $this->from(route('admin.riders.create'))
            ->actingAs($admin)
            ->post(route('admin.riders.store'), array_merge(
                $this->validPayload(),
                ['id_image' => $this->realPngUpload('huge-id.png', 6 * 1024 * 1024)]
            ))
            ->assertSessionHasErrors('id_image');

        $this->assertSame(0, User::where('role', 'rider')->count());
    }

    public function test_uploaded_id_appears_in_customer_verification_modal(): void
    {
        Storage::fake('public');
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => false]);

        $path = 'id-verifications/modal-id.png';
        Storage::disk('public')->put($path, base64_decode(self::TINY_PNG_BASE64));
        $rider->update([
            'idVerification' => [
                'id_path' => $path,
                'id_url' => '/storage/' . $path,
                'status' => 'pending',
                'submitted_at' => now()->toIso8601String(),
            ],
            'idUploaded' => true,
        ]);

        $html = $this->actingAs($admin)->get(route('admin.riders.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-id-url=', $html);
        $this->assertStringContainsString($path, $html);
    }

    public function test_two_uploads_never_share_a_path(): void
    {
        Storage::fake('public');
        $admin = $this->makeAdmin();

        $paths = [];
        foreach (['first-id@pedalya.test', 'second-id@pedalya.test'] as $email) {
            $this->actingAs($admin)->post(route('admin.riders.store'), array_merge(
                $this->validPayload(['email' => $email]),
                ['id_image' => $this->realPngUpload()]
            ))->assertRedirect(route('admin.riders.index'));

            $paths[] = User::where('email', $email)->firstOrFail()->idVerification['id_path'];
        }

        $this->assertNotSame($paths[0], $paths[1]);
        Storage::disk('public')->assertExists($paths[0]);
        Storage::disk('public')->assertExists($paths[1]);
    }

    public function test_register_form_supports_file_uploads(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->actingAs($admin)->get(route('admin.riders.create'))->assertOk()->getContent();

        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="id_image"', $html);
        $this->assertStringContainsString('Valid ID Photo', $html);
    }
}
