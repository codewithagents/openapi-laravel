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
use App\Data\Lab\LabEnumConstData;
use App\Data\Lab\LabFormatsData;
use App\Data\Lab\LabGalleryRequestData;
use App\Data\Lab\LabHeaderEchoData;
use App\Data\Lab\LabHeaderHeaderData;
use App\Data\Lab\LabInlineBodyRequestData;
use App\Data\Lab\LabInlineShapeData;
use App\Data\Lab\LabMapData;
use App\Data\Lab\LabNestedData;
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
use App\Data\Lab\LabTraitCheckData;
use App\Data\Lab\LabTupleData;
use App\Data\Lab\LabUnionData;
use App\Data\Lab\LabUnionSelectorData;
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

    public function labUnion(LabUnionData $labUnion): LabUnionData
    {
        return $labUnion;
    }

    public function labNested(LabNestedData $labNested): LabNestedData
    {
        return $labNested;
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

    public function labInlineBody(LabInlineBodyRequestData $body): JsonResponse
    {
        // The generated abstract returns JsonResponse for this inline-object
        // body op. Echo the validated, hydrated values.
        return new JsonResponse($body->toArray());
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
}
