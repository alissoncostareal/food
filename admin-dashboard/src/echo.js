import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const token = localStorage.getItem('access_token');

window.Echo = new Echo({
    broadcaster: 'reverb',
    
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
                    const t = localStorage.getItem('access_token');
                    return t ? `Bearer ${t}` : '';
                },
                Accept: 'application/json',
            }
    }
});