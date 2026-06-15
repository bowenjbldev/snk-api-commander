import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import '@carbon/charts-react/styles.css'
import './styles/index.scss' // Carbon (heavy; rarely edited)
import './styles/tokens.scss' // app tokens/utilities (light; edited often → fast HMR)
import App from './App.tsx'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
