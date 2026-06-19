import { computed } from 'vue'
import {
  getMaintenanceMessage,
  isModuleUnderMaintenance
} from '@/constants/moduleMaintenance'
import {
  moduleMaintenanceState,
  clearModuleMaintenanceState,
  setModuleMaintenanceState
} from '@/state/moduleMaintenanceState'

let loaded = false

export async function refreshModuleMaintenance({ force = false } = {}) {
  if (loaded && !force) {
    return moduleMaintenanceState.value
  }

  const { fetchCurrentUser } = await import('@/composables/useFeatureAccess')
  const user = await fetchCurrentUser({ force })
  setModuleMaintenanceState(user?.module_maintenance)
  loaded = true

  return moduleMaintenanceState.value
}

export function clearModuleMaintenanceCache() {
  clearModuleMaintenanceState()
  loaded = false
}

export { setModuleMaintenanceState }

export function useModuleMaintenance() {
  const maintenance = computed(() => moduleMaintenanceState.value)

  const isInMaintenance = (moduleKey) => isModuleUnderMaintenance(moduleMaintenanceState.value, moduleKey)

  const messageFor = (moduleKey) => getMaintenanceMessage(moduleMaintenanceState.value, moduleKey)

  return {
    maintenance,
    isInMaintenance,
    messageFor,
    refreshModuleMaintenance
  }
}
