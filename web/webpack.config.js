var path = require('path');
var webpack = require('webpack');

var config = {
    resolve: {
        alias: {
            jquery: "jquery/src/jquery",
            jquery_jgrowl: "jgrowl/jquery.jgrowl.js",
            jquery_hashchange: "jquery-hashchange/jquery.ba-hashchange.min.js",
        }
    },
    plugins: [
        new webpack.ProvidePlugin({
            'window.jQuery': 'jquery',
            'window.$': 'jquery',
        })
    ],
    entry: {
        app: ['./js/index.js'],
    },
    output: {
        filename: 'bundle.js',
    	path: path.resolve(__dirname, 'js')
    },

    module: {
        loaders: [
            {test: /\.css$/, loader: "style-loader!css-loader"}
        ],
/*
        rules: [
	  // { 
            // test: /\.css$/, 
            // use: [
              // { loader: "style-loader!css-loader" }
            // ]
          // },
          // the url-loader uses DataUrls. 
          // the file-loader emits files. 
          {
            test: /\.woff(2)?(\?v=[0-9]\.[0-9]\.[0-9])?$/,
            use: [
              {
                loader: 'url-loader',
                options: {
                  limit: 10000,
                  mimetype: 'application/font-woff'
                }
              }
            ]
          },
          {
            test: /\.(ttf|eot|svg)(\?v=[0-9]\.[0-9]\.[0-9])?$/,
            use: [
              { loader: 'file-loader' }
            ]
          }
       ]
*/
    }
};

module.exports = config;

