<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Tests\Feature\Example\Writable\Http\WidgetsController;
use Illuminate\Support\Facades\Route;

/**
 * End to end proof for the READ/WRITE-SPLIT variant over real HTTP.
 *
 * The generated AbstractWidgetsController types the createWidget body param as
 * WidgetWritableData (writeOnly secret in, readOnly id dropped) and returns
 * WidgetData (readOnly id out, writeOnly secret dropped). This test boots a
 * Testbench app, loads the GENERATED routes, and drives the chain to prove:
 *   - a valid write body hydrates and returns the read variant (id present,
 *     secret absent),
 *   - a missing required write field is rejected with 422 by the generated
 *     WidgetWritableData::rules(), through the injected writable body param,
 *   - the read endpoint returns the read variant.
 *
 * This closes the B1 follow-up: the petstore demo only proved rules() over HTTP
 * for a non-split schema; this proves it for the writable variant specifically.
 */
beforeEach(function () {
    // The concrete controller keeps a tiny static in-memory store; reset it so
    // each test starts clean and ids are deterministic.
    WidgetsController::reset();

    // Register the generated route table exactly as a host app would.
    Route::middleware('api')->group(function () {
        require __DIR__.'/Writable/routes/api.generated.php';
    });
});

it('creates a widget from a valid write body and returns the read variant', function () {
    $response = $this->postJson('/widgets', ['name' => 'ok', 'secret' => 's']);

    // The spec declares 201 for createWidget; the generated route enforces it
    // via the inlined RespondsWithStatus middleware (issue #64).
    $response->assertCreated()
        ->assertJsonPath('name', 'ok');

    // Read variant: the store-assigned readOnly id is present...
    expect($response->json('id'))->toBeInt();

    // ...and the writeOnly secret never appears in the read output.
    expect($response->json())->not->toHaveKey('secret');
});

it('rejects a write body missing the required name with 422 from the writable rules', function () {
    // Only the writable variant has these required-write rules. The 422 here is
    // produced by WidgetWritableData::rules() when laravel-data hydrates the
    // injected WidgetWritableData param, not by any hand-written validation.
    $response = $this->postJson('/widgets', ['secret' => 's']);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('rejects a write body missing the required writeOnly secret with 422', function () {
    // secret is writeOnly + required: it lives only on the writable variant, so
    // this 422 can only come from WidgetWritableData::rules().
    $response = $this->postJson('/widgets', ['name' => 'ok']);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['secret']);
});

it('rejects a write body missing both required write fields with 422', function () {
    $response = $this->postJson('/widgets', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'secret']);
});

it('fetches a seeded widget as the read variant and 404s an unknown id', function () {
    $id = $this->postJson('/widgets', ['name' => 'ok', 'secret' => 's'])->json('id');

    $read = $this->getJson("/widgets/{$id}");
    $read->assertSuccessful()
        ->assertJsonPath('id', $id)
        ->assertJsonPath('name', 'ok');

    // Read variant must never leak the writeOnly secret.
    expect($read->json())->not->toHaveKey('secret');

    $this->getJson('/widgets/999999')->assertNotFound();
});
