# Changelog

## [0.3.0](https://github.com/codewithagents/openapi-laravel/compare/v0.2.0...v0.3.0) (2026-06-10)


### Features

* **emitter:** merge allOf member schemas into the composed class ([2ca9691](https://github.com/codewithagents/openapi-laravel/commit/2ca96910912cf30b71bc3cb0d7bed417b93bb945))
* **emitter:** represent additionalProperties as typed maps with per-value rules ([514ef45](https://github.com/codewithagents/openapi-laravel/commit/514ef45f6ea5e7685b1b1dbf73bf9521b74def72))
* **emitter:** treat const as a single-value enum ([5601e31](https://github.com/codewithagents/openapi-laravel/commit/5601e3191788b3bea3548a1e60ae71e5b09c0d5b))


### Bug Fixes

* **emitter:** union allOf member nullability, guard merged read/write split ([2174896](https://github.com/codewithagents/openapi-laravel/commit/217489623546cd9a5654e93f2da5918baef721f1))
* **naming:** escape a property named this to avoid the $this fatal ([a48457f](https://github.com/codewithagents/openapi-laravel/commit/a48457f5fd55efce8469b301580abe97695048ed))
* **security:** neutralize spec-injection vectors in the generator ([1c4f7d0](https://github.com/codewithagents/openapi-laravel/commit/1c4f7d060b747e348217800edb933eab96a4e484))
* **server:** import Request for a $ref requestBody, add import-resolution gate ([a956723](https://github.com/codewithagents/openapi-laravel/commit/a9567237f2d0f5a4884c3424cd5661bfabba9a29))


### Miscellaneous Chores

* add FUNDING.yml (GitHub Sponsors: codewithagents) ([c70041a](https://github.com/codewithagents/openapi-laravel/commit/c70041a8900ff4f938ca87fb9d26c77f8cdd712b))

## [0.2.0](https://github.com/codewithagents/openapi-laravel/compare/v0.1.1...v0.2.0) (2026-06-10)


### Features

* generate abstract controllers and routes from the spec (v2 server scaffold) ([cc79a76](https://github.com/codewithagents/openapi-laravel/commit/cc79a7696850309c531fd4339d8e8d065667524e))


### Bug Fixes

* harden server scaffold per review (exit codes, output, naming, single collect) ([34a9d78](https://github.com/codewithagents/openapi-laravel/commit/34a9d780c0b3ab25631c713b88a26ef801f4fcae))
* type generated rules() with a [@return](https://github.com/return) docblock for PHPStan max ([890373a](https://github.com/codewithagents/openapi-laravel/commit/890373a9a85cd00660b45e8698d337090b206b8b))


### Miscellaneous Chores

* pin pest memory to 512M so composer test/test:type do not OOM locally ([f625121](https://github.com/codewithagents/openapi-laravel/commit/f62512121361572a3576a485c38af97d9d7f9a98))

## [0.1.1](https://github.com/codewithagents/openapi-laravel/compare/v0.1.0...v0.1.1) (2026-06-10)


### Bug Fixes

* exclude tests, docs, and dev tooling from the published package ([0bf8c13](https://github.com/codewithagents/openapi-laravel/commit/0bf8c131f1090a2187811e596a5d273db866eecb))

## 0.1.0 (2026-06-10)


### Features

* phase 1 skeleton - package wiring, tooling, CI ([c3c4826](https://github.com/codewithagents/openapi-laravel/commit/c3c48266daa5f92cb9e3e2f57750594e6bf93532))
* phase 2+3 - parser and naming layers ([9b3a943](https://github.com/codewithagents/openapi-laravel/commit/9b3a943eccbbf8add3e9167efda0ce4da8394da6))
* phase 4 - models emitter (core) ([56664f3](https://github.com/codewithagents/openapi-laravel/commit/56664f3aea243495cc39270192f1d1a406aba6b5))
* phase 5 - rules() emitter (spec constraints -&gt; Laravel validation) ([761927f](https://github.com/codewithagents/openapi-laravel/commit/761927f6c5a5e461c74b18413f27e484cc1810a0))
* readOnly/writeOnly variant split (completes phase 4) ([4f3c874](https://github.com/codewithagents/openapi-laravel/commit/4f3c874100f7bf2e8aed0c0c0ff024efa7aa4b8d))
* standalone vendor/bin/openapi-laravel entry ([3cda5f6](https://github.com/codewithagents/openapi-laravel/commit/3cda5f6eef141719bcbdeb62ba5e66ce73efacce))
* working openapi:generate command + FileWriter ([d4c98fe](https://github.com/codewithagents/openapi-laravel/commit/d4c98fe103c3e13b4d58b1ed2d3e5c024dea0671))


### Miscellaneous Chores

* release 0.1.0 ([75021c7](https://github.com/codewithagents/openapi-laravel/commit/75021c7f44ae93cdb4c44b541a2e83e4d9e0315c))
