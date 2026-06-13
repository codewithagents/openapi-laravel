<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Lab\LabAllOfData;
use App\Data\Lab\LabArrayData;
use App\Data\Lab\LabBackedEnumData;
use App\Data\Lab\LabClosedData;
use App\Data\Lab\LabEnumConstData;
use App\Data\Lab\LabFormatsData;
use App\Data\Lab\LabMapData;
use App\Data\Lab\LabNestedData;
use App\Data\Lab\LabNumericData;
use App\Data\Lab\LabPresenceData;
use App\Data\Lab\LabShapeData;
use App\Data\Lab\LabStringData;
use App\Data\Lab\LabUnionData;
use App\Data\LabRefPayloadData;

/**
 * Hand-written concrete controller for the stateless validation/serialization
 * lab. It extends the GENERATED AbstractLabController.
 *
 * Every method is a pure echo: by the time it runs, the framework has already
 * hydrated and validated the typed Data argument against the generated
 * rules(), so a spec-invalid payload 422s before reaching the body. Returning
 * the same Data object back proves the serialization side of the round trip
 * (MapName, maps, unions, nested shapes, enums, defaults). No persistence is
 * needed, so this controller touches no PetStore.
 *
 * The 202 endpoint (labAccepted) returns a Data object as usual; the generated
 * route carries RespondsWithStatus:202, which rewrites the framework's default
 * 200 into the spec-declared 202 on the way out.
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
}
