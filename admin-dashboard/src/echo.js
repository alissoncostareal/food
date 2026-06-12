import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Pusher = Pusher

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL || import.meta.env.VITE_API_URL || 'http://localhost:8000')
  .replace(/\/api\/v1\/?$/, '')
  .replace(/\/$/, '')

const broadcastAuthEndpoint = import.meta.env.VITE_BROADCAST_AUTH_ENDPOINT
  || `${apiBaseUrl}/api/broadcasting/auth`

const createEcho = (token) => {
  const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY
  const pusherCluster = import.meta.env.VITE_PUSHER_APP_CLUSTER

  if (!pusherKey || !pusherCluster) {
    console.warn('[Echo] VITE_PUSHER_APP_KEY ou VITE_PUSHER_APP_CLUSTER não configurados.')
    return null
  }

  return new Echo({
    broadcaster: 'pusher',
    key: pusherKey,
    cluster: pusherCluster,
    forceTLS: (import.meta.env.VITE_PUSHER_SCHEME || 'https') === 'https',
    encrypted: true,
    wsHost: import.meta.env.VITE_PUSHER_HOST || undefined,
    wsPort: import.meta.env.VITE_PUSHER_PORT ? Number(import.meta.env.VITE_PUSHER_PORT) : undefined,
    wssPort: import.meta.env.VITE_PUSHER_PORT ? Number(import.meta.env.VITE_PUSHER_PORT) : undefined,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: broadcastAuthEndpoint,
    auth: {
      headers: {
        Authorization: token ? `Bearer ${token}` : '',
        Accept: 'application/json'
      }
    }
  })
}

const disconnectEcho = () => {
  if (window.Echo?.disconnect) {
    window.Echo.disconnect()
  }

  window.Echo = null
  window.__PARTIUMENU_ECHO_TOKEN__ = null
}

const initializeEcho = ({ force = false } = {}) => {
  const token = localStorage.getItem('auth_token')

  if (!token) {
    disconnectEcho()
    return null
  }

  if (window.Echo && window.__PARTIUMENU_ECHO_TOKEN__ === token && !force) {
    return window.Echo
  }

  disconnectEcho()

  window.Echo = createEcho(token)

  if (window.Echo) {
    window.__PARTIUMENU_ECHO_TOKEN__ = token
  }

  return window.Echo
}

window.PartiuMenuEcho = {
  initialize: initializeEcho,
  disconnect: disconnectEcho
}

window.Echo = initializeEcho()

export default window.Echo
