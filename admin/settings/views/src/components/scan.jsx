import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import apiFetch from '@wordpress/api-fetch'
import { toast } from 'sonner'
import { __, _n, sprintf } from '@wordpress/i18n'
import { getNonce } from '../utils'
import DiffModal from './scan-diff'
import { STATUS, Icon, LanguageFlag, Pill, IssueCard, IgnoredPanel, PassedChecks, groupResults, useAccordion } from './scan-parts'

const request = (path, method = 'GET', data) => apiFetch({ path: `lmat/v1/scan${path}`, method, headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': getNonce() }, ...(data ? { data } : {}) })
const SECTIONS = [
    { key: 'urls', label: __('URLs & Routing', 'linguator-pro'), icon: 'admin-links', blurb: __('Permalinks, language domains and locale conflicts.', 'linguator-pro'), scanLabel: __('Scan URLs', 'linguator-pro') },
    { key: 'content', label: __('Content', 'linguator-pro'), icon: 'admin-page', blurb: __('Pages, posts and custom post types with translation issues.', 'linguator-pro'), scanLabel: __('Scan content', 'linguator-pro') },
    { key: 'elementor', label: __('Elementor', 'linguator-pro'), icon: 'layout', blurb: __('Template translations, languages, document types and Elementor layouts.', 'linguator-pro'), scanLabel: __('Scan Elementor', 'linguator-pro') },
    { key: 'site', label: __('Site Setup', 'linguator-pro'), icon: 'admin-settings', blurb: __('Languages, homepage, menus, strings and the language switcher.', 'linguator-pro'), scanLabel: __('Scan site setup', 'linguator-pro') },
]
const TABS = [{ key: 'overview', label: __('Overview', 'linguator-pro'), icon: 'dashboard' }, ...SECTIONS, { key: 'coverage', label: __('Coverage', 'linguator-pro'), icon: 'chart-area' }]
const TAB_HASH = 'lmat-tab-'
const tabFromHash = () => {
    const key = (window.location.hash || '').replace(/^#/, '')
    const wanted = key.startsWith(TAB_HASH) ? key.slice(TAB_HASH.length) : ''
    return TABS.some(item => item.key === wanted) ? wanted : 'overview'
}
const PENDING_KEY = 'lmat_scan_pending_rescans'
const readPending = () => { try { const list = JSON.parse(window.localStorage.getItem(PENDING_KEY) || '[]'); return Array.isArray(list) ? list : [] } catch (error) { return [] } }
const writePending = list => { try { list.length ? window.localStorage.setItem(PENDING_KEY, JSON.stringify(list)) : window.localStorage.removeItem(PENDING_KEY) } catch (error) { /* storage unavailable */ } }
// Remember the issue or row whose fix link was just opened, so it is rescanned when the user comes back.
const queuePending = item => { if (!item.id) return; const key = JSON.stringify([item.id, item.keys]); writePending([...readPending().filter(old => JSON.stringify([old.id, old.keys]) !== key), item]) }
const keysInReport = (data, keys) => (data.results || []).some(result => (result.details || []).some(row => keys.includes(row.ignore_key)))
const toneFor = score => score >= 90 ? 'ok' : score >= 70 ? 'mid' : 'low'

const Score = ({ score, scanned }) => {
    const grade = score >= 90 ? 'excellent' : score >= 75 ? 'good' : score >= 55 ? 'attention' : 'risk'
    const label = score >= 90 ? __('Excellent', 'linguator-pro') : score >= 75 ? __('Good', 'linguator-pro') : score >= 55 ? __('Needs Attention', 'linguator-pro') : __('Needs Improvement', 'linguator-pro')
    return <div className='lmat-scan-summary-health'><div className='lmat-scan-health-rings'><div className='lmat-scan-summary-overall'>
        <span className='lmat-scan-summary-overall-label'>{__('Overall Score', 'linguator-pro')}</span>
        <div className='lmat-scan-summary-overall-content'><div className='lmat-scan-overall-ring'>
            <svg className='lmat-scan-overall-ring-svg' viewBox='0 0 120 120' width='120' height='120' aria-hidden='true'><circle className='lmat-scan-overall-ring-track' cx='60' cy='60' r='54' fill='none' strokeWidth='10' /><circle className={`lmat-scan-overall-ring-progress lmat-scan-score-${grade}`} cx='60' cy='60' r='54' fill='none' strokeWidth='10' strokeLinecap='round' strokeDasharray={2 * Math.PI * 54} strokeDashoffset={2 * Math.PI * 54 * (1 - score / 100)} transform='rotate(-90 60 60)' /></svg>
            <span className='lmat-scan-overall-ring-value'>{scanned ? `${score}%` : '—'}</span>
        </div><div className='lmat-scan-overall-grade-block'><span className={`lmat-scan-summary-grade lmat-scan-score-${grade}`}><span className='lmat-scan-summary-grade-icon'><Icon name={score >= 70 ? 'yes' : 'warning'} /></span>{scanned ? label : __('Not scanned', 'linguator-pro')}</span></div></div>
    </div></div></div>
}

const Summary = ({ report, groups }) => <div className='lmat-scan-summary-counts' aria-label={__('Scan counts', 'linguator-pro')}>
    {[
        ['crit', __('Critical', 'linguator-pro'), groups.filter(result => result.status === 'critical').length, 'dismiss', __('Important', 'linguator-pro')],
        ['warn', __('Warnings', 'linguator-pro'), groups.filter(result => result.status === 'warning' || result.status === 'info').length, 'warning', __('Needs attention', 'linguator-pro')],
        ['pass', __('Passed', 'linguator-pro'), groups.filter(result => result.status === 'pass').length, 'yes-alt', __('Good', 'linguator-pro')],
        ['ignored', __('Ignored', 'linguator-pro'), report.ignored_items.length, 'hidden', __('Dismissed', 'linguator-pro')],
    ].map(([key, label, count, icon, hint]) => <div key={key} className={`lmat-scan-summary-count lmat-scan-summary-count-${key}`}><Icon name={icon} /><span className='lmat-scan-summary-count-label'>{label}</span><strong>{count}</strong><span className='lmat-scan-summary-count-sub'>{hint}</span></div>)}
</div>

const Environment = ({ report }) => <section className='lmat-scan-card lmat-scan-environment-card'>
    <h2><Icon name='admin-generic' />{__('Site Setup Overview', 'linguator-pro')}</h2>
    <ul className='lmat-scan-environment-list'>{report.environment.map(item => <li key={item.label} className='lmat-scan-environment-row'>
        <span className='lmat-scan-environment-row-label'><Icon name={item.icon} />{item.label}</span>
        <span className='lmat-scan-environment-row-value'>{item.kind === 'languages' || item.kind === 'language' ? <span className='lmat-scan-lang-tags'>{(item.kind === 'languages' ? item.value : item.value ? [item.value] : []).map(slug => <span className='lmat-scan-lang-tag' key={slug}><LanguageFlag language={report.languages?.find(language => language.slug === slug)} /><span>{slug.toUpperCase()}</span></span>)}{!item.value?.length && __('None', 'linguator-pro')}</span> : item.kind === 'list' ? <ul className='lmat-scan-environment-addon-list'>{item.value.map(value => <li key={value}>{value}</li>)}</ul> : item.kind === 'link' ? <a className='lmat-scan-environment-row-link' href={item.url} target='_blank' rel='noopener noreferrer'>{item.value}<Icon name='external' /></a> : item.value}</span>
    </li>)}</ul>
</section>

const Breakdown = ({ report, onGoto }) => {
    const routing = report.results.filter(result => result.section === 'urls')
    const routingScore = routing.length ? Math.round(routing.filter(result => result.status === 'pass').length / routing.length * 100) : null
    const rows = [
        { key: 'coverage', icon: 'media-document', label: __('Content', 'linguator-pro'), score: report.health.coverage, tab: 'content', action: __('Review content', 'linguator-pro'), ok: __('All important content is translated.', 'linguator-pro'), needs: __('Some posts or pages still need translation work.', 'linguator-pro') },
        { key: 'setup', icon: 'admin-site-alt3', label: __('Site setup', 'linguator-pro'), score: report.health.setup, tab: 'site', action: __('Review setup', 'linguator-pro'), ok: __('Languages, menus, and homepage setup look healthy.', 'linguator-pro'), needs: __('Language, menu, or homepage setup needs attention.', 'linguator-pro') },
        { key: 'routing', icon: 'admin-links', label: __('URLs & Routing', 'linguator-pro'), score: routingScore, tab: 'urls', action: __('Review URLs', 'linguator-pro'), ok: __('Language URLs and routing look healthy.', 'linguator-pro'), needs: __('Some language URLs, slugs, or routing still need attention.', 'linguator-pro') },
    ]
    if (report.elementor_available) rows.splice(2, 0, { key: 'builder', icon: 'layout', label: __('Elementor Integration', 'linguator-pro'), score: report.health.builder ?? null, tab: 'elementor', action: __('Review Elementor', 'linguator-pro'), ok: __('Scanned Elementor templates passed their checks.', 'linguator-pro'), needs: __('Some Elementor templates need translation fixes.', 'linguator-pro') })
    return <section className='lmat-scan-card lmat-scan-breakdown-card'><h2><Icon name='chart-bar' />{__('Translation Health Breakdown', 'linguator-pro')}</h2><ul className='lmat-scan-breakdown-list'>{rows.map(row => <li key={row.key} className={`lmat-scan-breakdown-item lmat-scan-breakdown-${row.key} lmat-scan-breakdown-tone-${toneFor(row.score)}`}>
        <Icon name={row.icon} className='lmat-scan-breakdown-icon' /><div className='lmat-scan-breakdown-copy'><div className='lmat-scan-breakdown-main'><div className='lmat-scan-breakdown-text'><strong className='lmat-scan-breakdown-title'>{row.label}</strong><span className='lmat-scan-breakdown-desc'>{row.score === null ? (row.key === 'builder' && report.results.some(result => result.id === 'elementor_none') ? __('No saved Elementor templates were found.', 'linguator-pro') : __('Run a scan to check this area.', 'linguator-pro')) : row.score >= 90 ? row.ok : row.needs}</span></div><div className='lmat-scan-breakdown-meta'><span className='lmat-scan-breakdown-score'>{row.score === null ? '—' : `${row.score}%`}</span><button className='lmat-scan-breakdown-goto' onClick={() => onGoto(row.tab)}>{row.action}</button></div></div><div className='lmat-scan-breakdown-bar' role='meter' aria-label={row.label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={row.score ?? 0}><span style={{ width: `${row.score ?? 0}%` }} /></div></div>
    </li>)}</ul></section>
}

const Overview = ({ report, groups, cardProps, onGoto, onScan }) => {
    const critical = groups.filter(result => result.status === 'critical')
    const accordion = useAccordion(critical.map(result => result.id))
    const partial = Object.keys(report.partial_scans || {})
    return <>
        {partial.length > 0 && <div className='lmat-scan-partial-scan-notice' role='status'><Icon name='info' /><p>{sprintf(__('Some sections were rescanned on their own (%s). Run a full scan to refresh everything together.', 'linguator-pro'), partial.map(key => SECTIONS.find(section => section.key === key)?.label || key).join(', '))}</p></div>}
        <div className='lmat-scan-top-grid'>{report.has_scan ? <Breakdown report={report} onGoto={onGoto} /> : <section className='lmat-scan-card lmat-scan-first-scan'><div className='lmat-scan-section-heading'><div><h2><Icon name='search' />{__('Run your first scan', 'linguator-pro')}</h2><p>{__('Scan your site to check languages, URLs, content, menus, strings and the language switcher. Your health score and any issues found will appear when the scan is complete.', 'linguator-pro')}</p></div></div><p className='lmat-scan-first-scan-note'>{__('It only scans your site, no changes are made to any content or settings.', 'linguator-pro')}</p><div className='lmat-scan-card-actions'><button className='button button-primary' disabled={cardProps.busy || cardProps.blocked} onClick={onScan}>{__('Scan Website', 'linguator-pro')}</button></div></section>}<Environment report={report} /></div>
        {critical.length > 0 ? <section className='lmat-scan-card lmat-scan-fix-order'><div className='lmat-scan-section-heading'><div><h2>{__('Fix These First', 'linguator-pro')}</h2><p>{__('Critical issues that are most likely to break the visitor experience.', 'linguator-pro')}</p></div></div><div className='lmat-scan-card-stack'>{critical.map(result => <IssueCard key={result.id} result={result} whyHow open={accordion.isOpen(result.id)} onToggle={accordion.toggle(result.id)} {...cardProps} />)}</div></section> : report.has_scan && <section className='lmat-scan-card lmat-scan-overview-clear'><Icon name='yes-alt' /><div><h2>{__('No critical issues', 'linguator-pro')}</h2><p>{__('Nothing needs urgent attention. Open the other tabs to review warnings.', 'linguator-pro')}</p></div></section>}
    </>
}

const DOCUMENT_TYPES = { header: __('Headers', 'linguator-pro'), footer: __('Footers', 'linguator-pro'), single: __('Single templates', 'linguator-pro'), archive: __('Archives', 'linguator-pro'), popup: __('Popups', 'linguator-pro'), 'loop-item': __('Loop items', 'linguator-pro'), section: __('Sections', 'linguator-pro'), container: __('Containers', 'linguator-pro'), page: __('Pages', 'linguator-pro'), 'wp-page': __('Pages', 'linguator-pro'), 'wp-post': __('Posts', 'linguator-pro'), 'error-404': __('404 pages', 'linguator-pro'), 'search-results': __('Search results', 'linguator-pro'), elementor_library: __('Templates', 'linguator-pro'), elementor_component: __('Components', 'linguator-pro') }

const SectionPanel = ({ section, report, groups, cardProps, onScan, onGoto, onRestore }) => {
    const [severity, setSeverity] = useState('all')
    const [search, setSearch] = useState('')
    const [type, setType] = useState('')
    const results = groups.filter(result => result.section === section.key)
    const needsElementorScan = section.key === 'elementor' && !results.length && !report.partial_scans?.elementor
    const problems = results.filter(result => result.status !== 'pass')
    const critical = problems.filter(result => result.status === 'critical')
    const passed = results.filter(result => result.status === 'pass')
    const ignored = report.ignored_items.filter(item => item.section === section.key)
    const typeOf = row => section.key === 'elementor' ? row.template_type : row.post_type
    const typeCounts = useMemo(() => {
        const counts = {}
        problems.forEach(result => (result.details || []).forEach(row => { const key = typeOf(row); if (key) counts[key] = (counts[key] || 0) + 1 }))
        return counts
    }, [groups, section.key])
    const visible = problems.filter(result => result.status !== 'critical' && (severity === 'all' || result.status === severity) && ((!type && !search) || (result.details || []).some(row => (!type || typeOf(row) === type) && (!search || String(row.label).toLowerCase().includes(search.toLowerCase())))))
    const accordion = useAccordion(severity === 'pass' ? ['passed'] : severity === 'ignored' ? ['ignored'] : visible.map(result => result.id))
    useEffect(() => accordion.reset(), [severity, search, type])
    return <section className={`lmat-scan-card lmat-scan-grouped-section ${section.key === 'content' ? 'lmat-scan-content-section' : ''}`}>
        <div className='lmat-scan-section-heading'><div><h2><Icon name={section.icon} />{section.label}</h2>{section.key === 'elementor' && report.elementor_summary?.checked ? <ul className='lmat-scan-elementor-stats'><li><Icon name='media-document' />{sprintf(_n('Scanned %d document', 'Scanned %d documents', report.elementor_summary.checked, 'linguator-pro'), report.elementor_summary.checked)}</li><li><Icon name='screenoptions' />{sprintf(_n('%d widget', '%d widgets', report.elementor_summary.widgets || 0, 'linguator-pro'), report.elementor_summary.widgets || 0)}</li>{report.health.builder != null && <li><Icon name='heart' />{sprintf(__('Health %d%%', 'linguator-pro'), report.health.builder)}</li>}</ul> : <p>{section.blurb}</p>}</div><div className='lmat-scan-section-scan-bar'><button className='button button-secondary' disabled={cardProps.busy || cardProps.blocked} onClick={() => onScan(section.key)}><Icon name='update' />{section.scanLabel}</button><p className='lmat-scan-section-scan-meta'><Icon name='clock' />{sprintf(__('Last checked: %s', 'linguator-pro'), needsElementorScan ? __('Not scanned yet', 'linguator-pro') : report.partial_scans?.[section.key] || report.time_formatted)}</p></div></div>
        <div className='lmat-scan-section-filters'>
            {section.key === 'content' && <div className='lmat-scan-filter-search'><Icon name='search' /><input className='lmat-scan-filter-search-input' type='search' aria-label={__('Search by title', 'linguator-pro')} placeholder={__('Search by title...', 'linguator-pro')} value={search} onChange={event => setSearch(event.target.value)} /></div>}
            <div className='lmat-scan-filter-pills lmat-scan-filter-pills--severity' role='group' aria-label={__('Filter by severity', 'linguator-pro')}>
                <Pill active={severity === 'all'} onClick={() => setSeverity('all')} count={problems.length}>{__('All Issues', 'linguator-pro')}</Pill>
                {Object.entries(STATUS).map(([key, value]) => <Pill key={key} active={severity === key} onClick={() => setSeverity(key)} count={results.filter(result => result.status === key).length}>{value.label}</Pill>)}
                <Pill active={severity === 'ignored'} onClick={() => setSeverity('ignored')} count={ignored.length}>{__('Ignored', 'linguator-pro')}</Pill>
            </div>
            {(section.key === 'content' || section.key === 'elementor') && Object.keys(typeCounts).length > 1 && <div className='lmat-scan-filter-pills lmat-scan-filter-pills--types'><Pill active={!type} onClick={() => setType('')}>{__('All types', 'linguator-pro')}</Pill>{Object.entries(typeCounts).map(([key, count]) => <Pill key={key} active={type === key} onClick={() => setType(key)} count={count}>{section.key === 'elementor' ? (DOCUMENT_TYPES[key] || key) : key === 'page' ? __('Pages', 'linguator-pro') : key === 'post' ? __('Posts', 'linguator-pro') : key}</Pill>)}</div>}
        </div>
        {critical.length > 0 && (severity === 'all' || severity === 'critical') && <div className='lmat-scan-critical-pointer'><Icon name='dismiss' /><p>{sprintf(__('%d critical issue types in this area are listed in Fix These First.', 'linguator-pro'), critical.length)}</p><button className='button button-secondary' onClick={() => onGoto('overview')}>{__('Open Overview', 'linguator-pro')}</button></div>}
        {severity !== 'pass' && severity !== 'ignored' && <div className='lmat-scan-card-stack'>{visible.map(result => <IssueCard key={`${result.id}:${severity}:${search}:${type}`} result={result} open={accordion.isOpen(result.id)} onToggle={accordion.toggle(result.id)} filters={{ search, type }} {...cardProps} />)}{!visible.length && !(critical.length && (severity === 'all' || severity === 'critical')) && <p role='status' className='lmat-scan-url-empty'>{needsElementorScan ? __('Run Scan Elementor to check your templates.', 'linguator-pro') : problems.length ? __('No issues match this filter.', 'linguator-pro') : __('No issues in this section.', 'linguator-pro')}</p>}</div>}
        {(severity === 'all' || severity === 'pass') && <PassedChecks key={`passed-${severity}`} results={passed} open={accordion.isOpen('passed')} onToggle={accordion.toggle('passed')} />}
        {(severity === 'all' || severity === 'ignored') && <IgnoredPanel key={`ignored-${severity}`} languages={report.languages || []} open={accordion.isOpen('ignored')} onToggle={accordion.toggle('ignored')} items={ignored} busy={cardProps.busy} onRestore={onRestore} />}
        {severity === 'ignored' && !ignored.length && <p role='status'>{__('No ignored items in this section.', 'linguator-pro')}</p>}
    </section>
}

const Coverage = ({ report }) => <section className='lmat-scan-card lmat-scan-coverage-card'><div className='lmat-scan-section-heading'><div><h2><Icon name='chart-area' />{__('Translation Coverage', 'linguator-pro')}</h2><p>{__('How complete each language is. Counts published default-language posts, pages and custom post types linked in Linguator. Menus and strings are checked in Site Setup.', 'linguator-pro')}</p></div></div>
    {!report.coverage.length ? <p>{__('No coverage data yet. Run a scan with at least two languages.', 'linguator-pro')}</p> : <ul className='lmat-scan-coverage-list'><li className='lmat-scan-coverage-list-head' aria-hidden='true'><span>{__('Language', 'linguator-pro')}</span><span>{__('Coverage', 'linguator-pro')}</span></li>{report.coverage.map(row => <li key={row.slug} className={`lmat-scan-coverage-row lmat-scan-coverage-tone-${toneFor(row.percent)}`}><span className='lmat-scan-coverage-lang'><span className='lmat-scan-coverage-lang-flag'><LanguageFlag language={report.languages?.find(language => language.slug === row.slug)} /></span><strong>{row.name}</strong></span><span className='lmat-scan-coverage-meter-wrap' title={sprintf(__('%1$d of %2$d translated', 'linguator-pro'), row.translated, row.total)}><span className='lmat-scan-coverage-meter' role='meter' aria-label={row.name} aria-valuemin={0} aria-valuemax={100} aria-valuenow={row.percent}><span style={{ width: `${row.percent}%` }} /></span><strong className='lmat-scan-coverage-score'>{row.percent}%</strong></span></li>)}</ul>}
    {report.coverage.some(row => row.sampled) && <p>{__('Large sites are sampled: only the most recent items per post type are counted.', 'linguator-pro')}</p>}
</section>

const Scan = () => {
    const [report, setReport] = useState(null)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [busy, setBusy] = useState(false)
    // The open tab lives in the URL hash, so a reload or a shared link comes back to the same tab.
    const [tab, setTabState] = useState(tabFromHash)
    const setTab = useCallback(key => {
        setTabState(key)
        try { window.history.replaceState(null, '', `#${TAB_HASH}${key}`) } catch (error) { /* The tab still changes without a hash. */ }
    }, [])
    useEffect(() => {
        const onHash = () => setTabState(tabFromHash())
        window.addEventListener('hashchange', onHash)
        return () => window.removeEventListener('hashchange', onHash)
    }, [])
    const [diff, setDiff] = useState(null)
    const [rowStatus, setRowStatus] = useState({})
    const setStatus = useCallback((id, value) => setRowStatus(current => { const next = { ...current }; value ? next[id] = value : delete next[id]; return next }), [])
    // Rescan rows: show Checking…, then whether each issue is gone, as the Polylang addon does.
    const checkKeys = useCallback(async (id, section, keys) => {
        const statusId = keys.join(',')
        setStatus(statusId, { state: 'checking', message: __('Checking…', 'linguator-pro') })
        try {
            const next = await request('/recheck', 'POST', { id, section, keys })
            if (keysInReport(next, keys)) {
                setReport(next)
                setStatus(statusId, { state: 'present', message: __('This issue is still present.', 'linguator-pro') })
                setTimeout(() => setStatus(statusId, null), 3000)
            } else {
                setStatus(statusId, { state: 'resolved', message: __('This issue is now resolved.', 'linguator-pro') })
                await new Promise(resolve => setTimeout(resolve, 1200))
                setReport(next)
                setStatus(statusId, null)
            }
            return next
        } catch (error) {
            setStatus(statusId, null)
            return null
        }
    }, [])
    const recheckRow = async (result, row) => {
        const keys = row.targets.map(target => target.ignore_key).filter(Boolean)
        if (!await checkKeys(row.source_id || result.members?.[0]?.id || result.id, result.section, keys)) toast.error(__('The row could not be rechecked. Refresh the results and try again.', 'linguator-pro'))
    }
    const fail = error => toast.error(error?.message || __('Something went wrong. Please try again.', 'linguator-pro'))
    const load = useCallback(() => { setLoading(true); setError(null); request('').then(setReport).catch(setError).finally(() => setLoading(false)) }, [])
    useEffect(load, [load])
    const call = useCallback(async (path, data, success) => {
        setBusy(true)
        try { setReport(await request(path, 'POST', data)); if (success) toast.success(success) } catch (error) { fail(error) } finally { setBusy(false) }
    }, [])
    const pendingBusy = useRef(false)
    const runPending = useCallback(async () => {
        if (pendingBusy.current) return
        const items = readPending()
        if (!items.length) return
        writePending([])
        for (const item of items) {
            if (item.keys?.length) { setTab(item.section); await checkKeys(item.id, item.section, item.keys); continue }
            const statusId = 'issue:' + item.id
            setStatus(statusId, { state: 'checking', message: __('Checking…', 'linguator-pro') })
            try {
                const next = await request('/recheck', 'POST', { id: item.id, section: item.section })
                const present = next.results?.some(result => result.id === item.id && result.status !== 'pass')
                setStatus(statusId, { state: present ? 'present' : 'resolved', message: present ? __('This issue is still present.', 'linguator-pro') : __('This issue is now resolved.', 'linguator-pro') })
                if (!present) await new Promise(resolve => setTimeout(resolve, 1100))
                setReport(next)
            } catch (error) {
                setStatus(statusId, { state: 'present', message: __('Could not check', 'linguator-pro') })
            }
        }
    }, [checkKeys])
    useEffect(() => {
        if (loading || !report) return
        runPending()
        const onVisible = () => { if (document.visibilityState === 'visible') runPending() }
        document.addEventListener('visibilitychange', onVisible)
        window.addEventListener('focus', runPending)
        return () => { document.removeEventListener('visibilitychange', onVisible); window.removeEventListener('focus', runPending) }
    }, [loading, !!report, runPending])
    const trackFix = event => {
        const link = event.target.closest?.('a[href]')
        const holder = link?.closest('[data-track-id]')
        if (!link || !holder || link.getAttribute('href').startsWith('#')) return
        let keys = []
        try { keys = JSON.parse(holder.dataset.trackKeys || '[]') } catch (error) { keys = [] }
        queuePending({ id: holder.dataset.trackId, section: holder.dataset.trackSection, keys })
    }
    // A saved tab can point at a section this site does not have, such as Elementor after it was deactivated.
    useEffect(() => {
        if (report && tab === 'elementor' && !report.elementor_available && !report.results.some(result => result.section === 'elementor')) setTab('overview')
    }, [report, tab, setTab])
    const groups = useMemo(() => groupResults(report?.results || []), [report])
    if (loading) return <div role='status' className='lmat-inspector-loading'><Icon name='update' />{__('Loading scan results…', 'linguator-pro')}</div>
    if (!report) return <div role='alert'><h2>{__('Unable to load scan results', 'linguator-pro')}</h2><p>{error?.message}</p><button className='button' onClick={load}>{__('Try again', 'linguator-pro')}</button></div>
    const cardProps = {
        busy, blocked: !!report.unassigned, languages: report.languages || [],
        onIgnore: key => call('/ignore', { key, ignore: true }),
        onIgnoreMany: async keys => {
            setBusy(true)
            try { for (const key of [...new Set(keys)]) setReport(await request('/ignore', 'POST', { key, ignore: true })) } catch (error) { fail(error) } finally { setBusy(false) }
        },
        onRecheck: result => result.members?.length > 1 ? call('/section', { section: result.section }, __('Issues rechecked.', 'linguator-pro')) : call('/recheck', { id: result.members?.[0]?.id || result.id, section: result.section }, __('Issue rechecked.', 'linguator-pro')),
        rowStatus, onRecheckRow: recheckRow,
        onDiff: row => setDiff(row),
    }
    const scan = () => call('/run', undefined, __('Scan complete.', 'linguator-pro'))
    const current = SECTIONS.find(section => section.key === tab)
    const tabs = TABS.filter(item => item.key !== 'elementor' || report.elementor_available || report.results.some(result => result.section === 'elementor'))
    const selectTabByKey = event => {
        const index = tabs.findIndex(item => item.key === tab)
        let next
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length
        if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length
        if (event.key === 'Home') next = 0
        if (event.key === 'End') next = tabs.length - 1
        if (next !== undefined) { event.preventDefault(); setTab(tabs[next].key); event.currentTarget.parentElement.querySelectorAll('[role="tab"]')[next]?.focus() }
    }
    return <div className='lmat-inspector lmat-scan-wrap'>
        {report.unassigned && <div className='lmat-scan-nolang-notice' role='alert'>
            <Icon name='warning' />
            <p>{__('There are posts, pages, categories or tags without a language. Set them all to the default language so they can be scanned and translated. Scanning is paused until then.', 'linguator-pro')}</p>
            <button type='button' className='button button-primary' disabled={busy} onClick={() => call('/assign-default-language', undefined, __('Default language assigned.', 'linguator-pro'))}>{__('Set to default language', 'linguator-pro')}</button>
        </div>}
        <section className='lmat-scan-summary-card'><div className='lmat-scan-page-header'>
            <div className='lmat-scan-summary-branding'><span className='lmat-scan-brand-mark' aria-hidden='true'><svg viewBox='0 0 40 48'><path fill='#1e293b' d='M20 2.5C14.2 6.4 8.6 8.4 2.5 9.2V24H20V2.5z' /><path fill='#fff' stroke='#1e293b' strokeWidth='.8' d='M20 2.5c5.8 3.9 11.4 5.9 17.5 6.7V24H20V2.5zM2.5 24C2.5 34.8 9.6 42.3 20 45.7V24H2.5z' /><path fill='#1e293b' d='M37.5 24C37.5 34.8 30.4 42.3 20 45.7V24h17.5z' /><path fill='none' stroke='#1e293b' strokeWidth='1.8' d='M20 2.5C14.2 6.4 8.6 8.4 2.5 9.2v14.6c0 10.8 7.1 18.3 17.5 21.7 10.4-3.4 17.5-10.9 17.5-21.7V9.2C31.4 8.4 25.8 6.4 20 2.5z' /></svg></span><div className='lmat-scan-summary-branding-text'><h1 className='lmat-scan-title'>{__('Translation Inspector', 'linguator-pro')}</h1><p className='lmat-scan-sub'>{__('Find and fix translation issues across your multilingual website.', 'linguator-pro')}</p></div></div>
            <div className='lmat-scan-header-actions'><span className={`lmat-scan-cta${!report.has_scan ? ' is-prompt' : ''}`}><button className={`button button-primary lmat-scan-rescan-button${busy ? ' is-loading' : ''}`} disabled={busy || !!report.unassigned} title={report.unassigned ? __('Set the content without a language to the default language first, then scan.', 'linguator-pro') : undefined} onClick={scan}>{busy ? <Icon name='update' /> : <img src={report.scan_icon_url} className='lmat-scan-icon lmat-scan-icon--button' alt='' width={16} height={16} />}{busy ? __('Scanning…', 'linguator-pro') : __('Scan Website', 'linguator-pro')}</button></span><p className='lmat-scan-last-scan'><Icon name='calendar-alt' />{sprintf(__('Last scan: %s', 'linguator-pro'), report.time_formatted || __('never', 'linguator-pro'))}</p></div>
        </div><div className='lmat-scan-summary-body'><Score score={report.has_scan ? report.health.score : 0} scanned={report.has_scan} />{report.has_scan && <Summary report={report} groups={groups} />}</div></section>
        <div className='lmat-scan-report-stack'><nav className='lmat-scan-section-nav' aria-label={__('Report sections', 'linguator-pro')}><div className='lmat-scan-section-nav-list' role='tablist' aria-label={__('Report sections', 'linguator-pro')}>{tabs.map(item => <button key={item.key} id={`scan-tab-${item.key}`} type='button' role='tab' aria-selected={tab === item.key} aria-controls='scan-panel' tabIndex={tab === item.key ? 0 : -1} className={`lmat-scan-nav-tab${tab === item.key ? ' is-active' : ''}`} onKeyDown={selectTabByKey} onClick={() => setTab(item.key)}><Icon name={item.icon} /><span className='lmat-scan-nav-tab-label'>{item.label}</span></button>)}</div></nav>
            <div id='scan-panel' onPointerDownCapture={trackFix} role='tabpanel' aria-labelledby={`scan-tab-${tab}`} aria-busy={busy} className='lmat-scan-tab-panel' tabIndex={0}>
                {tab === 'overview' && <Overview report={report} groups={groups} cardProps={cardProps} onGoto={setTab} onScan={scan} />}
                {current && <SectionPanel key={current.key} section={current} report={report} groups={groups} cardProps={cardProps} onGoto={setTab} onScan={section => call('/section', { section }, __('Scan finished. Results are up to date.', 'linguator-pro'))} onRestore={key => call('/ignore', { key, ignore: false })} />}
                {tab === 'coverage' && <Coverage report={report} />}
            </div>
        </div>
        {diff && <DiffModal row={diff} request={request} onClose={() => setDiff(null)} onSynced={next => { setReport(next); setDiff(null); toast.success(__('Marked as up to date.', 'linguator-pro')) }} onError={fail} />}
        {busy && <div className='lmat-inspector-overlay' role='status' aria-live='polite'><div><Icon name='update' /><p>{__('Updating scan results…', 'linguator-pro')}</p></div></div>}
    </div>
}
export default Scan
