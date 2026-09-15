<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response(): void
    {
        // An instance with no users at all redirects every route to the
        // first-run setup screen; create one so this exercises a "normal"
        // visit to the home page instead.
        User::factory()->create();

        $response = $this->get(route('home'));

        $response->assertOk();
    }
}
