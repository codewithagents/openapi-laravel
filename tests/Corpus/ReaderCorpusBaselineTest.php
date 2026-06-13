<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * #104 T4: THE acceptance gate for the cebe replacement, restructured from
 * the T3 dual-path comparison (which died when the emitter stopped accepting
 * the cebe object model) into a frozen-baseline check. For every corpus spec
 * (the `corpus_specs` dataset globs the entire tests/Fixtures/specs
 * directory; the 130 specs the v0.11.0 freeze saw, no exclusions among them)
 * the full generation pipeline runs
 * through the typed spec graph and the result is hashed: sha256 over every
 * generated file, sorted by filename, covering the exact name AND the full
 * byte content of each one, plus the merged warning list. The hash must equal
 * the frozen v0.11.0 baseline in tests/Fixtures/corpus-baseline-v0.11.0.json,
 * which was generated from a pristine v0.11.0 worktree (the cebe pipeline at
 * tag v0.11.0) with the byte-for-byte identical recipe.
 *
 * The OpenAPI 3.2 fixtures added in #104 T8 (POST-v0.11.0 additions, listed
 * in READER_BASELINE_POST_FREEZE_SPECS) are explicitly exempt: v0.11.0 never
 * saw them, so they cannot be in the frozen baseline, and the baseline file
 * must NOT be regenerated to include them. Their end-to-end coverage
 * (parse, warnings, hydration, generation, php -l, import resolution) lives
 * in OpenApi32CorpusTest; the coverage assertion below pins that every
 * exempt name exists on disk and is absent from the baseline, so the list
 * cannot rot into a silent skip.
 *
 * Anything the reader path drops, moves, retypes, coerces, or renames, in any
 * file, by even one byte, changes the spec's hash and fails here. The gate
 * outlived the migration (Task 7 removed the cebe dependency entirely): it
 * stays as the frozen-baseline proof that the reader pipeline emits exactly
 * what the v0.11.0 cebe pipeline emitted.
 *
 * Regenerating the baseline wholesale is only legitimate from v0.11.0 itself:
 *   git worktree add /tmp/openapi-laravel-v0110 v0.11.0
 * then run this exact pipeline + recipe there (cebe parseFile instead of
 * parseFileToDocument, everything else identical).
 *
 * INTENTIONAL post-freeze rebaseline (#108): exactly four specs carry a
 * post-#108 hash instead of the pristine v0.11.0 one, because v0.11.0 emitted
 * two `.php` files whose names differed only by letter case (e.g.
 * `HttpHealthCheckData.php` / `HTTPHealthCheckData.php`). On a case-insensitive
 * filesystem (macOS APFS default, Windows NTFS) the second write silently
 * clobbered the first, so the v0.11.0 output for these specs was actually
 * broken. The #108 fix dedupes class names case-insensitively, suffixing the
 * second of each colliding pair (`...Data_2.php`), which is the ONLY change to
 * these specs (the case-insensitive allocator diverges from the case-sensitive
 * one solely on a case-fold collision). The four are listed in
 * READER_BASELINE_REBASELINED_108 with the colliding pair, so the rebaseline is
 * auditable and not a silent baseline drift; every other spec stays the frozen
 * v0.11.0 freeze, byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#108 output
 * (case-insensitive class-name dedup), keyed by spec basename, with the
 * case-only-colliding class-name pair v0.11.0 emitted as one clobbered file.
 * Documentation only: the per-spec test below still compares against the JSON
 * baseline, which now holds these specs' post-#108 hashes.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_108 = [
    'docusign.json' => 'BccEmailArchive / BCCEmailArchive',
    'flickr.json' => 'PhotoUrls / PhotoURLs',
    'google_compute.json' => 'HttpHealthCheck / HTTPHealthCheck, HttpsHealthCheck / HTTPSHealthCheck',
    'zoom.json' => 'AccountSettingsTsp / AccountSettingsTSP, UserSettingsTsp / UserSettingsTSP',
];

/*
 * INTENTIONAL post-freeze rebaseline (#110): the fourteen specs in
 * READER_BASELINE_REBASELINED_110 carry a post-#110 hash instead of the
 * pristine v0.11.0 one, because they use `#/components/requestBodies/...`
 * refs, which v0.11.0 left as a warned Illuminate\Http\Request fallback.
 * Issue #110 resolves the component and routes it through the same
 * content-type logic an inline body takes: a wrapped schema `$ref` types the
 * param with the existing Data class, an inline object schema synthesizes ONE
 * shared `<Component>RequestData` class, and a non-object shape keeps the
 * fallback with a reworded component-grained warning. Every divergence is
 * therefore a strict improvement (typed params and removed/reworded
 * fallback warnings). docusign and zoom sit in BOTH lists: their hashes
 * carry the #108 case-insensitive dedup AND the #110 typed bodies. Every
 * spec outside the two rebaseline lists stays the frozen v0.11.0 freeze,
 * byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#110 output
 * (component request bodies resolved to typed Data params), keyed by spec
 * basename, with the dominant change for auditability. The per-spec test
 * below still compares against the JSON baseline, which now holds these
 * specs' post-#110 hashes; the coverage test pins that every listed name
 * exists on disk and in the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_110 = [
    'bitbucket.json' => 'schema-$ref component bodies typed against existing Data classes',
    'clevercloud.json' => 'schema-$ref component bodies typed against existing Data classes',
    'clicksend.json' => 'inline-object component bodies synthesize 7 shared classes',
    'docker.json' => 'array-shaped component body keeps the fallback with the component-grained warning',
    'docusign.json' => 'schema-$ref component bodies typed against existing Data classes (hash also carries the #108 case-insensitive dedup)',
    'reverb.json' => 'inline-object component bodies synthesize 3 shared classes',
    'sendgrid.json' => 'mixed: schema-$ref bodies typed, 4 non-object bodies keep the warned fallback',
    'slack.json' => 'schema-$ref component bodies typed against existing Data classes',
    'snyk.json' => 'inline-object component bodies synthesize 6 shared classes',
    'square.json' => 'schema-$ref component bodies typed against existing Data classes',
    'trello.json' => 'schema-$ref component bodies typed against existing Data classes',
    'xero.json' => 'schema-$ref component body typed against the existing Data class',
    'zoom.json' => 'inline-object component bodies synthesize 3 shared classes (hash also carries the #108 case-insensitive dedup)',
    'zuora.json' => 'schema-$ref component bodies typed against existing Data classes',
];

/*
 * INTENTIONAL post-freeze rebaseline (#116): the fourteen specs in
 * READER_BASELINE_REBASELINED_116 carry a post-#116 hash, because their
 * generated output (or warning list) involves response `$ref`s, which
 * v0.11.0 left as a warned JsonResponse fallback. Issue #116 resolves a
 * `#/components/responses/...` ref and routes it through the same
 * content-type logic an inline response takes: a wrapped schema `$ref` types
 * the return with the existing Data class, an inline object schema
 * synthesizes ONE shared `<Component>ResponseData` class (READ variant), and
 * a non-object shape keeps the fallback with a reworded component-grained
 * warning. A response `$ref` that is NOT a resolvable component response
 * (brex and digitalocean point into `#/paths/...`) keeps the fallback with
 * the reworded unresolvable warning, which alone changes those two hashes.
 * Every divergence is therefore a strict improvement (typed returns and
 * removed/reworded fallback warnings). clevercloud, sendgrid, and xero sit
 * in BOTH the #110 and #116 lists: their hashes carry both rebaselines.
 * Every spec outside the rebaseline lists stays the frozen v0.11.0 freeze,
 * byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#116 output
 * (component responses resolved to typed returns), keyed by spec basename,
 * with the dominant change for auditability. The per-spec test below still
 * compares against the JSON baseline, which now holds these specs' post-#116
 * hashes; the coverage test pins that every listed name exists on disk and
 * in the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_116 = [
    'ably.json' => 'schema-$ref component responses typed against existing Data classes',
    'amadeus.json' => 'inline-object component responses synthesize 2 shared classes',
    'brex.json' => 'unresolvable #/paths/... response $refs keep the fallback with the reworded warning',
    'circleci.json' => 'schema-$ref component responses typed against existing Data classes',
    'clevercloud.json' => 'schema-$ref component responses typed against existing Data classes (hash also carries #110)',
    'digitalocean.json' => 'unresolvable #/paths/... response $refs keep the fallback with the reworded warning',
    'github.json' => 'inline-object component responses synthesize 3 shared classes, schema-$ref ones typed',
    'here_positioning.json' => 'inline-object component response synthesizes 1 shared class',
    'sendgrid.json' => 'schema-$ref component responses typed against existing Data classes (hash also carries #110)',
    'soundcloud.json' => 'mixed: 1 inline-object component response synthesizes a shared class (plus a nested class), 17 non-object ones keep the warned fallback',
    'spotify.yaml' => 'inline-object component responses synthesize 17 shared classes, 8 non-object ones keep the warned fallback',
    'wayback.json' => 'schema-$ref component responses typed against existing Data classes',
    'worldtime.json' => 'schema-$ref component responses typed against existing Data classes',
    'xero.json' => 'schema-$ref component responses typed against existing Data classes (hash also carries #110)',
];

/*
 * INTENTIONAL post-freeze rebaseline (#120, issues #117/#118): the
 * thirty-six specs in READER_BASELINE_REBASELINED_120 carry a post-#120
 * hash, because they declare at least one selected success response whose
 * content has NO JSON media type (text/html, text/plain, XML, or binary
 * downloads such as octet-stream, pdf, image/*, audio, csv). v0.11.0 typed
 * those methods JsonResponse silently, which asserts a JSON body the spec
 * never promised; #120 types them as the base Symfony Response (the parent
 * of BinaryFileResponse and StreamedResponse, so any concrete response
 * subclass satisfies the signature) and warns per operation naming the
 * declared media types. Every divergence is the same single mechanical
 * change: JsonResponse becomes Response in the affected signatures plus one
 * warning line per affected operation. The aws_* SOAP-style specs and
 * docusign dominate the counts because they declare text/xml-only responses
 * on essentially every operation. A spec also present in an earlier
 * rebaseline list accumulates the changes; every spec outside the rebaseline
 * lists stays the frozen v0.11.0 freeze, byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#120 output
 * (non-JSON-only success responses typed as the base Symfony Response with a
 * per-operation warning, issues #117/#118), keyed by spec basename, with the
 * number of affected operations for auditability. The per-spec test below
 * still compares against the JSON baseline, which now holds these specs'
 * post-#120 hashes; the coverage test pins that every listed name exists on
 * disk and in the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_120 = [
    '1password-connect.yaml' => '3 non-JSON-only responses typed as base Response',
    'apple_appstore.json' => '2 non-JSON-only responses typed as base Response',
    'aws_cloudformation.json' => '124 non-JSON-only (text/xml) responses typed as base Response',
    'aws_iam.json' => '170 non-JSON-only (text/xml) responses typed as base Response',
    'aws_rds.json' => '260 non-JSON-only (text/xml) responses typed as base Response',
    'aws_s3.json' => '57 non-JSON-only (text/xml) responses typed as base Response',
    'aws_sns.json' => '62 non-JSON-only (text/xml) responses typed as base Response',
    'aws_sqs.json' => '22 non-JSON-only (text/xml) responses typed as base Response',
    'box.json' => '4 non-JSON-only (binary download) responses typed as base Response',
    'bungie.json' => '134 non-JSON-only responses typed as base Response',
    'clevercloud.json' => '30 non-JSON-only responses typed as base Response',
    'clicksend.json' => '1 non-JSON-only response typed as base Response',
    'codat_accounting.json' => '7 non-JSON-only responses typed as base Response',
    'digitalocean.json' => '3 non-JSON-only responses typed as base Response',
    'docker.json' => '3 non-JSON-only responses typed as base Response',
    'docusign.json' => '345 non-JSON-only responses typed as base Response',
    'dracoon.json' => '3 non-JSON-only responses typed as base Response',
    'ebay_fulfillment.json' => '1 non-JSON-only response typed as base Response',
    'ebay_marketing.json' => '1 non-JSON-only response typed as base Response',
    'elevenlabs.json' => '3 non-JSON-only (audio) responses typed as base Response',
    'github.json' => '4 non-JSON-only responses typed as base Response',
    'here_tracking.json' => '1 non-JSON-only response typed as base Response',
    'linode.json' => '1 non-JSON-only response typed as base Response',
    'openai.yaml' => '2 non-JSON-only responses typed as base Response',
    'pinecone.json' => '5 non-JSON-only responses typed as base Response',
    'plaid.json' => '3 non-JSON-only responses typed as base Response',
    'redocly-museum.yaml' => '1 non-JSON-only (pdf ticket) response typed as base Response',
    'shutterstock.json' => '1 non-JSON-only response typed as base Response',
    'snyk.json' => '7 non-JSON-only responses typed as base Response',
    'stackexchange.json' => '123 non-JSON-only responses typed as base Response',
    'stripe.json' => '1 non-JSON-only response typed as base Response',
    'wolfram.json' => '2 non-JSON-only responses typed as base Response',
    'wordnik.json' => '16 non-JSON-only responses typed as base Response',
    'worldtime.json' => '6 non-JSON-only responses typed as base Response',
    'xero.json' => '26 non-JSON-only responses typed as base Response',
    'zuora.json' => '1 non-JSON-only response typed as base Response',
];

/*
 * INTENTIONAL post-freeze rebaseline (#122, issues #114/#115): the
 * twenty-six specs in READER_BASELINE_REBASELINED_122 carry a post-#122
 * hash, because they declare response headers on a selected success response
 * (#114) or root webhooks (#115), which v0.11.0 dropped in total silence.
 * #122 changes NO generated file for them: the divergence is purely the new
 * degradation warnings, which feed the frozen hash recipe alongside the file
 * contents. Headers warn per operation on the SELECTED success response only
 * (the one response the generator consumes; error-response headers stay
 * silent by design); webhooks warn once per document naming the keys. No
 * corpus spec declares operation-level callbacks (airflow carries only an
 * empty components.callbacks map), so the callbacks warning (#115) appears
 * in no rebaselined hash. A spec also present in an earlier rebaseline list
 * accumulates the changes; every spec outside the rebaseline lists stays the
 * frozen v0.11.0 freeze, byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#122 output
 * (warned drops for response headers on selected success responses and for
 * root webhooks, issues #114/#115), keyed by spec basename, with the number
 * of new warnings for auditability. The per-spec test below still compares
 * against the JSON baseline, which now holds these specs' post-#122 hashes;
 * the coverage test pins that every listed name exists on disk and in the
 * baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_122 = [
    '1password-connect.yaml' => '2 response-header warnings',
    'ably.json' => '22 response-header warnings',
    'adyen-checkout.yaml' => '22 response-header warnings',
    'bitbucket.json' => '28 response-header warnings',
    'box.json' => '2 response-header warnings',
    'bunq.json' => '421 response-header warnings',
    'canada_holidays.json' => '1 response-header warning',
    'circleci.json' => '1 response-header warning',
    'clickup.json' => '1 response-header warning',
    'digitalocean.json' => '169 response-header warnings',
    'docker.json' => '2 response-header warnings',
    'ebay_fulfillment.json' => '1 response-header warning',
    'ebay_marketing.json' => '8 response-header warnings',
    'github.json' => '201 response-header warnings',
    'here_positioning.json' => '1 response-header warning',
    'notion.json' => '13 response-header warnings',
    'openai.yaml' => '2 response-header warnings plus the document webhook warning',
    'openbanking.json' => '6 response-header warnings',
    'petstore-3.0.yaml' => '1 response-header warning (the classic /user/login rate-limit headers)',
    'redocly-museum.yaml' => 'the document webhook warning only',
    'shipstation.json' => '1 response-header warning',
    'snyk.json' => '8 response-header warnings',
    'twilio_api_v2010.json' => '165 response-header warnings',
    'webflow.json' => '121 response-header warnings',
    'zoom.json' => '10 response-header warnings',
    'zuora.json' => '428 response-header warnings',
];

/*
 * INTENTIONAL post-freeze rebaseline (#124): the seven specs in
 * READER_BASELINE_REBASELINED_124 carry a post-#124 hash, because they declare
 * a named-component discriminated union (#38). v0.11.0 emitted the morphable
 * base's morph() with a `default => null` arm: an UNKNOWN discriminator value
 * morphed to null and surfaced as the uncatchable CannotCreateAbstractClass (a
 * 500) on the creation paths (from() / validateAndCreate() / container
 * injection), which resolve the morph class BEFORE validation runs. #124
 * changes that one arm to `default => throw ValidationException::withMessages(
 * [<discriminator> => ...])` and adds the matching `use` import, so an unknown
 * value is a clean 422 on every path. The divergence is exactly that arm plus
 * the import on each discriminated-union base; nothing else moves. Every spec
 * outside the rebaseline lists stays the frozen v0.11.0 freeze, byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#124 output (the
 * morph() default arm throws a ValidationException for an unknown discriminator
 * value), keyed by spec basename, with the count of discriminated-union bases
 * affected for auditability. The per-spec test below still compares against the
 * JSON baseline, which now holds these specs' post-#124 hashes; the coverage
 * test pins that every listed name exists on disk and in the baseline, so the
 * list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_124 = [
    'ably_control.json' => '2 discriminated-union bases: unknown discriminator value now a 422',
    'adyen-checkout.yaml' => '1 discriminated-union base: unknown discriminator value now a 422',
    'airflow.json' => '1 discriminated-union base: unknown discriminator value now a 422',
    'bitbucket.json' => '1 discriminated-union base: unknown discriminator value now a 422',
    'jira.json' => '3 discriminated-union bases: unknown discriminator value now a 422',
    'openai.yaml' => '26 discriminated-union bases: unknown discriminator value now a 422',
    'twitter.json' => '2 discriminated-union bases: unknown discriminator value now a 422',
];

/*
 * INTENTIONAL post-freeze rebaseline (#125): the fifty-three specs in
 * READER_BASELINE_REBASELINED_125 carry a post-#125 hash because they declare
 * at least one operation with a non-200 success status, so they inline the
 * `RespondsWithStatus` support class, and #125 changed that one shared file.
 * The bug: spatie/laravel-data serializes a Data object returned from a POST
 * as 201 Created, so the middleware's old exactly-200 guard never matched and
 * a declared 202 (or any non-201 success) on a mutating Data-returning op was
 * silently served as 201. The fix widens the guard to normalize any
 * framework-default 2xx to the declared status while still leaving every
 * error response untouched. The divergence is the SAME single mechanical
 * change for every listed spec: the inlined `RespondsWithStatus.php` body
 * (the guard line and its docblock), NOTHING else; no per-operation file and
 * no warning list changed (verified: each listed spec emits a
 * RespondsWithStatus.php, and only that file's bytes moved). A spec also
 * present in an earlier rebaseline list accumulates the changes; every spec
 * outside the rebaseline lists stays the frozen v0.11.0 freeze, byte for
 * byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#125 output
 * (the inlined `RespondsWithStatus` middleware now normalizes any
 * framework-default 2xx, honoring a declared non-200 success status on a
 * Data-returning operation that laravel-data answered 201), keyed by spec
 * basename. The per-spec test below still compares against the JSON baseline,
 * which now holds these specs' post-#125 hashes; the coverage test pins that
 * every listed name exists on disk and in the baseline, so the list cannot
 * rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_125 = [
    '1password-connect.yaml' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'ably_control.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'adyen-checkout.yaml' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'adyen-legal-entity.yaml' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'airflow.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'apple_appstore.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'appwrite.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'asana.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'aws_apigateway.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'aws_lambda.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'aws_s3.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'bitbucket.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'box.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'circleci.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'clevercloud.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'clickup.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'configcat.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'devto.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'digitalocean.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'docker.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'docusign.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'dracoon.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'ebay_fulfillment.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'ebay_marketing.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'gettyimages.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'github.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'here_tracking.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'jira.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'klarna.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'openai.yaml' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'pinecone.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'redocly-museum.yaml' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'resend.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'sendgrid.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'sentry.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'shipstation.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'shutterstock.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'snyk.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'soundcloud.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'spotify.yaml' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'traccar.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'twilio.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'twilio_api_v2010.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'twilio_messaging.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'twilio_verify.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'twilio_video.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'twitter.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'vercel.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'vimeo.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'webflow.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'xero.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'zoom.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
    'zuora.json' => 'inlined RespondsWithStatus middleware updated to normalize any framework-default 2xx',
];

/*
 * INTENTIONAL post-freeze rebaseline (#126): the same seven specs as #124 carry
 * a post-#126 hash, because they declare a named-component discriminated union
 * (#38). #124 made an UNKNOWN discriminator value a clean 422 by throwing in the
 * morph() default arm, but a discriminator that is MISSING ENTIRELY still 500ed
 * on the creation paths: spatie's DataMorphClassResolver short-circuits to a
 * null morph (the uncatchable CannotCreateAbstractClass) when the morphable
 * property is absent AND has no default, so morph() was never reached. #126
 * declares the morphable base's discriminator NULLABLE with a `null` default:
 * the resolver now calls morph() with null for a missing key, the default arm
 * throws, and a missing discriminator is a clean 422 on from() /
 * validateAndCreate() / container injection too. The divergence is exactly that
 * one property declaration on each discriminated-union base (`string $x` ->
 * `?string $x = null`); the variants are untouched (they forward a non-null
 * value into the nullable parameter), and nothing else moves. Every spec
 * outside the rebaseline lists stays the frozen v0.11.0 freeze, byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#126 output (the
 * morphable base's discriminator is nullable with a `null` default so a MISSING
 * discriminator surfaces a 422 on the creation paths instead of an uncatchable
 * CannotCreateAbstractClass 500), keyed by spec basename, with the count of
 * discriminated-union bases affected for auditability. The per-spec test below
 * still compares against the JSON baseline, which now holds these specs'
 * post-#126 hashes; the coverage test pins that every listed name exists on
 * disk and in the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_126 = [
    'ably_control.json' => '2 discriminated-union bases: missing discriminator now a 422',
    'adyen-checkout.yaml' => '1 discriminated-union base: missing discriminator now a 422',
    'airflow.json' => '1 discriminated-union base: missing discriminator now a 422',
    'bitbucket.json' => '1 discriminated-union base: missing discriminator now a 422',
    'jira.json' => '3 discriminated-union bases: missing discriminator now a 422',
    'openai.yaml' => '26 discriminated-union bases: missing discriminator now a 422',
    'twitter.json' => '2 discriminated-union bases: missing discriminator now a 422',
];

/*
 * INTENTIONAL post-freeze rebaseline (#113): the 106 specs in
 * READER_BASELINE_REBASELINED_113 carry a post-#113 hash because they declare
 * `in: path` parameters, which v0.11.0 typed as positional scalar controller
 * arguments WITHOUT runtime validation: a path segment's min/max/pattern/enum/
 * format constraints were silently dropped, so a bad value returned 200 instead
 * of 422. Issue #113 synthesizes a per-operation `<Operation>PathData` class
 * with spec-derived rules() plus a `fromRoute(Request)` factory (the same
 * pipeline the query class of #63 uses), and the abstract controller method
 * gains a docblock pointer to `::fromRoute($request)`. The path Data class is
 * NOT in the readerBaselinePipeline file set (it is exercised end-to-end by the
 * #113 round-trip and differential tests), so the ONLY change in each of these
 * hashes is the added controller docblock pointer lines: a strict, additive
 * documentation improvement, no existing file dropped, retyped, or reworded,
 * and the warning lists are byte-identical. Every spec outside the rebaseline
 * lists stays the frozen v0.11.0 freeze, byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#113 output
 * (path parameter validation classes generated, controller docblock pointers
 * added), keyed by spec basename. The per-spec test below still compares
 * against the JSON baseline, which now holds these specs' post-#113 hashes;
 * the coverage test pins that every listed name exists on disk and in the
 * baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_113 = [
    '1password-connect.yaml' => 'path parameter validation classes generated',
    'ably.json' => 'path parameter validation classes generated',
    'ably_control.json' => 'path parameter validation classes generated',
    'adyen-checkout.yaml' => 'path parameter validation classes generated',
    'adyen-legal-entity.yaml' => 'path parameter validation classes generated',
    'airflow.json' => 'path parameter validation classes generated',
    'apisguru.json' => 'path parameter validation classes generated',
    'apple_appstore.json' => 'path parameter validation classes generated',
    'appwrite.json' => 'path parameter validation classes generated',
    'asana.json' => 'path parameter validation classes generated',
    'aws_apigateway.json' => 'path parameter validation classes generated',
    'aws_lambda.json' => 'path parameter validation classes generated',
    'aws_s3.json' => 'path parameter validation classes generated',
    'aws_sqs.json' => 'path parameter validation classes generated',
    'bbc.json' => 'path parameter validation classes generated',
    'bikewise.json' => 'path parameter validation classes generated',
    'bitbucket.json' => 'path parameter validation classes generated',
    'box.json' => 'path parameter validation classes generated',
    'brex.json' => 'path parameter validation classes generated',
    'bungie.json' => 'path parameter validation classes generated',
    'bunq.json' => 'path parameter validation classes generated',
    'canada_holidays.json' => 'path parameter validation classes generated',
    'circleci.json' => 'path parameter validation classes generated',
    'clevercloud.json' => 'path parameter validation classes generated',
    'clicksend.json' => 'path parameter validation classes generated',
    'codat_accounting.json' => 'path parameter validation classes generated',
    'codat_banking.json' => 'path parameter validation classes generated',
    'configcat.json' => 'path parameter validation classes generated',
    'devto.json' => 'path parameter validation classes generated',
    'digitalocean.json' => 'path parameter validation classes generated',
    'discourse.json' => 'path parameter validation classes generated',
    'dnd5e.json' => 'path parameter validation classes generated',
    'docker.json' => 'path parameter validation classes generated',
    'docusign.json' => 'path parameter validation classes generated',
    'dracoon.json' => 'path parameter validation classes generated',
    'ebay_fulfillment.json' => 'path parameter validation classes generated',
    'ebay_marketing.json' => 'path parameter validation classes generated',
    'elevenlabs.json' => 'path parameter validation classes generated',
    'exchangerate.json' => 'path parameter validation classes generated',
    'gettyimages.json' => 'path parameter validation classes generated',
    'giphy.json' => 'path parameter validation classes generated',
    'github.json' => 'path parameter validation classes generated',
    'google_bigquery.json' => 'path parameter validation classes generated',
    'google_calendar.json' => 'path parameter validation classes generated',
    'google_cloudrun.json' => 'path parameter validation classes generated',
    'google_compute.json' => 'path parameter validation classes generated',
    'google_docs.json' => 'path parameter validation classes generated',
    'google_drive.json' => 'path parameter validation classes generated',
    'google_functions.json' => 'path parameter validation classes generated',
    'google_gke.json' => 'path parameter validation classes generated',
    'google_gmail.json' => 'path parameter validation classes generated',
    'google_logging.json' => 'path parameter validation classes generated',
    'google_monitoring.json' => 'path parameter validation classes generated',
    'google_pubsub.json' => 'path parameter validation classes generated',
    'google_sheets.json' => 'path parameter validation classes generated',
    'google_speech.json' => 'path parameter validation classes generated',
    'google_translate.json' => 'path parameter validation classes generated',
    'google_tts.json' => 'path parameter validation classes generated',
    'google_vision.json' => 'path parameter validation classes generated',
    'here_tracking.json' => 'path parameter validation classes generated',
    'jira.json' => 'path parameter validation classes generated',
    'klarna.json' => 'path parameter validation classes generated',
    'linode.json' => 'path parameter validation classes generated',
    'lufthansa.json' => 'path parameter validation classes generated',
    'medium.json' => 'path parameter validation classes generated',
    'notion.json' => 'path parameter validation classes generated',
    'okta.json' => 'path parameter validation classes generated',
    'openai.yaml' => 'path parameter validation classes generated',
    'petstore-3.0.yaml' => 'path parameter validation classes generated',
    'pinecone.json' => 'path parameter validation classes generated',
    'postman.json' => 'path parameter validation classes generated',
    'rawg.json' => 'path parameter validation classes generated',
    'redocly-museum.yaml' => 'path parameter validation classes generated',
    'resend.json' => 'path parameter validation classes generated',
    'reverb.json' => 'path parameter validation classes generated',
    'sendgrid.json' => 'path parameter validation classes generated',
    'sentry.json' => 'path parameter validation classes generated',
    'shutterstock.json' => 'path parameter validation classes generated',
    'snyk.json' => 'path parameter validation classes generated',
    'soundcloud.json' => 'path parameter validation classes generated',
    'spotify.yaml' => 'path parameter validation classes generated',
    'square.json' => 'path parameter validation classes generated',
    'stackexchange.json' => 'path parameter validation classes generated',
    'stripe.json' => 'path parameter validation classes generated',
    'tomtom_maps.json' => 'path parameter validation classes generated',
    'tomtom_routing.json' => 'path parameter validation classes generated',
    'traccar.json' => 'path parameter validation classes generated',
    'trello.json' => 'path parameter validation classes generated',
    'twilio.json' => 'path parameter validation classes generated',
    'twilio_api_v2010.json' => 'path parameter validation classes generated',
    'twilio_messaging.json' => 'path parameter validation classes generated',
    'twilio_verify.json' => 'path parameter validation classes generated',
    'twilio_video.json' => 'path parameter validation classes generated',
    'twitter.json' => 'path parameter validation classes generated',
    'vercel.json' => 'path parameter validation classes generated',
    'vimeo.json' => 'path parameter validation classes generated',
    'vonage.json' => 'path parameter validation classes generated',
    'weather_visual.json' => 'path parameter validation classes generated',
    'webflow.json' => 'path parameter validation classes generated',
    'wordnik.json' => 'path parameter validation classes generated',
    'worldtime.json' => 'path parameter validation classes generated',
    'xero.json' => 'path parameter validation classes generated',
    'xero_assets.json' => 'path parameter validation classes generated',
    'youtube.json' => 'path parameter validation classes generated',
    'zoom.json' => 'path parameter validation classes generated',
    'zuora.json' => 'path parameter validation classes generated',
];

/*
 * INTENTIONAL post-freeze rebaseline (#121): the 42 specs in
 * READER_BASELINE_REBASELINED_121 carry a post-#121 hash because they declare
 * `in: header` parameters, which v0.11.0 dropped in total silence beyond one
 * "header parameters are not supported yet" warning per operation: a
 * constrained custom header (min/max/pattern/enum/format) was never validated,
 * so a bad value returned 200 instead of 422. Issue #121 synthesizes a
 * per-operation `<Operation>HeaderData` class with spec-derived rules() plus a
 * `fromHeaders(Request)` factory (the same location-parameterized pipeline the
 * query class of #63 and the path class of #113 use), and the abstract
 * controller method gains a docblock pointer to `::fromHeaders($request)`. The
 * header Data class is NOT in the readerBaselinePipeline file set (it is
 * exercised end-to-end by the #121 round-trip and differential tests, exactly
 * like the #113 path class is excluded), so the ONLY divergences in each of
 * these hashes are strictly ADDITIVE: the added controller docblock pointer
 * lines, the REMOVAL of the now-obsolete per-operation header-drop warnings,
 * and (for specs that declare a reserved/framework-owned standard header such
 * as Accept or Authorization) the narrower per-header reserved-skip warning
 * the synthesizer emits in their place. Verified per spec: no existing file is
 * dropped or retyped, and every inserted line is a header docblock pointer.
 * The `in: cookie` warning is unchanged (cookie support stays out of scope).
 * A spec also present in an earlier rebaseline list accumulates the changes;
 * every spec outside the rebaseline lists stays the frozen v0.11.0 freeze,
 * byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#121 output
 * (header parameter validation classes generated, controller docblock pointers
 * added, the header-drop warning removed in favor of typed classes and a
 * narrower reserved-header skip warning), keyed by spec basename. The per-spec
 * test below still compares against the JSON baseline, which now holds these
 * specs' post-#121 hashes; the coverage test pins that every listed name
 * exists on disk and in the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_121 = [
    'ably.json' => 'header parameter validation classes generated',
    'adyen-checkout.yaml' => 'header parameter validation classes generated',
    'adyen-legal-entity.yaml' => 'header parameter validation classes generated',
    'amadeus.json' => 'header parameter validation classes generated',
    'aws_apigateway.json' => 'header parameter validation classes generated',
    'aws_cloudformation.json' => 'header parameter validation classes generated',
    'aws_cognito.json' => 'header parameter validation classes generated',
    'aws_dynamodb.json' => 'header parameter validation classes generated',
    'aws_iam.json' => 'header parameter validation classes generated',
    'aws_lambda.json' => 'header parameter validation classes generated',
    'aws_rds.json' => 'header parameter validation classes generated',
    'aws_s3.json' => 'header parameter validation classes generated',
    'aws_sns.json' => 'header parameter validation classes generated',
    'aws_sqs.json' => 'header parameter validation classes generated',
    'box.json' => 'header parameter validation classes generated',
    'brex.json' => 'header parameter validation classes generated',
    'bunq.json' => 'header parameter validation classes generated',
    'circleci.json' => 'header parameter validation classes generated',
    'clevercloud.json' => 'header parameter validation classes generated',
    'configcat.json' => 'header parameter validation classes generated',
    'digitalocean.json' => 'header parameter validation classes generated',
    'discourse.json' => 'header parameter validation classes generated',
    'docker.json' => 'header parameter validation classes generated',
    'dracoon.json' => 'header parameter validation classes generated',
    'elevenlabs.json' => 'header parameter validation classes generated',
    'gettyimages.json' => 'header parameter validation classes generated',
    'here_positioning.json' => 'header parameter validation classes generated',
    'here_tracking.json' => 'header parameter validation classes generated',
    'jira.json' => 'header parameter validation classes generated',
    'lufthansa.json' => 'header parameter validation classes generated',
    'notion.json' => 'header parameter validation classes generated',
    'openbanking.json' => 'header parameter validation classes generated',
    'petstore-3.0.yaml' => 'header parameter validation classes generated',
    'resend.json' => 'header parameter validation classes generated',
    'sendgrid.json' => 'header parameter validation classes generated',
    'shutterstock.json' => 'header parameter validation classes generated',
    'slack.json' => 'header parameter validation classes generated',
    'vercel.json' => 'header parameter validation classes generated',
    'webflow.json' => 'header parameter validation classes generated',
    'xero.json' => 'header parameter validation classes generated',
    'xero_assets.json' => 'header parameter validation classes generated',
    'zuora.json' => 'header parameter validation classes generated',
];

/**
 * Corpus specs added AFTER the v0.11.0 baseline freeze (#104 T8: the OpenAPI
 * 3.2 fixtures). The frozen baseline cannot contain them by definition, so
 * the per-spec comparison exempts exactly these names; everything else in
 * the glob must hash-match the freeze. OpenApi32CorpusTest owns their
 * end-to-end coverage.
 *
 * @var array<string, true>
 */
const READER_BASELINE_POST_FREEZE_SPECS = [
    'openapi-3.2-cdn-additional-operations.yaml' => true,
    'openapi-3.2-logstream-item-schema.yaml' => true,
    'openapi-3.2-museum.yaml' => true,
    'openapi-3.2-payments-default-mapping.yaml' => true,
    'openapi-3.2-query-flights.yaml' => true,
];

it('generates output byte-identical to the frozen v0.11.0 baseline', function (string $path) {
    $baseline = readerBaselineHashes();
    $spec = basename($path);

    if (isset(READER_BASELINE_POST_FREEZE_SPECS[$spec])) {
        $this->markTestSkipped(
            "{$spec} was added after the v0.11.0 baseline freeze (#104 T8, OpenAPI 3.2 corpus); ".
            'it is exempt by READER_BASELINE_POST_FREEZE_SPECS and covered end-to-end by OpenApi32CorpusTest.'
        );
    }

    expect($baseline)->toHaveKey($spec);

    [$files, $warnings] = readerBaselinePipeline($path);

    expect(readerBaselineHash($files, $warnings))->toBe(
        $baseline[$spec],
        "Generated output for {$spec} diverged from the frozen v0.11.0 baseline (".count($files).' files, '.count($warnings).' warnings hashed).',
    );
})->with('corpus_specs')->group('slow');

it('covers every corpus spec in the frozen baseline, nothing more', function () {
    $specs = array_map(
        static fn (string $path): string => basename($path),
        glob(__DIR__.'/../Fixtures/specs/*') ?: [],
    );
    sort($specs, SORT_STRING);

    // Every exempt post-freeze spec must actually exist on disk (no stale
    // exemptions) and the baseline must consist of exactly the glob minus
    // the exemptions: the frozen file gains nothing and loses nothing.
    $frozen = [];
    foreach ($specs as $spec) {
        if (! isset(READER_BASELINE_POST_FREEZE_SPECS[$spec])) {
            $frozen[] = $spec;
        }
    }
    expect(array_intersect_key(READER_BASELINE_POST_FREEZE_SPECS, array_flip($specs)))
        ->toHaveCount(count(READER_BASELINE_POST_FREEZE_SPECS))
        ->and(array_keys(readerBaselineHashes()))->toBe($frozen);

    // Every spec rebaselined for #108 must still exist on disk and carry a hash
    // in the baseline, so the documented rebaseline list cannot rot into a
    // stale reference to a removed or post-freeze-exempt spec.
    $baseline = readerBaselineHashes();
    foreach (array_keys(READER_BASELINE_REBASELINED_108) as $rebaselined) {
        expect($specs)->toContain($rebaselined)
            ->and($baseline)->toHaveKey($rebaselined)
            ->and(READER_BASELINE_POST_FREEZE_SPECS)->not->toHaveKey($rebaselined);
    }

    // Every spec rebaselined for #110, #116, #120, #122, #124, #125, #126,
    // #113, or #121 must still exist on disk and carry a hash in the baseline
    // (it is an update, not an exemption): a renamed or deleted spec would make
    // the documented rebaseline lists rot silently.
    foreach ([...array_keys(READER_BASELINE_REBASELINED_110), ...array_keys(READER_BASELINE_REBASELINED_116), ...array_keys(READER_BASELINE_REBASELINED_120), ...array_keys(READER_BASELINE_REBASELINED_122), ...array_keys(READER_BASELINE_REBASELINED_124), ...array_keys(READER_BASELINE_REBASELINED_125), ...array_keys(READER_BASELINE_REBASELINED_126), ...array_keys(READER_BASELINE_REBASELINED_113), ...array_keys(READER_BASELINE_REBASELINED_121)] as $spec) {
        expect($specs)->toContain($spec)
            ->and($baseline)->toHaveKey($spec);
    }
});

/**
 * The frozen v0.11.0 per-spec output hashes, keyed by spec basename, sorted.
 *
 * @return array<string, string>
 */
function readerBaselineHashes(): array
{
    /** @var array<string, string> $baseline */
    $baseline = json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/corpus-baseline-v0.11.0.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $baseline;
}

/**
 * The full generation pipeline (models, support classes, per-operation
 * query/body Data classes, controllers, routes), mirroring the
 * command/standalone wiring exactly as GenerateServerCorpusTest does, with
 * default options. The whole pipeline (model layer AND operation collector)
 * consumes the one typed spec graph, exactly like GenerationPlanner since
 * Task 5. Returns the generated code keyed by relative filename plus the
 * combined deterministic warning list.
 *
 * @return array{0: array<string, string>, 1: list<string>}
 */
function readerBaselinePipeline(string $path): array
{
    $parser = new SpecParser;
    $document = $parser->parseFileToDocument($path);

    $generator = new ModelGenerator;
    $modelFiles = $generator->generate($document);

    $options = new ServerOptions;
    $collector = new OperationCollector($options, $generator->registry(), null, $generator);
    $descriptors = $collector->collect($document);
    $controllers = (new ControllerGenerator($options))->generate($descriptors);
    $routes = (new RouteGenerator($options))->generate($descriptors);

    $files = [];
    foreach ([
        ...array_values($modelFiles),
        ...array_values($generator->supportFiles()),
        ...array_values($generator->queryFiles()),
        ...array_values($generator->bodyFiles()),
        ...array_values($generator->responseFiles()),
        ...array_values($controllers),
        $routes,
    ] as $file) {
        $files[$file->filename()] = $file->code;
    }

    return [$files, [...$parser->warnings(), ...$generator->warnings(), ...$collector->warnings()]];
}

/**
 * The frozen baseline recipe: sha256 over every generated file sorted by
 * filename (name and full byte content both feed the hash, NUL-delimited so
 * no concatenation ambiguity exists), then every warning in order. Must stay
 * byte-for-byte identical to the recipe that froze the v0.11.0 baseline.
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $warnings
 */
function readerBaselineHash(array $files, array $warnings): string
{
    ksort($files, SORT_STRING);

    $ctx = hash_init('sha256');
    foreach ($files as $name => $code) {
        hash_update($ctx, $name."\0".$code."\0");
    }
    foreach ($warnings as $warning) {
        hash_update($ctx, 'warning:'.$warning."\0");
    }

    return hash_final($ctx);
}
