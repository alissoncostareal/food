import axios from 'axios'
import { clearAuthSession } from '@/utils/authSession'
import { getApiErrorMessage } from '@/utils/apiError'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1',
  withCredentials: true,
  timeout: 25000,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json'
  }
})

api.interceptors.request.use(
  config => {
    const token = localStorage.getItem('auth_token')

    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }

    if (config.data instanceof FormData) {
      if (config.headers?.set) {
        config.headers.set('Content-Type', null)
      } else if (config.headers) {
        delete config.headers['Content-Type']
        delete config.headers['content-type']
      }
    }

    return config
  },
  error => Promise.reject(error)
)

api.interceptors.response.use(
  response => response,
  async error => {
    const status = error.response?.status
    const data = error.response?.data || {}

    if (status === 401) {
      clearAuthSession()

      const { default: router } = await import('@/router')
      const currentRoute = router.currentRoute.value?.path

      if (currentRoute !== '/login' && currentRoute !== '/register') {
        router.push('/login')
      }
    }

    if (status === 403 && data.upgrade_required) {
      const { default: router } = await import('@/router')
      const userRole = localStorage.getItem('user_role')
      const currentRoute = router.currentRoute.value?.path

      if (userRole !== 'super_admin' && !['/billing', '/plans', '/login', '/register'].includes(currentRoute)) {
        router.push('/billing')
      }
    }

    if (status === 429) {
      error.userMessage = getApiErrorMessage(error, 'Muitas tentativas. Aguarde um momento e tente novamente.')
    }

    return Promise.reject(error)
  }
)

export default api
