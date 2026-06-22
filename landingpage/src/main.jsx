import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'
import PrivacyPage from './pages/PrivacyPage.jsx'

const isPrivacyRoute = /^\/privacidade\/?$/.test(window.location.pathname)

createRoot(document.getElementById('root')).render(
  <StrictMode>
    {isPrivacyRoute ? <PrivacyPage /> : <App />}
  </StrictMode>,
)
