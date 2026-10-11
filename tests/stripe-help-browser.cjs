const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const {chromium}=require(path.resolve(__dirname,'../../Kalendar/node_modules/playwright'));
(async()=>{
  const browser=await chromium.launch({channel:'msedge'});
  try{
    const page=await browser.newPage({viewport:{width:1200,height:900}});
    await page.setContent(fs.readFileSync(path.resolve(__dirname,'../tmp/admin-settings.html'),'utf8'));
    for(const css of ['wp-includes/css/buttons.css','wp-admin/css/common.css','wp-admin/css/forms.css'])await page.addStyleTag({path:path.resolve(__dirname,'../tmp/admin-check-core/wordpress',css)});
    await page.addStyleTag({path:path.resolve(__dirname,'../georg-kloster-orthodox-donate/assets/css/admin.css')});
    await page.addStyleTag({path:path.resolve(__dirname,'../georg-kloster-orthodox-donate/assets/css/admin-native.css')});
    await page.addStyleTag({content:'@media(max-width:782px){body{padding:12px}aside{display:none}}'});
    const help=page.locator('.klp-stripe-help');
    assert.equal(await help.getAttribute('open'),null);
    await help.locator('summary').click();
    assert.ok(await help.evaluate(e=>e.open));
    assert.equal(await help.locator('a').count(),5);
    assert.equal(await help.locator('a[href="https://dashboard.stripe.com/webhooks"]').getAttribute('rel'),'noopener noreferrer');
    assert.ok((await help.innerText()).includes('Snapshot'));
    await help.screenshot({path:path.resolve(__dirname,'../tmp/stripe-help-desktop.png')});
    await page.setViewportSize({width:390,height:844});
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Settings fit mobile width');
    await help.locator('summary').scrollIntoViewIfNeeded();
    await page.screenshot({path:path.resolve(__dirname,'../tmp/stripe-help-mobile.png')});
    console.log('PASS Stripe browser help: expandable guide, safe official links, snapshot explanation and mobile width');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
