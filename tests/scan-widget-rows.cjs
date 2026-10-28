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

const {groupResults, issueCount}=component.exports;
const menuGroup=groupResults(['header','footer'].map(location=>({id:'menu_'+location,status:'critical',section:'site',label:location,details:['en','eo','es'].map(language=>({label:language,ignore_key:'menu:'+location+':'+language}))})))[0];
assert.equal(issueCount(menuGroup),2,'Navigation totals count locations rather than six language gaps or one group');
const contentGroup=groupResults([{id:'content_unpublished_page',status:'warning',section:'content',details:[{label:'A',ignore_key:'content:1:eo'},{label:'A',ignore_key:'content:1:es'},{label:'B',ignore_key:'content:2:eo'}]}])[0];
assert.equal(issueCount(contentGroup),2,'Content totals count source pages, not languages');
console.log('Header statistics: shared row counts match menu locations and source pages.');

const {ignoreKeysForResult}=component.exports;
const bulkResult={id:'content_missing',section:'content',status:'warning',label:'Missing translations',details:Array.from({length:12},(_,i)=>({label:'Page '+i,ignore_key:'content:'+i+':fr',post_type:'page'}))};
assert.equal(ignoreKeysForResult(bulkResult).length,12,'Ignore all must include later pages');
const bulkHtml=renderToStaticMarkup(React.createElement(IssueCard,{result:bulkResult,initialOpen:true,filters:{search:'Page 1'},onIgnoreMany:()=>{}}));
assert(bulkHtml.includes('Ignore all items in Missing translations'),'Accordion needs an accessible bulk action');
assert.equal(ignoreKeysForResult(menuGroup).length,6,'Grouped language rows must all be dismissed');
assert.deepEqual(ignoreKeysForResult({...bulkResult,details_truncated:true}),['content_missing'],'Truncated reports must dismiss the whole issue');
assert(!renderToStaticMarkup(React.createElement(IssueCard,{result:{...bulkResult,status:'pass'},onIgnoreMany:()=>{}})).includes('Ignore all items'),'Passing checks need no bulk dismiss action');
console.log('Bulk ignore: all pages, grouped languages, filtered lists and passing states verified.');

const {groupIgnoredItems,IgnoredPanel}=component.exports;
const ignoredRows=[{key:'content:10:fr',label:'One page',section:'content',issue:'Missing translation',language:'fr'},{key:'content:10:de',label:'One page',section:'content',issue:'Missing translation',language:'de'},{key:'content:11:fr',label:'One page',section:'content',issue:'Missing translation',language:'fr'}];
const ignoredGroups=groupIgnoredItems(ignoredRows);
assert.equal(ignoredGroups.length,2,'Same page in two languages counts once; identical titles on different pages stay separate');
assert.deepEqual(ignoredGroups[0].keys,['content:10:fr','content:10:de'],'Restore a page must restore all its language entries');
assert.equal(groupIgnoredItems([{key:'elementor:fields:20:fr:a',section:'elementor',source_post_id:10},{key:'elementor:fields:21:de:b',section:'elementor',source_post_id:10}]).length,1,'Translated Elementor document IDs must group by original page');
const ignoredHtml=renderToStaticMarkup(React.createElement(IgnoredPanel,{items:ignoredRows,expanded:true,languages:[{slug:'fr',name:'French'},{slug:'de',name:'German'}],onRestore:()=>{},onRestoreMany:()=>{}}));
assert(ignoredHtml.includes('Ignored Issues (2)'),'Ignored panel count must match page rows');
assert.equal((ignoredHtml.match(/>Restore</g)||[]).length,2,'One restore button per page row');
console.log('Ignored pages: multi-language grouping, unique IDs, Elementor sources and page-level restoration verified.');

const menuBulkHtml=renderToStaticMarkup(React.createElement(IssueCard,{result:menuGroup,initialOpen:true,onIgnore:()=>{},onIgnoreMany:()=>{}}));
assert.equal((menuBulkHtml.match(/Ignore all missing languages for/g)||[]).length,2,'Each menu location needs an explicitly labelled bulk-ignore control');
console.log('Navigation rows: Header and Footer expose Ignore all for their language gaps.');

const categoryIgnores=['hi','de','fr'].map(language=>({key:'term:22:category:'+language,label:'Uncategorized',section:'content',issue:'Categories & Tags',language}));
const categoryGrouped=groupIgnoredItems(categoryIgnores);
assert.equal(categoryGrouped.length,1,'One term missing three languages is one ignored issue');
assert.deepEqual(categoryGrouped[0].issues,['Categories & Tags'],'The issue name must appear once, without language-specific repetitions');
assert.equal(groupIgnoredItems([{key:'content:10:fr',section:'content',source_post_id:10,issue:'Missing Translations'},{key:'content_updated:10:fr',section:'content',source_post_id:10,issue:'Default Language Updated'}]).length,2,'Different issue accordions on the same page count separately, matching the active list');
console.log('Ignored issue parity: one canonical heading per row; counts follow active issue families.');

const mixedPageIssues = [
 {key:'content:10:fr',section:'content',source_post_id:10,issue_id:'content_unpublished_page',issue:'Same display text',language:'fr'},
 {key:'content:10:de',section:'content',source_post_id:10,issue_id:'content_unpublished_page',issue:'Same display text',language:'de'},
 {key:'content_updated:10:fr',section:'content',source_post_id:10,issue_id:'content_source_updated',issue:'Same display text',language:'fr'},
];
const mixedGroups=groupIgnoredItems(mixedPageIssues);
assert.equal(mixedGroups.length,2,'Different issue IDs on one page stay separate even when display text matches');
assert.deepEqual(mixedGroups[0].keys,['content:10:fr','content:10:de'],'Restoring unpublished translations must not restore source-updated issues');
assert.deepEqual(mixedGroups[1].keys,['content_updated:10:fr']);
assert.equal(groupIgnoredItems(['fr','de'].map(language=>({key:'term:22:category:'+language,section:'content',issue_id:'term_missing_'+language,issue:'Missing in '+language}))).length,1,'Language variants use the same family as the active accordion');
console.log('Ignored issue identity: distinct issue types stay separate; language variants share one row.');

const selectableHtml=renderToStaticMarkup(React.createElement(IssueCard,{result:bulkResult,initialOpen:true,onIgnoreMany:()=>{}}));
assert(selectableHtml.includes('Select all matching items'),'Bulk toolbar needs a select-all checkbox');
assert(selectableHtml.includes('Ignore selected (0)'),'Selection action starts empty');
assert.equal((selectableHtml.match(/aria-label="Select Page /g)||[]).length,10,'Each row on the current page needs a checkbox');
for(const id of ['elementor_fields','translated_permalinks','menu_dead_links']) {
 const html=renderToStaticMarkup(React.createElement(IssueCard,{result:{id,section:'elementor',status:'warning',label:id,details:[detail]},initialOpen:true,onIgnoreMany:()=>{}}));
 assert(html.includes('aria-label="Select Sample page"'),id+': card layouts need row selection too');
}
console.log('Bulk selection: select-all toolbar, empty selection, paginated table rows and card layouts verified.');
