const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require(path.resolve(__dirname,'../../Kalendar/node_modules/playwright'));
(async()=>{
 const browser=await chromium.launch({channel:'msedge'});
 const root=path.resolve(__dirname,'..');
 const output=path.join(root,'wordpressorg-assets');fs.mkdirSync(output,{recursive:true});
 try{
  for(let number=1;number<=6;number++){
   const admin=number>=5;
   const page=await browser.newPage({viewport:{width:admin?1280:1000,height:900},deviceScaleFactor:1,locale:'ru-RU'});
   await page.setContent(fs.readFileSync(path.join(root,'tmp/directory-screenshots/screenshot-'+number+'.html'),'utf8'));
   if(admin){
    for(const css of ['wp-includes/css/buttons.css','wp-admin/css/common.css','wp-admin/css/forms.css','wp-admin/css/list-tables.css'])await page.addStyleTag({path:path.join(root,'tmp/admin-check-core/wordpress',css)});
    for(const css of ['admin.css','admin-native.css'])await page.addStyleTag({path:path.join(root,'georg-kloster-orthodox-donate/assets/css',css)});
    await page.addStyleTag({content:'body{margin:28px;background:#f0f0f1;font:13px Arial,sans-serif;color:#1d2327}.wrap{margin:0}.screen-reader-text{position:absolute;clip-path:inset(50%)}'});
   }else{
    await page.addStyleTag({content:'body{margin:30px auto;max-width:800px;background:#fff;font:16px/1.5 Arial,sans-serif;color:#202936}.screen-reader-text{position:absolute;clip-path:inset(50%)}'});
    await page.addStyleTag({path:path.join(root,'georg-kloster-orthodox-donate/assets/css/public.css')});
    await page.evaluate(()=>{window.georgKlosterOrthodoxDonate={required:'Please complete all required fields.',error:'Screenshot preview',ajaxUrl:'/offline'};window.fetch=()=>Promise.reject(new Error('Screenshot is offline'));});
    await page.addScriptTag({path:path.join(root,'georg-kloster-orthodox-donate/assets/js/public.js')});
    await page.evaluate(()=>document.dispatchEvent(new Event('DOMContentLoaded')));
    if(number!==2)await page.locator('[name="donation_amount"]').fill(number===4?'15.00':'25.00');
    if(number===2){await page.locator('.gkod-service').first().click();await page.locator('[name="names"]').fill('Иоанн\nМария\nАнна');}
    if(number===4)await page.locator('[name="recurring_consent"]').check();
   }
   await page.evaluate(()=>document.fonts.ready);
   const target=page.locator(admin?'.wrap':'body');
   await target.screenshot({path:path.join(output,'screenshot-'+number+'.png')});
   console.log('PASS screenshot-'+number+': current '+(admin?'WordPress administration':'shortcode interface'));
   await page.close();
  }
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
