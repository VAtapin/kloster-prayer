const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const {chromium}=require(path.resolve(__dirname,'../../Kalendar/node_modules/playwright'));
(async()=>{
 const browser=await chromium.launch({channel:'msedge'});
 try{
  const page=await browser.newPage();
  await page.setContent(fs.readFileSync(path.resolve(__dirname,'../tmp/coexistence-forms.html'),'utf8'));
  await page.addScriptTag({path:path.resolve(__dirname,'../tmp/coexistence-config.js')});
  await page.evaluate(()=>{window.sent=[];window.fetch=async(url,options)=>{window.sent.push(Object.fromEntries(options.body));return {json:async()=>({success:false,data:{message:'Local fixture'}})};};});
  for(const dir of ['kloster-prayer','georg-kloster-orthodox-donate'])await page.addScriptTag({path:path.resolve(__dirname,'../tmp/coexistence-check',dir,'assets/js/public.js')});
  await page.evaluate(()=>document.dispatchEvent(new Event('DOMContentLoaded')));
  for(const [selector,action]of[['.klp-form','klp_create_payment'],['.gkod-form','gkod_create_payment']]){
   const form=page.locator(selector);
   await form.locator('[name=donation_amount]').fill('10.00');
   await form.locator('[name=donor_email]').fill('fixture@example.invalid');
   await form.locator('button[type=submit]').click();
   await page.waitForFunction(()=>window.sent.length>0);
   const sent=await page.evaluate(()=>window.sent.pop());assert.equal(sent.action,action);
   assert.equal(await page.evaluate(()=>window.sent.length),0,'Each script submits only its own form');
  }
  console.log('PASS coexistence browser: both real forms and scripts on one page send separate AJAX actions without duplicate submission');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
