/**
 * Next.js App Router (Route Handlers) integration helper for DoxaClient.
 */
function createNextAuth(doxaClient, cookieName = 'doxa_tx') {
  return {
    /**
     * Handler to initiate Doxa login in a Next.js route handler (e.g. app/api/auth/doxa/route.ts)
     */
    async handleLogin(request, options = {}) {
      const { url, state, nonce, codeVerifier } = await doxaClient.beginLogin(options);
      const isHttps = request.headers.get('x-forwarded-proto') === 'https' || request.url.startsWith('https://');

      const response = new Response(null, {
        status: 302,
        headers: {
          Location: url,
        },
      });

      const txData = JSON.stringify({ state, nonce, codeVerifier, createdAt: Date.now() });
      const cookieValue = `${cookieName}=${encodeURIComponent(txData)}; Path=/; HttpOnly; SameSite=Lax; Max-Age=600${isHttps ? '; Secure' : ''}`;
      response.headers.set('Set-Cookie', cookieValue);

      return response;
    },

    /**
     * Handler to process OIDC callback in a Next.js route handler (e.g. app/api/auth/callback/route.ts)
     */
    async handleCallback(request, resolver, onSuccess) {
      const url = new URL(request.url);
      const queryParams = Object.fromEntries(url.searchParams.entries());

      // Parse cookie
      const cookieHeader = request.headers.get('cookie') || '';
      const cookies = Object.fromEntries(
        cookieHeader.split(';').map(c => {
          const [k, ...v] = c.trim().split('=');
          return [k, decodeURIComponent(v.join('='))];
        })
      );

      let transaction = null;
      if (cookies[cookieName]) {
        try {
          transaction = JSON.parse(cookies[cookieName]);
        } catch {
          // parse error
        }
      }

      if (!transaction) {
        return new Response(JSON.stringify({ error: 'invalid_transaction', message: 'Missing transaction state' }), {
          status: 400,
          headers: { 'Content-Type': 'application/json' },
        });
      }

      const user = await doxaClient.resolveAccount(queryParams, transaction, resolver);

      let response;
      if (typeof onSuccess === 'function') {
        response = await onSuccess(user, request);
      } else {
        response = new Response(JSON.stringify({ success: true, user }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        });
      }

      // Clear transaction cookie
      response.headers.append('Set-Cookie', `${cookieName}=; Path=/; HttpOnly; Max-Age=0`);
      return response;
    },
  };
}

module.exports = { createNextAuth };
