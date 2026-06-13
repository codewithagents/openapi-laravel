import { useState } from 'react'
import type { Pet } from '../api/models.js'
import { uploadFile, ApiError } from '../api/client.js'

// The demo API key the backend's ApiKey middleware expects on the upload route
// (the spec's pet_upload_key scheme, header X-API-Key). Must match
// PET_UPLOAD_API_KEY in the backend env. Demo only: a real app would never
// hardcode a key in the client bundle.
const UPLOAD_API_KEY = 'demo-upload-key'

interface PetDetailProps {
  pet: Pet
  /** Called after a successful upload so the parent can refresh the pet. */
  onUploaded?: (petId: bigint) => void
  onClose: () => void
}

export function PetDetail({ pet, onUploaded, onClose }: PetDetailProps) {
  const [file, setFile] = useState<File | null>(null)
  const [caption, setCaption] = useState('')
  const [uploadStatus, setUploadStatus] = useState('')
  const [uploadError, setUploadError] = useState('')

  const handleUpload = async () => {
    setUploadStatus('')
    setUploadError('')
    if (!file) {
      setUploadError('Choose a file first.')
      return
    }
    try {
      // The generated client builds the multipart FormData. We pass the API key
      // via per-call config: the generated _requestForm forwards it as the
      // X-API-Key header (the spec's pet_upload_key scheme).
      const result = await uploadFile(
        String(pet.id ?? ''),
        { image: file, caption: caption || undefined },
        undefined,
        { apiKey: UPLOAD_API_KEY },
      )
      setUploadStatus(result.message ?? 'Uploaded.')
      if (pet.id != null) onUploaded?.(pet.id)
    } catch (err) {
      if (err instanceof ApiError) {
        setUploadError(`Upload failed: API error ${err.status}`)
      } else if (err instanceof Error) {
        setUploadError(`Upload failed: ${err.message}`)
      } else {
        setUploadError('Upload failed.')
      }
    }
  }

  return (
    <div className="detail-panel" data-testid="pet-detail">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
        <h2>Pet Detail</h2>
        <button onClick={onClose} data-testid="detail-close">Close</button>
      </div>

      <div className="detail-row">
        <span className="detail-label">ID</span>
        <span className="detail-value" data-testid="detail-id">{String(pet.id ?? '')}</span>
      </div>

      <div className="detail-row">
        <span className="detail-label">Name</span>
        <span className="detail-value" data-testid="detail-name">{pet.name}</span>
      </div>

      <div className="detail-row">
        <span className="detail-label">Status</span>
        <span
          className={`tag ${pet.status ?? ''}`}
          data-testid="detail-status"
        >
          {pet.status ?? 'unknown'}
        </span>
      </div>

      <div className="detail-row">
        <span className="detail-label">Photo URLs</span>
        <span className="detail-value" data-testid="detail-photo-urls">
          {pet.photoUrls.length > 0 ? pet.photoUrls.join(', ') : 'none'}
        </span>
      </div>

      <div className="detail-row">
        <span className="detail-label">Category</span>
        <span className="detail-value" data-testid="detail-category">
          {pet.category?.name ?? 'none'}
        </span>
      </div>

      <div className="detail-row">
        <span className="detail-label">Microchip ID</span>
        <span className="detail-value" data-testid="detail-microchip-id">
          {pet.microchip_id ?? 'none'}
        </span>
      </div>

      <div className="detail-row">
        <span className="detail-label">Weight (kg)</span>
        <span className="detail-value" data-testid="detail-weight-kg">
          {pet.weight_kg != null ? String(pet.weight_kg) : 'null'}
        </span>
      </div>

      <div className="detail-row">
        <span className="detail-label">External ID</span>
        <span className="detail-value" data-testid="detail-external-id">
          {pet.external_id != null ? String(pet.external_id) : 'none'}
        </span>
      </div>

      <div className="detail-row">
        <span className="detail-label">Attributes</span>
        <span className="detail-value" data-testid="detail-attributes">
          {pet.attributes && Object.keys(pet.attributes).length > 0
            ? Object.entries(pet.attributes)
                .map(([k, v]) => `${k}=${v}`)
                .join(', ')
            : 'none'}
        </span>
      </div>

      <div className="detail-row">
        <span className="detail-label">Created At</span>
        <span className="detail-value" data-testid="detail-created-at">
          {pet.created_at ?? 'none'}
        </span>
      </div>

      <div className="detail-row">
        <span className="detail-label">Tags</span>
        <span className="detail-value" data-testid="detail-tags">
          {pet.tags && pet.tags.length > 0
            ? pet.tags.map((t) => t.name ?? '').filter(Boolean).join(', ')
            : 'none'}
        </span>
      </div>

      <div className="detail-row" style={{ flexDirection: 'column', alignItems: 'stretch', gap: '0.5rem' }}>
        <span className="detail-label">Upload Photo (multipart/form-data)</span>
        <input
          type="file"
          accept="image/png"
          data-testid="upload-file-input"
          onChange={(e) => setFile(e.target.files?.[0] ?? null)}
        />
        <input
          type="text"
          placeholder="Optional caption"
          value={caption}
          data-testid="upload-caption-input"
          onChange={(e) => setCaption(e.target.value)}
        />
        <button data-testid="upload-submit" onClick={() => { void handleUpload() }}>
          Upload
        </button>
        {uploadStatus && (
          <span className="detail-value" data-testid="upload-status">{uploadStatus}</span>
        )}
        {uploadError && (
          <span className="alert error" data-testid="upload-error">{uploadError}</span>
        )}
      </div>

      <p style={{ marginTop: '1rem', fontSize: '0.75rem', color: '#9ca3af' }}>
        Note: secret_note is writeOnly and is never returned by the server.
      </p>
    </div>
  )
}
