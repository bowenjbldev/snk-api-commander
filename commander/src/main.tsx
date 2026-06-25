import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './styles/index.scss' // Carbon (heavy; rarely edited)
// @carbon/ibm-products styles for CreateSidePanel — released components only, Carbon NOT re-imported
// (index.scss already loaded it). After the Carbon base, before our token overrides.
import '@carbon/ibm-products/css/index-without-carbon-released-only.min.css'
import './styles/tokens.scss' // app tokens/utilities (light; edited often → fast HMR)
import App from './App.tsx'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
