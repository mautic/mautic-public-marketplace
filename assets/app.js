// Deliberately no CSS imports here. AssetMapper renders every CSS entry in the import map as a
// `data:application/javascript,` module, which our CSP script-src blocks — and one blocked module
// takes the whole app.js graph down with it, so nothing on the page runs. Stylesheets are linked
// from base.html.twig instead; the Tom Select theme lives in assets/styles/vendor/.
import '@hotwired/turbo';
import 'bootstrap';
import './validation.js';
import './rating.js';
import './publish-upload.js';
import './upload-wizard.js';
import './enhanced-select.js';
