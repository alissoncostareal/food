import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'
import PrivacyPage from './pages/PrivacyPage.jsx'
import DataDeletionPage from './pages/DataDeletionPage.jsx'
import SeoLandingPage from './pages/SeoLandingPage.jsx'
import { getSeoPageByPath } from './lib/seoPages.js'

const LEGAL_PAGES = {
  '/privacidade': PrivacyPage,
  '/exclusao-de-dados': DataDeletionPage,
}

const pathname = window.location.pathname.replace(/\/$/, '') || '/'
const seoPage = getSeoPageByPath(pathname)
const Page = LEGAL_PAGES[pathname] || (seoPage ? () => <SeoLandingPage page={seoPage} /> : App)

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <Page />
  </StrictMode>,
)
