import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const token = localStorage.getItem('access_token');

window.Echo = new Echo({
    broadcaster: 'reverb',
    
    // 🚀 AQUI ESTÁ O SEGREDO: Passando a chave fixa para eliminar o erro de 'undefined'
    key: 'ifoodclonereverbkey123', 
    
    wsHost: '127.0.0.1', 
    wsPort: 8080,
    wssPort: 8080,
    forceTLS: false,
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
    
    authEndpoint: 'http://127.0.0.1:8000/broadcasting/auth',
    auth: {
            headers: {
                get Authorization() {
                    // Agora buscando pela chave correta 'access_token'
                    const t = localStorage.getItem('access_token');
                    return t ? `Bearer ${t}` : '';
                },
                Accept: 'application/json',
            }
    }
});