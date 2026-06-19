import { ref } from 'vue'

export const moduleMaintenanceState = ref({})

export function setModuleMaintenanceState(state) {
  moduleMaintenanceState.value = state || {}
}

export function clearModuleMaintenanceState() {
  moduleMaintenanceState.value = {}
}
