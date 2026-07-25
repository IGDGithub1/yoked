import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './styles/tokens.css'
import './styles/base.css'
import './styles/quiz.css'
import App from './App'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>
)

/*
 * The service worker is a progressive enhancement — it makes the app installable
 * and survives a dropped connection, but nothing depends on it. Registered after
 * render and failing silently, so a browser that refuses it (or the Vite dev
 * server, where it is not served) costs the user nothing.
 *
 * Registered as /sw.php, not /sw.js, and that is deliberate. SiteGround's nginx
 * serves .js straight off disk with a one-year Expires and never consults
 * Apache, so a /sw.js worker would be pinned for a year with no way to recall
 * it — and the worker is what controls every other cache. nginx does proxy
 * .php, so sw.php sets its own no-cache headers plus Service-Worker-Allowed: /
 * (needed for root scope). Verified with curl -I; see public_html/sw.php.
 */
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.php', { scope: '/' }).catch(() => {})
  })
}
