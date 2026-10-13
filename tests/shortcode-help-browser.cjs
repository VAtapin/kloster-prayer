const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const {chromium} = require(path.resolve(__dirname, '../../Kalendar/node_modules/playwright'));
(async () => {
  const browser = await chromium.launch({channel:'msedge'});
  try {
    const page = await browser.newPage();
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.setContent(fs.readFileSync(path.resolve(__dirname,'../tmp/shortcode-help-preview.html'),'utf8'));
    await page.addStyleTag({content:'body{font:14px Arial;margin:20px;background:#f0f0f1}.screen-reader-text{position:absolute;clip-path:inset(50%);width:1px;height:1px;overflow:hidden}.widefat{width:100%;border-collapse:collapse}th,td{padding:8px;text-align:left}.gkod-card{background:white;border:1px solid #ccc}input{padding:6px}button,.button{padding:6px 12px}nav a{text-decoration:none;color:#2271b1;border:1px solid #2271b1;background:white}h1{font-size:24px}code{font-size:13px}'});
    await page.addStyleTag({path:path.resolve(__dirname,'../georg-kloster-orthodox-donations/assets/css/admin-native.css')});
    await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async value=>{window.copied=value;}}}));
    await page.addScriptTag({path:path.resolve(__dirname,'../georg-kloster-orthodox-donations/assets/js/admin-shortcodes.js')});
    await page.evaluate(()=>document.dispatchEvent(new Event('DOMContentLoaded')));
    const first=page.locator('.gkod-copy-shortcode').first();
    await first.click();
    assert.equal(await page.evaluate(()=>window.copied),await page.locator('.gkod-shortcode-example input').first().inputValue());
    await page.waitForFunction(()=>document.querySelector('.gkod-copy-status').textContent==='Скопировано.');
    await page.evaluate(()=>navigator.clipboard.writeText=async()=>{throw new Error('Denied');});
    await first.click();
    await page.waitForFunction(()=>document.querySelector('.gkod-copy-status').textContent.includes('вручную'));
    assert.equal(await page.evaluate(()=>document.activeElement.selectionEnd-document.activeElement.selectionStart),await page.locator('.gkod-shortcode-example input').first().inputValue().then(s=>s.length));
    assert.equal(await page.locator('nav a').count(),0);
    await page.setViewportSize({width:1200,height:900});await page.screenshot({path:path.resolve(__dirname,'../tmp/shortcode-help-desktop.png'),fullPage:true});
    await page.setViewportSize({width:390,height:844});
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Mobile guide fits page');
    await page.screenshot({path:path.resolve(__dirname,'../tmp/shortcode-help-mobile.png'),fullPage:true});
    await page.screenshot({path:path.resolve(__dirname,'../tmp/shortcode-help-mobile-top.png')});
    assert.deepEqual(errors,[]);console.log('PASS shortcode guide browser: clipboard, denied clipboard/manual selection, languages, mobile width and no script errors');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
