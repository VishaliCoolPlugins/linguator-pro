// Render the real React component: separate fields in one document must stay visible.
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const assert = require('node:assert/strict');
const babel = require('@babel/core');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const filename = path.resolve(__dirname, '../admin/settings/views/src/components/scan-parts.jsx');
const compiled = babel.transformSync(fs.readFileSync(filename, 'utf8'), { filename, presets: ['@babel/preset-react'], plugins: ['@babel/plugin-transform-modules-commonjs'] }).code;
const component = new Module(filename, module);
component.filename = filename;
component.paths = Module._nodeModulePaths(path.dirname(filename));
component._compile(compiled, filename);
const { IssueCard } = component.exports;
for (const id of ['elementor_fields', 'elementor_untranslated', 'elementor_repeater', 'elementor_links']) {
 const details = ['First widget issue', 'Second widget issue'].map((label,index)=>({label,message:'Review this field',layout:'widget',language:'fr',ignore_key:`elementor:fields:99:fr:widget${index}:title`,action_url:'#preview'}));
 const html = renderToStaticMarkup(React.createElement(IssueCard,{ result:{id,status:'warning',section:'elementor',label:'Widget checks',details},initialOpen:true }));
 assert(html.includes('First widget issue') && html.includes('Second widget issue'), `${id}: a field was hidden by grouping`);
 assert.equal((html.match(/Rescan issue/g)||[]).length,2,`${id}: each field needs its own rescan`);
}
console.log('React widget rows: separate fields and row actions preserved for all four issue layouts.');
const filteredResult = {id:'elementor_fields',section:'elementor',status:'warning',label:'Template filter',details:[{label:'Header finding',message:'Visible header detail',post_type:'elementor_library',template_type:'header',layout:'widget',ignore_key:'elementor:fields:99:fr:heading:title'}]};
const filteredHtml = renderToStaticMarkup(React.createElement(IssueCard,{result:filteredResult,initialOpen:true,filters:{type:'header'}}));
assert(filteredHtml.includes('Header finding') && filteredHtml.includes('Visible header detail'),'Elementor type filtering must use template_type for both cards and rows');
const emptyHtml = renderToStaticMarkup(React.createElement(IssueCard,{result:{id:'lang_default',section:'site',status:'critical',label:'Default language',message:'Choose a default language in language settings.',details:[]},whyHow:true,initialOpen:true}));
assert(emptyHtml.includes('lmat-scan-issue-explanation'),'A summary-only issue must have readable body content');
assert(!emptyHtml.includes('lmat-inspector-guidance'),'Missing guidance must not render a blank guidance panel');
console.log('Accordion content: template filters preserve rows and summary-only issues have a readable fallback.');

const renderIssue = result => renderToStaticMarkup(React.createElement(IssueCard,{result,initialOpen:true}));
const detail = {label:'Sample page',message:'Review this translation',language:'fr',post_type:'page',ignore_key:'sample:1',action_url:'#edit'};
for (const id of ['url_configuration','navigation','lang_permalinks','content_identical','content_acf','content_placeholder']) {
 const html = renderIssue({id,section:'site',status:'warning',label:id,details:[detail]});
 assert(!html.includes('<table'),id+': Toolkit uses detail rows, not a table');
}
for (const id of ['elementor_orphan','elementor_empty','elementor_outdated','elementor_layout']) {
 const html = renderIssue({id,section:'elementor',status:'warning',label:id,details:[detail]});
 assert(html.includes('lmat-scan-detail-row--card') && !html.includes('<table'),id+': document findings use example cards');
}
const stringsHtml=renderIssue({id:'strings_missing',section:'site',status:'warning',label:'Strings',fix_url:'#strings',details:[detail]});
assert(stringsHtml.indexOf('lmat-scan-strings-top-actions') < stringsHtml.indexOf('<table'),'Strings settings must be above the table');
assert(!stringsHtml.includes('Rescan issue'),'Toolkit string rows have Ignore rather than duplicate rescan controls');
const linkHtml=renderIssue({id:'content_links',section:'content',status:'warning',label:'Links',details:[{...detail,code:'dead_link',url:'/missing/'}]});
assert(linkHtml.includes('Current link:') && linkHtml.includes('Problem:') && linkHtml.includes('How to fix:'),'Broken links need numbered guidance');
console.log('Toolkit parity: detail rows, Elementor cards, top settings actions and link instructions verified.');
