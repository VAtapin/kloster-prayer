const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require.resolve('../georg-kloster-orthodox-donations/assets/js/admin-images.js'), 'utf8');
const handlers = {};
let frameOpenCount = 0;
function jquery(selector) {
  if (typeof selector === 'function') {
    selector(jquery);
    return;
  }
  if (selector === '#gkod-image-preview') return {length: 0};
  if (selector === '#gkod-campaign-image-id') return {length: 1, val() { return this; }};
  return {length: 0, on(event, callback) { handlers[selector] = callback; return this; }, empty() { return this; }};
}
const context = {
  jQuery: jquery,
  window: {georgKlosterOrthodoxDonateAdmin: {campaignTitle: 'Campaign image', campaignUse: 'Use image'}},
  wp: {media() { return {on() {}, open() { frameOpenCount++; }}; }}
};

vm.runInNewContext(source, context);
assert.equal(typeof handlers['#gkod-campaign-image-select'], 'function', 'Campaign handler registers without a service image preview');
handlers['#gkod-campaign-image-select']();
assert.equal(frameOpenCount, 1, 'Campaign image button opens the WordPress media frame');
console.log('PASS campaign image chooser initializes independently of the service image field');
