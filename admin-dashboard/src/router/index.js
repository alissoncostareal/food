import { createRouter, createWebHistory } from 'vue-router'
import { fetchCurrentUser } from '@/composables/useFeatureAccess'
import { clearAuthSession } from '@/utils/authSession'
import { isPlatformAdmin, isSuperAdminRoute } from '@/utils/platformAdmin'
import DashboardLayout from '../layouts/DashboardLayout.vue'

import RegisterView from '../views/RegisterView.vue'
import DashboardView from '../views/DashboardView.vue'
import LoginView from '../views/LoginView.vue'
import ForgotPasswordView from '../views/ForgotPasswordView.vue'
import ResetPasswordView from '../views/ResetPasswordView.vue'
import ProductView from '../views/ProductView.vue'
import OrdersView from '../views/OrdersView.vue'
import CategorieView from '../views/CategorieView.vue'
import Plans from '../views/PlansView.vue'
import Settings from '../views/SettingsView.vue'
import StoreView from '../views/StoreView.vue'
import OnboardingStoreView from '../views/OnboardingStoreView.vue'
import CouponsView from '../views/CouponsView.vue'
import BillingView from '../views/BillingView.vue'
import SuperAdminView from '../views/SuperAdminView.vue'
import ReportsView from '../views/ReportsView.vue'
import DeliveryAreasView from '../views/DeliveryAreasView.vue'
import DeliveryDriversView from '../views/DeliveryDriversView.vue'
import ImportView from '../views/ImportView.vue'
import IfoodIntegrationView from '../views/IfoodIntegrationView.vue'
import WhatsappIntegrationView from '../views/WhatsappIntegrationView.vue'
import PaymentsView from '../views/PaymentsView.vue'
import IntelligenceView from '../views/IntelligenceView.vue'
import TeamView from '../views/TeamView.vue'
import AcceptInviteView from '../views/AcceptInviteView.vue'

const dashboardChildRoutes = [
  {
    path: '',
    redirect: '/dashboard'
  },
  {
    path: 'dashboard',
    name: 'Dashboard',
    component: DashboardView,
    meta: { title: 'Dashboard', mobileSummary: true }
  },
  {
    path: 'plans',
    name: 'Plans',
    component: Plans,
    meta: { title: 'Planos' }
  },
  {
    path: 'billing',
    name: 'Meu Plano',
    component: BillingView,
    meta: { title: 'Meu Plano', ownerOnly: true }
  },
  {
    path: 'orders',
    name: 'Orders',
    component: OrdersView,
    meta: { title: 'Pedidos' }
  },
  {
    path: 'products',
    name: 'Products',
    component: ProductView,
    meta: { title: 'Cardápio' }
  },
  {
    path: 'categories',
    name: 'Categories',
    component: CategorieView,
    meta: { title: 'Categorias' }
  },
  {
    path: 'coupons',
    name: 'Coupons',
    component: CouponsView,
    meta: {
      feature: 'coupons',
      title: 'Cupons'
    }
  },
  {
    path: 'reports',
    name: 'Relatórios',
    component: ReportsView,
    meta: {
      feature: 'advanced_reports',
      title: 'Relatórios'
    }
  },
  {
    path: 'intelligence',
    name: 'Inteligência',
    component: IntelligenceView,
    meta: {
      feature: 'intelligence',
      title: 'Inteligência'
    }
  },
  {
    path: 'delivery-areas',
    name: 'Áreas de Entrega',
    component: DeliveryAreasView,
    meta: {
      feature: 'delivery_areas',
      title: 'Áreas de Entrega'
    }
  },
  {
    path: 'delivery-drivers',
    name: 'Entregadores',
    component: DeliveryDriversView,
    meta: {
      title: 'Entregadores'
    }
  },
  {
    path: 'import',
    name: 'Importação',
    component: ImportView,
    meta: {
      feature: 'ifood_integration',
      title: 'Importação'
    }
  },
  {
    path: 'integrations/whatsapp',
    name: 'WhatsApp',
    component: WhatsappIntegrationView,
    meta: {
      feature: 'whatsapp_auto',
      title: 'WhatsApp'
    }
  },
  {
    path: 'integrations/ifood',
    name: 'iFood',
    component: IfoodIntegrationView,
    meta: {
      feature: 'ifood_integration',
      title: 'iFood'
    }
  },
  {
    path: 'integrations',
    redirect: '/integrations/whatsapp'
  },
  {
    path: 'loja',
    name: 'Loja',
    component: StoreView,
    meta: { title: 'Loja' }
  },
  {
    path: 'payments',
    name: 'Recebimentos',
    component: PaymentsView,
    meta: { title: 'Recebimentos', ownerOnly: true }
  },
  {
    path: 'settings',
    name: 'Settings',
    component: Settings,
    meta: { title: 'Configurações' }
  },
  {
    path: 'team',
    name: 'Equipe',
    component: TeamView,
    meta: { title: 'Equipe', ownerOnly: true, feature: 'team' }
  }
]

const routes = [
  {
    path: '/register',
    name: 'Register',
    component: RegisterView,
    meta: { title: 'Cadastro' }
  },
  {
    path: '/login',
    name: 'Login',
    component: LoginView,
    meta: { title: 'Login' }
  },
  {
    path: '/forgot-password',
    name: 'ForgotPassword',
    component: ForgotPasswordView,
    meta: { title: 'Esqueci minha senha' }
  },
  {
    path: '/reset-password',
    name: 'ResetPassword',
    component: ResetPasswordView,
    meta: { title: 'Redefinir senha' }
  },
  {
    path: '/convite/:token',
    name: 'AcceptInvite',
    component: AcceptInviteView,
    meta: { title: 'Aceitar convite' }
  },
  {
    path: '/onboarding/loja',
    name: 'OnboardingStore',
    component: OnboardingStoreView,
    meta: { requiresAuth: true, onboarding: true, title: 'Criar loja' }
  },
  {
    path: '/',
    component: DashboardLayout,
    meta: { requiresAuth: true },
    children: dashboardChildRoutes
  },
  {
    path: '/super-admin/:section?',
    name: 'SuperAdmin',
    component: SuperAdminView,
    meta: {
      requiresAuth: true,
      role: 'super_admin',
      superAdmin: true,
      title: 'Super Admin'
    },
    beforeEnter: (to) => {
      const validSections = new Set(['overview', 'stores', 'plans', 'settings', 'courtesies', 'landing'])
      const section = to.params.section

      if (!section) {
        return { path: '/super-admin/overview', replace: true }
      }

      if (!validSections.has(section)) {
        return { path: '/super-admin/overview', replace: true }
      }
    }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

const publicAuthRouteNames = new Set(['Login', 'Register', 'ForgotPassword', 'ResetPassword', 'AcceptInvite'])

const redirectToLogin = (query = {}) => {
  clearAuthSession()
  return { name: 'Login', query }
}

const resolvePlanFeatures = (plan) => {
  if (!plan) return {}

  const features = { ...(plan.features || {}) }

  if (plan.slug === 'premium' && features.intelligence === undefined) {
    features.intelligence = true
  }

  if (plan.slug === 'premium' && features.team === undefined) {
    features.team = true
  }

  return features
}

const requiresAuth = (to) => to.matched.some((record) => record.meta.requiresAuth)

router.beforeEach(async (to) => {
  const isAuthenticated = !!localStorage.getItem('auth_token')
  const superAdminRoute = isSuperAdminRoute(to)

  if (superAdminRoute && !isAuthenticated) {
    return { name: 'Login', query: { redirect: to.fullPath, notice: 'super_admin' } }
  }

  if (requiresAuth(to) && !isAuthenticated) {
    return { name: 'Login', query: { redirect: to.fullPath } }
  }

  if (!isAuthenticated) {
    return
  }

  let user

  try {
    user = await fetchCurrentUser({ force: superAdminRoute })
  } catch {
    return redirectToLogin({ redirect: to.fullPath })
  }

  if (publicAuthRouteNames.has(to.name)) {
    if (isPlatformAdmin(user) && to.name !== 'Register') {
      return { path: '/super-admin/overview' }
    }

    if (!isPlatformAdmin(user)) {
      if (user.needs_onboarding) return { name: 'OnboardingStore' }
      return { name: 'Dashboard' }
    }

    return
  }

  if (superAdminRoute) {
    if (!isPlatformAdmin(user)) {
      clearAuthSession()
      return {
        name: 'Login',
        query: { redirect: to.fullPath, notice: 'super_admin' }
      }
    }

    return
  }

  if (isPlatformAdmin(user)) {
    return { path: '/super-admin/overview' }
  }

  if (to.meta.onboarding) {
    if (!user.needs_onboarding) {
      return { name: 'Dashboard' }
    }

    return
  }

  if (user.needs_onboarding && user.role === 'store_owner' && requiresAuth(to)) {
    return { name: 'OnboardingStore' }
  }

  if (to.meta.ownerOnly) {
    if (!user?.permissions?.can_manage_team && !user?.permissions?.can_manage_billing) {
      return { name: 'Dashboard' }
    }
  }

  if (to.meta.feature) {
    if (!user?.store?.has_active_subscription) {
      return { path: '/billing', query: { upgrade: to.meta.feature } }
    }

    const features = resolvePlanFeatures(user?.store?.plan)

    if (!features[to.meta.feature]) {
      return { path: '/billing', query: { upgrade: to.meta.feature } }
    }
  }
})

export default router
