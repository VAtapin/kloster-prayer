const assert = require('node:assert/strict');
const path = require('node:path');
const {chromium} = require(path.resolve(__dirname, '../../Kalendar/node_modules/playwright'));
(async()=>{
 const browser=await chromium.launch({channel:'msedge'});
 try {
  const page=await browser.newPage();
  await page.setContent('<div class="gkod-form" data-lang="ru"><form><textarea name="names"></textarea><input name="intention" type="radio" value="health" checked><label class="gkod-service" data-intention="both" data-mode="per_person" data-price="200" data-limit="10"><input type="checkbox" checked></label><output></output><div class="gkod-error" hidden></div><button class="gkod-submit">Оплатить</button></form></div>');
  await page.addScriptTag({path:path.resolve(__dirname,'../georg-kloster-orthodox-donate/assets/js/public.js')});
  await page.evaluate(()=>document.dispatchEvent(new Event('DOMContentLoaded')));
  const field=page.locator('textarea');
  await field.fill('Мария, Иоанн,, Болящего Леонида');
  assert.equal(await field.inputValue(),'Мария\nИоанн\n\nБолящего Леонида');
  await field.blur();
  assert.equal(await field.inputValue(),'Мария\nИоанн\nБолящего Леонида');
  assert.match(await page.locator('output').textContent(),/6,00/);
  await field.fill('Anna, Maria\nИоанн; Анна-Мария');
  await field.blur();
  assert.equal(await field.inputValue(),'Anna\nMaria\nИоанн\nАнна-Мария');
  assert.match(await page.locator('output').textContent(),/8,00/);
  await field.fill('Мария');
  await field.press('End');await field.press(',');
  assert.equal(await field.inputValue(),'Мария\n');
  await field.pressSequentially('Анна');
  assert.equal(await field.inputValue(),'Мария\nАнна');
  console.log('PASS browser: paste, typed comma, cursor, blank lines, compound names and price');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
