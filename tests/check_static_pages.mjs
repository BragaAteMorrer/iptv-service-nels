// Static frontend gate for the browser-only interface; requires Node >= 20.
import { readFileSync } from 'node:fs';
import { Script } from 'node:vm';
import assert from 'node:assert/strict';

const pages = [
 'public/home.html', 'public/tv.html', 'public/catalog.html',
 'public/series.html', 'public/lineup.html', 'public/devices.html', 'public/account.html',
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

const series = readFileSync('public/series.html', 'utf8');
assert.ok(series.includes('Lecture indisponible'), 'Series page must state production playback limitations');
assert.ok(!series.includes('/api/v1/vod/items/'), 'Do not call the prohibited VOD session endpoint from production UI');
assert.ok(!series.includes('/api/v1/vod/range'), 'Do not expose the development-only VOD relay');
console.log('PASS series viewer does not offer unsupported production playback');

const shellPages=['home','tv','catalog','series','lineup','devices','account'];
for(const name of shellPages){
 const page=readFileSync('public/'+name+'.html','utf8');
 assert.ok(page.includes('class="ns-app-header"'),name+': missing unified header');
 assert.ok(page.includes('href="/account.html"'),name+': missing personal dashboard');
}
const tv=readFileSync('public/tv.html','utf8');
assert.ok(!tv.includes('X-Admin-Token') && !tv.includes('id="token"'),'Public TV must not ask for a global admin token');
const dashboard=readFileSync('public/account.html','utf8');
assert.ok(dashboard.includes('/api/account/sources'),'Account dashboard needs its own API');
assert.ok(!dashboard.includes('access_token'),'Browser must never reveal an auth token');
console.log('PASS NSatellite account navigation and no legacy admin token');
