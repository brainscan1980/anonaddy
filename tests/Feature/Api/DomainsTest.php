<?php

namespace Tests\Feature\Api;

use App\Domain;
use App\Recipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        parent::setUpPassport();
    }

    /** @test */
    public function user_can_get_all_domains()
    {
        // Arrange
        factory(Domain::class, 3)->create([
            'user_id' => $this->user->id
        ]);

        // Act
        $response = $this->get('/api/v1/domains');

        // Assert
        $response->assertSuccessful();
        $this->assertCount(3, $response->json()['data']);
    }

    /** @test */
    public function user_can_get_individual_domain()
    {
        // Arrange
        $domain = factory(Domain::class)->create([
            'user_id' => $this->user->id
        ]);

        // Act
        $response = $this->get('/api/v1/domains/'.$domain->id);

        // Assert
        $response->assertSuccessful();
        $this->assertCount(1, $response->json());
        $this->assertEquals($domain->domain, $response->json()['data']['domain']);
    }

    /** @test */
    public function user_can_create_new_domain()
    {
        $response = $this->json('POST', '/api/v1/domains', [
            'domain' => 'example.com'
        ]);

        $response->assertStatus(201);
        $this->assertEquals('example.com', $response->getData()->data->domain);
    }

    /** @test */
    public function user_can_not_create_the_same_domain()
    {
        factory(Domain::class)->create([
            'user_id' => $this->user->id,
            'domain' => 'example.com'
        ]);

        $response = $this->json('POST', '/api/v1/domains', [
            'domain' => 'example.com'
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');
    }

    /** @test */
    public function new_domain_must_be_a_valid_fqdn()
    {
        $response = $this->json('POST', '/api/v1/domains', [
            'domain' => 'example.'
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');
    }

    /** @test */
    public function new_domain_must_not_include_protocol()
    {
        $response = $this->json('POST', '/api/v1/domains', [
            'domain' => 'https://example.com'
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');
    }

    /** @test */
    public function new_domain_must_not_be_local()
    {
        $response = $this->json('POST', '/api/v1/domains', [
            'domain' => config('anonaddy.domain')
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');
    }

    /** @test */
    public function new_domain_must_not_be_local_subdomain()
    {
        $response = $this->json('POST', '/api/v1/domains', [
            'domain' => 'subdomain'.config('anonaddy.domain')
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');
    }

    /** @test */
    public function user_can_activate_domain()
    {
        $domain = factory(Domain::class)->create([
            'user_id' => $this->user->id,
            'active' => false
        ]);

        $response = $this->json('POST', '/api/v1/active-domains/', [
            'id' => $domain->id
        ]);

        $response->assertStatus(200);
        $this->assertEquals(true, $response->getData()->data->active);
    }

    /** @test */
    public function user_can_deactivate_domain()
    {
        $domain = factory(Domain::class)->create([
            'user_id' => $this->user->id,
            'active' => true
        ]);

        $response = $this->json('DELETE', '/api/v1/active-domains/'.$domain->id);

        $response->assertStatus(204);
        $this->assertFalse($this->user->domains[0]->active);
    }

    /** @test */
    public function user_can_update_domain_description()
    {
        $domain = factory(Domain::class)->create([
            'user_id' => $this->user->id
        ]);

        $response = $this->json('PATCH', '/api/v1/domains/'.$domain->id, [
            'description' => 'The new description'
        ]);

        $response->assertStatus(200);
        $this->assertEquals('The new description', $response->getData()->data->description);
    }

    /** @test */
    public function user_can_delete_domain()
    {
        $domain = factory(Domain::class)->create([
            'user_id' => $this->user->id
        ]);

        $response = $this->json('DELETE', '/api/v1/domains/'.$domain->id);

        $response->assertStatus(204);
        $this->assertEmpty($this->user->domains);
    }

    /** @test */
    public function user_can_update_domain_default_recipient()
    {
        $domain = factory(Domain::class)->create([
            'user_id' => $this->user->id,
            'domain_verified_at' => now()
        ]);

        $newDefaultRecipient = factory(Recipient::class)->create([
            'user_id' => $this->user->id
        ]);

        $response = $this->json('PATCH', '/api/v1/domains/'.$domain->id.'/default-recipient', [
            'default_recipient' => $newDefaultRecipient->id
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'default_recipient_id' => $newDefaultRecipient->id
        ]);

        $this->assertEquals($newDefaultRecipient->email, $domain->refresh()->defaultRecipient->email);
    }

    /** @test */
    public function user_cannot_update_domain_default_recipient_with_unverified_recipient()
    {
        $domain = factory(Domain::class)->create([
            'user_id' => $this->user->id,
            'domain_verified_at' => now()
        ]);

        $newDefaultRecipient = factory(Recipient::class)->create([
            'user_id' => $this->user->id,
            'email_verified_at' => null
        ]);

        $response = $this->json('PATCH', '/api/v1/domains/'.$domain->id.'/default-recipient', [
            'default_recipient' => $newDefaultRecipient->id
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('domains', [
            'id' => $domain->id,
            'default_recipient_id' => $newDefaultRecipient->id
        ]);
    }

    /** @test */
    public function user_can_remove_domain_default_recipient()
    {
        $defaultRecipient = factory(Recipient::class)->create([
            'user_id' => $this->user->id
        ]);

        $domain = factory(Domain::class)->create([
            'user_id' => $this->user->id,
            'default_recipient_id' => $defaultRecipient->id,
            'domain_verified_at' => now(),
        ]);

        $response = $this->json('PATCH', '/api/v1/domains/'.$domain->id.'/default-recipient', [
            'default_recipient' => ''
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'default_recipient_id' => null
        ]);

        $this->assertNull($domain->refresh()->defaultRecipient);
    }
}