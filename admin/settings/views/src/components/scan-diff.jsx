import React, { useEffect, useRef, useState } from 'react'
import { __, sprintf } from '@wordpress/i18n'
import { Icon } from './scan-parts'

const TYPE_LABEL = { changed: __('Changed', 'translate-words'), added: __('Added', 'translate-words'), removed: __('Removed', 'translate-words') }

// "See what changed" dialog: the default-language text now versus when the translation was last up to date.
const DiffModal = ({ row, request, onClose, onSynced, onError }) => {
    const [split, setSplit] = useState(true)
    const [data, setData] = useState(null)
    const [message, setMessage] = useState('')
    const [saving, setSaving] = useState(false)
    const dialog = useRef(null)
    const options = row.options || []
    const [current, setCurrent] = useState(row)

    useEffect(() => {
        let cancelled = false
        setData(null)
        setMessage('')
        request(`/diff?translation_id=${current.translation_id}&split=${split ? 'true' : 'false'}`)
            .then(result => { if (!cancelled) setData(result) })
            .catch(error => { if (!cancelled) setMessage(error?.message || __('The changes could not be loaded.', 'translate-words')) })
        return () => { cancelled = true }
    }, [current.translation_id, split])

    useEffect(() => {
        const previous = document.activeElement
        dialog.current?.focus()
        const onKey = event => {
            if (event.key === 'Escape') onClose()
            if (event.key !== 'Tab' || !dialog.current) return
            const items = [...dialog.current.querySelectorAll('a[href], button:not([disabled])')]
            if (!items.length) return
            const first = items[0]
            const last = items[items.length - 1]
            if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.current)) {
                event.preventDefault()
                last.focus()
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault()
                first.focus()
            }
        }
        document.addEventListener('keydown', onKey)
        return () => {
            document.removeEventListener('keydown', onKey)
            previous?.focus?.()
        }
    }, [])

    const markSynced = async () => {
        setSaving(true)
        try {
            onSynced(await request('/mark-synced', 'POST', { translation_id: current.translation_id }))
        } catch (error) {
            setMessage(error?.message || __('Could not update the translation.', 'translate-words'))
            onError?.(error)
        } finally {
            setSaving(false)
        }
    }

    return <div className='lmat-scan-diff-modal-overlay' onMouseDown={event => event.target === event.currentTarget && onClose()}>
        <div ref={dialog} className='lmat-scan-diff-modal' role='dialog' aria-modal='true' aria-labelledby='scan-diff-title' tabIndex={-1}>
            <div className='lmat-scan-diff-modal-header'>
                <h2 className='lmat-scan-diff-modal-title' id='scan-diff-title'>{__('What changed in the source', 'translate-words')}</h2>
                <button type='button' className='lmat-scan-diff-modal-close' aria-label={__('Close', 'translate-words')} onClick={onClose}>×</button>
            </div>
            <div className='lmat-scan-diff-modal-toolbar'>
                <div className='lmat-scan-diff-view-toggle' role='group' aria-label={__('Diff layout', 'translate-words')}>
                    <button type='button' className={split ? 'active' : ''} aria-pressed={split} onClick={() => setSplit(true)}>{__('Side by side', 'translate-words')}</button>
                    <button type='button' className={split ? '' : 'active'} aria-pressed={!split} onClick={() => setSplit(false)}>{__('Inline', 'translate-words')}</button>
                </div>
                {options.length > 1 && <div className='lmat-scan-diff-view-toggle' role='group' aria-label={__('Language', 'translate-words')}>
                    {options.map(option => <button key={option.translation_id} type='button' className={option.translation_id === current.translation_id ? 'active' : ''} aria-pressed={option.translation_id === current.translation_id} onClick={() => setCurrent(option)}>{String(option.language || '').toUpperCase()}</button>)}
                </div>}
            </div>
            <div className='lmat-scan-diff-modal-body' aria-live='polite'>
                {!data && !message && <p className='lmat-scan-diff-modal-loading'><Icon name='update' /> {__('Loading changes…', 'translate-words')}</p>}
                {message && <p className='lmat-scan-diff-error' role='alert'>{message}</p>}
                {data && <>
                    <div className='lmat-scan-diff-modal-intro'>
                        <p><strong>{data.source_title}</strong>{data.baseline_human && ` — ${sprintf(__('compared with the source %s ago', 'translate-words'), data.baseline_human)}`}</p>
                        {data.baseline_date && <p className='description'>{data.baseline_date}</p>}
                        {data.partial && <p className='description'>{__('Only the title and body can be compared, because no saved copy of the earlier source exists.', 'translate-words')}</p>}
                    </div>
                    {!data.has_baseline && <p className='description'>{__('No saved copy of the earlier source exists, so the exact changes cannot be shown. After you mark it as up to date, future changes will be shown.', 'translate-words')}</p>}
                    {data.has_baseline && !data.fields.length && <p className='description'>{__('No text difference was found. If nothing needs translating, mark it as up to date.', 'translate-words')}</p>}
                    {data.fields.map(field => <div key={field.key} className='lmat-scan-diff-field-block'>
                        <h3 className='lmat-scan-diff-field-title'>{field.label} <span className={`lmat-scan-change-tag lmat-scan-change-tag--${field.type}`}>{TYPE_LABEL[field.type] || field.type}</span></h3>
                        {field.truncated && <p className='description'>{__('Only the first part of this text is compared.', 'translate-words')}</p>}
                        {/* Built and sanitized on the server with wp_text_diff() and wp_kses(). */}
                        <div className='lmat-scan-diff-table-wrap' dangerouslySetInnerHTML={{ __html: field.diff }} />
                    </div>)}
                </>}
            </div>
            <div className='lmat-scan-diff-modal-footer'>
                {data?.source_edit_url && <a className='button' href={data.source_edit_url} target='_blank' rel='noopener noreferrer'>{__('Open source', 'translate-words')}</a>}
                {data?.edit_url && <a className='button' href={data.edit_url} target='_blank' rel='noopener noreferrer'>{__('Edit translation', 'translate-words')}</a>}
                {data?.can_sync && <button type='button' className='button button-primary' disabled={saving} onClick={markSynced}>{saving ? __('Updating…', 'translate-words') : __('Mark as up to date', 'translate-words')}</button>}
                <button type='button' className='button button-secondary' onClick={onClose}>{__('Close', 'translate-words')}</button>
            </div>
        </div>
    </div>
}

export default DiffModal
