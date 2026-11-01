/**
 * Express framework integration helper for DoxaClient.
 */
function createExpressAuth(doxaClient, sessionKey = 'doxa_tx') {
  return {
    /**
     * Route handler to initiate Doxa login.
     * Usage: app.get('/auth/doxa', auth.login());
     */
    login(options = {}) {
      return async (req, res, next) => {
        try {
          const { url, state, nonce, codeVerifier } = await doxaClient.beginLogin(options);
          if (req.session) {
            req.session[sessionKey] = { state, nonce, codeVerifier, createdAt: Date.now() };
          } else {
            res.cookie(sessionKey, JSON.stringify({ state, nonce, codeVerifier }), {
              httpOnly: true,
              secure: req.secure || req.headers['x-forwarded-proto'] === 'https',
              sameSite: 'lax',
              maxAge: 10 * 60 * 1000,
            });
          }
          res.redirect(url);
        } catch (err) {
          next(err);
        }
      };
    },

    /**
     * Route handler to process OIDC callback and resolve local account.
     * Usage: app.get('/auth/doxa/callback', auth.callback(myResolver, (user, req, res) => res.redirect('/dashboard')));
     */
    callback(resolver, onSuccess) {
      return async (req, res, next) => {
        try {
          let transaction = null;
          if (req.session && req.session[sessionKey]) {
            transaction = req.session[sessionKey];
            delete req.session[sessionKey];
          } else if (req.cookies && req.cookies[sessionKey]) {
            try {
              transaction = JSON.parse(req.cookies[sessionKey]);
            } catch {
              // ignore parse failure
            }
            res.clearCookie(sessionKey);
          }

          if (!transaction) {
            return res.status(400).json({ error: 'invalid_transaction', message: 'Missing transaction state' });
          }

          const user = await doxaClient.resolveAccount(req.query, transaction, resolver);
          if (typeof onSuccess === 'function') {
            return await onSuccess(user, req, res);
          }
          return res.json({ success: true, user });
        } catch (err) {
          next(err);
        }
      };
    },
  };
}

module.exports = {
  createExpressAuth,
};
