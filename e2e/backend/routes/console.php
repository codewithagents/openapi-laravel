<?php

use App\Support\PetStore;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reset the file-backed demo store to its deterministic seed. Handy before a
// browser E2E run so every run starts from a known state.
Artisan::command('petstore:reset', function (PetStore $store) {
    $store->reset();
    $this->info('Pet store reset to seed (Rex #1, Whiskers #2).');
})->purpose('Reset the file-backed demo store to its seed state');
