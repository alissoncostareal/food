import { ref } from 'vue'

const STORAGE_ENABLED = 'partiumenu:new-order-sound-enabled'
const STORAGE_UNLOCKED = 'partiumenu:new-order-sound-unlocked'
const REPEAT_INTERVAL_MS = 15000

const enabled = ref(localStorage.getItem(STORAGE_ENABLED) !== 'false')
let audioContext = null
let repeatTimer = null
let pendingCount = 0
let unlockPersisted = localStorage.getItem(STORAGE_UNLOCKED) === 'true'
let userGestureReceived = unlockPersisted
let unlockPersistHandler = null

const markUserGesture = () => {
  userGestureReceived = true
}

const persistUnlockFlag = () => {
  localStorage.setItem(STORAGE_UNLOCKED, 'true')

  if (!unlockPersisted) {
    unlockPersisted = true
    unlockPersistHandler?.()
  }
}

const ensureAudioContext = async () => {
  try {
    if (!userGestureReceived && !unlockPersisted) {
      return false
    }

    const AudioContextClass = window.AudioContext || window.webkitAudioContext
    if (!AudioContextClass) return false

    if (!audioContext) {
      audioContext = new AudioContextClass()
    }

    if (audioContext.state === 'suspended') {
      await audioContext.resume()
    }

    if (audioContext.state === 'running') {
      persistUnlockFlag()
      return true
    }

    return false
  } catch {
    return false
  }
}

const connectTone = (ctx, start, duration) => {
  const filter = ctx.createBiquadFilter()
  const gain = ctx.createGain()

  filter.type = 'lowpass'
  filter.frequency.setValueAtTime(2800, start)
  filter.Q.value = 0.7

  gain.gain.setValueAtTime(0.0001, start)
  gain.gain.exponentialRampToValueAtTime(0.38, start + 0.018)
  gain.gain.exponentialRampToValueAtTime(0.0001, start + duration)

  gain.connect(filter)
  filter.connect(ctx.destination)

  return { gain, filter }
}

const playTrillTone = (ctx, start, freq, duration, vibratoHz = 14) => {
  const osc = ctx.createOscillator()
  const lfo = ctx.createOscillator()
  const lfoGain = ctx.createGain()
  const { gain } = connectTone(ctx, start, duration)

  osc.type = 'sine'
  osc.frequency.setValueAtTime(freq, start)

  lfo.type = 'sine'
  lfo.frequency.setValueAtTime(vibratoHz, start)
  lfoGain.gain.setValueAtTime(18, start)

  lfo.connect(lfoGain)
  lfoGain.connect(osc.frequency)

  osc.connect(gain)
  osc.start(start)
  osc.stop(start + duration + 0.02)
  lfo.start(start)
  lfo.stop(start + duration + 0.02)
}

const playBellTap = (ctx, start, freq, duration = 0.11) => {
  const osc = ctx.createOscillator()
  const { gain } = connectTone(ctx, start, duration)

  osc.type = 'sine'
  osc.frequency.setValueAtTime(freq, start)

  osc.connect(gain)
  osc.start(start)
  osc.stop(start + duration + 0.02)
}

const playChimeInternal = () => {
  if (!audioContext || audioContext.state !== 'running') return

  const ctx = audioContext
  const t = ctx.currentTime

  playTrillTone(ctx, t, 740, 0.42, 13)
  playBellTap(ctx, t + 0.52, 880)
  playBellTap(ctx, t + 0.66, 880)
  playTrillTone(ctx, t + 0.82, 988, 0.28, 16)
}

const stopRepeat = () => {
  if (repeatTimer) {
    clearInterval(repeatTimer)
    repeatTimer = null
  }
}

const startRepeatIfNeeded = () => {
  stopRepeat()

  if (!enabled.value || pendingCount <= 0) {
    return
  }

  repeatTimer = setInterval(async () => {
    if (!enabled.value || pendingCount <= 0) {
      stopRepeat()
      return
    }

    await playChime()
  }, REPEAT_INTERVAL_MS)
}

const playChime = async () => {
  if (!enabled.value) return false

  const ok = await ensureAudioContext()
  if (!ok) return false

  try {
    playChimeInternal()
    return true
  } catch (error) {
    console.warn('[NewOrderAlert] Play failed', error)
    return false
  }
}

export function useNewOrderAlert(onUnlockedPersist) {
  if (onUnlockedPersist) {
    unlockPersistHandler = onUnlockedPersist
  }

  const syncPendingCount = (count) => {
    pendingCount = Math.max(0, Number(count) || 0)

    if (pendingCount <= 0) {
      stopRepeat()
      return
    }

    if (enabled.value) {
      startRepeatIfNeeded()
    }
  }

  const notifyNewOrder = async () => {
    if (!enabled.value) return

    await playChime()
    startRepeatIfNeeded()
  }

  const setEnabled = (value) => {
    enabled.value = value
    localStorage.setItem(STORAGE_ENABLED, value ? 'true' : 'false')

    if (!value) {
      stopRepeat()
    } else if (pendingCount > 0) {
      startRepeatIfNeeded()
    }
  }

  const applyPreferences = (prefs = {}) => {
    enabled.value = prefs.new_order_sound_enabled !== false
    localStorage.setItem(STORAGE_ENABLED, enabled.value ? 'true' : 'false')

    if (prefs.new_order_sound_unlocked) {
      unlockPersisted = true
      localStorage.setItem(STORAGE_UNLOCKED, 'true')
    }

    if (!enabled.value) {
      stopRepeat()
    } else if (pendingCount > 0) {
      startRepeatIfNeeded()
    }
  }

  return {
    enabled,
    markUserGesture,
    ensureAudioContext,
    playChime,
    notifyNewOrder,
    syncPendingCount,
    getPendingCount: () => pendingCount,
    stopRepeat,
    setEnabled,
    applyPreferences
  }
}
