require('../node_modules/jgrowl/jquery.jgrowl.css')
require('../node_modules/intro.js/introjs.css')
require('purecss')
require('../node_modules/jquery-ui-bundle/jquery-ui.css');
require('../node_modules/jquery-ui-bundle/jquery-ui.theme.css');
// require('font-awesome-sass-loader')
// window._ = require('lodash')
require('lodash')


// Load jQuery and expose to globally
require('expose-loader?$!expose-loader?jQuery!jquery');


// jQuery Plugins Load Below
require('jquery-ui-bundle')

// Browser plugin was deprecated in jQuery 1.9
require('jquery-browser-plugin') // Required for hashchange 
require('jquery_hashchange')
require('jquery_jgrowl')
require('expose-loader?introJs!intro.js')
require('jquery.tipsy')
require('flowjs')
require('jquery-validation')

require('jquery-idletimer')
require('jquery-ui-touch-punch')
require('jquery.scrollto')
require('jquery_switchbutton')
require('imagesloaded')
require('meltdown-extra')
require('jquery.fileDownload')

// Required for FullStats
require('jquery_css_transform')
require('jquery-animate-css-rotate-scale')
require('jquery-sparkline')

// Additional JS Plugins
// require('handsontable')
// require('plotly')
