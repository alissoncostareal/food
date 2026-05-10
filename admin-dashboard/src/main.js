import { createApp } from 'vue'
import App from './App.vue'
import router from './router' // Ele busca automaticamente o index.js da pasta router
import './style.css'

const app = createApp(App)

app.use(router)
app.mount('#app')