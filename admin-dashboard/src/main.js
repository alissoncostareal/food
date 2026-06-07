// 🚀 Agora o arquivo existe! O Vite vai encontrar e registrar o window.Echo
import './echo' 

import { createApp } from 'vue'
import App from './App.vue'
import router from './router' 
import './style.css'

const app = createApp(App)

app.use(router)
app.mount('#app')