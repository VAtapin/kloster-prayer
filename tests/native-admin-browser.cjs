const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const {chromium}=require(path.resolve(__dirname,'../../Kalendar/node_modules/playwright'));
(async()=>{
  const browser=await chromium.launch({channel:'msedge'});
  try{
    const page=await browser.newPage({viewport:{width:1280,height:900}});
    for(const name of ['native-campaign-ru','native-campaign-de','native-forms-ru','native-services-ru','native-campaign-list','native-support-empty','native-support-filled']){
      await page.setContent('<body class="wp-admin wp-core-ui">'+fs.readFileSync(path.resolve(__dirname,'../tmp/'+name+'.html'),'utf8')+'</body>');
      for(const css of ['wp-includes/css/buttons.css','wp-admin/css/common.css','wp-admin/css/forms.css','wp-admin/css/list-tables.css'])await page.addStyleTag({path:path.resolve(__dirname,'../tmp/admin-check-core/wordpress',css)});
      await page.addStyleTag({path:path.resolve(__dirname,'../georg-kloster-orthodox-donate/assets/css/admin.css')});
      await page.addStyleTag({path:path.resolve(__dirname,'../georg-kloster-orthodox-donate/assets/css/admin-native.css')});
      await page.addStyleTag({content:'body{margin:20px;background:#f0f0f1;font:13px Arial;color:#1d2327}.screen-reader-text{position:absolute;clip-path:inset(50%)}'});
      if(name==='native-campaign-ru')assert.equal(await page.locator('[name^="title_"]').count(),1);
      if(name.includes('support'))assert.equal(await page.locator('table.wp-list-table').count(),1);
      if(name==='native-support-empty')assert.equal(await page.locator('.no-items').count(),1);
      if(name==='native-support-filled')assert.equal(await page.locator('.button[name],button.button').count(),1);
      await page.screenshot({path:path.resolve(__dirname,'../tmp/'+name+'-desktop.png'),fullPage:true});
      await page.setViewportSize({width:390,height:844});
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No mobile overflow: '+name);
      await page.screenshot({path:path.resolve(__dirname,'../tmp/'+name+'-mobile.png')});
      await page.setViewportSize({width:1280,height:900});
    }
    console.log('PASS native admin browser: campaign/form/service editors, native lists, empty state, buttons and mobile widths with WordPress CSS');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
