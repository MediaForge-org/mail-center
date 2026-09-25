<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->withSession(['_token' => str_repeat('a', 40)])
        ->withHeader('X-CSRF-TOKEN', str_repeat('a', 40));
});

it('boots with PostgreSQL and both Redis services ready', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
    expect((int) DB::select('SELECT 1 AS value')[0]->value)->toBe(1);
    expect(Redis::connection('default')->ping())->toBeTruthy();
    expect(Redis::connection('cache')->ping())->toBeTruthy();

    $this->getJson('/ready')->assertOk()->assertJson(['status' => 'ready']);
});

it('requires authentication for the workspace and current-user API', function () {
    $this->get('/mail')->assertRedirect('/login');
    $this->getJson('/api/me')->assertUnauthorized();
    $this->get('/login')->assertOk()->assertSee('id="app"', false);
    $this->postJson('/register', [])->assertNotFound();
});

it('logs in, scopes the current-user response, and logs out', function () {
    $alice = User::factory()->create(['email' => 'alice@example.test']);
    $bob = User::factory()->create(['email' => 'bob@example.test']);

    $this->postJson('/login', ['email' => $alice->email, 'password' => 'password'])
        ->assertOk();
    $this->getJson('/api/me')->assertOk()->assertJson([
        'id' => $alice->id,
        'email' => $alice->email,
    ])->assertDontSee($bob->email);
    $this->get('/mail')->assertOk()->assertSee('id="app"', false);

    $this->withHeader('X-CSRF-TOKEN', session()->token());
    $this->postJson('/logout')->assertNoContent();
    Auth::forgetGuards(); // The HTTP test process reuses guards between simulated requests.
    $this->getJson('/api/me')->assertUnauthorized();
});

it('rejects incorrect credentials', function () {
    User::factory()->create(['email' => 'person@example.test']);

    $this->postJson('/login', ['email' => 'person@example.test', 'password' => 'wrong'])
        ->assertUnprocessable();
    $this->getJson('/api/me')->assertUnauthorized();
});
