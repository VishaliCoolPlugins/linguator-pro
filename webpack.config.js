import path from 'path';
import { fileURLToPath } from 'url';
import defaultConfig from '@wordpress/scripts/config/webpack.config.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Only the Site Scan screen is built here. Everything else ships with the free plugin.
export default {
  ...defaultConfig,
  entry: {
    scan: path.resolve(__dirname, 'admin/scan/src/index.js'),
  },
  output: {
    ...defaultConfig.output,
    path: path.resolve(__dirname, 'admin/assets/frontend/scan'),
    filename: '[name].js',
  },
};
