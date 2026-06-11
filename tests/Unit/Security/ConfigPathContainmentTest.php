<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\OptionException;
use CodeWithAgents\OpenApiLaravel\Console\PathContainment;
use CodeWithAgents\OpenApiLaravel\Console\StandaloneConfigLoader;

/**
 * Issue #54. A discovered `openapi-laravel.json` is cwd-sourced, not typed by
 * the operator, so its write paths are untrusted: a hostile config committed to
 * a cloned repository could redirect generated-file writes outside the project
 * the moment a developer runs the binary in that directory. PathContainment
 * pins every config-sourced write path inside the directory the config lives in.
 * These tests prove rejection of `..` traversal, an absolute escape, and a
 * symlinked-parent escape, plus acceptance of legitimate in-root relative and
 * nested paths, and that the guard fires before any file is written.
 */

/**
 * Creates a fresh, realpath-stable root directory under the temp dir.
 */
function containmentRoot(): string
{
    $root = realpath(sys_get_temp_dir()).'/oal_contain_'.uniqid();
    mkdir($root, 0755, true);

    return $root;
}

// --- The guard in isolation ----------------------------------------------

it('accepts a plain in-root relative path', function () {
    $root = containmentRoot();

    expect((new PathContainment($root))->contain('app/Data', 'output.path', $root.'/openapi-laravel.json'))
        ->toBe('app/Data');
});

it('accepts a deeply nested in-root relative path', function () {
    $root = containmentRoot();

    expect((new PathContainment($root))->contain('a/b/c/d/Data', 'output.path', $root.'/openapi-laravel.json'))
        ->toBe('a/b/c/d/Data');
});

it('accepts the root itself', function () {
    $root = containmentRoot();

    expect((new PathContainment($root))->contain('.', 'output.path', $root.'/openapi-laravel.json'))
        ->toBe('.');
});

it('accepts an absolute path that points back inside the root', function () {
    $root = containmentRoot();

    expect((new PathContainment($root))->contain($root.'/app/Data', 'output.path', $root.'/openapi-laravel.json'))
        ->toBe($root.'/app/Data');
});

it('accepts an in-root path that uses .. but stays contained', function () {
    $root = containmentRoot();

    // a/../app normalizes to app, still inside the root.
    expect((new PathContainment($root))->contain('a/../app/Data', 'output.path', $root.'/openapi-laravel.json'))
        ->toBe('a/../app/Data');
});

it('passes a null or empty path through untouched', function () {
    $root = containmentRoot();
    $guard = new PathContainment($root);

    expect($guard->contain(null, 'output.path', $root.'/openapi-laravel.json'))->toBeNull()
        ->and($guard->contain('', 'output.path', $root.'/openapi-laravel.json'))->toBe('');
});

it('rejects a single .. escape above the root', function () {
    $root = containmentRoot();

    (new PathContainment($root))->contain('../escaped', 'output.path', $root.'/openapi-laravel.json');
})->throws(OptionException::class, 'outside the project directory');

it('rejects a deep .. traversal that climbs past the root', function () {
    $root = containmentRoot();

    (new PathContainment($root))->contain('app/../../../../etc/cron.d', 'controllers.path', $root.'/openapi-laravel.json');
})->throws(OptionException::class, 'outside the project directory');

it('rejects an absolute path outside the root', function () {
    $root = containmentRoot();

    (new PathContainment($root))->contain('/etc/openapi-evil', 'routes.path', $root.'/openapi-laravel.json');
})->throws(OptionException::class, 'outside the project directory');

it('rejects a sibling directory reached via ..', function () {
    $root = containmentRoot();
    $siblingName = basename($root).'_sibling';

    (new PathContainment($root))->contain('../'.$siblingName.'/Data', 'output.path', $root.'/openapi-laravel.json');
})->throws(OptionException::class, 'outside the project directory');

it('names the offending path and key in the rejection message', function () {
    $root = containmentRoot();

    try {
        (new PathContainment($root))->contain('../../escape', 'controllers.path', $root.'/openapi-laravel.json');
        $this->fail('expected an OptionException');
    } catch (OptionException $e) {
        expect($e->getMessage())
            ->toContain('controllers.path')
            ->toContain('../../escape')
            ->toContain($root);
    }
});

it('rejects a symlinked parent whose target escapes the root', function () {
    if (! function_exists('symlink')) {
        $this->markTestSkipped('symlink() not available on this platform.');
    }

    $root = containmentRoot();
    // An out-of-root directory the symlink will point at.
    $outside = containmentRoot();

    // root/link -> outside. A config path of "link/Data" lexically looks
    // in-root, but the symlinked parent resolves outside.
    $made = @symlink($outside, $root.'/link');
    if ($made === false) {
        $this->markTestSkipped('symlink creation not permitted on this filesystem.');
    }

    (new PathContainment($root))->contain('link/Data', 'output.path', $root.'/openapi-laravel.json');
})->throws(OptionException::class, 'outside the project directory');

it('accepts a symlinked parent whose target stays inside the root', function () {
    if (! function_exists('symlink')) {
        $this->markTestSkipped('symlink() not available on this platform.');
    }

    $root = containmentRoot();
    mkdir($root.'/real', 0755, true);

    $made = @symlink($root.'/real', $root.'/link');
    if ($made === false) {
        $this->markTestSkipped('symlink creation not permitted on this filesystem.');
    }

    expect((new PathContainment($root))->contain('link/Data', 'output.path', $root.'/openapi-laravel.json'))
        ->toBe('link/Data');
});

it('anchors a relative path under the root, not the filesystem root', function () {
    $root = containmentRoot();
    // "child" is created so the existing-prefix walk descends into it before the
    // non-existent tail. This pins that the relative value is resolved against
    // the root and that a contained nested target is accepted.
    mkdir($root.'/child', 0755, true);

    expect((new PathContainment($root))->contain('child/grand/Data', 'output.path', $root.'/openapi-laravel.json'))
        ->toBe('child/grand/Data');
});

it('rejects a relative path that only escapes because it is anchored under the root', function () {
    $root = containmentRoot();

    // From the root, climbing four levels lands above any temp root.
    (new PathContainment($root))->contain('deep/../../../../../../etc', 'output.path', $root.'/openapi-laravel.json');
})->throws(OptionException::class, 'outside the project directory');

it('resolves a chain of symlinked parents and rejects the one that escapes', function () {
    if (! function_exists('symlink')) {
        $this->markTestSkipped('symlink() not available on this platform.');
    }

    $root = containmentRoot();
    $outside = containmentRoot();

    // root/a is a real dir, root/a/b -> outside. A path of a/b/Data walks a
    // (real), then b (symlink whose target escapes).
    mkdir($root.'/a', 0755, true);
    $made = @symlink($outside, $root.'/a/b');
    if ($made === false) {
        $this->markTestSkipped('symlink creation not permitted on this filesystem.');
    }

    (new PathContainment($root))->contain('a/b/Data', 'output.path', $root.'/openapi-laravel.json');
})->throws(OptionException::class, 'outside the project directory');

it('rejects a broken symlink in the path, failing closed', function () {
    if (! function_exists('symlink')) {
        $this->markTestSkipped('symlink() not available on this platform.');
    }

    $root = containmentRoot();

    // A dangling symlink: the target does not exist, so realpath() yields false.
    // The guard must reject rather than silently accept.
    $made = @symlink($root.'/no_such_target_'.uniqid(), $root.'/dangling');
    if ($made === false) {
        $this->markTestSkipped('symlink creation not permitted on this filesystem.');
    }

    (new PathContainment($root))->contain('dangling/Data', 'output.path', $root.'/openapi-laravel.json');
})->throws(OptionException::class, 'outside the project directory');

it('accepts a multi-segment not-yet-created in-root tail', function () {
    $root = containmentRoot();

    // None of these segments exist; the whole tail is appended verbatim and
    // must stay contained.
    expect((new PathContainment($root))->contain('does/not/exist/yet/Data', 'output.path', $root.'/openapi-laravel.json'))
        ->toBe('does/not/exist/yet/Data');
});

it('anchors a relative root against the working directory', function () {
    // A relative root is canonicalized against getcwd(). We chdir into a real
    // directory, then pass its basename as the (relative) root. An in-root path
    // is accepted, an escaping one is rejected, proving the root resolved to the
    // real working-directory child and not to "/".
    $parent = containmentRoot();
    $rootName = 'reldir';
    mkdir($parent.'/'.$rootName, 0755, true);

    $previous = getcwd();
    chdir($parent);

    try {
        $guard = new PathContainment($rootName);

        expect($guard->contain('Data', 'output.path', $parent.'/'.$rootName.'/openapi-laravel.json'))->toBe('Data');

        $threw = false;
        try {
            $guard->contain('../../escape', 'output.path', $parent.'/'.$rootName.'/openapi-laravel.json');
        } catch (OptionException) {
            $threw = true;
        }
        expect($threw)->toBeTrue();
    } finally {
        chdir((string) $previous);
    }
});

it('falls back to lexical normalization for a non-existent root and still contains', function () {
    // A root that does not exist cannot be realpath-resolved; the guard
    // normalizes it lexically rather than crashing, and containment still holds.
    $root = sys_get_temp_dir().'/oal_contain_absent_'.uniqid().'/sub';
    $guard = new PathContainment($root);

    expect($guard->contain('Data', 'output.path', $root.'/openapi-laravel.json'))->toBe('Data');

    $threw = false;
    try {
        $guard->contain('../../../escape', 'output.path', $root.'/openapi-laravel.json');
    } catch (OptionException) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
});

// --- The guard wired through the config loader ---------------------------

/**
 * Writes a config file into a fresh root and returns [configPath, root].
 *
 * @param  array<string, mixed>  $config
 * @return array{0: string, 1: string}
 */
function writeContainmentConfig(array $config): array
{
    $root = containmentRoot();
    $path = $root.'/openapi-laravel.json';
    file_put_contents($path, (string) json_encode($config));

    return [$path, $root];
}

it('rejects a config whose output.path escapes via ..', function () {
    [$path] = writeContainmentConfig(['output' => ['path' => '../../escaped/Data']]);

    (new StandaloneConfigLoader)->load($path, dirname($path));
})->throws(OptionException::class, 'outside the project directory');

it('rejects a config whose controllers.path is an absolute escape', function () {
    [$path] = writeContainmentConfig(['controllers' => ['path' => '/tmp/evil-controllers']]);

    (new StandaloneConfigLoader)->load($path, dirname($path));
})->throws(OptionException::class, 'outside the project directory');

it('rejects a config whose routes.path escapes via ..', function () {
    [$path] = writeContainmentConfig(['routes' => ['path' => '../../../etc/api.generated.php']]);

    (new StandaloneConfigLoader)->load($path, dirname($path));
})->throws(OptionException::class, 'outside the project directory');

it('keeps legitimate in-root config paths working unchanged', function () {
    [$path] = writeContainmentConfig([
        'output' => ['path' => 'app/Data'],
        'controllers' => ['path' => 'app/Http/Controllers/Api'],
        'routes' => ['path' => 'routes/api.generated.php'],
    ]);

    $config = (new StandaloneConfigLoader)->load($path, dirname($path));

    expect($config->outputPath)->toBe('app/Data')
        ->and($config->controllerPath)->toBe('app/Http/Controllers/Api')
        ->and($config->routesPath)->toBe('routes/api.generated.php');
});

it('fails the load before any directory is created for the escaping target', function () {
    [$path, $root] = writeContainmentConfig(['output' => ['path' => '../oal_should_not_exist_'.uniqid()]]);

    $escapeTarget = dirname($root).'/'.basename(json_decode((string) file_get_contents($path), true)['output']['path']);

    try {
        (new StandaloneConfigLoader)->load($path, dirname($path));
        $this->fail('expected an OptionException');
    } catch (OptionException) {
        // The guard runs during load(), well before the writer. Nothing on the
        // escape path may have been created.
        expect(file_exists($escapeTarget))->toBeFalse();
    }
});
