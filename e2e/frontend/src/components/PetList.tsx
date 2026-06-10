import { useState, useEffect, useCallback } from 'react'
import { findPetsByStatus, deletePet, getPetById } from '../api/client.js'
import { ApiError } from '../api/client.js'
import type { Pet } from '../api/models.js'
import { PetDetail } from './PetDetail.js'

type StatusFilter = 'available' | 'pending' | 'sold'

interface PetListProps {
  /** Increment this to trigger a refresh of the pet list. */
  refreshToken: number
}

export function PetList({ refreshToken }: PetListProps) {
  const [pets, setPets] = useState<Pet[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('available')
  const [selectedPet, setSelectedPet] = useState<Pet | null>(null)
  const [deleteError, setDeleteError] = useState('')

  const loadPets = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const result = await findPetsByStatus({ status: statusFilter })
      setPets(result)
    } catch (err) {
      if (err instanceof ApiError) {
        setError(`API error ${err.status}`)
      } else if (err instanceof Error) {
        setError(err.message)
      } else {
        setError('Failed to load pets.')
      }
    } finally {
      setLoading(false)
    }
  }, [statusFilter])

  useEffect(() => {
    void loadPets()
  }, [loadPets, refreshToken])

  const handleDelete = async (pet: Pet) => {
    setDeleteError('')
    try {
      await deletePet(String(pet.id ?? ''))
      setPets((prev) => prev.filter((p) => p.id !== pet.id))
      if (selectedPet?.id === pet.id) setSelectedPet(null)
    } catch (err) {
      if (err instanceof ApiError) {
        setDeleteError(`Delete failed: API error ${err.status}`)
      } else if (err instanceof Error) {
        setDeleteError(`Delete failed: ${err.message}`)
      } else {
        setDeleteError('Delete failed.')
      }
    }
  }

  const handleView = async (pet: Pet) => {
    try {
      const detail = await getPetById(String(pet.id ?? ''))
      setSelectedPet(detail)
    } catch (err) {
      if (err instanceof ApiError) {
        setError(`Load detail failed: API error ${err.status}`)
      } else if (err instanceof Error) {
        setError(err.message)
      } else {
        setError('Failed to load pet detail.')
      }
    }
  }

  return (
    <div>
      <div className="card">
        <h2>Pets</h2>

        <div className="status-filter" data-testid="status-filter">
          {(['available', 'pending', 'sold'] as StatusFilter[]).map((s) => (
            <button
              key={s}
              className={statusFilter === s ? 'active' : ''}
              data-testid={`filter-${s}`}
              onClick={() => setStatusFilter(s)}
            >
              {s}
            </button>
          ))}
        </div>

        {error && (
          <div className="alert error" data-testid="list-error">{error}</div>
        )}

        {deleteError && (
          <div className="alert error" data-testid="delete-error">{deleteError}</div>
        )}

        {loading && <p data-testid="list-loading">Loading...</p>}

        {!loading && pets.length === 0 && !error && (
          <p data-testid="empty-state">No pets with status "{statusFilter}".</p>
        )}

        {!loading && pets.length > 0 && (
          <table className="pet-table" data-testid="pet-list">
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Status</th>
                <th>Microchip</th>
                <th>Weight</th>
                <th>External ID</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {pets.map((pet) => (
                <tr key={String(pet.id ?? '')} data-testid="pet-row">
                  <td data-testid="pet-id">{String(pet.id ?? '')}</td>
                  <td data-testid="pet-name">{pet.name}</td>
                  <td>
                    <span className={`tag ${pet.status ?? ''}`} data-testid="pet-status">
                      {pet.status ?? 'unknown'}
                    </span>
                  </td>
                  <td data-testid="pet-microchip-id">{pet.microchip_id ?? ''}</td>
                  <td data-testid="pet-weight-kg">
                    {pet.weight_kg != null ? String(pet.weight_kg) : 'null'}
                  </td>
                  <td data-testid="pet-external-id">
                    {pet.external_id != null ? String(pet.external_id) : ''}
                  </td>
                  <td>
                    <button
                      data-testid="view-btn"
                      onClick={() => { void handleView(pet) }}
                      style={{ marginRight: '0.5rem' }}
                    >
                      View
                    </button>
                    <button
                      data-testid="delete-btn"
                      className="danger"
                      onClick={() => { void handleDelete(pet) }}
                    >
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {selectedPet && (
        <PetDetail pet={selectedPet} onClose={() => setSelectedPet(null)} />
      )}
    </div>
  )
}
