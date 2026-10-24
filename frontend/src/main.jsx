import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
// Bootstrap JS (ESM) registers the data-API for dropdowns, modals, collapse, etc.
import 'bootstrap'
import './styles/main.css'
import App from './App.jsx'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
