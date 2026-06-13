# Changelog

## [0.13.0](https://github.com/codewithagents/openapi-laravel/compare/v0.12.0...v0.13.0) (2026-06-13)


### Features

* **parser:** include JSON pointer and expected shape in spec parse errors ([183f58c](https://github.com/codewithagents/openapi-laravel/commit/183f58c8a7fe7ba76186ba79deb2f8ed16cd229e))
* **parser:** name the attempted format and source file in spec file errors ([c0cb2b6](https://github.com/codewithagents/openapi-laravel/commit/c0cb2b682e8c5cd5bca2e17ea817b33f3dc6394c))
* **server:** emit [@deprecated](https://github.com/deprecated) docblock on controller methods for deprecated operations ([a1c98b9](https://github.com/codewithagents/openapi-laravel/commit/a1c98b9da122060fb9709fbf6fe6ee99549e87fd))
* **server:** split non-exploded delimited array query parameters ([#132](https://github.com/codewithagents/openapi-laravel/issues/132)) ([0d10ebe](https://github.com/codewithagents/openapi-laravel/commit/0d10ebeb1df009971408de685102328e2e5e6425))
* **server:** synthesize deepObject query parameters into nested query rules ([#131](https://github.com/codewithagents/openapi-laravel/issues/131)) ([8bfbf9c](https://github.com/codewithagents/openapi-laravel/commit/8bfbf9cc2976d9ff631e69c9ce08166f84728498))
* **server:** synthesize typed return from inline object response schemas ([#129](https://github.com/codewithagents/openapi-laravel/issues/129)) ([6333f1f](https://github.com/codewithagents/openapi-laravel/commit/6333f1f1d7343d7154300174375e57ceaeb7b84f))
* **server:** type application/x-www-form-urlencoded object request bodies ([#130](https://github.com/codewithagents/openapi-laravel/issues/130)) ([083ac3e](https://github.com/codewithagents/openapi-laravel/commit/083ac3eaf616c663604a70c5f0635c19beac33ed))


### Bug Fixes

* **emitter:** emit self:: instead of static:: return type in final generated Data factories ([97bd030](https://github.com/codewithagents/openapi-laravel/commit/97bd0304c371b6f7ec9934d6f2cc5f1b75445092))
* **server:** make delimited-array query classes additive so the split runs on GET ([#132](https://github.com/codewithagents/openapi-laravel/issues/132)) ([e95e8b0](https://github.com/codewithagents/openapi-laravel/commit/e95e8b0174f172f85f1e496b259a668ba8e31801))

## [0.12.0](https://github.com/codewithagents/openapi-laravel/compare/v0.11.0...v0.12.0) (2026-06-13)


### Features

* **e2e:** extend contract and backend for the runtime feature matrix ([8161294](https://github.com/codewithagents/openapi-laravel/commit/8161294484851c31d403f51a8dcb718930fc566e))
* **emitter:** generate validation for header parameters ([#121](https://github.com/codewithagents/openapi-laravel/issues/121)) ([277316c](https://github.com/codewithagents/openapi-laravel/commit/277316c7e3a23d6dfa05288f4a90e5e6e5938dca))
* **emitter:** generate validation for path parameters ([#113](https://github.com/codewithagents/openapi-laravel/issues/113)) ([c8357ac](https://github.com/codewithagents/openapi-laravel/commit/c8357ace0be07c7edb895f3cd5bb78fa79e37fc0))
* **parser:** add internal OpenAPI spec value objects ([#104](https://github.com/codewithagents/openapi-laravel/issues/104)) ([a875697](https://github.com/codewithagents/openapi-laravel/commit/a875697c39dea830dd0260d1c41ef30f52f259c1))
* **parser:** add OpenApiReader hydrating the typed spec graph ([#104](https://github.com/codewithagents/openapi-laravel/issues/104)) ([c11aeed](https://github.com/codewithagents/openapi-laravel/commit/c11aeeda299317cd8003d1412911d8d0fccae7e6))
* **server:** resolve component $ref request bodies to typed Data params ([#110](https://github.com/codewithagents/openapi-laravel/issues/110)) ([343e48d](https://github.com/codewithagents/openapi-laravel/commit/343e48d6c46b48ea26999632490d8ca6f1257de9))
* **server:** resolve component $ref responses to typed return types ([#116](https://github.com/codewithagents/openapi-laravel/issues/116)) ([0d2e8ab](https://github.com/codewithagents/openapi-laravel/commit/0d2e8ab70f53704bd901696b4dc5ee6b52f04cb6))
* **server:** type non-JSON responses as base Response with degradation warning ([#120](https://github.com/codewithagents/openapi-laravel/issues/120)) ([3be9f98](https://github.com/codewithagents/openapi-laravel/commit/3be9f98224287b85e6c392f27be53d1c92f1989d))
* **server:** warn on response headers, callbacks, and webhooks instead of silent drops ([#122](https://github.com/codewithagents/openapi-laravel/issues/122)) ([9a4312b](https://github.com/codewithagents/openapi-laravel/commit/9a4312b1a0063552b9921eef25a1623fb78e81f4))


### Bug Fixes

* **emitter:** constrain integer path params with whereNumber to prevent TypeError 500 ([#129](https://github.com/codewithagents/openapi-laravel/issues/129)) ([4b1ff10](https://github.com/codewithagents/openapi-laravel/commit/4b1ff107607d038868ad31d2c81736e8606008df))
* **emitter:** reject missing discriminator value with 422 ([fa80101](https://github.com/codewithagents/openapi-laravel/commit/fa80101c5920483e66c3a131eb72bb95e0bc9f9e)), closes [#126](https://github.com/codewithagents/openapi-laravel/issues/126)
* **emitter:** reject unknown discriminator value with 422 ([#124](https://github.com/codewithagents/openapi-laravel/issues/124)) ([#127](https://github.com/codewithagents/openapi-laravel/issues/127)) ([e988afa](https://github.com/codewithagents/openapi-laravel/commit/e988afa72e4698c98a60ac15cef7f5334d3afd33))
* **emitter:** synthesize writable variants transitively so nested readOnly recurses on write ([7437138](https://github.com/codewithagents/openapi-laravel/commit/7437138472c5eb9c7bc7e200b4087888a6335a51))
* **naming:** dedupe class names case-insensitively to avoid filename collisions ([#108](https://github.com/codewithagents/openapi-laravel/issues/108)) ([#112](https://github.com/codewithagents/openapi-laravel/issues/112)) ([680a309](https://github.com/codewithagents/openapi-laravel/commit/680a309bddc71acecc289958a3a8de471ef1b8c2))
* **parser:** bound the 3.2 itemSchema scan, route mistyped additionalProperties to extra ([#104](https://github.com/codewithagents/openapi-laravel/issues/104)) ([93461da](https://github.com/codewithagents/openapi-laravel/commit/93461da73251101c1d1b1403341002eab3f1812d))
* **parser:** bound total hydrated node count against YAML alias amplification ([#107](https://github.com/codewithagents/openapi-laravel/issues/107)) ([c1c354f](https://github.com/codewithagents/openapi-laravel/commit/c1c354f2e9645f81ac4baabf858c89898a761631))
* **server:** honor declared non-200 success status on Data-returning operations ([#125](https://github.com/codewithagents/openapi-laravel/issues/125)) ([#128](https://github.com/codewithagents/openapi-laravel/issues/128)) ([3648a34](https://github.com/codewithagents/openapi-laravel/commit/3648a34d4f4265b8744d9b488d4d41bdb7745de9))
* **support:** scope NoUnknownPropertiesRule to its nested subtree so closed objects enforce recursively ([#30](https://github.com/codewithagents/openapi-laravel/issues/30)) ([22a4327](https://github.com/codewithagents/openapi-laravel/commit/22a4327fe138dc794fe7e64bfa9d6f22df59ac6d))

## [0.11.0](https://github.com/codewithagents/openapi-laravel/compare/v0.10.0...v0.11.0) (2026-06-12)


### ⚠ BREAKING CHANGES

* **emitter:** generated controller method names and route names change for every clean RESTful operation (e.g. getPetById becomes show, the route name follows). Regenerate your output; the drift gate (openapi:check) shows the full change. Users who ran openapi:scaffold must rename the overridden methods in their concrete controllers to match the new abstract signatures (PHP fatals at class load otherwise), and route('...') call sites must follow the new route names. The --laravel-conventions and --no-laravel-conventions flags are removed (artisan rejects them, the standalone binary ignores unknown flags), and a controllers.laravel_conventions key in openapi-laravel.json is now rejected as unknown.
* **emitter:** generated Data classes and enums solely owned by one tag group now land in per-tag subdirectories with namespaces following the directories (data/Pet/PetData.php under App\Data\Pet). Regenerate your output and update imports in hand-written code; the drift gate (openapi:check) shows the full change. The --group-by-tag and --no-group-by-tag flags are removed (artisan rejects them, the standalone binary ignores unknown flags), and an output.group_by_tag key in openapi-laravel.json is now rejected as unknown.

### Features

* **console:** add openapi:scaffold for one-time concrete controller stubs ([#78](https://github.com/codewithagents/openapi-laravel/issues/78)) ([fcc4541](https://github.com/codewithagents/openapi-laravel/commit/fcc454142c2fa053b20dca8c8c73ec2f0193efaf))
* **console:** add repeatable --exclude-path-prefix subset filter ([#96](https://github.com/codewithagents/openapi-laravel/issues/96)) ([1b31da3](https://github.com/codewithagents/openapi-laravel/commit/1b31da3be4af384ad2a34f197019bf035141448a))
* **console:** warn on every silent degradation to mixed or Request ([#67](https://github.com/codewithagents/openapi-laravel/issues/67)) ([bbea22f](https://github.com/codewithagents/openapi-laravel/commit/bbea22f6ad05efa46e92afb63e17b00a5def73dc))
* **emitter:** configurable controller base class plus validation extension pattern ([#83](https://github.com/codewithagents/openapi-laravel/issues/83)) ([68a92a5](https://github.com/codewithagents/openapi-laravel/commit/68a92a52418b31f64edb369058bdb48a78f58508))
* **emitter:** emit array-count rules from minProperties/maxProperties ([#72](https://github.com/codewithagents/openapi-laravel/issues/72)) ([b5f7b01](https://github.com/codewithagents/openapi-laravel/commit/b5f7b01b31171b0cb7255a85ccfb84d526693e5e))
* **emitter:** emit per-index rules for tuple prefixItems ([#82](https://github.com/codewithagents/openapi-laravel/issues/82)) ([381f47b](https://github.com/codewithagents/openapi-laravel/commit/381f47b043093199ede3ba567fd74d840abd3d00))
* **emitter:** emit required_with rules from dependentRequired ([#81](https://github.com/codewithagents/openapi-laravel/issues/81)) ([ee3eb4f](https://github.com/codewithagents/openapi-laravel/commit/ee3eb4fe02ef6d86db58f6e2b30ce7a62aa48b27))
* **emitter:** honor spec response status codes in the generated scaffold ([#64](https://github.com/codewithagents/openapi-laravel/issues/64)) ([78375db](https://github.com/codewithagents/openapi-laravel/commit/78375db3223cd3f0ae3f96d6b147f8520738e402))
* **emitter:** Laravel-convention controller method names become the only naming ([8594410](https://github.com/codewithagents/openapi-laravel/commit/8594410b9769ec8305c1eb7397a01ecb7d4f9c7b))
* **emitter:** make empty generated Data classes visible with a marker and warning ([#95](https://github.com/codewithagents/openapi-laravel/issues/95)) ([6c08721](https://github.com/codewithagents/openapi-laravel/commit/6c0872179dcb1c4c3786649e5235648f94a163b3))
* **emitter:** map security schemes to route middleware ([#77](https://github.com/codewithagents/openapi-laravel/issues/77)) ([d371ef5](https://github.com/codewithagents/openapi-laravel/commit/d371ef50740982b8fc5ef9f5196a51b09239b101))
* **emitter:** opt-in Laravel-convention controller method names ([#94](https://github.com/codewithagents/openapi-laravel/issues/94)) ([81ef4e4](https://github.com/codewithagents/openapi-laravel/commit/81ef4e483a45d01d2e7415f1db1c13ff30d3e390))
* **emitter:** opt-in tag-grouped data directory layout ([#93](https://github.com/codewithagents/openapi-laravel/issues/93)) ([dade3a0](https://github.com/codewithagents/openapi-laravel/commit/dade3a07c309e229f7148c7619f8b0342d710512))
* **emitter:** route names from operationId and route group config ([#71](https://github.com/codewithagents/openapi-laravel/issues/71)) ([b4003b5](https://github.com/codewithagents/openapi-laravel/commit/b4003b5ce24797bc14b392e98c801a4ae8ae57b3))
* **emitter:** synthesize typed Data classes for inline JSON request bodies ([#76](https://github.com/codewithagents/openapi-laravel/issues/76)) ([4de4524](https://github.com/codewithagents/openapi-laravel/commit/4de45242ca59324dc3f429f9e4b17774ae79f65e))
* **emitter:** tag-grouped data layout becomes the only layout ([1197f6d](https://github.com/codewithagents/openapi-laravel/commit/1197f6ddf6e51f7bb6bb1c19c839aa91bbb8ce82))
* **emitter:** typed and validated query parameters via per-operation QueryData classes ([#63](https://github.com/codewithagents/openapi-laravel/issues/63)) ([#99](https://github.com/codewithagents/openapi-laravel/issues/99)) ([6754bd7](https://github.com/codewithagents/openapi-laravel/commit/6754bd7a5f52cc057ef03ec2e4351ef3e1d663b4))
* **emitter:** typed multipart bodies with UploadedFile rules ([#75](https://github.com/codewithagents/openapi-laravel/issues/75)) ([a93b149](https://github.com/codewithagents/openapi-laravel/commit/a93b14914257fdf8ad46098563a6a10e52ff57e3))
* **parser:** exact OpenAPI version gating with loud 3.2 best-effort warnings ([#103](https://github.com/codewithagents/openapi-laravel/issues/103)) ([6f255ef](https://github.com/codewithagents/openapi-laravel/commit/6f255efcd0cd468d93d2a09eeef87dac85630dd2))
* support Laravel 13 ([#68](https://github.com/codewithagents/openapi-laravel/issues/68)) ([644bd00](https://github.com/codewithagents/openapi-laravel/commit/644bd008f0868ac9d24ccc306f31bf972deab92e))


### Bug Fixes

* **ci:** raise the laravel/pint floor to ^1.16.1 so prefer-lowest passes the generated-output gates ([#91](https://github.com/codewithagents/openapi-laravel/issues/91)) ([a778796](https://github.com/codewithagents/openapi-laravel/commit/a77879611a562e86fa02fd5e98bf81ab477794da))
* **deps:** raise minimum spatie/laravel-data to ^4.23 for dependentRequired rules on lowest deps ([f7c36be](https://github.com/codewithagents/openapi-laravel/commit/f7c36be2a8691d7d366e97579cf1cef2d66d07dd))
* **emitter:** allow patternProperties keys under closed-object enforcement ([#65](https://github.com/codewithagents/openapi-laravel/issues/65)) ([c0f3651](https://github.com/codewithagents/openapi-laravel/commit/c0f365161d41b9001d3ecbc8b8e5354b2cea3c9c))
* **emitter:** generate query classes independent of scaffold flags plus review follow-ups ([#63](https://github.com/codewithagents/openapi-laravel/issues/63)) ([458ad74](https://github.com/codewithagents/openapi-laravel/commit/458ad7441233d6d5fc7c2be90d812fa8e7b5bace))
* **emitter:** merge PathItem-level parameters and resolve parameter refs ([#66](https://github.com/codewithagents/openapi-laravel/issues/66)) ([#85](https://github.com/codewithagents/openapi-laravel/issues/85)) ([0363d6d](https://github.com/codewithagents/openapi-laravel/commit/0363d6da2d5db9f5750add77cb09bc700fd9c1f1))
* **tests:** create parent dirs for grouped output in corpus gates ([bdb4c22](https://github.com/codewithagents/openapi-laravel/commit/bdb4c22e9ac77a84c2a54d524ee553a72d3e5fdf))


### Miscellaneous Chores

* declare the PHP class API internal ([#69](https://github.com/codewithagents/openapi-laravel/issues/69)) ([76da32e](https://github.com/codewithagents/openapi-laravel/commit/76da32e2b181c997199eebedebb94c27ecc21f18))
* **deps:** explicit symfony/yaml require, drop ext-ctype ([ae6b1c0](https://github.com/codewithagents/openapi-laravel/commit/ae6b1c0093c94172600b166b3758360c980c5920))
* **e2e:** regenerate backend for grouped layout and convention naming ([e7e45d0](https://github.com/codewithagents/openapi-laravel/commit/e7e45d02bc60574fabfabebd34cee6cb5f9f818b))
* packaging and platform hygiene for 1.0 ([#74](https://github.com/codewithagents/openapi-laravel/issues/74)) ([ed8da68](https://github.com/codewithagents/openapi-laravel/commit/ed8da68275db651ef32dad4f932293d24047fc0a))

## [0.10.0](https://github.com/codewithagents/openapi-laravel/compare/v0.9.0...v0.10.0) (2026-06-11)


### Features

* **console:** enforce additionalProperties:false by default with a --no-enforce-closed-objects opt-out ([#30](https://github.com/codewithagents/openapi-laravel/issues/30)) ([3e2b95c](https://github.com/codewithagents/openapi-laravel/commit/3e2b95c7bca5c583a59cc745909d5fd2e35b08dd))
* **emitter:** discriminator-aware validation and hydration for inline-union and allOf-inheritance forms ([#38](https://github.com/codewithagents/openapi-laravel/issues/38)) ([083cfa6](https://github.com/codewithagents/openapi-laravel/commit/083cfa6ebfa65238cb4bed51170c391ae8f8634b))
* **emitter:** emit ?T and {} so generated output is Pint-idempotent ([#60](https://github.com/codewithagents/openapi-laravel/issues/60)) ([df924c4](https://github.com/codewithagents/openapi-laravel/commit/df924c4616c908b41c28eb6b5b43657c560b2f43))
* **emitter:** inline support classes into the consumer namespace so generated output has no runtime dependency on the generator ([#40](https://github.com/codewithagents/openapi-laravel/issues/40)) ([522dbc4](https://github.com/codewithagents/openapi-laravel/commit/522dbc4dba857e830977f6c574377bc0fe363a38))


### Bug Fixes

* **emitter:** emit array generics and drop DataCollectionOf on enum collections so generated output passes PHPStan max ([#62](https://github.com/codewithagents/openapi-laravel/issues/62)) ([9e3c6fb](https://github.com/codewithagents/openapi-laravel/commit/9e3c6fb21ca0eae8758de59b5e6de934a32489f1))

## [0.9.0](https://github.com/codewithagents/openapi-laravel/compare/v0.8.0...v0.9.0) (2026-06-11)


### Features

* subset generation with dependency closure ([#44](https://github.com/codewithagents/openapi-laravel/issues/44)) ([3b5c5be](https://github.com/codewithagents/openapi-laravel/commit/3b5c5be00c50305ef0e939de8af02c93111bfff8))

## [0.8.0](https://github.com/codewithagents/openapi-laravel/compare/v0.7.0...v0.8.0) (2026-06-11)


### ⚠ BREAKING CHANGES

* openapi:generate and openapi:check now emit and check the full output by default; use --no-controllers / --no-routes to opt out. Closes #45

### Features

* **console:** contain output paths from discovered config ([#54](https://github.com/codewithagents/openapi-laravel/issues/54)) ([8c7b152](https://github.com/codewithagents/openapi-laravel/commit/8c7b1522ebbe9242779802cdfffc1b1929f25adf))
* **emitter:** discriminator-aware oneOf/anyOf union base + variant generation ([0adcf08](https://github.com/codewithagents/openapi-laravel/commit/0adcf08bc57cd6347af7cc05062f3474ba87727e))
* **emitter:** emit [@deprecated](https://github.com/deprecated) docblocks for deprecated schemas and properties ([94a30aa](https://github.com/codewithagents/openapi-laravel/commit/94a30aaa9f6dc2965001b19272c4862b29543023))
* **emitter:** opt-in additionalProperties:false enforcement ([#30](https://github.com/codewithagents/openapi-laravel/issues/30)) ([f3f632e](https://github.com/codewithagents/openapi-laravel/commit/f3f632e315146c3cd86773d37a6a18fced8083fd))
* generate models, rules, controllers and routes by default ([#46](https://github.com/codewithagents/openapi-laravel/issues/46)) ([0a9acc9](https://github.com/codewithagents/openapi-laravel/commit/0a9acc99f35ba334c7e33ea2b3b2a8a515522e3a))


### Bug Fixes

* **ci:** whitelist Illuminate DataAwareRule for require-checker ([c7570d3](https://github.com/codewithagents/openapi-laravel/commit/c7570d330af820aab9578f9ed3181c40d8ac2f71))
* **deps:** require spatie/laravel-data ^4.15 for PropertyMorphableData morph support ([57b969b](https://github.com/codewithagents/openapi-laravel/commit/57b969b101a8cb4b35dfc5ab44d2676994c0d209))
* **emitter:** integer discriminator forwards int param and int morph arms ([e630b56](https://github.com/codewithagents/openapi-laravel/commit/e630b5646b94d4903e3e519bba3b7b16ad29b5e2))
* **emitter:** keep boolean members of a mixed-type enum in Rule::in ([ae8717e](https://github.com/codewithagents/openapi-laravel/commit/ae8717e991e81deb6749887ea1e2bc704b60f6c8))
* **emitter:** morph() reads PHP property name + warn on discriminated-union degrades ([98ef4cf](https://github.com/codewithagents/openapi-laravel/commit/98ef4cffad53c60ec8a094cf0d9a3932130c8e15))
* **emitter:** nullable mixed union accepts a present null ([#8](https://github.com/codewithagents/openapi-laravel/issues/8)) ([0026271](https://github.com/codewithagents/openapi-laravel/commit/0026271251c0c3dcde857f9789ca9485a707b876))
* **emitter:** pin a variant discriminator const for standalone validation (#disc-const) ([008cab7](https://github.com/codewithagents/openapi-laravel/commit/008cab7dc808658ac30ba93b9432f3cae4632fbc))
* **emitter:** validate format: time and duration ([9dbdf55](https://github.com/codewithagents/openapi-laravel/commit/9dbdf5548464393026443a9859d471a519f66a3b))


### Miscellaneous Chores

* bump minor for pre-major breaking changes in release-please ([646061f](https://github.com/codewithagents/openapi-laravel/commit/646061fca40756ff37c82f998d0594c28369e512))
* **release:** set bump-minor-pre-major to keep breaking changes in 0.x ([4d71261](https://github.com/codewithagents/openapi-laravel/commit/4d71261d399018da116e78a6cc8211171447600a))

## [0.7.0](https://github.com/codewithagents/openapi-laravel/compare/v0.6.0...v0.7.0) (2026-06-11)


### Features

* **emitter:** warn on non-standard per-property required key ([6cca300](https://github.com/codewithagents/openapi-laravel/commit/6cca300d93f19cf392d2376bc9658b02237ccb22)), closes [#34](https://github.com/codewithagents/openapi-laravel/issues/34)


### Bug Fixes

* **emitter:** enforce format: hostname with a real RFC1123 rule (Closes [#29](https://github.com/codewithagents/openapi-laravel/issues/29)) ([0713a2e](https://github.com/codewithagents/openapi-laravel/commit/0713a2edceb354ed863e250ffc13beae9ea77424))
* **emitter:** enforce nested-array item rules at every depth ([6282058](https://github.com/codewithagents/openapi-laravel/commit/628205879b106810dd7253367d8f440d00a1675a))
* **emitter:** type undiscriminated object unions as mixed to stop false-reject (Refs [#31](https://github.com/codewithagents/openapi-laravel/issues/31)) ([4c0fd4e](https://github.com/codewithagents/openapi-laravel/commit/4c0fd4e998fdf64891df9782da2b347a38298bb3))
* **parser:** avoid int-cast warning for out-of-range numeric-string bounds ([56d2963](https://github.com/codewithagents/openapi-laravel/commit/56d2963d976ec7c3a509da2cc50d43684a7ba13e))
* **parser:** coerce string-typed scalar constraints and nullable ([a0f767e](https://github.com/codewithagents/openapi-laravel/commit/a0f767e6190a222f6946d0e32d337a69529dba99)), closes [#32](https://github.com/codewithagents/openapi-laravel/issues/32) [#33](https://github.com/codewithagents/openapi-laravel/issues/33)

## [0.6.0](https://github.com/codewithagents/openapi-laravel/compare/v0.5.0...v0.6.0) (2026-06-11)


### Features

* **check:** add openapi:check drift command (0.6.0 headline) ([e29158e](https://github.com/codewithagents/openapi-laravel/commit/e29158e08face0273f19ae6093c6047817f9ded9))


### Bug Fixes

* **emitter:** emit plain array for an array-of-union, not DataCollectionOf(A|B::class) ([aceb9dc](https://github.com/codewithagents/openapi-laravel/commit/aceb9dc39cfc994d4b5cd74a90527dd360d1cea4))

## [0.5.0](https://github.com/codewithagents/openapi-laravel/compare/v0.4.0...v0.5.0) (2026-06-10)


### Features

* **console:** add --namespace option to openapi:generate ([4441c3b](https://github.com/codewithagents/openapi-laravel/commit/4441c3b2ca272e755092ebd86ee9c049c4f50fbc))


### Bug Fixes

* **emitter:** alias non-object components instead of empty Data classes ([b8ed19c](https://github.com/codewithagents/openapi-laravel/commit/b8ed19c7fc927a683bd83727a7776bd5fe7a6621))
* **emitter:** close eight silent-validation gaps in generated rules ([34c3ccc](https://github.com/codewithagents/openapi-laravel/commit/34c3cccbac5d5666d67e0908fe2f8b57208de682))
* **emitter:** guard against Data-name collision and mistyped scalar defaults ([e5ac8be](https://github.com/codewithagents/openapi-laravel/commit/e5ac8bee019e12e444db021c22ae27eea3998f16))
* **emitter:** serialize empty additionalProperties maps as {} not [] ([ef0eb64](https://github.com/codewithagents/openapi-laravel/commit/ef0eb64c6d104d32b9358febd20dd950fb688a27))
* **parser:** normalize boolean items and surface OOM cleanly ([ea90a02](https://github.com/codewithagents/openapi-laravel/commit/ea90a02d5ae1e3a6e0478648cb5155be2ed5660f))


### Miscellaneous Chores

* marketing launch prep (packagist metadata, badges, community files) ([#6](https://github.com/codewithagents/openapi-laravel/issues/6)) ([d398c27](https://github.com/codewithagents/openapi-laravel/commit/d398c27405c1768a5e0871de85a927a17e1323b3))

## [0.4.0](https://github.com/codewithagents/openapi-laravel/compare/v0.3.0...v0.4.0) (2026-06-10)


### Features

* **emitter:** emit native union types for oneOf/anyOf ([b9583d8](https://github.com/codewithagents/openapi-laravel/commit/b9583d8cda0ac5f019e9ed7b97642d5e68e7d5c8))


### Miscellaneous Chores

* exclude e2e demo app from pint style checks ([adecd0d](https://github.com/codewithagents/openapi-laravel/commit/adecd0d36e7817493acabc7816ece43cfc186da9))

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
