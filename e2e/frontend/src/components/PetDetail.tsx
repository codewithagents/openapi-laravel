import type { Pet } from '../api/models.js'

interface PetDetailProps {
  pet: Pet
  onClose: () => void
}

export function PetDetail({ pet, onClose }: PetDetailProps) {
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

      <p style={{ marginTop: '1rem', fontSize: '0.75rem', color: '#9ca3af' }}>
        Note: secret_note is writeOnly and is never returned by the server.
      </p>
    </div>
  )
}
