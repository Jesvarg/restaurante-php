<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_main_public_pages(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $restaurant = Restaurant::create([
            'name' => 'Restaurante Demo',
            'description' => 'Descripcion de prueba',
            'address' => 'Calle Falsa 123',
            'price_range' => 2,
            'status' => 'active',
            'user_id' => $owner->id,
        ]);

        $this->get(route('restaurants.index'))
            ->assertOk();

        $this->get(route('restaurants.show', $restaurant))
            ->assertOk()
            ->assertSee('Restaurante Demo');

        $this->get(route('login'))
            ->assertOk();

        $this->get(route('register'))
            ->assertOk();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }
}
