import axios from 'axios'
import { clearAuthSession } from '@/utils/authSession'
import { getApiErrorMessage } from '@/utils/apiError'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1',
  withCredentials: true,
  timeout: 60000,
  headers: {
    Accept: 'application/json'
  }
})

api.interceptors.request.use(
  config => {
    const token = localStorage.getItem('auth_token')

    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }

    const isFormData = typeof FormData !== 'undefined' && config.data instanceof FormData

    if (isFormData) {
      config.transformRequest = [(data, headers) => {
        delete headers['Content-Type']
        delete headers['content-type']
        return data
      }]
    } else if (config.data !== undefined) {
      config.headers['Content-Type'] = 'application/json'
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
