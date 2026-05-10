import { createRouter, createWebHistory } from 'vue-router'
import RegisterView from '../views/RegisterView.vue'
import DashboardView from '../views/DashboardView.vue'
import LoginView from '../views/LoginView.vue'

// Dica: Para páginas que você ainda não criou, podemos usar o Dashboard temporariamente
// ou criar um componente simples "Em breve".
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
    component: () => import('../views/PlansView.vue'), // Lazy loading (carrega só quando precisa)
  },
  {
    path: '/orders',
    name: 'Orders',
    component: DashboardView // Temporário até você criar a OrdersView
  },
  {
    path: '/products',
    name: 'Products',
    component: DashboardView // Temporário até você criar a ProductsView
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

// Proteção básica: Se não tiver token, manda pro register
router.beforeEach((to, from, next) => {
  const isAuthenticated = localStorage.getItem('auth_token')
  if (to.meta.requiresAuth && !isAuthenticated) {
    next('/register')
  } else {
    next()
  }
})

export default router