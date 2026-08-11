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

/*
 * INTENTIONAL post-freeze rebaseline (#129): the 34 specs in
 * READER_BASELINE_REBASELINED_129 carry a post-#129 hash because they declare
 * at least one `integer`-typed `in: path` parameter, and #129 adds a
 * `->whereNumber('<token>')` constraint to each such parameter's generated
 * route. The bug: the abstract controller method types an integer path param
 * `int`, so under strict_types Laravel's ControllerDispatcher bound an
 * untrusted NON-numeric path segment straight onto the typed `int` parameter
 * and PHP threw an uncatchable TypeError 500 BEFORE the controller body (and
 * the #113 `fromRoute()` validation guard) could run. The fix constrains the
 * route so a non-numeric segment fails to MATCH (a clean 404 route-miss); an
 * in-shape numeric value still reaches the controller, where the #113 PathData
 * guard enforces min/max/etc and answers a clean 422 on a range violation. The
 * ONLY divergence in each of these hashes is the added `->whereNumber('...')`
 * call(s) on the routes file (keyed by the raw spec token, in path order);
 * no Data class, controller, or warning changed, and a `number`/float path
 * param stays typed `string` and unconstrained. A spec also present in an
 * earlier rebaseline list accumulates the changes; every spec outside the
 * rebaseline lists stays the frozen v0.11.0 freeze, byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#129 output (an
 * integer path parameter now carries a `->whereNumber()` route constraint),
 * keyed by spec basename. The per-spec test below still compares against the
 * JSON baseline, which now holds these specs' post-#129 hashes; the coverage
 * test pins that every listed name exists on disk and in the baseline, so the
 * list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_129 = [
    'airflow.json' => 'integer path parameter constrained with whereNumber',
    'aws_lambda.json' => 'integer path parameter constrained with whereNumber',
    'aws_sqs.json' => 'integer path parameter constrained with whereNumber',
    'bikewise.json' => 'integer path parameter constrained with whereNumber',
    'bitbucket.json' => 'integer path parameter constrained with whereNumber',
    'bungie.json' => 'integer path parameter constrained with whereNumber',
    'bunq.json' => 'integer path parameter constrained with whereNumber',
    'canada_holidays.json' => 'integer path parameter constrained with whereNumber',
    'circleci.json' => 'integer path parameter constrained with whereNumber',
    'configcat.json' => 'integer path parameter constrained with whereNumber',
    'devto.json' => 'integer path parameter constrained with whereNumber',
    'digitalocean.json' => 'integer path parameter constrained with whereNumber',
    'discourse.json' => 'integer path parameter constrained with whereNumber',
    'dnd5e.json' => 'integer path parameter constrained with whereNumber',
    'dracoon.json' => 'integer path parameter constrained with whereNumber',
    'gettyimages.json' => 'integer path parameter constrained with whereNumber',
    'giphy.json' => 'integer path parameter constrained with whereNumber',
    'github.json' => 'integer path parameter constrained with whereNumber',
    'google_sheets.json' => 'integer path parameter constrained with whereNumber',
    'here_tracking.json' => 'integer path parameter constrained with whereNumber',
    'jira.json' => 'integer path parameter constrained with whereNumber',
    'linode.json' => 'integer path parameter constrained with whereNumber',
    'petstore-3.0.yaml' => 'integer path parameter constrained with whereNumber',
    'rawg.json' => 'integer path parameter constrained with whereNumber',
    'sendgrid.json' => 'integer path parameter constrained with whereNumber',
    'sentry.json' => 'integer path parameter constrained with whereNumber',
    'shutterstock.json' => 'integer path parameter constrained with whereNumber',
    'soundcloud.json' => 'integer path parameter constrained with whereNumber',
    'stackexchange.json' => 'integer path parameter constrained with whereNumber',
    'tomtom_maps.json' => 'integer path parameter constrained with whereNumber',
    'tomtom_routing.json' => 'integer path parameter constrained with whereNumber',
    'traccar.json' => 'integer path parameter constrained with whereNumber',
    'zoom.json' => 'integer path parameter constrained with whereNumber',
    'zuora.json' => 'integer path parameter constrained with whereNumber',
];

/*
 * INTENTIONAL post-freeze rebaseline (GitHub issue #129, inline object
 * responses): the 51 specs in READER_BASELINE_REBASELINED_129_INLINE_RESPONSES
 * carry a post-fix hash because they declare at least one inline (non-$ref) 2xx
 * JSON OBJECT response schema, which the generator now synthesizes as a typed
 * `<Operation>ResponseData` class (READ variant: readOnly kept, writeOnly
 * dropped) and types the abstract method against, instead of the previous
 * silent JsonResponse fallback. The divergence is therefore a strict
 * improvement (a typed return plus the new Data class file, sometimes nested
 * inline classes too); the prior fallback was silent, so no warning text
 * changed for the object case.
 *
 * NOTE ON THE NUMBER: the constant above (READER_BASELINE_REBASELINED_129) was
 * created by the `->whereNumber()` route-constraint work, whose COMMIT mis-cited
 * "#129" even though GitHub issue #129 is THIS feature (inline object response
 * synthesis). Both lists are kept distinct and auditable rather than renaming
 * the older one: the suffix `_INLINE_RESPONSES` disambiguates the real issue
 * #129 from the mislabeled commit. A spec present in an earlier rebaseline list
 * (incl. the whereNumber `_129` list) accumulates the changes; every spec
 * outside the rebaseline lists stays the frozen v0.11.0 freeze, byte for byte.
 *
 * Inline ARRAY, scalar, union, enum, and free-form-map responses do NOT shift:
 * they keep the JsonResponse fallback (now with a per-operation warning naming
 * the operation), and a component-$ref response was already typed by issue #116.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-issue-#129
 * output (an inline object 2xx response now synthesizes a typed ResponseData
 * class), keyed by spec basename. The per-spec test below still compares
 * against the JSON baseline, which now holds these specs' post-#129 hashes;
 * the coverage test pins that every listed name exists on disk and in the
 * baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_129_INLINE_RESPONSES = [
    '1password-connect.yaml' => 'inline object response synthesizes ResponseData',
    'airflow.json' => 'inline object response synthesizes ResponseData',
    'apisguru.json' => 'inline object response synthesizes ResponseData',
    'asana.json' => 'inline object response synthesizes ResponseData',
    'box.json' => 'inline object response synthesizes ResponseData',
    'brex.json' => 'inline object response synthesizes ResponseData',
    'canada_holidays.json' => 'inline object response synthesizes ResponseData',
    'circleci.json' => 'inline object response synthesizes ResponseData',
    'clevercloud.json' => 'inline object response synthesizes ResponseData',
    'codat_accounting.json' => 'inline object response synthesizes ResponseData',
    'devto.json' => 'inline object response synthesizes ResponseData',
    'digitalocean.json' => 'inline object response synthesizes ResponseData',
    'discourse.json' => 'inline object response synthesizes ResponseData',
    'dnd5e.json' => 'inline object response synthesizes ResponseData',
    'docker.json' => 'inline object response synthesizes ResponseData',
    'ebay_fulfillment.json' => 'inline object response synthesizes ResponseData',
    'ebay_marketing.json' => 'inline object response synthesizes ResponseData',
    'elevenlabs.json' => 'inline object response synthesizes ResponseData',
    'exchangerate.json' => 'inline object response synthesizes ResponseData',
    'flickr.json' => 'inline object response synthesizes ResponseData',
    'giphy.json' => 'inline object response synthesizes ResponseData',
    'github.json' => 'inline object response synthesizes ResponseData',
    'here_tracking.json' => 'inline object response synthesizes ResponseData',
    'jira.json' => 'inline object response synthesizes ResponseData',
    'linode.json' => 'inline object response synthesizes ResponseData',
    'lufthansa.json' => 'inline object response synthesizes ResponseData',
    'medium.json' => 'inline object response synthesizes ResponseData',
    'nasa_apod.json' => 'inline object response synthesizes ResponseData',
    'notion.json' => 'inline object response synthesizes ResponseData',
    'nytimes.json' => 'inline object response synthesizes ResponseData',
    'open-meteo.yaml' => 'inline object response synthesizes ResponseData',
    'openai.yaml' => 'inline object response synthesizes ResponseData',
    'openbanking.json' => 'inline object response synthesizes ResponseData',
    'petstore-3.0.yaml' => 'inline object response synthesizes ResponseData',
    'postman.json' => 'inline object response synthesizes ResponseData',
    'rawg.json' => 'inline object response synthesizes ResponseData',
    'sendgrid.json' => 'inline object response synthesizes ResponseData',
    'sentry.json' => 'inline object response synthesizes ResponseData',
    'slack.json' => 'inline object response synthesizes ResponseData',
    'snyk.json' => 'inline object response synthesizes ResponseData',
    'stripe.json' => 'inline object response synthesizes ResponseData',
    'telegram.json' => 'inline object response synthesizes ResponseData',
    'twilio_api_v2010.json' => 'inline object response synthesizes ResponseData',
    'twilio_messaging.json' => 'inline object response synthesizes ResponseData',
    'twilio_verify.json' => 'inline object response synthesizes ResponseData',
    'twilio_video.json' => 'inline object response synthesizes ResponseData',
    'twilio.json' => 'inline object response synthesizes ResponseData',
    'twitter.json' => 'inline object response synthesizes ResponseData',
    'vercel.json' => 'inline object response synthesizes ResponseData',
    'zoom.json' => 'inline object response synthesizes ResponseData',
    'zuora.json' => 'inline object response synthesizes ResponseData',

];

/*
 * INTENTIONAL post-freeze rebaseline (#30 nested-recursion fix): the
 * twenty-three specs in READER_BASELINE_REBASELINED_30 carry a post-fix hash
 * because they reference the inlined NoUnknownPropertiesRule (closed-object
 * enforcement, on by default), whose RUNTIME BODY changed. The bug: the rule is
 * a DataAwareRule, so laravel-data handed it the FULL top-level payload via
 * setData(); a rule attached to a NESTED closed object therefore compared the
 * ROOT keys against the nested allow-list and false-rejected every valid nested
 * payload. The fix scopes the rule to its OWN attribute subtree (it strips the
 * trailing sentinel segment from the validation attribute, reads that node out
 * of the payload, and policing only that node's keys), so a nested or
 * collection-nested closed object now enforces against its own keys. The ONLY
 * divergence in each of these hashes is the bytes of the inlined
 * Support/NoUnknownPropertiesRule.php copy (the emitted rules() expressions,
 * Data classes, controllers, routes, and warnings are all byte-unchanged). A
 * spec also present in an earlier rebaseline list accumulates the changes;
 * every spec outside the rebaseline lists stays the frozen v0.11.0 freeze,
 * byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#30-fix output
 * (the inlined NoUnknownPropertiesRule body now scopes to its own subtree),
 * keyed by spec basename. The per-spec test below still compares against the
 * JSON baseline, which now holds these specs' post-fix hashes; the coverage
 * test pins that every listed name exists on disk and in the baseline, so the
 * list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_30 = [
    'ably_control.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'adyen-checkout.yaml' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'adyen-legal-entity.yaml' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'apisguru.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'bbc.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'bitbucket.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'codat_accounting.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'configcat.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'discourse.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'gettyimages.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'github.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'here_positioning.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'here_tracking.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'jira.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'openai.yaml' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'shutterstock.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'slack.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'stripe.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'twitter.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'vercel.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'webflow.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'zoom.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
    'zuora.json' => 'inlined NoUnknownPropertiesRule body scoped to its own subtree',
];

/*
 * INTENTIONAL post-freeze rebaseline (transitive nested-readOnly write split):
 * the twenty-eight specs in READER_BASELINE_REBASELINED_NESTED_READONLY carry a
 * post-fix hash because the read/write variant split is now TRANSITIVE. The bug:
 * readOnly enforcement did not recurse into nested objects on the WRITE path. A
 * schema whose OWN properties carried no readOnly/writeOnly but whose NESTED
 * object (at any depth, including inside a collection, a map, an allOf member, or
 * reached through a component `$ref`) marked a property readOnly never got a
 * writable variant, so a request body bound to the READ variant whose nested item
 * classes treated the nested readOnly field as writable; a client-sent value for
 * that server-managed field was wrongly accepted. The fix makes the split
 * decision transitive (a writable variant is synthesized when the schema OR any
 * descendant declares a flag) and recurses the readOnly-stripping into the nested
 * and collection-nested Data classes, with a component `$ref` in a write scope
 * resolving to that component's writable variant. The divergence in each of these
 * hashes is purely ADDITIVE: new `<...>WritableData` classes for the transitively
 * split schemas (and their descendants), plus the request-body controller params
 * that now type the writable variant; no read-path output, enum, route, or
 * warning changed. A spec also present in an earlier rebaseline list accumulates
 * the changes; every spec outside the rebaseline lists stays the frozen v0.11.0
 * freeze, byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-fix output (the
 * transitive nested-readOnly write split), keyed by spec basename. The per-spec
 * test below still compares against the JSON baseline, which now holds these
 * specs' post-fix hashes; the coverage test pins that every listed name exists on
 * disk and in the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_NESTED_READONLY = [
    '1password-connect.yaml' => 'transitive nested-readOnly write split',
    'ably.json' => 'transitive nested-readOnly write split',
    'adyen-legal-entity.yaml' => 'transitive nested-readOnly write split',
    'airflow.json' => 'transitive nested-readOnly write split',
    'asana.json' => 'transitive nested-readOnly write split',
    'box.json' => 'transitive nested-readOnly write split',
    'bunq.json' => 'transitive nested-readOnly write split',
    'configcat.json' => 'transitive nested-readOnly write split',
    'github.json' => 'transitive nested-readOnly write split',
    'google_bigquery.json' => 'transitive nested-readOnly write split',
    'google_cloudrun.json' => 'transitive nested-readOnly write split',
    'google_docs.json' => 'transitive nested-readOnly write split',
    'google_drive.json' => 'transitive nested-readOnly write split',
    'google_functions.json' => 'transitive nested-readOnly write split',
    'google_gke.json' => 'transitive nested-readOnly write split',
    'google_gmail.json' => 'transitive nested-readOnly write split',
    'google_logging.json' => 'transitive nested-readOnly write split',
    'google_monitoring.json' => 'transitive nested-readOnly write split',
    'google_pubsub.json' => 'transitive nested-readOnly write split',
    'google_sheets.json' => 'transitive nested-readOnly write split',
    'google_speech.json' => 'transitive nested-readOnly write split',
    'google_translate.json' => 'transitive nested-readOnly write split',
    'google_vision.json' => 'transitive nested-readOnly write split',
    'jira.json' => 'transitive nested-readOnly write split',
    'linode.json' => 'transitive nested-readOnly write split',
    'plaid.json' => 'transitive nested-readOnly write split',
    'rawg.json' => 'transitive nested-readOnly write split',
    'xero.json' => 'transitive nested-readOnly write split',
];

/*
 * INTENTIONAL post-freeze rebaseline (GitHub issue #130, form-urlencoded
 * object request bodies): the 37 specs in READER_BASELINE_REBASELINED_130
 * carry a post-fix hash because they declare at least one
 * application/x-www-form-urlencoded OBJECT request body, which the generator
 * now synthesizes as a typed `<Operation>RequestData` class (the same
 * JSON-object pipeline of issue #76, since urlencoded input arrives in
 * `$request->all()` exactly like JSON) and types the controller param against,
 * instead of the previous warned Request fallback. The divergence is therefore
 * a strict improvement (a typed body param plus the new Data class file,
 * sometimes nested inline classes too) plus the changed wording of the generic
 * "declares no application/json, multipart/form-data, or
 * application/x-www-form-urlencoded schema" fallback warning (a third media
 * type joined the list). A spec present in an earlier rebaseline list
 * accumulates the changes; every spec outside the rebaseline lists stays the
 * frozen v0.11.0 freeze, byte for byte.
 *
 * A form-urlencoded body that is NOT an object (array, scalar, union, enum,
 * free-form map) does NOT shift: it keeps the warned Request fallback, exactly
 * like the non-object JSON case.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-issue-#130
 * output (a form-urlencoded object request body now synthesizes a typed
 * RequestData class), keyed by spec basename. The per-spec test below still
 * compares against the JSON baseline, which now holds these specs' post-#130
 * hashes; the coverage test pins that every listed name exists on disk and in
 * the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_130 = [
    'aws_cloudformation.json' => 'form-urlencoded object body synthesizes RequestData',
    'aws_iam.json' => 'form-urlencoded object body synthesizes RequestData',
    'aws_rds.json' => 'form-urlencoded object body synthesizes RequestData',
    'aws_s3.json' => 'form-urlencoded object body synthesizes RequestData',
    'aws_sns.json' => 'form-urlencoded object body synthesizes RequestData',
    'aws_sqs.json' => 'form-urlencoded object body synthesizes RequestData',
    'box.json' => 'form-urlencoded object body synthesizes RequestData',
    'brex.json' => 'form-urlencoded object body synthesizes RequestData',
    'clevercloud.json' => 'form-urlencoded object body synthesizes RequestData',
    'docker.json' => 'form-urlencoded object body synthesizes RequestData',
    'docusign.json' => 'form-urlencoded object body synthesizes RequestData',
    'gettyimages.json' => 'form-urlencoded object body synthesizes RequestData',
    'github.json' => 'form-urlencoded object body synthesizes RequestData',
    'google_bigquery.json' => 'form-urlencoded object body synthesizes RequestData',
    'google_drive.json' => 'form-urlencoded object body synthesizes RequestData',
    'google_gmail.json' => 'form-urlencoded object body synthesizes RequestData',
    'here_tracking.json' => 'form-urlencoded object body synthesizes RequestData',
    'jira.json' => 'form-urlencoded object body synthesizes RequestData',
    'linode.json' => 'form-urlencoded object body synthesizes RequestData',
    'notion.json' => 'form-urlencoded object body synthesizes RequestData',
    'okta.json' => 'form-urlencoded object body synthesizes RequestData',
    'petstore-3.0.yaml' => 'form-urlencoded object body synthesizes RequestData',
    'postman.json' => 'form-urlencoded object body synthesizes RequestData',
    'slack.json' => 'form-urlencoded object body synthesizes RequestData',
    'soundcloud.json' => 'form-urlencoded object body synthesizes RequestData',
    'spotify.yaml' => 'form-urlencoded object body synthesizes RequestData',
    'stripe.json' => 'form-urlencoded object body synthesizes RequestData',
    'traccar.json' => 'form-urlencoded object body synthesizes RequestData',
    'twilio_api_v2010.json' => 'form-urlencoded object body synthesizes RequestData',
    'twilio_messaging.json' => 'form-urlencoded object body synthesizes RequestData',
    'twilio_verify.json' => 'form-urlencoded object body synthesizes RequestData',
    'twilio_video.json' => 'form-urlencoded object body synthesizes RequestData',
    'twilio.json' => 'form-urlencoded object body synthesizes RequestData',
    'vercel.json' => 'form-urlencoded object body synthesizes RequestData',
    'xero.json' => 'form-urlencoded object body synthesizes RequestData',
    'youtube.json' => 'form-urlencoded object body synthesizes RequestData',
    'zuora.json' => 'form-urlencoded object body synthesizes RequestData',
];

/**
 * INTENTIONAL post-freeze rebaseline (GitHub issue #132, delimited array query
 * parameters): the 18 specs in READER_BASELINE_REBASELINED_DELIMITED_ARRAYS
 * carry a post-#132 hash because they declare a non-exploded delimited array
 * query parameter (form + explode: false, spaceDelimited, or pipeDelimited).
 * Such a parameter used to be skipped with a warning; it is now synthesized
 * into the operation's query class and its fromQuery() factory splits the
 * single joined string on the delimiter before the array rules validate, so
 * the generated query class (and the dropped skip warning) changed. The
 * follow-up fix also makes such a query class ADDITIVE on a body-less operation
 * (not container-injected): spatie laravel-data validates the raw request
 * before the fromQuery() split runs, so an injected class would 422 on the
 * unsplit string. The abstract controller therefore carries a
 * `::fromQuery($request)` docblock pointer instead of an injected param for
 * those operations, exactly as path (#113) / header (#121) do, which shifts the
 * controller output too. A spec present in an earlier rebaseline list
 * accumulates the changes; every spec outside the rebaseline lists stays the
 * frozen v0.11.0 freeze, byte for byte.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_DELIMITED_ARRAYS = [
    'airflow.json' => 'non-exploded delimited array query parameter is split and validated',
    'apple_appstore.json' => 'non-exploded delimited array query parameter is split and validated',
    'asana.json' => 'non-exploded delimited array query parameter is split and validated',
    'box.json' => 'non-exploded delimited array query parameter is split and validated',
    'bungie.json' => 'non-exploded delimited array query parameter is split and validated',
    'docker.json' => 'non-exploded delimited array query parameter is split and validated',
    'gettyimages.json' => 'non-exploded delimited array query parameter is split and validated',
    'here_positioning.json' => 'non-exploded delimited array query parameter is split and validated',
    'here_tracking.json' => 'non-exploded delimited array query parameter is split and validated',
    'open-meteo.yaml' => 'non-exploded delimited array query parameter is split and validated',
    'reverb.json' => 'non-exploded delimited array query parameter is split and validated',
    'sendgrid.json' => 'non-exploded delimited array query parameter is split and validated',
    'soundcloud.json' => 'non-exploded delimited array query parameter is split and validated',
    'spotify.yaml' => 'non-exploded delimited array query parameter is split and validated',
    'traccar.json' => 'non-exploded delimited array query parameter is split and validated',
    'twitter.json' => 'non-exploded delimited array query parameter is split and validated',
    'wordnik.json' => 'non-exploded delimited array query parameter is split and validated',
    'xero.json' => 'non-exploded delimited array query parameter is split and validated',
];

/*
 * INTENTIONAL post-freeze rebaseline (#131, deepObject object query
 * parameters): the four specs in READER_BASELINE_REBASELINED_DEEPOBJECT carry
 * a post-#131 hash because they declare a `style: deepObject` query parameter
 * whose schema is an OBJECT (Stripe-style filter[gte]=...&filter[lte]=...).
 * Such a parameter used to be skipped with the "style deepObject is not
 * supported yet" warning; it is now synthesized into the operation's query
 * class as a nested object property, flowing through the SAME nested-object
 * pipeline a body property uses (resolveType spawns a nested Data class, which
 * carries the per-property rules). PHP/Laravel parse the bracketed keys
 * NATIVELY into a nested array, so fromQuery() needs no manual splitting and
 * the class stays container-injectable on a body-less GET (unlike the
 * delimited-array case of #132). The divergence is the new nested query Data
 * class(es) plus the dropped/changed skip warnings; a deepObject parameter on a
 * non-object schema (or with explode: false) keeps a skip with a more specific
 * reason, which also feeds the hash. A spec present in an earlier rebaseline
 * list accumulates the changes; every spec outside the rebaseline lists stays
 * the frozen v0.11.0 freeze, byte for byte.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_DEEPOBJECT = [
    'here_tracking.json' => 'deepObject object query parameters synthesized; a non-object deepObject keeps a more specific skip',
    'openai.yaml' => 'deepObject object query parameter synthesized as a nested query class',
    'soundcloud.json' => 'deepObject object query parameters synthesized as nested query classes',
    'stripe.json' => 'deepObject object query parameters synthesized as nested query classes',
];

/*
 * INTENTIONAL post-freeze rebaseline (QDPHP self-vs-static sweep): the 116
 * specs in READER_BASELINE_REBASELINED_SELF_STATIC carry a refreshed hash
 * because the per-operation query Data factory's return type changed from
 * `: static` to `: self`. The classes are always emitted final, so static
 * was redundant there (Qodana PhpUnnecessaryStaticReferenceInspection); self
 * is the equivalent, non-redundant declaration. The divergence is exactly
 * that one-token change on each generated fromQuery() (and, off the baseline
 * pipeline, fromRoute()/fromHeaders()) signature, nothing else. A spec present
 * in an earlier rebaseline list accumulates the change; every spec outside the
 * rebaseline lists stays the frozen v0.11.0 freeze, byte for byte.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_SELF_STATIC = [
    '1password-connect.yaml' => 'final query Data factory return type changed from static to self',
    'ably.json' => 'final query Data factory return type changed from static to self',
    'adyen-checkout.yaml' => 'final query Data factory return type changed from static to self',
    'adyen-legal-entity.yaml' => 'final query Data factory return type changed from static to self',
    'airflow.json' => 'final query Data factory return type changed from static to self',
    'amadeus.json' => 'final query Data factory return type changed from static to self',
    'apple_appstore.json' => 'final query Data factory return type changed from static to self',
    'appwrite.json' => 'final query Data factory return type changed from static to self',
    'asana.json' => 'final query Data factory return type changed from static to self',
    'aws_apigateway.json' => 'final query Data factory return type changed from static to self',
    'aws_cloudformation.json' => 'final query Data factory return type changed from static to self',
    'aws_cognito.json' => 'final query Data factory return type changed from static to self',
    'aws_dynamodb.json' => 'final query Data factory return type changed from static to self',
    'aws_iam.json' => 'final query Data factory return type changed from static to self',
    'aws_lambda.json' => 'final query Data factory return type changed from static to self',
    'aws_rds.json' => 'final query Data factory return type changed from static to self',
    'aws_s3.json' => 'final query Data factory return type changed from static to self',
    'aws_sns.json' => 'final query Data factory return type changed from static to self',
    'aws_sqs.json' => 'final query Data factory return type changed from static to self',
    'balldontlie.json' => 'final query Data factory return type changed from static to self',
    'bbc.json' => 'final query Data factory return type changed from static to self',
    'bigdatacloud.json' => 'final query Data factory return type changed from static to self',
    'bikewise.json' => 'final query Data factory return type changed from static to self',
    'bitbucket.json' => 'final query Data factory return type changed from static to self',
    'box.json' => 'final query Data factory return type changed from static to self',
    'braze.json' => 'final query Data factory return type changed from static to self',
    'brex.json' => 'final query Data factory return type changed from static to self',
    'bungie.json' => 'final query Data factory return type changed from static to self',
    'canada_holidays.json' => 'final query Data factory return type changed from static to self',
    'circleci.json' => 'final query Data factory return type changed from static to self',
    'clevercloud.json' => 'final query Data factory return type changed from static to self',
    'codat_accounting.json' => 'final query Data factory return type changed from static to self',
    'codat_banking.json' => 'final query Data factory return type changed from static to self',
    'configcat.json' => 'final query Data factory return type changed from static to self',
    'devto.json' => 'final query Data factory return type changed from static to self',
    'digitalocean.json' => 'final query Data factory return type changed from static to self',
    'discourse.json' => 'final query Data factory return type changed from static to self',
    'dnd5e.json' => 'final query Data factory return type changed from static to self',
    'docker.json' => 'final query Data factory return type changed from static to self',
    'docusign.json' => 'final query Data factory return type changed from static to self',
    'dracoon.json' => 'final query Data factory return type changed from static to self',
    'ebay_fulfillment.json' => 'final query Data factory return type changed from static to self',
    'ebay_marketing.json' => 'final query Data factory return type changed from static to self',
    'elevenlabs.json' => 'final query Data factory return type changed from static to self',
    'flickr.json' => 'final query Data factory return type changed from static to self',
    'gettyimages.json' => 'final query Data factory return type changed from static to self',
    'giphy.json' => 'final query Data factory return type changed from static to self',
    'github.json' => 'final query Data factory return type changed from static to self',
    'google_bigquery.json' => 'final query Data factory return type changed from static to self',
    'google_calendar.json' => 'final query Data factory return type changed from static to self',
    'google_cloudrun.json' => 'final query Data factory return type changed from static to self',
    'google_compute.json' => 'final query Data factory return type changed from static to self',
    'google_docs.json' => 'final query Data factory return type changed from static to self',
    'google_drive.json' => 'final query Data factory return type changed from static to self',
    'google_functions.json' => 'final query Data factory return type changed from static to self',
    'google_gke.json' => 'final query Data factory return type changed from static to self',
    'google_gmail.json' => 'final query Data factory return type changed from static to self',
    'google_logging.json' => 'final query Data factory return type changed from static to self',
    'google_monitoring.json' => 'final query Data factory return type changed from static to self',
    'google_pubsub.json' => 'final query Data factory return type changed from static to self',
    'google_sheets.json' => 'final query Data factory return type changed from static to self',
    'google_speech.json' => 'final query Data factory return type changed from static to self',
    'google_translate.json' => 'final query Data factory return type changed from static to self',
    'google_tts.json' => 'final query Data factory return type changed from static to self',
    'google_vision.json' => 'final query Data factory return type changed from static to self',
    'here_positioning.json' => 'final query Data factory return type changed from static to self',
    'here_tracking.json' => 'final query Data factory return type changed from static to self',
    'ipgeo.json' => 'final query Data factory return type changed from static to self',
    'jira.json' => 'final query Data factory return type changed from static to self',
    'linode.json' => 'final query Data factory return type changed from static to self',
    'lufthansa.json' => 'final query Data factory return type changed from static to self',
    'medium.json' => 'final query Data factory return type changed from static to self',
    'nasa_apod.json' => 'final query Data factory return type changed from static to self',
    'notion.json' => 'final query Data factory return type changed from static to self',
    'nytimes.json' => 'final query Data factory return type changed from static to self',
    'okta.json' => 'final query Data factory return type changed from static to self',
    'open-meteo.yaml' => 'final query Data factory return type changed from static to self',
    'openai.yaml' => 'final query Data factory return type changed from static to self',
    'petstore-3.0.yaml' => 'final query Data factory return type changed from static to self',
    'postman.json' => 'final query Data factory return type changed from static to self',
    'rawg.json' => 'final query Data factory return type changed from static to self',
    'redocly-museum.yaml' => 'final query Data factory return type changed from static to self',
    'resend.json' => 'final query Data factory return type changed from static to self',
    'reverb.json' => 'final query Data factory return type changed from static to self',
    'sendgrid.json' => 'final query Data factory return type changed from static to self',
    'sentry.json' => 'final query Data factory return type changed from static to self',
    'shutterstock.json' => 'final query Data factory return type changed from static to self',
    'slack.json' => 'final query Data factory return type changed from static to self',
    'snyk.json' => 'final query Data factory return type changed from static to self',
    'soundcloud.json' => 'final query Data factory return type changed from static to self',
    'spotify.yaml' => 'final query Data factory return type changed from static to self',
    'square.json' => 'final query Data factory return type changed from static to self',
    'stackexchange.json' => 'final query Data factory return type changed from static to self',
    'stripe.json' => 'final query Data factory return type changed from static to self',
    'tomtom_maps.json' => 'final query Data factory return type changed from static to self',
    'tomtom_routing.json' => 'final query Data factory return type changed from static to self',
    'traccar.json' => 'final query Data factory return type changed from static to self',
    'trello.json' => 'final query Data factory return type changed from static to self',
    'twilio.json' => 'final query Data factory return type changed from static to self',
    'twilio_api_v2010.json' => 'final query Data factory return type changed from static to self',
    'twilio_messaging.json' => 'final query Data factory return type changed from static to self',
    'twilio_verify.json' => 'final query Data factory return type changed from static to self',
    'twilio_video.json' => 'final query Data factory return type changed from static to self',
    'twitter.json' => 'final query Data factory return type changed from static to self',
    'vercel.json' => 'final query Data factory return type changed from static to self',
    'vimeo.json' => 'final query Data factory return type changed from static to self',
    'wayback.json' => 'final query Data factory return type changed from static to self',
    'weather_visual.json' => 'final query Data factory return type changed from static to self',
    'webflow.json' => 'final query Data factory return type changed from static to self',
    'wolfram.json' => 'final query Data factory return type changed from static to self',
    'wordnik.json' => 'final query Data factory return type changed from static to self',
    'xero.json' => 'final query Data factory return type changed from static to self',
    'xero_assets.json' => 'final query Data factory return type changed from static to self',
    'youtube.json' => 'final query Data factory return type changed from static to self',
    'zoom.json' => 'final query Data factory return type changed from static to self',
    'zuora.json' => 'final query Data factory return type changed from static to self',
];

/*
 * INTENTIONAL post-freeze rebaseline (deprecated controller docblocks): the 22
 * specs in READER_BASELINE_REBASELINED_DEPRECATED_CONTROLLER_DOCBLOCKS carry a
 * post-fix hash because they declare at least one operation marked
 * `deprecated: true`, whose abstract controller method now carries an
 * `@deprecated` docblock line (symmetric with the `@deprecated` tag a
 * deprecated schema already gives its generated Data class). OpenAPI's
 * operation `deprecated` is a bare boolean with no reason field, so the line is
 * a plain `@deprecated`. The ONLY divergence in each of these hashes is the
 * added `@deprecated` docblock line(s) on the affected abstract controller
 * methods: a strict, additive documentation improvement, no existing file
 * dropped, retyped, or reworded, and the warning lists are byte-identical. A
 * spec also present in an earlier rebaseline list accumulates the change; every
 * spec outside the rebaseline lists stays the frozen v0.11.0 freeze, byte for
 * byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-fix output (a
 * deprecated operation's abstract controller method gains an `@deprecated`
 * docblock line), keyed by spec basename. The per-spec test below still
 * compares against the JSON baseline, which now holds these specs' post-fix
 * hashes; the coverage test pins that every listed name exists on disk and in
 * the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_DEPRECATED_CONTROLLER_DOCBLOCKS = [
    'adyen-checkout.yaml' => 'deprecated operation gains an @deprecated controller method docblock',
    'apple_appstore.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'aws_lambda.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'aws_s3.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'bitbucket.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'codat_accounting.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'codat_banking.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'digitalocean.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'dracoon.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'elevenlabs.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'github.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'here_tracking.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'jira.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'linode.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'openai.yaml' => 'deprecated operation gains an @deprecated controller method docblock',
    'plaid.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'resend.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'shutterstock.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'soundcloud.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'spotify.yaml' => 'deprecated operation gains an @deprecated controller method docblock',
    'stripe.json' => 'deprecated operation gains an @deprecated controller method docblock',
    'zoom.json' => 'deprecated operation gains an @deprecated controller method docblock',
];

/*
 * INTENTIONAL post-freeze rebaseline (int32/int64 format range rules): the 58
 * specs in READER_BASELINE_REBASELINED_INT_FORMATS carry a post-fix hash
 * because they declare at least one integer field with `format: int32` or
 * `format: int64`. v0.11.0 emitted a bare `'integer'` rule for those, leaving
 * the format-implied value range unenforced, so an out-of-range integer was
 * wrongly accepted. The fix adds the signed range as Laravel `min:`/`max:`
 * rule STRINGS (int32: -2147483648..2147483647, int64:
 * -9223372036854775808..9223372036854775807; emitted as strings because the
 * int64 minimum is PHP_INT_MIN and a literal would overflow to float). An
 * explicit `minimum`/`maximum` (or 3.1 numeric exclusive bound) on the same
 * side wins, so a format bound is added only where the schema sets none. The
 * ONLY divergence in each of these hashes is the added range rule(s) on the
 * affected integer fields; no existing rule is dropped or retyped, and the
 * warning lists are byte-identical. A spec also present in an earlier
 * rebaseline list accumulates the change; every spec outside the rebaseline
 * lists stays the frozen v0.11.0 freeze, byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-fix output
 * (int32/int64 fields gain format-derived min/max range rules), keyed by spec
 * basename. The per-spec test below still compares against the JSON baseline,
 * which now holds these specs' post-fix hashes; the coverage test pins that
 * every listed name exists on disk and in the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_INT_FORMATS = [
    'ably.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'adyen-checkout.yaml' => 'int32/int64 fields gain format-derived min/max range rules',
    'adyen-legal-entity.yaml' => 'int32/int64 fields gain format-derived min/max range rules',
    'amadeus.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'appwrite.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'bikewise.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'bitbucket.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'box.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'brex.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'bungie.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'clevercloud.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'codat_accounting.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'codat_banking.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'configcat.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'devto.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'digitalocean.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'docker.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'docusign.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'dracoon.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'ebay_fulfillment.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'ebay_marketing.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'gettyimages.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'giphy.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'github.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_bigquery.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_calendar.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_cloudrun.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_compute.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_docs.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_drive.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_functions.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_gke.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_gmail.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_logging.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_monitoring.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_pubsub.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_sheets.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_speech.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_translate.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_tts.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'google_vision.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'jira.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'klarna.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'lufthansa.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'medium.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'openai.yaml' => 'int32/int64 fields gain format-derived min/max range rules',
    'petstore-3.0.yaml' => 'int32/int64 fields gain format-derived min/max range rules',
    'pinecone.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'sendgrid.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'sentry.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'spotify.yaml' => 'int32/int64 fields gain format-derived min/max range rules',
    'square.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'twilio_video.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'twitter.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'wordnik.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'youtube.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'zoom.json' => 'int32/int64 fields gain format-derived min/max range rules',
    'zuora.json' => 'int32/int64 fields gain format-derived min/max range rules',
];

/*
 * INTENTIONAL post-freeze rebaseline (uncompilable-pattern rules fix, commit
 * c670c09, bundled into this branch): the two specs in
 * READER_BASELINE_REBASELINED_RULES_UNCOMPILABLE_PATTERN carry a post-fix hash,
 * because they declare a `pattern` PHP's PCRE cannot compile (AWS IAM and
 * SendGrid use JSON-Schema `\uXXXX` escapes, which PCRE spells `\x{XXXX}`).
 * v0.11.0 emitted the pattern verbatim as a `regex:#...#` rule, which throws at
 * runtime on the first validation; the fix drops the uncompilable rule instead
 * of emitting a broken one. The ONLY divergence in each hash is the removed
 * `regex:` rule on the affected string fields. The three sibling fixes bundled
 * in the same commit range (float fixed-decimal rendering, enum int-back, and
 * non-finite numeric keywords) touch NO corpus spec, so they need no rebaseline
 * entry. sendgrid ALSO emits #168 error-factory output, so its hash carries
 * both changes; every spec outside the rebaseline lists stays the frozen
 * v0.11.0 freeze, byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-fix output (an
 * uncompilable `\uXXXX`-escape `pattern` drops its broken `regex:` rule instead
 * of emitting one, commit c670c09), keyed by spec basename. The per-spec test
 * below still compares against the JSON baseline, which now holds these specs'
 * post-fix hashes; the coverage test pins that every listed name exists on disk
 * and in the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_RULES_UNCOMPILABLE_PATTERN = [
    'aws_iam.json' => 'uncompilable \uXXXX-escape query-param patterns drop their broken regex: rules',
    'sendgrid.json' => 'an uncompilable \uXXXX-escape pattern drops its broken regex: rule (hash also carries the #168 error-factory output)',
];

/*
 * INTENTIONAL post-freeze rebaseline (#168): the thirty-two specs in
 * READER_BASELINE_REBASELINED_168 carry a post-#168 hash, because they declare
 * at least one error response (a concrete 4xx/5xx status) whose JSON schema
 * resolves to a NAMED-COMPONENT object. v0.11.0 generated the error component's
 * Data class but nothing that throws it; #168 adds, per such operation, a
 * `<Operation>Errors` throwable-factory class (one static method per qualifying
 * error status, forwarding into the ApiError carrier) AND inlines the ApiError
 * support class into the consumer's Support namespace (the unified trigger:
 * ApiError is inlined iff at least one factory is emitted). The divergence in
 * each hash is exactly the new factory file(s), the added Support/ApiError.php,
 * and, for an operation that DID get a factory, one warn-and-skip line per error
 * slot it could not cover (an inline-object, non-object, unresolvable, or
 * default/4XX/5XX wildcard slot); an operation with no qualifying error slot
 * generates no factory and no warning. No existing Data class, controller, or
 * routes file moves (responseType() is untouched by the feature). A spec also
 * present in an earlier rebaseline list accumulates the changes; every spec
 * outside the rebaseline lists stays the frozen v0.11.0 freeze, byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#168 output
 * (generated <Operation>Errors factory classes plus the inlined ApiError
 * support class for spec-declared named-component object error responses),
 * keyed by spec basename, with the number of factory classes for auditability.
 * The per-spec test below still compares against the JSON baseline, which now
 * holds these specs' post-#168 hashes; the coverage test pins that every listed
 * name exists on disk and in the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_168 = [
    '1password-connect.yaml' => '12 <Operation>Errors factory classes plus the inlined ApiError support class',
    'ably_control.json' => '22 <Operation>Errors factory classes plus the inlined ApiError support class',
    'adyen-checkout.yaml' => '20 <Operation>Errors factory classes plus the inlined ApiError support class',
    'adyen-legal-entity.yaml' => '33 <Operation>Errors factory classes plus the inlined ApiError support class',
    'airflow.json' => '71 <Operation>Errors factory classes plus the inlined ApiError support class',
    'amadeus.json' => '2 <Operation>Errors factory classes plus the inlined ApiError support class (hash also carries 2 default-slot warn-and-skip lines)',
    'apple_appstore.json' => '252 <Operation>Errors factory classes plus the inlined ApiError support class',
    'asana.json' => '166 <Operation>Errors factory classes plus the inlined ApiError support class',
    'aws_apigateway.json' => '120 <Operation>Errors factory classes plus the inlined ApiError support class',
    'aws_cognito.json' => '101 <Operation>Errors factory classes plus the inlined ApiError support class',
    'aws_dynamodb.json' => '52 <Operation>Errors factory classes plus the inlined ApiError support class',
    'aws_lambda.json' => '66 <Operation>Errors factory classes plus the inlined ApiError support class',
    'bitbucket.json' => '251 <Operation>Errors factory classes plus the inlined ApiError support class',
    'box.json' => '160 <Operation>Errors factory classes plus the inlined ApiError support class (hash also carries 160 default-slot warn-and-skip lines)',
    'clevercloud.json' => '1 <Operation>Errors factory class plus the inlined ApiError support class',
    'dnd5e.json' => '1 <Operation>Errors factory class plus the inlined ApiError support class',
    'docker.json' => '96 <Operation>Errors factory classes plus the inlined ApiError support class',
    'dracoon.json' => '280 <Operation>Errors factory classes plus the inlined ApiError support class',
    'elevenlabs.json' => '18 <Operation>Errors factory classes plus the inlined ApiError support class',
    'github.json' => '479 <Operation>Errors factory classes plus the inlined ApiError support class',
    'here_positioning.json' => '1 <Operation>Errors factory class plus the inlined ApiError support class',
    'jira.json' => '70 <Operation>Errors factory classes plus the inlined ApiError support class',
    'klarna.json' => '1 <Operation>Errors factory class plus the inlined ApiError support class',
    'openai.yaml' => '14 <Operation>Errors factory classes plus the inlined ApiError support class',
    'redocly-museum.yaml' => '8 <Operation>Errors factory classes plus the inlined ApiError support class',
    'sendgrid.json' => '127 <Operation>Errors factory classes plus the inlined ApiError support class (hash also carries the uncompilable-pattern rules fix)',
    'soundcloud.json' => '55 <Operation>Errors factory classes plus the inlined ApiError support class',
    'spotify.yaml' => '3 <Operation>Errors factory classes plus the inlined ApiError support class',
    'vimeo.json' => '226 <Operation>Errors factory classes plus the inlined ApiError support class',
    'webflow.json' => '81 <Operation>Errors factory classes plus the inlined ApiError support class',
    'xero.json' => '96 <Operation>Errors factory classes plus the inlined ApiError support class',
    'zuora.json' => '140 <Operation>Errors factory classes plus the inlined ApiError support class',
];

/*
 * INTENTIONAL post-freeze rebaseline (#172, boolean query parameters on
 * body-less operations): the 73 specs in READER_BASELINE_REBASELINED_172 carry
 * a post-#172 hash because they declare a `type: boolean` query parameter on a
 * body-less operation. Such a query class used to be container-injected, and
 * spatie laravel-data validates the RAW request BEFORE it calls the magic
 * fromQuery() creation method. Laravel's stock `boolean` rule accepts
 * true/false/1/0/"1"/"0" but NOT the form-style literals "true"/"false", so the
 * `'true' => '1'` mapping the factory emits never ran and a spec-valid
 * ?flag=true was a 422. #172 forces such a class ADDITIVE, exactly as the
 * delimited-array (#132), path (#113) and header (#121) cases already are.
 *
 * The divergence in each hash is confined to the ABSTRACT CONTROLLER files: the
 * injected query parameter leaves the method signature, its now-unused `use`
 * import is dropped, and a `::fromQuery($request)` docblock pointer is added.
 * No Data class, query class, routes file, or warning changes, so the whole
 * corpus divergence is the injected-to-additive shift and nothing else. A spec
 * present in an earlier rebaseline list accumulates the changes; every spec
 * outside the rebaseline lists stays the frozen v0.11.0 freeze, byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#172 output
 * (body-less boolean query classes forced additive), keyed by spec basename,
 * with the number of shifted operations for auditability. The per-spec test
 * below still compares against the JSON baseline, which now holds these specs'
 * post-#172 hashes; the coverage test pins that every listed name exists on
 * disk and in the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_172 = [
    '1password-connect.yaml' => '2 body-less boolean-query operations shifted from injected to additive',
    'adyen-legal-entity.yaml' => '1 body-less boolean-query operation shifted from injected to additive',
    'airflow.json' => '3 body-less boolean-query operations shifted from injected to additive',
    'amadeus.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'appwrite.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'aws_apigateway.json' => '3 body-less boolean-query operations shifted from injected to additive',
    'aws_cloudformation.json' => '9 body-less boolean-query operations shifted from injected to additive',
    'aws_iam.json' => '5 body-less boolean-query operations shifted from injected to additive',
    'aws_rds.json' => '37 body-less boolean-query operations shifted from injected to additive',
    'aws_s3.json' => '51 body-less boolean-query operations shifted from injected to additive',
    'aws_sns.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'bbc.json' => '2 body-less boolean-query operations shifted from injected to additive',
    'bikewise.json' => '2 body-less boolean-query operations shifted from injected to additive',
    'bitbucket.json' => '2 body-less boolean-query operations shifted from injected to additive',
    'box.json' => '2 body-less boolean-query operations shifted from injected to additive',
    'brex.json' => '3 body-less boolean-query operations shifted from injected to additive',
    'bungie.json' => '10 body-less boolean-query operations shifted from injected to additive',
    'clevercloud.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'devto.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'digitalocean.json' => '4 body-less boolean-query operations shifted from injected to additive',
    'discourse.json' => '2 body-less boolean-query operations shifted from injected to additive',
    'docker.json' => '17 body-less boolean-query operations shifted from injected to additive',
    'dracoon.json' => '18 body-less boolean-query operations shifted from injected to additive',
    'elevenlabs.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'flickr.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'gettyimages.json' => '4 body-less boolean-query operations shifted from injected to additive',
    'github.json' => '13 body-less boolean-query operations shifted from injected to additive',
    'google_bigquery.json' => '21 body-less boolean-query operations shifted from injected to additive',
    'google_calendar.json' => '18 body-less boolean-query operations shifted from injected to additive',
    'google_cloudrun.json' => '9 body-less boolean-query operations shifted from injected to additive',
    'google_compute.json' => '379 body-less boolean-query operations shifted from injected to additive',
    'google_docs.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'google_drive.json' => '30 body-less boolean-query operations shifted from injected to additive',
    'google_functions.json' => '7 body-less boolean-query operations shifted from injected to additive',
    'google_gke.json' => '18 body-less boolean-query operations shifted from injected to additive',
    'google_gmail.json' => '47 body-less boolean-query operations shifted from injected to additive',
    'google_logging.json' => '19 body-less boolean-query operations shifted from injected to additive',
    'google_monitoring.json' => '15 body-less boolean-query operations shifted from injected to additive',
    'google_pubsub.json' => '18 body-less boolean-query operations shifted from injected to additive',
    'google_sheets.json' => '4 body-less boolean-query operations shifted from injected to additive',
    'google_speech.json' => '6 body-less boolean-query operations shifted from injected to additive',
    'google_translate.json' => '10 body-less boolean-query operations shifted from injected to additive',
    'google_tts.json' => '4 body-less boolean-query operations shifted from injected to additive',
    'google_vision.json' => '6 body-less boolean-query operations shifted from injected to additive',
    'here_tracking.json' => '12 body-less boolean-query operations shifted from injected to additive',
    'jira.json' => '36 body-less boolean-query operations shifted from injected to additive',
    'lufthansa.json' => '2 body-less boolean-query operations shifted from injected to additive',
    'nasa_apod.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'nytimes.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'openai.yaml' => '3 body-less boolean-query operations shifted from injected to additive',
    'rawg.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'reverb.json' => '2 body-less boolean-query operations shifted from injected to additive',
    'sendgrid.json' => '12 body-less boolean-query operations shifted from injected to additive',
    'sentry.json' => '12 body-less boolean-query operations shifted from injected to additive',
    'shutterstock.json' => '14 body-less boolean-query operations shifted from injected to additive',
    'slack.json' => '20 body-less boolean-query operations shifted from injected to additive',
    'snyk.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'soundcloud.json' => '5 body-less boolean-query operations shifted from injected to additive',
    'spotify.yaml' => '1 body-less boolean-query operation shifted from injected to additive',
    'square.json' => '4 body-less boolean-query operations shifted from injected to additive',
    'stackexchange.json' => '4 body-less boolean-query operations shifted from injected to additive',
    'tomtom_routing.json' => '2 body-less boolean-query operations shifted from injected to additive',
    'traccar.json' => '10 body-less boolean-query operations shifted from injected to additive',
    'twilio.json' => '23 body-less boolean-query operations shifted from injected to additive',
    'twilio_api_v2010.json' => '24 body-less boolean-query operations shifted from injected to additive',
    'twilio_video.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'vercel.json' => '1 body-less boolean-query operation shifted from injected to additive',
    'vimeo.json' => '20 body-less boolean-query operations shifted from injected to additive',
    'weather_visual.json' => '2 body-less boolean-query operations shifted from injected to additive',
    'xero.json' => '5 body-less boolean-query operations shifted from injected to additive',
    'youtube.json' => '45 body-less boolean-query operations shifted from injected to additive',
    'zoom.json' => '8 body-less boolean-query operations shifted from injected to additive',
    'zuora.json' => '6 body-less boolean-query operations shifted from injected to additive',
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
    // #113, #121, #129, #30, #130, the transitive nested-readOnly write split,
    // #132 (delimited arrays), #131 (deepObject object query parameters), the
    // deprecated controller docblocks, int32/int64 formats, the uncompilable
    // `\uXXXX`-pattern rules fix, or #168 (generated <Operation>Errors factory
    // classes) must still exist on disk and carry a hash in the baseline (it is
    // an update, not an exemption): a renamed or deleted spec would make the
    // documented rebaseline lists rot silently.
    foreach ([...array_keys(READER_BASELINE_REBASELINED_110), ...array_keys(READER_BASELINE_REBASELINED_116), ...array_keys(READER_BASELINE_REBASELINED_120), ...array_keys(READER_BASELINE_REBASELINED_122), ...array_keys(READER_BASELINE_REBASELINED_124), ...array_keys(READER_BASELINE_REBASELINED_125), ...array_keys(READER_BASELINE_REBASELINED_126), ...array_keys(READER_BASELINE_REBASELINED_113), ...array_keys(READER_BASELINE_REBASELINED_121), ...array_keys(READER_BASELINE_REBASELINED_129), ...array_keys(READER_BASELINE_REBASELINED_129_INLINE_RESPONSES), ...array_keys(READER_BASELINE_REBASELINED_30), ...array_keys(READER_BASELINE_REBASELINED_NESTED_READONLY), ...array_keys(READER_BASELINE_REBASELINED_130), ...array_keys(READER_BASELINE_REBASELINED_DELIMITED_ARRAYS), ...array_keys(READER_BASELINE_REBASELINED_DEEPOBJECT), ...array_keys(READER_BASELINE_REBASELINED_SELF_STATIC), ...array_keys(READER_BASELINE_REBASELINED_DEPRECATED_CONTROLLER_DOCBLOCKS), ...array_keys(READER_BASELINE_REBASELINED_INT_FORMATS), ...array_keys(READER_BASELINE_REBASELINED_RULES_UNCOMPILABLE_PATTERN), ...array_keys(READER_BASELINE_REBASELINED_168), ...array_keys(READER_BASELINE_REBASELINED_172)] as $spec) {
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
        ...array_values($generator->errorFactoryFiles()),
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
