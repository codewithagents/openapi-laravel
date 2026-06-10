import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { configureClient } from './api/client-config.js'
import { App } from './App.js'
import './index.css'

const apiBase = (import.meta.env['VITE_API_BASE'] as string | undefined) ?? 'http://localhost:8088/api'

configureClient({
  baseUrl: apiBase,
  credentials: 'omit',
})

const rootEl = document.getElementById('root')
if (!rootEl) throw new Error('Root element not found')

createRoot(rootEl).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
