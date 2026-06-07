import axios from 'axios';

const api = axios.create({
  // Troque para o IP do seu Mac ou localhost se o Docker mapear diretamente
  baseURL: 'http://localhost:8000/api/v1', 
  timeout: 10000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
});

export default api;