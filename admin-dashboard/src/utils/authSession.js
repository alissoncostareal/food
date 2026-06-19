import { clearCachedUser } from '@/composables/useFeatureAccess'
import { clearModuleMaintenanceCache } from '@/composables/useModuleMaintenance'

export function syncUserSession(user) {
  if (!user) return

  if (user.role) {
    localStorage.setItem('user_role', user.role)
  }

  if (user.name) {
    localStorage.setItem('user_name', user.name)
  }
}

export function clearAuthSession() {
  localStorage.removeItem('auth_token')
  localStorage.removeItem('user_role')
  localStorage.removeItem('user_name')
  clearCachedUser()
  clearModuleMaintenanceCache()
}
