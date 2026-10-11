const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const {chromium} = require(path.resolve(__dirname, '../../Kalendar/node_modules/playwright'));
(async () => {
  const browser = await chromium.launch({channel: 'msedge'});
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent(fs.readFileSync(path.resolve(__dirname, '../tmp/support-preview.html'), 'utf8'));
    await page.addStyleTag({content: 'body{font-family:Arial,sans-serif;margin:24px;max-width:860px}input,select,textarea{font:inherit}table{margin-bottom:20px}'});
    await page.addStyleTag({path: path.resolve(__dirname, '../georg-kloster-orthodox-donate/assets/css/public.css')});
    await page.evaluate(() => {
      window.georgKlosterOrthodoxDonate = {required: 'Заполните обязательные поля.', error: 'Ошибка', ajaxUrl: '/fixture'};
      window.sent = [];
      window.fetch = async (_, options) => {
        window.sent.push(Object.fromEntries(options.body));
        return {json: async () => ({success: false, data: {message: 'Fixture validation'}})};
      };
    });
    await page.addScriptTag({path: path.resolve(__dirname, '../georg-kloster-orthodox-donate/assets/js/public.js')});
    await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
    const form = page.locator('.gkod-form form');
    await form.locator('[name="donation_amount"]').fill('10,00');
    await form.locator('[name="donor_email"]').fill('donor@example.invalid');
    await form.locator('.gkod-submit').click();
    assert.equal(await page.evaluate(() => window.sent.length), 0, 'Unchecked consent blocks submission');
    await form.locator('[name="recurring_consent"]').check();
    await form.locator('[name="frequency"]').selectOption('year');
    await form.locator('.gkod-submit').click();
    await page.waitForFunction(() => window.sent.length === 1);
    const payload = await page.evaluate(() => window.sent[0]);
    assert.equal(payload.frequency, 'year');
    assert.equal(payload.recurring_consent, '1');
    assert.equal(payload.donation_amount, '10,00');
    assert.equal('names' in payload, false);
    await page.waitForFunction(() => !document.querySelector('.gkod-submit').disabled);
    assert.equal(await form.locator('.gkod-error').textContent(), 'Fixture validation');
    assert.ok(await page.locator('input[name="subscription_id"]').count(), 'History has cancellation controls');
    assert.ok(await page.locator('input[name="_wpnonce"]').count(), 'Cancellation nonce retained');
    assert.deepEqual(errors, []);
    await page.screenshot({path: path.resolve(__dirname, '../tmp/support-desktop.png'), fullPage: true});
    await page.setViewportSize({width: 390, height: 844});
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), 'Mobile page has no horizontal overflow');
    await page.screenshot({path: path.resolve(__dirname, '../tmp/support-mobile.png'), fullPage: true});
    console.log('PASS actual recurring form and history in browser: explicit consent, yearly payload, error recovery, cancellation nonce and no script errors');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
