const fs = require('node:fs');
const yaml = require('js-yaml');
module.exports = yaml.load(fs.readFileSync(__dirname + '/realisations.yaml', 'utf8'));
