<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Http\Controllers\Api\InMemoryStore;
use Illuminate\Support\Facades\Route;

/**
 * End to end demo test: boot a Testbench app, load the GENERATED routes file,
 * resolve the HAND-WRITTEN concrete controllers, and drive the full HTTP chain.
 * This proves the generated models (typed params + spec rules()) and generated
 * routing wire up into a working Laravel API with only the business logic
 * written by hand.
 */
beforeEach(function () {
    // One shared in-memory store for the whole request lifecycle, so seeding in
    // a test is visible to the controller that handles the request.
    $this->store = new InMemoryStore;
    $this->app->instance(InMemoryStore::class, $this->store);

    // Register the generated route table exactly as a host app would: inside a
    // route group. The generated file references the concrete controllers.
    Route::middleware('api')->group(function () {
        require __DIR__.'/../../../examples/petstore/routes/api.generated.php';
    });
});

function validPetPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Rex',
        'photoUrls' => ['https://example.com/rex.png'],
        'status' => 'available',
        'tags' => [['id' => 1, 'name' => 'good-boy']],
    ], $overrides);
}

it('creates a pet from a valid body and returns the typed pet', function () {
    $response = $this->postJson('/pet', validPetPayload());

    $response->assertSuccessful()
        ->assertJsonPath('name', 'Rex')
        ->assertJsonPath('status', 'available')
        ->assertJsonPath('photoUrls.0', 'https://example.com/rex.png')
        ->assertJsonPath('tags.0.name', 'good-boy');

    // The store assigned an id, proving PetData hydrated and round-tripped.
    expect($response->json('id'))->toBeInt();
});

it('rejects an invalid pet body with 422 from the spec-derived rules', function () {
    // Missing the required name and photoUrls: no hand-written validation here,
    // the 422 comes entirely from the generated PetData::rules().
    $response = $this->postJson('/pet', ['status' => 'available']);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'photoUrls']);
});

it('fetches a seeded pet by id and 404s an unknown id', function () {
    $created = $this->postJson('/pet', validPetPayload())->json();
    $id = $created['id'];

    $this->getJson("/pet/{$id}")
        ->assertSuccessful()
        ->assertJsonPath('id', $id)
        ->assertJsonPath('name', 'Rex');

    $this->getJson('/pet/999999')->assertNotFound();
});

it('finds pets by status and returns a collection', function () {
    $this->postJson('/pet', validPetPayload(['name' => 'Available1', 'status' => 'available']));
    $this->postJson('/pet', validPetPayload(['name' => 'Sold1', 'status' => 'sold']));

    $response = $this->getJson('/pet/findByStatus?status=available');

    $response->assertSuccessful();
    $names = array_column($response->json(), 'name');

    expect($response->json())->toBeArray()
        ->and($names)->toContain('Available1')
        ->and($names)->not->toContain('Sold1');
});

it('updates a pet with form data', function () {
    $id = $this->postJson('/pet', validPetPayload(['status' => 'available']))->json('id');

    // The spec declares name/status as query parameters (issue #63), so the
    // generated UpdatePetWithFormQueryData hydrates them from the query string.
    $this->post("/pet/{$id}?name=Renamed&status=sold")
        ->assertSuccessful()
        ->assertJsonPath('name', 'Renamed')
        ->assertJsonPath('status', 'sold');
});

it('rejects an out-of-enum status query parameter with 422 from the generated query rules', function () {
    $this->postJson('/pet', validPetPayload());

    // No hand-written validation: the 422 comes entirely from the generated
    // FindPetsByStatusQueryData::rules() on container injection (issue #63).
    $this->getJson('/pet/findByStatus?status=shiny')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('fills the spec default when the status query parameter is omitted', function () {
    $this->postJson('/pet', validPetPayload(['name' => 'DefaultPet', 'status' => 'available']));
    $this->postJson('/pet', validPetPayload(['name' => 'SoldPet', 'status' => 'sold']));

    // status defaults to 'available' in the spec, so the bare call filters.
    $response = $this->getJson('/pet/findByStatus');

    $response->assertSuccessful();
    $names = array_column($response->json(), 'name');

    expect($names)->toContain('DefaultPet')
        ->and($names)->not->toContain('SoldPet');
});

it('uploads an image for a pet', function () {
    $id = $this->postJson('/pet', validPetPayload())->json('id');

    $this->call('POST', "/pet/{$id}/uploadImage", [], [], [], [], 'binarydata')
        ->assertSuccessful()
        ->assertJsonPath('code', 200)
        ->assertJsonPath('type', 'success');
});

it('exercises the store controller: place, fetch, and inventory', function () {
    $order = $this->postJson('/store/order', [
        'petId' => 3,
        'quantity' => 2,
        'status' => 'placed',
        'complete' => false,
    ]);

    $order->assertSuccessful()
        ->assertJsonPath('petId', 3)
        ->assertJsonPath('quantity', 2);

    $id = $order->json('id');

    $this->getJson("/store/order/{$id}")
        ->assertSuccessful()
        ->assertJsonPath('id', $id);

    $this->postJson('/pet', validPetPayload(['status' => 'available']));
    $this->getJson('/store/inventory')
        ->assertSuccessful()
        ->assertJsonPath('available', 1);
});

it('exercises the user controller: create and fetch by name', function () {
    $this->postJson('/user', [
        'id' => 10,
        'username' => 'theUser',
        'firstName' => 'John',
        'email' => 'john@example.com',
    ])->assertSuccessful()->assertJsonPath('username', 'theUser');

    $this->getJson('/user/theUser')
        ->assertSuccessful()
        ->assertJsonPath('firstName', 'John')
        ->assertJsonPath('email', 'john@example.com');

    $this->getJson('/user/nobody')->assertNotFound();
});
