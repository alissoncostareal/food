import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

export const fetchLandingContent = async () => {
  const { data } = await api.get('/landing')
  return data
}

export const fetchPlans = async () => {
  const { data } = await api.get('/plans')
  return data?.data || data?.plans || data || []
}

export const submitLead = async (payload) => {
  const { data } = await api.post('/landing/leads', payload)
  return data
}

export default api
