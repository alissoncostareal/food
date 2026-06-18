import { ref, onMounted, onBeforeUnmount } from 'vue'

const DISMISS_KEY = 'partiumenu_pwa_install_dismissed'
const DISMISS_DAYS = 14

const canInstall = ref(false)
const isInstalled = ref(false)
const isIosSafari = ref(false)
const isMacSafari = ref(false)

let deferredPrompt = null
let listenersRegistered = false
let mountCount = 0

function isStandalone() {
  if (typeof window === 'undefined') return false

  return (
    window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true
  )
}

function detectBrowser() {
  const ua = window.navigator.userAgent
  isIosSafari.value = /iPad|iPhone|iPod/.test(ua) && !window.MSStream
  isMacSafari.value =
    /Macintosh/.test(ua) && /Safari/.test(ua) && !/Chrome|Chromium|Edg|OPR|Firefox/.test(ua)
}

function handleBeforeInstall(event) {
  event.preventDefault()
  deferredPrompt = event
  canInstall.value = true
}

function handleAppInstalled() {
  deferredPrompt = null
  canInstall.value = false
  isInstalled.value = true
}

function registerListeners() {
  if (listenersRegistered || typeof window === 'undefined') return

  listenersRegistered = true
  detectBrowser()
  window.addEventListener('beforeinstallprompt', handleBeforeInstall)
  window.addEventListener('appinstalled', handleAppInstalled)
}

export function wasPwaInstallDismissedRecently() {
  try {
    const raw = localStorage.getItem(DISMISS_KEY)
    if (!raw) return false

    const dismissedAt = Number(raw)
    if (!Number.isFinite(dismissedAt)) return false

    const ms = DISMISS_DAYS * 24 * 60 * 60 * 1000
    return Date.now() - dismissedAt < ms
  } catch {
    return false
  }
}

export function usePwaInstall() {
  onMounted(() => {
    mountCount += 1
    isInstalled.value = isStandalone()
    registerListeners()
  })

  onBeforeUnmount(() => {
    mountCount = Math.max(0, mountCount - 1)
  })

  const install = async () => {
    if (!deferredPrompt) return false

    deferredPrompt.prompt()
    const { outcome } = await deferredPrompt.userChoice
    deferredPrompt = null
    canInstall.value = false

    if (outcome === 'accepted') {
      isInstalled.value = true
      return true
    }

    return false
  }

  const dismiss = () => {
    canInstall.value = false

    try {
      localStorage.setItem(DISMISS_KEY, String(Date.now()))
    } catch {
      // ignore
    }
  }

  return {
    canInstall,
    isInstalled,
    isIosSafari,
    isMacSafari,
    install,
    dismiss,
  }
}
