import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const token = localStorage.getItem('access_token');

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'sa1',
    forceTLS: true,
    
    authEndpoint: `${import.meta.env.VITE_API_BASE_URL}/broadcasting/auth`,
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