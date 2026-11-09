// Static frontend gate for the browser-only interface; requires Node >= 20.
import { readFileSync } from 'node:fs';
import { Script } from 'node:vm';
import assert from 'node:assert/strict';

const pages = [
 'public/home.html', 'public/tv.html', 'public/catalog.html',
 'public/series.html', 'public/lineup.html', 'public/devices.html',
 'deploy/o2switch/public_html/index.html'
];
for (const path of pages) {
 const html = readFileSync(path, 'utf8');
 const scripts = [...html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)];
 for (const [index, match] of scripts.entries()) {
  new Script(match[1], { filename: path + ':inline:' + index });
 }
 const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map(match => match[1]);
 assert.equal(new Set(ids).size, ids.length, path + ': duplicate DOM ID');
 if (path.startsWith('public/')) {
  const auth = html.indexOf('<script src="/assets/nsatellite-auth.js"></script>');
  const inline = html.indexOf('<script>');
  assert.ok(auth >= 0 && (inline < 0 || auth < inline), path + ': auth client must load before inline script');
  assert.ok(html.includes('NSatelliteAuth.session()'), path + ': session restoration is missing');
  assert.ok(!html.includes('access_token'), path + ': browser should not read bearer tokens');
 }
 console.log('PASS static browser page:', path);
}
new Script(readFileSync('public/assets/nsatellite-auth.js', 'utf8'), {
 filename: 'public/assets/nsatellite-auth.js'
});
console.log('PASS browser auth JS syntax');
