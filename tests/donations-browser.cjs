const assert = require('node:assert/strict');
const path = require('node:path');
const {chromium} = require(path.resolve(__dirname, '../../Kalendar/node_modules/playwright'));
(async () => {
  const browser = await chromium.launch({channel: 'msedge'});
  try {
    const page = await browser.newPage();
    await page.setContent('<div class="klp-form" data-lang="ru" data-request-type="donation"><form novalidate><input name="donation_amount" required><input name="donor_email" type="email" required><output></output><div class="klp-error" hidden></div><button class="klp-submit">Пожертвовать</button></form></div>');
    await page.evaluate(() => {
      window.klosterPrayer = {required: 'Заполните обязательные поля.', error: 'Ошибка', ajaxUrl: '/ajax'};
      window.sent = [];
      window.fetch = async (url, options) => {
        window.sent.push(Object.fromEntries(options.body));
        return {json: async () => ({success: false, data: {message: 'Server validation'}})};
      };
    });
    await page.addScriptTag({path: process.argv[2] ? path.resolve(process.argv[2]) : path.resolve(__dirname, '../georg-kloster-orthodox-donate/assets/js/public.js')});
    await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
    const amount = page.locator('[name="donation_amount"]');
    await page.locator('[name="donor_email"]').fill('donor@example.invalid');
    for (const value of ['0.49', '-1', '1e3', '1.234', '1000000']) {
      await amount.fill(value);
      assert.equal(await amount.evaluate(el => el.checkValidity()), false, value);
      await page.locator('button').click();
      assert.equal(await page.evaluate(() => window.sent.length), 0);
    }
    await amount.fill('12,34');
    assert.equal(await amount.evaluate(el => el.checkValidity()), true);
    assert.match(await page.locator('output').textContent(), /12,34/);
    await page.locator('button').click();
    await page.waitForFunction(() => window.sent.length === 1);
    assert.equal(await page.evaluate(() => window.sent[0].donation_amount), '12,34');
    assert.equal(await page.evaluate(() => 'names' in window.sent[0]), false);
    await page.waitForFunction(() => !document.querySelector('button').disabled);
    assert.equal(await page.locator('.klp-error').textContent(), 'Server validation');
    await amount.fill('0.50');
    assert.equal(await amount.evaluate(el => el.checkValidity()), true);
    await amount.fill('999999.99');
    assert.equal(await amount.evaluate(el => el.checkValidity()), true);
    console.log('PASS browser donations: independent form, exact decimal display, amount bounds, submit payload and server error recovery');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
