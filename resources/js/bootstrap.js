import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: process.env.MIX_PUSHER_APP_KEY,
    cluster: process.env.MIX_PUSHER_APP_CLUSTER,
    forceTLS: true
});

// Listen for notifications
if (window.userId) {
    window.Echo.private(`user.${window.userId}`)
        .listen('NotificationSent', (e) => {
            console.log('New notification:', e.notification);
            // Handle the notification (show toast, update UI, etc.)
            showNotificationToast(e.notification);
        });
}

function showNotificationToast(notification) {
    // Your toast notification implementation
    alert(`${notification.title}: ${notification.message}`);
}
