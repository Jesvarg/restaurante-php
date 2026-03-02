<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoritesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_toggle_favorites(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $user = User::factory()->create(['role' => 'user']);

        $restaurant = Restaurant::create([
            'name' => 'Restaurante Favorito',
            'description' => 'Descripcion',
            'address' => 'Avenida Principal 456',
            'price_range' => 3,
            'status' => 'active',
            'user_id' => $owner->id,
        ]);

        $this->actingAs($user)
            ->post(route('restaurants.toggleFavorite', $restaurant))
            ->assertRedirect();

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->actingAs($user)
            ->post(route('restaurants.toggleFavorite', $restaurant))
            ->assertRedirect();

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
        ]);
    }
}
