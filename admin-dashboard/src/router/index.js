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
    path: '/settings',
    name: 'Settings',
    component: Settings,
    meta: { requiresAuth: true }
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

const hasFeature = (user, feature) => {
  if (!feature) return true

  return Boolean(user?.store?.plan?.features?.[feature])
}

router.beforeEach(async (to) => {
  const isAuthenticated = !!localStorage.getItem('auth_token')

  if (to.meta.requiresAuth && !isAuthenticated) {
    return { name: 'Login' }
  }

  if (isAuthenticated && (to.name === 'Register' || to.name === 'Login')) {
    return { name: 'Dashboard' }
  }

  if (isAuthenticated && to.meta.feature) {
    try {
      const user = await getCurrentUser()

      if (!hasFeature(user, to.meta.feature)) {
        return { name: 'Plans' }
      }
    } catch (error) {
      localStorage.removeItem('auth_token')
      return { name: 'Login' }
    }
  }
})

export default router