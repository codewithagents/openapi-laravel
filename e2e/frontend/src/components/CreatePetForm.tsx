import { useState } from 'react'
import { addPet } from '../api/client.js'
import { ApiError } from '../api/client.js'
import type { PetWritable } from '../api/models.js'

interface FieldErrors {
  name?: string
  status?: string
  photoUrls?: string
  microchip_id?: string
  weight_kg?: string
  external_id?: string
  secret_note?: string
  attributes?: string
  [key: string]: string | undefined
}

/**
 * Parse a Laravel 422 validation error response.
 * Laravel returns: { message: string, errors: { field: string[] } }
 * We flatten to { field: first_message }.
 */
function parseLaravel422(body: unknown): FieldErrors {
  if (
    body != null &&
    typeof body === 'object' &&
    'errors' in body &&
    body.errors != null &&
    typeof body.errors === 'object'
  ) {
    const result: FieldErrors = {}
    for (const [key, messages] of Object.entries(body.errors as Record<string, unknown>)) {
      if (Array.isArray(messages) && messages.length > 0) {
        result[key] = String(messages[0])
      }
    }
    return result
  }
  return {}
}

function parseValidationErrors(error: unknown): FieldErrors {
  if (error instanceof ApiError && error.status === 422) {
    return parseLaravel422(error.body)
  }
  return {}
}

interface AttributePair {
  key: string
  value: string
}

interface CreatePetFormProps {
  onCreated: () => void
}

export function CreatePetForm({ onCreated }: CreatePetFormProps) {
  const [name, setName] = useState('')
  const [status, setStatus] = useState<'available' | 'pending' | 'sold'>('available')
  const [photoUrl, setPhotoUrl] = useState('')
  const [microchipId, setMicrochipId] = useState('')
  const [weightKg, setWeightKg] = useState('')
  const [externalId, setExternalId] = useState('')
  const [secretNote, setSecretNote] = useState('')
  const [attributes, setAttributes] = useState<AttributePair[]>([{ key: '', value: '' }])
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [globalError, setGlobalError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const addAttributeRow = () => {
    setAttributes((prev) => [...prev, { key: '', value: '' }])
  }

  const removeAttributeRow = (index: number) => {
    setAttributes((prev) => prev.filter((_, i) => i !== index))
  }

  const updateAttributeKey = (index: number, key: string) => {
    setAttributes((prev) =>
      prev.map((pair, i) => (i === index ? { ...pair, key } : pair)),
    )
  }

  const updateAttributeValue = (index: number, value: string) => {
    setAttributes((prev) =>
      prev.map((pair, i) => (i === index ? { ...pair, value } : pair)),
    )
  }

  const buildAttributesMap = (): Record<string, string> | undefined => {
    const filled = attributes.filter((p) => p.key.trim() !== '')
    if (filled.length === 0) return undefined
    const result: Record<string, string> = {}
    for (const pair of filled) {
      result[pair.key.trim()] = pair.value
    }
    return result
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setFieldErrors({})
    setGlobalError('')
    setSubmitting(true)

    const photoUrls = photoUrl.trim() ? [photoUrl.trim()] : []

    // external_id: try numeric first, then string
    let externalIdValue: string | number | undefined = undefined
    if (externalId.trim() !== '') {
      const asNumber = Number(externalId.trim())
      externalIdValue = Number.isNaN(asNumber) ? externalId.trim() : asNumber
    }

    const body: PetWritable = {
      name: name.trim(),
      photoUrls,
      status,
      ...(microchipId.trim() ? { microchip_id: microchipId.trim() } : {}),
      ...(weightKg.trim() !== ''
        ? { weight_kg: weightKg.trim() === 'null' ? null : Number(weightKg) }
        : {}),
      ...(externalIdValue !== undefined ? { external_id: externalIdValue } : {}),
      ...(secretNote.trim() ? { secret_note: secretNote.trim() } : {}),
      ...(buildAttributesMap() !== undefined
        ? { attributes: buildAttributesMap() }
        : {}),
    }

    try {
      await addPet(body)
      setName('')
      setStatus('available')
      setPhotoUrl('')
      setMicrochipId('')
      setWeightKg('')
      setExternalId('')
      setSecretNote('')
      setAttributes([{ key: '', value: '' }])
      onCreated()
    } catch (err: unknown) {
      const errors = parseValidationErrors(err)
      if (Object.keys(errors).length > 0) {
        setFieldErrors(errors)
      } else if (err instanceof Error) {
        setGlobalError(err.message)
      } else {
        setGlobalError('An unexpected error occurred.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="card">
      <h2>Create Pet</h2>

      {globalError && (
        <div className="alert error" data-testid="error-global">
          {globalError}
        </div>
      )}

      <form onSubmit={(e) => { void handleSubmit(e) }} data-testid="create-form">
        <div className="field">
          <label htmlFor="field-name">Name *</label>
          <input
            id="field-name"
            data-testid="field-name"
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="e.g. Doggo"
          />
          {fieldErrors['name'] && (
            <p className="error-text" data-testid="error-name">{fieldErrors['name']}</p>
          )}
        </div>

        <div className="field">
          <label htmlFor="field-status">Status</label>
          <select
            id="field-status"
            data-testid="field-status"
            value={status}
            onChange={(e) => setStatus(e.target.value as 'available' | 'pending' | 'sold')}
          >
            <option value="available">available</option>
            <option value="pending">pending</option>
            <option value="sold">sold</option>
          </select>
          {fieldErrors['status'] && (
            <p className="error-text" data-testid="error-status">{fieldErrors['status']}</p>
          )}
        </div>

        <div className="field">
          <label htmlFor="field-photo-url">Photo URL</label>
          <input
            id="field-photo-url"
            data-testid="field-photo-url"
            type="text"
            value={photoUrl}
            onChange={(e) => setPhotoUrl(e.target.value)}
            placeholder="https://example.com/photo.jpg"
          />
          {fieldErrors['photoUrls'] && (
            <p className="error-text" data-testid="error-photo-urls">{fieldErrors['photoUrls']}</p>
          )}
        </div>

        <div className="field">
          <label htmlFor="field-microchip-id">Microchip ID</label>
          <input
            id="field-microchip-id"
            data-testid="field-microchip-id"
            type="text"
            value={microchipId}
            onChange={(e) => setMicrochipId(e.target.value)}
            placeholder="chip-abc-123"
          />
          {fieldErrors['microchip_id'] && (
            <p className="error-text" data-testid="error-microchip-id">{fieldErrors['microchip_id']}</p>
          )}
        </div>

        <div className="field">
          <label htmlFor="field-weight-kg">Weight (kg) - enter "null" for explicit null</label>
          <input
            id="field-weight-kg"
            data-testid="field-weight-kg"
            type="text"
            value={weightKg}
            onChange={(e) => setWeightKg(e.target.value)}
            placeholder="e.g. 12.5 or null"
          />
          {fieldErrors['weight_kg'] && (
            <p className="error-text" data-testid="error-weight-kg">{fieldErrors['weight_kg']}</p>
          )}
        </div>

        <div className="field">
          <label htmlFor="field-external-id">External ID (string or number)</label>
          <input
            id="field-external-id"
            data-testid="field-external-id"
            type="text"
            value={externalId}
            onChange={(e) => setExternalId(e.target.value)}
            placeholder="e.g. 42 or ext-abc-123"
          />
          {fieldErrors['external_id'] && (
            <p className="error-text" data-testid="error-external-id">{fieldErrors['external_id']}</p>
          )}
        </div>

        <div className="field">
          <label htmlFor="field-secret-note">Secret Note (writeOnly - not returned in reads)</label>
          <input
            id="field-secret-note"
            data-testid="field-secret-note"
            type="text"
            value={secretNote}
            onChange={(e) => setSecretNote(e.target.value)}
            placeholder="Internal note..."
          />
          {fieldErrors['secret_note'] && (
            <p className="error-text" data-testid="error-secret-note">{fieldErrors['secret_note']}</p>
          )}
        </div>

        <div className="field">
          <label>Attributes (key/value pairs)</label>
          {attributes.map((pair, index) => (
            <div
              key={index}
              className="field-row"
              style={{ marginBottom: '0.25rem' }}
              data-testid={`attribute-row-${index}`}
            >
              <input
                data-testid={`attribute-key-${index}`}
                type="text"
                value={pair.key}
                onChange={(e) => updateAttributeKey(index, e.target.value)}
                placeholder="key"
              />
              <input
                data-testid={`attribute-value-${index}`}
                type="text"
                value={pair.value}
                onChange={(e) => updateAttributeValue(index, e.target.value)}
                placeholder="value"
              />
              <button
                type="button"
                data-testid={`attribute-remove-${index}`}
                onClick={() => removeAttributeRow(index)}
              >
                -
              </button>
            </div>
          ))}
          <button
            type="button"
            data-testid="attribute-add"
            onClick={addAttributeRow}
            style={{ marginTop: '0.25rem' }}
          >
            + Add Attribute
          </button>
          {fieldErrors['attributes'] && (
            <p className="error-text" data-testid="error-attributes">{fieldErrors['attributes']}</p>
          )}
        </div>

        <button
          type="submit"
          className="primary"
          data-testid="submit"
          disabled={submitting}
        >
          {submitting ? 'Creating...' : 'Create Pet'}
        </button>
      </form>
    </div>
  )
}
