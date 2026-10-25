/**
 * Notify plugin - push subscription helper (not Smarty).
 *
 * Binds a click handler to elements matching `[data-notify-push-enable]` and, when clicked:
 * - Requests Notification permission
 * - Subscribes using the browser Push API (VAPID public key)
 * - POSTs the subscription details to the server to persist them for the current user
 *
 * Visibility of the `[data-notify-push-cta]` wrapper is decided server-side (the dropdown hides it
 * when the recipient already has a stored push subscription); this file only hides it again right
 * after a successful subscribe, before the next dropdown refresh.
 *
 * Requirements:
 * - A service worker must be registered (e.g. `public/sw.js`)
 * - A clickable element with:
 *   - `data-notify-push-enable` (presence enables binding)
 *   - `data-vapid-public-key` (base64url VAPID public key)
 *   - optional `data-subscribe-url` (defaults to `/notify/notifications/push/subscribe`)
 *   - optional `data-csrf-token` (else the `csrfToken` cookie): sent as `X-CSRF-Token`,
 *     which the plugin controller requires
 */

(function () {
  /**
   * Convert a base64url-encoded VAPID public key into a Uint8Array as required by `pushManager.subscribe()`.
   *
   * @param {string} base64Url base64url (URL-safe) encoded string.
   * @returns {Uint8Array}
   */
  function base64UrlToUint8Array(base64Url) {
    const padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
    const base64 = (base64Url + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
      out[i] = raw.charCodeAt(i);
    }
    return out;
  }

  /**
   * CSRF token for the subscribe request: the button's `data-csrf-token`, else
   * the `csrfToken` cookie set by the Csrf component (not HttpOnly).
   *
   * @param {HTMLElement} btn Clicked element.
   * @returns {string}
   */
  function csrfToken(btn) {
    const fromButton = btn.getAttribute('data-csrf-token') || '';
    if (fromButton) {
      return fromButton;
    }
    const match = document.cookie.match(/(?:^|;\s*)csrfToken=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
  }

  /**
   * Enable push for the current user by creating a subscription and sending it to the backend.
   *
   * Expected button attributes:
   * - `data-vapid-public-key`: base64url VAPID public key (required)
   * - `data-subscribe-url`: endpoint to POST to (optional)
   *
   * Network:
   * - POST JSON `{ endpoint, p256dh, auth }` to subscribe URL.
   *
   * @param {HTMLElement} btn Clicked element that contains required data attributes.
   * @returns {Promise<boolean>} Resolves true when subscription is stored successfully.
   * @throws {Error} When browser support is missing, permission is denied, or the subscribe request fails.
   */
  async function enablePush(btn) {
    const vapidPublicKey = btn.getAttribute('data-vapid-public-key') || '';
    if (!vapidPublicKey) {
      throw new Error('Missing VAPID public key on button.');
    }
    if (!('Notification' in window) || !('serviceWorker' in navigator)) {
      throw new Error('Push notifications are not supported in this browser.');
    }

    const perm = await Notification.requestPermission();
    if (perm !== 'granted') {
      throw new Error('Permission not granted.');
    }

    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: base64UrlToUint8Array(vapidPublicKey),
    });

    const json = sub.toJSON();
    const endpoint = json.endpoint;
    const p256dh = json.keys && json.keys.p256dh ? json.keys.p256dh : '';
    const auth = json.keys && json.keys.auth ? json.keys.auth : '';

    const subscribeUrl = btn.getAttribute('data-subscribe-url') || '/notify/notifications/push/subscribe';
    const res = await fetch(subscribeUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken(btn) },
      body: JSON.stringify({ endpoint, p256dh, auth }),
    });
    if (!res.ok) {
      throw new Error('Subscribe request failed.');
    }
    return true;
  }

  /**
   * Global click handler that delegates to the nearest `[data-notify-push-enable]` element.
   *
   * @param {MouseEvent} e
   * @returns {void}
   */
  document.addEventListener('click', function (e) {
    const btn = e.target && e.target.closest ? e.target.closest('[data-notify-push-enable]') : null;
    if (!btn) {
      return;
    }
    e.preventDefault();

    btn.setAttribute('disabled', 'disabled');
    btn.classList.add('loading');
    btn.style.maskSize = btn.offsetHeight + 'px';

    enablePush(btn)
      .then(function () {
        btn.classList.remove('loading');
        var cta = btn.closest('[data-notify-push-cta]');
        if (cta) {
          cta.classList.add('hidden');
        } else {
          btn.textContent = __('Push enabled');
        }
      })
      .catch(function (err) {
        btn.classList.remove('loading');
        btn.removeAttribute('disabled');
        btn.textContent = __('Failed to enable push notifications');
      });
  });
}());
