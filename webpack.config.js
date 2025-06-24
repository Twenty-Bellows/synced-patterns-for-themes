const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const { CleanWebpackPlugin } = require('clean-webpack-plugin');

defaultConfig[ 0 ] = {
	...defaultConfig[ 0 ],
	plugins: [
		...defaultConfig[0].plugins,
		new CleanWebpackPlugin(),
	],
	...{
		entry: {
			'index': './src/index.js',
		},
	},
};

module.exports = defaultConfig;
