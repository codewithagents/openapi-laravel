import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { configureClient } from './api/client-config.js'
import { App } from './App.js'
import './index.css'

const apiBase = (import.meta.env['VITE_API_BASE'] as string | undefined) ?? 'http://localhost:8088/api'

// The generated openapi-zod-ts client does not add an Accept header by default,
// so browsers send text/html which causes Laravel to return 302 redirects on
// errors instead of 422 JSON. Setting Accept: application/json here ensures the
// client always negotiates JSON responses from the API (defense in depth on top
// of the ForceJsonAccept middleware on the Laravel side).
configureClient({
  baseUrl: apiBase,
  credentials: 'omit',
  headers: { Accept: 'application/json' },
})

const rootEl = document.getElementById('root')
if (!rootEl) throw new Error('Root element not found')

createRoot(rootEl).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
