<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\ApiResponseData;
use App\Data\Lab\LabAllOfData;
use App\Data\Lab\LabAnyOfData;
use App\Data\Lab\LabArrayData;
use App\Data\Lab\LabBackedEnumData;
use App\Data\Lab\LabCircleData;
use App\Data\Lab\LabClosedData;
use App\Data\Lab\LabClosedNestData;
use App\Data\Lab\LabCookieEchoData;
use App\Data\Lab\LabEnumConstData;
use App\Data\Lab\LabFormatsData;
use App\Data\Lab\LabFormBodyRequestData;
use App\Data\Lab\LabFormBodyResponseData;
use App\Data\Lab\LabGalleryRequestData;
use App\Data\Lab\LabHeaderEchoData;
use App\Data\Lab\LabHeaderHeaderData;
use App\Data\Lab\LabInlineBodyRequestData;
use App\Data\Lab\LabInlineBodyResponseData;
use App\Data\Lab\LabInlineResponseResponseData;
use App\Data\Lab\LabInlineShapeData;
use App\Data\Lab\LabInt64Data;
use App\Data\Lab\LabLooseUnionData;
use App\Data\Lab\LabMapData;
use App\Data\Lab\LabNestedBinaryRequestData;
use App\Data\Lab\LabNestedData;
use App\Data\Lab\LabNestedVariantData;
use App\Data\Lab\LabNestedVariantWritableData;
use App\Data\Lab\LabNumericData;
use App\Data\Lab\LabPathEchoData;
use App\Data\Lab\LabPathPathData;
use App\Data\Lab\LabPresenceData;
use App\Data\Lab\LabQueryEchoData;
use App\Data\Lab\LabQueryQueryData;
use App\Data\Lab\LabSecureEchoData;
use App\Data\Lab\LabShapeData;
use App\Data\Lab\LabSharedBodyRequestData;
use App\Data\Lab\LabSharedResponseResponseData;
use App\Data\Lab\LabSquareData;
use App\Data\Lab\LabStringData;
use App\Data\Lab\LabStylesEchoData;
use App\Data\Lab\LabStylesQueryData;
use App\Data\Lab\LabTraitCheckData;
use App\Data\Lab\LabTupleData;
use App\Data\Lab\LabUnionData;
use App\Data\Lab\LabUnionSelectorData;
use App\Data\Lab\LabVariantItemData;
use App\Data\Lab\LabVehicleBaseData;
use App\Data\LabRefPayloadData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hand-written concrete controller for the stateless validation/serialization
 * lab. It extends the GENERATED AbstractLabController.
 *
 * Most methods are a pure echo: by the time they run, the framework has already
 * hydrated and validated the typed Data argument against the generated rules(),
 * so a spec-invalid payload 422s before reaching the body. Returning the same
 * Data object back proves the serialization side of the round trip. No
 * persistence, so this controller touches no PetStore.
 *
 * A few methods are NOT pure echoes and the comment on each says why:
 * - labQuery / show (path) / labHeader: the parameter is not a body Data class,
 *   so the method assembles the echo response itself.
 * - labPlainText / labDownload: the spec declares a non-JSON response, which the
 *   generator types as the base Symfony Response (#117/#118). The body is
 *   consumer-written here.
 */
final class LabController extends AbstractLabController
{
    public function labNumeric(LabNumericData $labNumeric): LabNumericData
    {
        return $labNumeric;
    }

    public function labString(LabStringData $labString): LabStringData
    {
        return $labString;
    }

    public function labTraitCheck(LabTraitCheckData $labTraitCheck): LabTraitCheckData
    {
        return $labTraitCheck;
    }

    public function labArray(LabArrayData $labArray): LabArrayData
    {
        return $labArray;
    }

    public function labFormats(LabFormatsData $labFormats): LabFormatsData
    {
        return $labFormats;
    }

    public function labEnumConst(LabEnumConstData $labEnumConst): LabEnumConstData
    {
        return $labEnumConst;
    }

    public function labClosed(LabClosedData $labClosed): LabClosedData
    {
        return $labClosed;
    }

    public function labPresence(LabPresenceData $labPresence): LabPresenceData
    {
        return $labPresence;
    }

    public function labMap(LabMapData $labMap): LabMapData
    {
        return $labMap;
    }

    public function labEmptyMap(): LabMapData
    {
        // Empty-map RESPONSE serialization: return a LabMap whose typed
        // additionalProperties map (counts) is EMPTY. The generated
        // MapObjectTransformer forces JSON object encoding, so the empty map
        // serializes as {} not []. This pins the response side of map
        // serialization (the request side is covered by /lab/map and the
        // POST /pet attributes map).
        return new LabMapData(label: 'empty', counts: []);
    }

    public function labUnion(LabUnionData $labUnion): LabUnionData
    {
        return $labUnion;
    }

    public function labNested(LabNestedData $labNested): LabNestedData
    {
        return $labNested;
    }

    public function labNestedVariant(LabNestedVariantWritableData $labNestedVariant): LabNestedVariantData
    {
        // Nested-in-collection readOnly/writeOnly split (#writable-variant
        // recursion, fix 7437138). The body param is now the WRITABLE variant:
        // the generator synthesized it transitively, so each item is a
        // LabVariantItemWritableData carrying only the writable fields
        // (name + writeOnly secret). The readOnly serverId is NOT a property of
        // the writable item at all, so a client-sent serverId never reaches this
        // controller: it cannot be echoed back. We map the validated writable
        // input to the READ variant, stamping a server-managed serverId per item
        // (proving serverId is server-owned, never the client value). The read
        // variant drops the writeOnly secret from serialization.
        $readItems = [];
        foreach ($labNestedVariant->items as $index => $item) {
            $readItems[] = new LabVariantItemData(
                name: $item->name,
                serverId: 'SERVER-MANAGED-'.($index + 1),
            );
        }

        return new LabNestedVariantData(
            title: $labNestedVariant->title,
            items: $readItems,
        );
    }

    public function labClosedNest(LabClosedNestData $labClosedNest): LabClosedNestData
    {
        // Nested closed-object enforcement (#30 recursion). An unknown key on the
        // inner object (property `inner` or item `items[*]`) must 422 via the
        // recursing NoUnknownPropertiesRule BEFORE this body runs. A clean payload
        // round-trips as a pure echo.
        return $labClosedNest;
    }

    public function labBackedEnum(LabBackedEnumData $labBackedEnum): LabBackedEnumData
    {
        return $labBackedEnum;
    }

    public function labAllOf(LabAllOfData $labAllOf): LabAllOfData
    {
        return $labAllOf;
    }

    public function labShape(LabShapeData $labShape): LabShapeData
    {
        return $labShape;
    }

    public function labRefBody(LabRefPayloadData $labRefPayload): LabRefPayloadData
    {
        return $labRefPayload;
    }

    public function labAccepted(LabAllOfData $labAllOf): LabAllOfData
    {
        return $labAllOf;
    }

    // --- Stage 1: parameter axis --------------------------------------------

    public function labQuery(LabQueryQueryData $query): LabQueryEchoData
    {
        // The generated query Data was validated against rules() before this ran
        // (a bad tier/count/code 422s). We echo the validated values back.
        return new LabQueryEchoData(
            tier: $query->tier,
            count: $query->count,
            code: $query->code,
        );
    }

    public function show(int $score): LabPathEchoData
    {
        // Path-param min/max validation (#113): the generator emits
        // LabPathPathData with a fromRoute() factory that validates the route
        // parameters against rules() (score min:10 max:20). Calling it here
        // makes an out-of-range path value a 422 instead of a silent 200. The
        // validated value is then echoed back.
        $path = LabPathPathData::fromRoute(request());

        return new LabPathEchoData(score: $path->score);
    }

    public function labHeader(): LabHeaderEchoData
    {
        // Header-param validation (#121): the generator emits
        // LabHeaderHeaderData with a fromHeaders() factory that validates the
        // request headers against rules() (X-Lab-Token pattern ^tok-[0-9]{4}$,
        // required). Calling it here makes a bad or missing header a 422; the
        // validated value is echoed back.
        $header = LabHeaderHeaderData::fromHeaders(request());

        return new LabHeaderEchoData(token: $header->xLabToken);
    }

    // --- Stage 2: composition forms -----------------------------------------

    public function labInlineBody(LabInlineBodyRequestData $body): LabInlineBodyResponseData
    {
        // INLINE object response synthesis (#129): the op declares an inline
        // 200 object schema, so the generator now synthesizes
        // LabInlineBodyResponseData and types the abstract return as it (it used
        // to be JsonResponse). Echo the validated, hydrated request values into
        // the synthesized response Data.
        return new LabInlineBodyResponseData(title: $body->title, rank: $body->rank);
    }

    public function labFormBody(LabFormBodyRequestData $body): LabFormBodyResponseData
    {
        // application/x-www-form-urlencoded OBJECT body (#130). The generator
        // synthesizes LabFormBodyRequestData through the SAME pipeline as an
        // inline JSON object body (#76) and types this param with it. By the
        // time we run, Laravel has parsed the urlencoded body into the input
        // bag and laravel-data has validated it against the generated rules(),
        // so a spec-invalid payload 422s before reaching here. Echoing the
        // typed values back proves the urlencoded body hydrated and round-trips.
        return new LabFormBodyResponseData(label: $body->label, quantity: $body->quantity);
    }

    public function labInlineResponse(): LabInlineResponseResponseData
    {
        // INLINE object response synthesis (#129). The generator built
        // LabInlineResponseResponseData from the inline 200 object schema as the
        // READ variant: the readOnly generated_at stays, the writeOnly
        // internal_token is dropped (the class has no such property to set).
        // Returning a populated instance proves the synthesized typed return
        // serializes correctly over HTTP, symmetric with the inline request
        // body (#76) and distinct from the component $ref response (#116).
        return new LabInlineResponseResponseData(
            label: 'inline',
            generatedAt: '2026-06-13T12:00:00+00:00',
        );
    }

    public function labSharedOne(LabSharedBodyRequestData $body): LabSharedResponseResponseData
    {
        return new LabSharedResponseResponseData(sku: $body->sku, qty: $body->qty);
    }

    public function labSharedTwo(LabSharedBodyRequestData $body): LabSharedResponseResponseData
    {
        return new LabSharedResponseResponseData(sku: $body->sku, qty: $body->qty);
    }

    public function labInlineShape(LabInlineShapeData $labInlineShape): LabInlineShapeData
    {
        return $labInlineShape;
    }

    public function labInheritShape(LabVehicleBaseData $labVehicleBase): LabVehicleBaseData
    {
        return $labVehicleBase;
    }

    public function labResponseUnion(LabUnionSelectorData $labUnionSelector): LabCircleData|LabSquareData
    {
        // The response is typed as a union of two Data classes (#116). We pick a
        // concrete variant from the request so both shapes are exercised.
        return $labUnionSelector->want === 'circle'
            ? new LabCircleData(kind: 'circle', radius: 1.5)
            : new LabSquareData(kind: 'square', side: 4.0);
    }

    public function labAnyOfUnion(LabAnyOfData $labAnyOf): LabAnyOfData
    {
        return $labAnyOf;
    }

    public function labTuple(LabTupleData $labTuple): LabTupleData
    {
        return $labTuple;
    }

    // --- Stage 3: server / response surfaces --------------------------------

    public function labPlainText(): Response
    {
        // Non-JSON response (#117/#118): the generator types this as the base
        // Symfony Response and warns. The body is consumer-written.
        return new Response('lab plain text body', 200, ['Content-Type' => 'text/plain']);
    }

    public function labDownload(): Response
    {
        // Non-JSON binary download (#117/#118). Consumer-written body: a tiny
        // deterministic byte payload with an octet-stream content type.
        $bytes = "LABDOWNLOAD\x00\x01\x02";

        return new StreamedResponse(function () use ($bytes): void {
            echo $bytes;
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="lab.bin"',
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    public function labGallery(LabGalleryRequestData $body): ApiResponseData
    {
        // Multipart array-of-binary (#75 edge): $body->photos is a validated
        // list of UploadedFile (each passed file + mimetypes:image/png). We
        // count them and echo the count; a non-PNG part 422s before we get here.
        $count = count($body->photos);
        $album = $body->album !== null ? " in album {$body->album}" : '';

        return new ApiResponseData(
            code: 200,
            type: 'success',
            message: "Received {$count} photo(s){$album}.",
        );
    }

    public function labNestedBinary(LabNestedBinaryRequestData $body): ApiResponseData
    {
        // Nested-binary multipart residual (#75): the `wrapper.payload` field is
        // declared format:binary, but it sits BELOW the multipart root inside the
        // `wrapper` object. Only ROOT-level binary parts become UploadedFile; a
        // nested binary string stays a PLAIN STRING (no file handling, validated
        // with a 'string' rule, not 'file'/'mimetypes:'). So $body->wrapper->payload
        // is a string here, never an UploadedFile. We echo it back as text to
        // prove it round-trips as a plain string. A real PNG byte buffer would
        // arrive as that string verbatim, but the test sends plain text to keep
        // the assertion legible: a non-file value is accepted, which it would not
        // be for a root-level UploadedFile part.
        $payload = $body->wrapper->payload;
        $caption = $body->caption !== null ? " (caption: {$body->caption})" : '';

        return new ApiResponseData(
            code: 200,
            type: 'success',
            message: 'Nested payload echoed as a '.gettype($payload).' of length '.strlen($payload).": {$payload}{$caption}.",
        );
    }

    // --- Stage 4: #77 security middleware matrix (AND / OR / public) --------
    //
    // Each method is a pure echo reached ONLY after the route's generated
    // middleware stack has passed. The middleware is what proves the matrix;
    // the body just confirms the request got through. By the time any of these
    // run, every required guard has already returned 401 on a failed header, so
    // a 200 here means the full stamped stack admitted the request.

    public function labSecureSingle(): LabSecureEchoData
    {
        return new LabSecureEchoData(ok: true, op: 'secure-single');
    }

    public function labSecureAnd(): LabSecureEchoData
    {
        return new LabSecureEchoData(ok: true, op: 'secure-and');
    }

    public function labSecureOr(): LabSecureEchoData
    {
        return new LabSecureEchoData(ok: true, op: 'secure-or');
    }

    public function labSecurePublic(): LabSecureEchoData
    {
        return new LabSecureEchoData(ok: true, op: 'secure-public');
    }

    // --- Stage 5: documented residual pins ----------------------------------

    public function labStyles(LabStylesQueryData $query): LabStylesEchoData
    {
        // Non-standard query styles residual: the deepObject `filter` object and
        // the pipeDelimited `ids` array were SKIPPED with a warning at generation
        // time, so they are absent from LabStylesQueryData and never validated.
        // Only the supported `page` param survives, validated against rules()
        // (min:1 max:50). We echo the validated page so the test can prove the
        // skipped params do not gate the request: any garbage filter/ids value
        // still reaches a 200. A page default keeps the echo well-typed when the
        // optional param is omitted.
        return new LabStylesEchoData(page: $query->page ?? 0);
    }

    public function labCookie(): LabCookieEchoData
    {
        // Cookie-parameter residual: the spec declares a required in:cookie
        // `session_hint`, but the scaffold DROPS cookie params with a warning and
        // generates no typing or validation. The abstract method therefore takes
        // NO argument for it. We always return ok:true so the test can prove the
        // cookie is never validated: the op returns 200 regardless of (or
        // entirely without) the cookie.
        return new LabCookieEchoData(ok: true);
    }

    public function labInt64(LabInt64Data $labInt64): LabInt64Data
    {
        // int64 bounds residual: `ledger` is type:integer format:int64 with
        // min:1 max:9_000_000_000_000_000_000. Both bounds fit PHP's 64-bit int,
        // so the generator emitted real min:/max: rules (NOT a docblock-only
        // degradation). A normal in-range value round-trips; the test documents
        // that these particular bounds ARE enforced on this platform.
        return $labInt64;
    }

    public function labLooseUnion(LabLooseUnionData $labLooseUnion): LabLooseUnionData
    {
        // Undiscriminated object union residual (#31): `payload` is a oneOf of
        // two object schemas with NO discriminator, so the generator typed it as
        // `mixed` with a presence-only `required` rule. Either variant shape (or
        // a shape that belongs to neither) is ACCEPTED: there is no
        // variant-specific hydration or validation. We echo the raw payload back
        // so the test can prove it round-trips untouched.
        return $labLooseUnion;
    }

    public function labDualStatus(): JsonResponse
    {
        // Alternative-2xx pass-through residual (#64): /lab/dual-status declares
        // BOTH 200 and 202. The generator selects the smallest 2xx (200) as the
        // success status and emits NO RespondsWithStatus middleware, so a
        // controller-set status is NOT rewritten. The 200 declares no body, so
        // the return is typed JsonResponse, letting us set 202 explicitly. The
        // test pins that the controller-set 202 stays 202 (not clobbered to 200).
        return new JsonResponse(['state' => 'accepted'], 202);
    }
}
