export function isPlatformAdmin(user) {
  if (!user) return false

  return (
    user.role === 'super_admin'
    || user.role === 'admin'
    || Boolean(user.permissions?.is_super_admin)
    || Boolean(user.permissions?.can_manage_platform)
  )
}

export function isSuperAdminRoute(to) {
  return to.path.startsWith('/super-admin') || to.matched.some((record) => record.meta.superAdmin)
}
