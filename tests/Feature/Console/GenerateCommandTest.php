<?php

declare(strict_types=1);

$customerSpec = fn (): string => __DIR__.'/../../Fixtures/emitter/customer.json';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_cmd_'.uniqid();

it('generates classes from the configured spec', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $customerSpec());
    config()->set('openapi-laravel.output.path', $out);

    $this->artisan('openapi:generate')->assertSuccessful();

    expect(is_file($out.'/CustomerData.php'))->toBeTrue()
        ->and(is_file($out.'/CustomerStatus.php'))->toBeTrue()
        ->and(is_file($out.'/TagData.php'))->toBeTrue()
        ->and(is_file($out.'/CustomerAddressData.php'))->toBeTrue();
});

it('honours the --spec and --output options', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();

    $this->artisan('openapi:generate', [
        '--spec' => $customerSpec(),
        '--output' => $out,
    ])->assertSuccessful();

    expect(is_file($out.'/CustomerData.php'))->toBeTrue();
});

it('fails clearly when the spec cannot be read', function () use ($tempOut) {
    config()->set('openapi-laravel.spec', '/no/such/spec.json');
    config()->set('openapi-laravel.output.path', $tempOut());

    $this->artisan('openapi:generate')->assertFailed();
});

it('prunes stale files when asked', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();
    mkdir($out, 0755, true);
    file_put_contents($out.'/StaleData.php', '<?php // stale');

    $this->artisan('openapi:generate', [
        '--spec' => $customerSpec(),
        '--output' => $out,
        '--prune' => true,
    ])->assertSuccessful();

    expect(is_file($out.'/StaleData.php'))->toBeFalse()
        ->and(is_file($out.'/CustomerData.php'))->toBeTrue();
});
