/**
 * End-to-end against a local WordPress + WooCommerce (npm run site).
 *
 *   BASE=http://127.0.0.1:9400 node tests/e2e/run.mjs
 *
 * Drives the real admin form, then checks what an agent would see: the
 * well-known object and its headers, the head link, the JSON-LD Offer property,
 * the visible block, and a spec §4 discovery walk. Screenshots go to
 * docs/screenshots/. Exits non-zero on the first failed check.
 */

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.BASE || 'http://127.0.0.1:9400';
const SHOTS = new URL('../../docs/screenshots/', import.meta.url).pathname;
const SCHEMA = 'https://api.nctr.live/schema/bounty/v1.json';
const ALLIANCE = 'https://api.nctr.live/.well-known/bounty.json';
const PRODUCT = `${BASE}/product/trail-jacket/`;
const SETTINGS = `${BASE}/wp-admin/admin.php?page=open-bounty`;
const OWN = {
  program_name: 'Outfitters Club',
  program_url: 'https://example.com/club',
  earn_display: 'Club members earn points on every order, redeemable for store credit.',
  earn_type: 'points',
  denomination: 'points',
  terms_url: 'https://example.com/club/terms'
};
const BANNED = /\b(cashback|yield|yields|apy|apr|roi|invest|investment|investor|investing|dividend|securities|defi|aerodrome)\b|cash back|returns on|rate of return|revenue share|profit share|liquidity pool|lp token|funding source/i;

mkdirSync(SHOTS, { recursive: true });
let failures = 0;
const ok = (cond, msg) => {
  console.log(`${cond ? '  ✓' : '  ✗'} ${msg}`);
  if (!cond) failures += 1;
};

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
const http = ctx.request;

async function login() {
  await page.goto(`${BASE}/wp-login.php`);
  if (await page.locator('#user_login').count()) {
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await page.waitForLoadState('networkidle');
  }
}

async function save({ mode, confirm = false, own = null, block = true }) {
  await page.goto(SETTINGS);
  await page.check(`input[name="mode"][value="${mode}"]`);
  if (mode === 'alliance') await page.setChecked('input[name="alliance_confirmed"]', confirm);
  if (own) for (const [k, v] of Object.entries(own)) {
    if (k === 'earn_type') await page.selectOption('select[name="earn_type"]', v);
    else await page.fill(`[name="${k}"]`, v);
  }
  await page.setChecked('input[name="show_block"]', block);
  await page.click('#open_bounty_save');
  await page.waitForLoadState('networkidle');
  return (await page.locator('.notice').first().innerText()).trim();
}

async function wellKnown() {
  const res = await http.get(`${BASE}/.well-known/bounty.json`, { maxRedirects: 0 });
  return { res, body: res.status() === 200 ? await res.json() : null };
}

function bountyLinks(html) {
  const head = html.split('</head>')[0];
  return [...head.matchAll(/<link[^>]*rel="bounty"[^>]*>/g)].map((m) => m[0]);
}

function offerProperty(html) {
  for (const m of html.matchAll(/<script type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/g)) {
    let doc;
    try { doc = JSON.parse(m[1]); } catch { continue; }
    const nodes = doc['@graph'] || [doc];
    for (const n of nodes) {
      if (n['@type'] !== 'Product') continue;
      for (const offer of [].concat(n.offers || [])) {
        if (offer.additionalProperty) return offer.additionalProperty;
      }
      return null;
    }
  }
  return undefined; // no Product node at all
}

/** Required keys and no extras, driven by the published schema. */
function schemaErrors(doc, schema, path = '') {
  const errs = [];
  for (const k of schema.required || []) if (!(k in doc)) errs.push(`${path}${k} missing`);
  if (schema.additionalProperties === false) {
    for (const k of Object.keys(doc)) if (!schema.properties?.[k]) errs.push(`${path}${k} not permitted`);
  }
  for (const [k, sub] of Object.entries(schema.properties || {})) {
    if (sub.type === 'object' && doc[k]) errs.push(...schemaErrors(doc[k], sub, `${path}${k}.`));
  }
  return errs;
}

/** Spec §4, as a conforming agent runs it against the site root. */
async function discoveryWalk() {
  const steps = [];
  const r1 = await http.get(`${BASE}/.well-known/bounty.json`, { maxRedirects: 0 });
  steps.push(`1 GET /.well-known/bounty.json → ${r1.status()}`);
  if (r1.status() === 200) return { steps, object: await r1.json(), via: 'well-known' };
  const home = await (await http.get(`${BASE}/`)).text();
  const link = bountyLinks(home)[0];
  steps.push(`2 <link rel="bounty"> on home → ${link ? 'found' : 'none'}`);
  if (link) {
    const href = /href="([^"]+)"/.exec(link)[1];
    const r2 = await http.get(href);
    steps.push(`  GET ${href} → ${r2.status()}`);
    if (r2.status() === 200) return { steps, object: await r2.json(), via: 'link' };
  }
  steps.push('3 unknown');
  return { steps, object: null, via: 'unknown' };
}

const schema = await (await fetch(SCHEMA)).json();
const alliance = await (await fetch(ALLIANCE)).json();
await login();

// Start from nothing published, whatever a previous run left behind.
await page.goto(SETTINGS);
if (await page.locator('#open_bounty_unpublish').count()) {
  await page.click('#open_bounty_unpublish');
  await page.waitForLoadState('networkidle');
}

console.log('\nBefore any save');
{
  const { res } = await wellKnown();
  ok(res.status() === 404, `well-known is 404 (got ${res.status()})`);
  const home = await (await http.get(`${BASE}/`)).text();
  ok(bountyLinks(home).length === 0, 'no rel="bounty" link on the home page');
  const html = await (await http.get(PRODUCT)).text();
  ok(!html.includes('open-bounty__display'), 'no block on the product page');
  ok(offerProperty(html) === null, 'WooCommerce Offer present, no bounty property in it');
  await page.goto(SETTINGS);
  await page.screenshot({ path: `${SHOTS}settings-before.png`, fullPage: true });
}

console.log('\nAlliance without the confirmation is refused');
{
  const notice = await save({ mode: 'alliance', confirm: false });
  ok(/Nothing was published/.test(notice), 'refused: "Nothing was published"');
  ok(/Confirm that purchases/.test(notice), 'names the missing confirmation');
  ok((await wellKnown()).res.status() === 404, 'well-known still 404');
}

console.log('\nThe merchant\'s own sentence is theirs: "5% cash back on every order" publishes');
{
  const sentence = '5% cash back on every order';
  const notice = await save({ mode: 'own', own: { ...OWN, earn_display: sentence } });
  ok(/Published\./.test(notice), 'published on the open path');
  const { res, body } = await wellKnown();
  ok(res.status() === 200 && body.earn.display === sentence, 'served verbatim at the well-known path');
  const html = await (await http.get(PRODUCT)).text();
  ok(offerProperty(html)?.value === sentence, 'JSON-LD carries it verbatim');
  await page.goto(PRODUCT);
  ok((await page.locator('.open-bounty').innerText()).includes(sentence), 'the block shows it verbatim');
  await page.goto(SETTINGS);
  await page.click('#open_bounty_unpublish');
  await page.waitForLoadState('networkidle');
  ok((await wellKnown()).res.status() === 404, 'unpublished again for the next case');
}

console.log('\nSmile.io on a WooCommerce store: refused, nothing published');
{
  await page.goto(SETTINGS);
  await page.check('input[name="mode"][value="smile"]');
  await page.click('#open_bounty_save');
  await page.waitForLoadState('networkidle');
  const notice = (await page.locator('.notice').first().innerText()).trim();
  ok(/Nothing was published/.test(notice), 'refused: "Nothing was published"');
  ok(/No Smile\.io program was found/.test(notice), 'says no Smile.io program was found, and why');
  ok((await wellKnown()).res.status() === 404, 'well-known still 404');
  await page.screenshot({ path: `${SHOTS}settings-smile-refused.png`, fullPage: true });
}

console.log('\nYotpo Loyalty on a WooCommerce store without it: refused, nothing published');
{
  await page.goto(SETTINGS);
  await page.check('input[name="mode"][value="yotpo"]');
  await page.click('#open_bounty_save');
  await page.waitForLoadState('networkidle');
  const notice = (await page.locator('.notice').first().innerText()).trim();
  ok(/Nothing was published/.test(notice), 'refused: "Nothing was published"');
  ok(/No Yotpo Loyalty program was found/.test(notice), 'says no Yotpo program was found');
  ok(/support new WooCommerce accounts/.test(notice), "states Yotpo's WooCommerce position");
  ok((await wellKnown()).res.status() === 404, 'well-known still 404');
  await page.screenshot({ path: `${SHOTS}settings-yotpo-refused.png`, fullPage: true });
}

console.log('\nLoyaltyLion on a WooCommerce store without it: refused, nothing published');
{
  await page.goto(SETTINGS);
  await page.check('input[name="mode"][value="loyaltylion"]');
  await page.click('#open_bounty_save');
  await page.waitForLoadState('networkidle');
  const notice = (await page.locator('.notice').first().innerText()).trim();
  ok(/Nothing was published/.test(notice), 'refused: "Nothing was published"');
  ok(/No LoyaltyLion program was found/.test(notice), 'says no LoyaltyLion program was found');
  ok((await wellKnown()).res.status() === 404, 'well-known still 404');
}

console.log('\nDescribe your own reward');
{
  const notice = await save({ mode: 'own', own: OWN });
  ok(/Published\./.test(notice), 'published');
  ok(notice.includes('Validated against api.nctr.live/schema/bounty/v1.json · object version 0.3'), 'shows the schema and version validated against');
  await page.screenshot({ path: `${SHOTS}settings-published.png`, fullPage: true });

  const { res, body } = await wellKnown();
  ok(res.status() === 200, 'well-known 200');
  ok(/^application\/json/.test(res.headers()['content-type'] || ''), `content-type ${res.headers()['content-type']}`);
  ok(res.headers()['access-control-allow-origin'] === '*', 'CORS *');
  ok((res.headers()['link'] || '') === `<${SCHEMA}>; rel="describedby"`, `Link: ${res.headers()['link']}`);
  ok(schemaErrors(body, schema).length === 0, `object conforms to v1 (${schemaErrors(body, schema).join('; ') || 'no errors'})`);
  ok(body.earn.display === OWN.earn_display, 'earn.display verbatim');
  ok(!BANNED.test(JSON.stringify(body)), 'gate: well-known body clean');

  const home = await (await http.get(`${BASE}/`)).text();
  const links = bountyLinks(home);
  ok(links.length === 1 && links[0].includes(`href="${BASE}/.well-known/bounty.json"`), `exactly one head link → ${links[0]}`);

  const html = await (await http.get(PRODUCT)).text();
  const prop = offerProperty(html);
  ok(prop && prop.propertyID === 'bounty' && prop['@type'] === 'PropertyValue', 'JSON-LD: bounty PropertyValue inside the Offer');
  ok(prop && prop.value === OWN.earn_display, 'JSON-LD value verbatim');
  ok(prop && !('url' in prop), 'no api → no url');

  await page.goto(PRODUCT);
  const block = page.locator('.open-bounty');
  ok(await block.count() === 1, 'one visible block');
  ok((await block.innerText()).includes(OWN.earn_display), 'block shows the sentence verbatim');
  ok((await block.innerText()).includes(OWN.program_name), 'block shows the program name');
  const after = await page.evaluate(() => {
    const price = document.querySelector('.summary .price');
    const b = document.querySelector('.open-bounty');
    return !!(price && b && (price.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING));
  });
  ok(after, 'block sits after the price');
  ok(!BANNED.test(await block.innerText()), 'gate: block clean');
  await page.locator('.summary').screenshot({ path: `${SHOTS}product-summary.png` });
  await page.screenshot({ path: `${SHOTS}product-page.png`, fullPage: false });

  const walk = await discoveryWalk();
  console.log('    §4 walk:', walk.steps.join(' | '), '→', walk.via);
  ok(walk.via === 'well-known' && walk.object?.earn?.display === OWN.earn_display, '§4 resolves at step 1');

  // §4 step 2, run on its own: an agent that starts from the home page.
  const href = /href="([^"]+)"/.exec(links[0])[1];
  const viaLink = await http.get(href);
  ok(viaLink.status() === 200 && JSON.stringify(await viaLink.json()) === JSON.stringify(body),
    '§4 step 2: the head link resolves to the same object');
}

console.log('\nBlock theme (Twenty Twenty-Five)');
{
  async function activate(name) {
    await page.goto(`${BASE}/wp-admin/themes.php`);
    const link = page.locator(`a.activate[aria-label*="${name}"]`);
    if (await link.count()) { await link.first().click({ force: true }); await page.waitForLoadState('networkidle'); }
  }
  await activate('Twenty Twenty-Five');
  await page.goto(PRODUCT);
  const block = page.locator('.open-bounty');
  ok(await block.count() === 1, 'one visible block in a block theme');
  const after = await page.evaluate(() => {
    const price = document.querySelector('.wp-block-woocommerce-product-price, .wc-block-components-product-price');
    const b = document.querySelector('.open-bounty');
    return !!(price && b && (price.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING));
  });
  ok(after, 'block sits after the price in a block theme');
  const html = await (await http.get(PRODUCT)).text();
  ok(offerProperty(html)?.value === OWN.earn_display, 'JSON-LD present in a block theme');
  await page.screenshot({ path: `${SHOTS}product-page-block-theme.png` });
  await activate('Storefront');
}

console.log('\nPermalink flush keeps the route');
{
  await page.goto(`${BASE}/wp-admin/options-permalink.php`);
  await page.click('#submit');
  await page.waitForLoadState('networkidle');
  ok((await wellKnown()).res.status() === 200, 'well-known 200 after Settings → Permalinks → Save');
}

console.log('\nBlock toggled off');
{
  await save({ mode: 'own', own: OWN, block: false });
  const html = await (await http.get(PRODUCT)).text();
  ok(!html.includes('open-bounty__display'), 'no visible block');
  ok(offerProperty(html)?.value === OWN.earn_display, 'JSON-LD still present');
}

console.log('\nNCTR Alliance, confirmed');
{
  const notice = await save({ mode: 'alliance', confirm: true });
  ok(/Published\./.test(notice), 'published');
  const { body } = await wellKnown();
  ok(JSON.stringify(body) === JSON.stringify(alliance), 'serves the live alliance object unchanged');
  const html = await (await http.get(PRODUCT)).text();
  const prop = offerProperty(html);
  ok(prop?.value === alliance.earn.display && prop?.url === alliance.api, 'JSON-LD carries the alliance sentence and api');
  await page.goto(PRODUCT);
  await page.locator('.summary').screenshot({ path: `${SHOTS}product-summary-alliance.png` });
}

console.log('\nStop publishing');
{
  await page.goto(SETTINGS);
  await page.click('#open_bounty_unpublish');
  await page.waitForLoadState('networkidle');
  ok((await wellKnown()).res.status() === 404, 'well-known back to 404');
  const home = await (await http.get(`${BASE}/`)).text();
  ok(bountyLinks(home).length === 0, 'head link gone');
  const html = await (await http.get(PRODUCT)).text();
  ok(!html.includes('open-bounty__display') && offerProperty(html) === null, 'product page quiet');
  const walk = await discoveryWalk();
  console.log('    §4 walk:', walk.steps.join(' | '), '→', walk.via);
  ok(walk.via === 'unknown', '§4 reports unknown, never "no"');
}

// Leave the site in the published own-reward state for anyone looking at it.
await save({ mode: 'own', own: OWN });
await browser.close();
console.log(failures ? `\n${failures} check(s) FAILED` : '\nall checks passed');
process.exit(failures ? 1 : 0);
