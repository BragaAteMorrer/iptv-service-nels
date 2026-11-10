// Guards the PC/TV channel browser against reintroducing its 1000-item cap.
// The PHP integration smoke test exercises actual SQL search + source grants.
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import test from 'node:test';

const html=readFileSync('public/tv.html','utf8');
const backend=readFileSync('src/Controller/LineupController.php','utf8');

test('browser fetches small pages on demand, never ten fixed pages',()=>{
 assert.ok(html.includes('async function loadMoreCatalog()'));
 assert.ok(html.includes('id="loadMore"'));
 assert.ok(html.includes('id="loadMoreState"'));
 assert.ok(!html.includes('page<=10'));
 assert.ok(!html.includes('(max. 1000)'));
});

test('all-bouquet search paginates on the authenticated lineup endpoint',()=>{
 assert.ok(html.includes('async function searchLineup('));
 assert.ok(html.includes('id="searchMore"'));
 assert.ok(html.includes('searchResultIds'));
 assert.ok(html.includes("favorites:favoritesOnly?'1':'0'"));
 assert.ok(html.includes("'lineup?'+params"));
});

test('direct selection can fetch an uncached channel via scoped lookup',()=>{
 assert.ok(html.includes('async function fetchById('));
 assert.ok(html.includes('channel_id:String(channelId)'));
 assert.ok(html.includes('channel_number:String(number)'));
 assert.ok(html.includes('async function selectNumberValue('));
});

test('SQL search maintains user-source access, page totals and bounded filters',()=>{
 assert.ok(backend.includes("user_source_access"));
 assert.ok(backend.includes("SELECT COUNT(*) FROM channels"));
 assert.ok(backend.includes("Invalid lineup filters"));
 assert.ok(backend.includes("p.channel_number=?"));
 assert.ok(backend.includes("c.id=?"));
});
