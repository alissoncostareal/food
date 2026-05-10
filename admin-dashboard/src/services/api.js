import axios from 'axios';

const api = axios.create({
  // Se no Postman você usa a porta 8000, coloque aqui também:
  baseURL: 'http://localhost:8000/api', 
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
});

// Adicione isso para garantir que o Token seja enviado após o login
api.interceptors.request.use(config => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export default api;