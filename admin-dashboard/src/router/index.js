import { createRouter, createWebHistory } from 'vue-router'
import api from '@/services/api'

import RegisterView from '../views/RegisterView.vue'
import DashboardView from '../views/DashboardView.vue'
import LoginView from '../views/LoginView.vue'
import ProductView from '../views/ProductView.vue'
import OrdersView from '../views/OrdersView.vue'
import CategorieView from '../views/CategorieView.vue'
import Plans from '../views/PlansView.vue'
import Settings from '../views/SettingsView.vue'
import CouponsView from '../views/CouponsView.vue'
import BillingView from '../views/BillingView.vue'
import SuperAdminView from '../views/SuperAdminView.vue'
import ReportsView from '../views/ReportsView.vue'
import DeliveryAreasView from '../views/DeliveryAreasView.vue'

const routes = [
  {
    path: '/',
    redirect: '/register'
  },
  {
    path: '/register',
    name: 'Register',
    component: RegisterView
  },
  {
    path: '/login',
    name: 'Login',
    component: LoginView
  },
  {
    path: '/dashboard',
    name: 'Dashboard',
    component: DashboardView,
    meta: { requiresAuth: true }
  },
  {
    path: '/plans',
    name: 'Plans',
    component: Plans,
    meta: { requiresAuth: true }
  },
  {
    path: '/billing',
    name: 'Meu Plano',
    component: BillingView,
    meta: { requiresAuth: true }
  },
  {
    path: '/orders',
    name: 'Orders',
    component: OrdersView,
    meta: { requiresAuth: true }
  },
  {
    path: '/products',
    name: 'Products',
    component: ProductView,
    meta: { requiresAuth: true }
  },
  {
    path: '/categories',
    name: 'Categories',
    component: CategorieView,
    meta: { requiresAuth: true }
  },
  {
    path: '/coupons',
    name: 'Coupons',
    component: CouponsView,
    meta: {
      requiresAuth: true,
      feature: 'coupons'
    }
  },
  {
    path: '/reports',
    name: 'Relatórios',
    component: ReportsView,
    meta: {
      requiresAuth: true,
      feature: 'advanced_reports'
    }
  },
  {
    path: '/delivery-areas',
    name: 'Áreas de Entrega',
    component: DeliveryAreasView,
    meta: {
      requiresAuth: true,
      feature: 'delivery_areas'
    }
  },
  {
    path: '/settings',
    name: 'Settings',
    component: Settings,
    meta: { requiresAuth: true }
  },
  {
    path: '/super-admin',
    name: 'SuperAdmin',
    component: SuperAdminView,
    meta: {
      requiresAuth: true,
      role: 'super_admin'
    }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

const getCurrentUser = async () => {
  const { data } = await api.get('/me')
  return data
}

router.beforeEach(async (to) => {
  const isAuthenticated = !!localStorage.getItem('auth_token')

  if (to.meta.requiresAuth && !isAuthenticated) {
    return { name: 'Login' }
  }

  if (isAuthenticated && (to.name === 'Register' || to.name === 'Login')) {
    try {
      const user = await getCurrentUser()
      return user.role === 'super_admin' ? { name: 'SuperAdmin' } : { name: 'Dashboard' }
    } catch (error) {
      localStorage.removeItem('auth_token')
      return { name: 'Login' }
    }
  }

  if (isAuthenticated && to.meta.role) {
    try {
      const user = await getCurrentUser()

      if (user.role !== to.meta.role) {
        return { name: 'Dashboard' }
      }
    } catch (error) {
      localStorage.removeItem('auth_token')
      return { name: 'Login' }
    }
  }

  if (isAuthenticated && to.meta.feature) {
    return true
  }
})

export default router
