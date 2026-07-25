<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('connecte un utilisateur via l endpoint login (résolution via connexion système)', function (): void {
    $ids = seedCore();
    DB::table('users')->where('id', $ids['user_admin'])->update(['password_hash' => Hash::make('Str0ng!Passw0rd')]);

    $this->postJson('/api/v1/auth/login', ['email' => 'admin@test.local', 'password' => 'Str0ng!Passw0rd'])
        ->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'email']]);
});

it('refuse la connexion et ne sélectionne jamais un compte arbitraire si l email existe sur 2 tenants', function (): void {
    $ids = seedCore();

    // Second tenant avec le MÊME email admin actif
    $t2 = (string) Str::uuid7();
    DB::table('tenants')->insert(['id' => $t2, 'name' => 'T2', 'slug' => 't2-'.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('users')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $t2, 'email' => 'admin@test.local',
        'password_hash' => Hash::make('Str0ng!Passw0rd'), 'first_name' => 'A', 'last_name' => 'B',
        'password_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Sans désambiguïsation → refus explicite, jamais de sélection arbitraire
    $this->postJson('/api/v1/auth/login', ['email' => 'admin@test.local', 'password' => 'Str0ng!Passw0rd'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'auth.ambiguous_account');

    // Avec le slug du tenant 1 (en-tête) → connexion OK, bon tenant
    DB::table('users')->where('id', $ids['user_admin'])->update(['password_hash' => Hash::make('Str0ng!Passw0rd')]);
    $slug = DB::table('tenants')->where('id', $ids['tenant'])->value('slug');
    $this->withHeader('X-Tenant-Slug', $slug)
        ->postJson('/api/v1/auth/login', ['email' => 'admin@test.local', 'password' => 'Str0ng!Passw0rd'])
        ->assertOk()
        ->assertJsonPath('user.id', $ids['user_admin']);
});
