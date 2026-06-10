import { useState } from 'react'
import { PetList } from './components/PetList.js'
import { CreatePetForm } from './components/CreatePetForm.js'

/**
 * Root application component for the Petstore demo.
 * Exercises the generated openapi-zod-ts client against the
 * Laravel backend generated from e2e/spec/petstore.yaml.
 */
export function App() {
  // Increment to force PetList to refresh after a create.
  const [refreshToken, setRefreshToken] = useState(0)

  const handlePetCreated = () => {
    setRefreshToken((prev) => prev + 1)
  }

  return (
    <div>
      <h1>Petstore Demo</h1>
      <p style={{ marginBottom: '1.5rem', color: '#6b7280', fontSize: '0.875rem' }}>
        Contract-first e2e demo. TypeScript client generated from{' '}
        <code>e2e/spec/petstore.yaml</code> via{' '}
        <code>openapi-zod-ts@1.0.1</code>.
      </p>

      <div className="layout">
        <div>
          <CreatePetForm onCreated={handlePetCreated} />
        </div>
        <div>
          <PetList refreshToken={refreshToken} />
        </div>
      </div>
    </div>
  )
}
