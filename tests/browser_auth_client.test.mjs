// Node built-in test runner: no network, browser, credentials or third-party data.
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import assert from 'node:assert/strict';
import test from 'node:test';

const client = readFileSync(new URL('../public/assets/nsatellite-auth.js', import.meta.url), 'utf8');

function harness(response) {
 const calls = [];
 const context = {
  window: {},
  Headers,
  fetch: async (path, options) => {
   calls.push({ path, options });
   if (response instanceof Error) throw response;
   return response;
  }
 };
 runInNewContext(client, context, { filename: 'nsatellite-auth.js' });
 return { api: context.window.NSatelliteAuth, calls };
}

function reply(status, body) {
 return { status, ok: status >= 200 && status < 300, json: async () => body };
}

test('200 authenticated restores a real browser session', async () => {
 const { api, calls } = harness(reply(200, { authenticated: true }));
 assert.equal(await api.session(), true);
 assert.equal(calls[0].path, '/api/identity/browser/session');
 assert.equal(calls[0].options.credentials, 'same-origin');
 assert.equal(calls[0].options.cache, 'no-store');
});

test('401 is the only ordinary not-signed-in response', async () => {
 const { api } = harness(reply(401, { authenticated: false }));
 assert.equal(await api.session(), false);
});

test('server outage does not masquerade as a logout', async () => {
 const { api } = harness(reply(503, { error: 'Maintenance' }));
 await assert.rejects(api.session(), /Maintenance/);
});

test('network outage does not masquerade as a logout', async () => {
 const { api } = harness(new Error('DNS unavailable'));
 await assert.rejects(api.session(), /inaccessible/);
});

test('malformed session response fails visibly', async () => {
 const { api } = harness(reply(200, { nonsense: true }));
 await assert.rejects(api.session(), /invalide/);
});

test('login uses cookie credentials and CSRF marker, never reads a token', async () => {
 const { api, calls } = harness(reply(200, { authenticated: true, expires_in: 604800 }));
 await api.login('alice@example.test', 'only-a-test-password');
 assert.equal(calls[0].path, '/api/identity/browser/login');
 assert.equal(calls[0].options.credentials, 'same-origin');
 assert.equal(calls[0].options.headers.get('X-NSatellite-Request'), '1');
 assert.equal(calls[0].options.headers.get('Content-Type'), 'application/json');
 assert.equal(calls[0].options.headers.has('Authorization'), false);
 const payload = JSON.parse(calls[0].options.body);
 assert.equal(payload.email, 'alice@example.test');
});

test('logout sends same-origin POST without exposing browser session cookie', async () => {
 const { api, calls } = harness(reply(200, { ok: true }));
 await api.logout();
 assert.equal(calls[0].path, '/api/identity/browser/logout');
 assert.equal(calls[0].options.headers.get('X-NSatellite-Request'), '1');
 assert.equal(calls[0].options.credentials, 'same-origin');
 assert.equal(calls[0].options.headers.has('Authorization'), false);
});
