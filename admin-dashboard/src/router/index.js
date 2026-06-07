import { createRouter, createWebHistory } from 'vue-router'
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
    component: Plans
  },
  {
    path: '/orders',
    name: 'Orders',
    component: OrdersView
  },
   {
    path: '/categories',
    name: 'Categories',
    component: CategorieView
  },
  {
    path: '/products',
    name: 'Products',
    component: ProductView
  },
  {
    path: '/coupons',
    name: 'Coupons',
    component: CouponsView
  },
  {
    path: '/settings',
    name: 'Settings',
    component: Settings
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach((to, from) => {
  const isAuthenticated = !!localStorage.getItem('auth_token')
  
  if (to.meta.requiresAuth && !isAuthenticated) {
    return { name: 'Register' }
  }

  if (isAuthenticated && (to.name === 'Register' || to.name === 'Login')) {
    return { name: 'Dashboard' }
  }
  
})

export default router