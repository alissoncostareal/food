import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { storeHasPlanFeature } from '@/constants/planFeatures'
import { syncUserSession } from '@/utils/authSession'
import { setModuleMaintenanceState } from '@/state/moduleMaintenanceState'

let cachedUser = null
let inflight = null
let cachedToken = null

export function setCachedUser(user) {
  cachedUser = user
}

export function clearCachedUser() {
  cachedUser = null
  inflight = null
  cachedToken = null
}

export async function fetchCurrentUser({ force = false } = {}) {
  const token = localStorage.getItem('auth_token')

  if (!token) {
    clearCachedUser()
    throw new Error('Sessão não encontrada')
  }

  if (cachedToken && cachedToken !== token) {
    clearCachedUser()
  }

  cachedToken = token

  if (!force && cachedUser) {
    return cachedUser
  }

  if (!force && inflight) {
    return inflight
  }

  inflight = api
    .get('/me')
    .then(({ data }) => {
      cachedUser = data
      syncUserSession(data)
      setModuleMaintenanceState(data?.module_maintenance)
      return data
    })
    .finally(() => {
      inflight = null
    })

  return inflight
}

function resolveFeatureAccess(user, featureKey) {
  if (!featureKey) {
    return 'unlocked'
  }

  return storeHasPlanFeature(user?.store, featureKey) ? 'unlocked' : 'locked'
}

export function useFeatureAccess(featureKey) {
  const status = ref('loading')

  const applyUser = (user) => {
    status.value = resolveFeatureAccess(user, featureKey)
  }

  const refresh = async ({ force = false } = {}) => {
    if (!cachedUser || force) {
      status.value = 'loading'
    }

    try {
      const user = await fetchCurrentUser({ force })
      applyUser(user)
    } catch {
      status.value = 'locked'
    }
  }

  onMounted(async () => {
    if (cachedUser) {
      applyUser(cachedUser)
      return
    }

    await refresh()
  })

  return {
    status,
    isLoading: computed(() => status.value === 'loading'),
    isLocked: computed(() => status.value === 'locked'),
    isUnlocked: computed(() => status.value === 'unlocked'),
    refresh
  }
}
