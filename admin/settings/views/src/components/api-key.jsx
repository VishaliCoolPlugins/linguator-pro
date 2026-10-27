import React, { useEffect, useRef, useState, useCallback, forwardRef, useImperativeHandle } from 'react'
import { Button, Container, Input, Label } from '@bsf/force-ui'
import apiFetch from "@wordpress/api-fetch"
import { toast } from 'sonner'
import { __, sprintf } from '@wordpress/i18n'
import { getNonce } from '../utils'

const providerKeyLinks = {
  gemini: 'https://aistudio.google.com/app/api-keys',
}

const providerKeyLabels = {
  gemini: 'Gemini',
}

const providerMeta = [
  {
    key: 'gemini',
    modelKey: 'gemini_model',
    heading: __('Add Gemini API key', 'translate-words'),
    modelHeading: __('Select Gemini Model', 'translate-words'),
    placeholder: __('Enter your API key', 'translate-words'),
  },
]

const ApiKey = forwardRef(function ApiKey({ data, setData, embedded = false, onPendingChange }, ref) {
  const normalizeApiKey = useCallback((v) => (v || '').toString().replace(/\s+/g, '').trim(), [])
  const [loading, setLoading] = useState(true)
  const [masked, setMasked] = useState({ gemini: '' })
  const [configured, setConfigured] = useState({ gemini: false })
  const [keyDrafts, setKeyDrafts] = useState({ gemini: '' })
  const [availableModels, setAvailableModels] = useState({ gemini: [] })
  const [models, setModels] = useState({
    gemini_model: 'gemini-2.5-flash',
  })
  const [handleButtonDisabled, setHandleButtonDisabled] = useState(true)
  const initialModelsRef = useRef({
    gemini_model: 'gemini-2.5-flash',
  })

  const computeHasPendingSave = useCallback(() => {
    const hasKeyChanges = Object.values(keyDrafts).some((v) => (v || '').trim() !== '')
    const initial = initialModelsRef.current || {}
    const hasModelChanges =
      (models.gemini_model || '') !== (initial.gemini_model || '')
    return hasKeyChanges || hasModelChanges
  }, [keyDrafts, models])

  useEffect(() => {
    const config = data?.api_keys_configuration
    if (!config) return

    const keys = config?.keys || {}
    const m = config?.models || {}
    const discovered = config?.available_models || {}

    const nextMasked = {
      gemini: keys?.gemini || '',
    }

    setMasked(nextMasked)
    setConfigured({
      gemini: Boolean(nextMasked.gemini),
    })
    const nextModels = {
      gemini_model: m?.gemini_model || 'gemini-2.5-flash',
    }
    setModels(nextModels)
    initialModelsRef.current = nextModels
    setAvailableModels({
      gemini:
        Array.isArray(discovered?.gemini) || (discovered?.gemini && typeof discovered.gemini === 'object')
          ? discovered.gemini
          : [],
    })
    setLoading(false)
  }, [data?.api_keys_configuration])

  // Fallback: if settings didn't include api_keys_configuration for some reason, fetch once.
  useEffect(() => {
    if (data?.api_keys_configuration) return
    let cancelled = false

    async function load() {
      try {
        const resp = await apiFetch({
          path: 'lmat/v1/settings',
          method: 'GET',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': getNonce(),
          },
        })
        if (cancelled) return

        const config = resp?.api_keys_configuration || {}
        const keys = config?.keys || {}
        const m = config?.models || {}
        const discovered = config?.available_models || {}

        const nextMasked = {
          gemini: keys?.gemini || '',
        }

        setMasked(nextMasked)
        setConfigured({
          gemini: Boolean(nextMasked.gemini),
        })
        const nextModels = {
          gemini_model: m?.gemini_model || 'gemini-2.5-flash',
        }
        setModels(nextModels)
        initialModelsRef.current = nextModels
        setAvailableModels({
          gemini:
            Array.isArray(discovered?.gemini) || (discovered?.gemini && typeof discovered.gemini === 'object')
              ? discovered.gemini
              : [],
        })
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    load()
    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    const hasKeyChanges = Object.values(keyDrafts).some((v) => (v || '').trim() !== '')
    const initial = initialModelsRef.current || {}
    const hasModelChanges =
      (models.gemini_model || '') !== (initial.gemini_model || '')

    setHandleButtonDisabled(!(hasKeyChanges || hasModelChanges))
  }, [keyDrafts, models, configured.gemini])

  useEffect(() => {
    if (!embedded || !onPendingChange) return
    onPendingChange(computeHasPendingSave())
  }, [embedded, onPendingChange, computeHasPendingSave])

  const persistApiKeys = useCallback(async ({ resetProvider } = {}) => {
    const keys = {}
    const modelsBody = {
      gemini_model: models.gemini_model,
    }

    for (const { key } of providerMeta) {
      const draft = normalizeApiKey(keyDrafts[key])

      if (resetProvider === key) {
        keys[key] = ''
        continue
      }

      if (draft !== '') {
        keys[key] = draft
      }
    }

    const apiBody = { keys, models: modelsBody }

    // Save via Settings route
    const resp = await apiFetch({
      path: 'lmat/v1/settings',
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': getNonce(),
      },
      body: JSON.stringify(apiBody),
    })

    if (setData && resp) {
      setData((prev) => ({ ...(prev || {}), ...(resp || {}) }))
    }

    const nextModels = {
      gemini_model: modelsBody.gemini_model,
    }
    setModels(nextModels)
    initialModelsRef.current = nextModels

    const nextConfigured = { ...configured }
    const nextMasked = { ...masked }
    const draft = normalizeApiKey(keyDrafts.gemini)
    if (resetProvider === 'gemini') {
      nextConfigured.gemini = false
      nextMasked.gemini = ''
    } else if (draft !== '') {
      nextConfigured.gemini = true
      nextMasked.gemini = `••••••••${draft.slice(-4)}`
    }
    setConfigured(nextConfigured)
    setMasked(nextMasked)

    setKeyDrafts({ gemini: '' })
    setHandleButtonDisabled(true)

    // Update models directly from the save response
    const config = resp?.api_keys_configuration || {}
    const discovered = config?.available_models || {}
    setAvailableModels({
      gemini:
        Array.isArray(discovered?.gemini) || (discovered?.gemini && typeof discovered.gemini === 'object')
          ? discovered.gemini
          : [],
    })
  }, [keyDrafts, models])

  useImperativeHandle(ref, () => ({
    hasConfiguredKey: (provider) => {
      const key = typeof provider === 'string' ? provider : ''
      if (!key) return false
      if (configured?.[key]) return true
      const m = (masked?.[key] || '').toString().trim()
      return m !== ''
    },
    getPendingPayload: () => {
      const hasKeyChanges = Object.values(keyDrafts).some((v) => (v || '').trim() !== '')
      const initial = initialModelsRef.current || {}
      const hasModelChanges = (models.gemini_model || '') !== (initial.gemini_model || '')
      if (!hasKeyChanges && !hasModelChanges) return null

      const keys = {}
      const geminiDraft = normalizeApiKey(keyDrafts.gemini)
      if (geminiDraft !== '') {
        keys.gemini = geminiDraft
      }

      const payload = {}
      if (Object.keys(keys).length) payload.keys = keys
      if (hasModelChanges) payload.models = { gemini_model: models.gemini_model }
      return payload
    },
    syncAfterParentSave: (payload, settingsResponse) => {
      if (!payload || typeof payload !== 'object') return

      if (payload.models && typeof payload.models === 'object') {
        const nextModels = {
          gemini_model: payload.models.gemini_model ?? models.gemini_model,
        }
        setModels(nextModels)
        initialModelsRef.current = nextModels
      }

      if (payload.keys && Object.prototype.hasOwnProperty.call(payload.keys, 'gemini')) {
        const v = typeof payload.keys.gemini === 'string' ? normalizeApiKey(payload.keys.gemini) : ''
        const nextConfigured = { ...configured }
        const nextMasked = { ...masked }
        if (v === '') {
          nextConfigured.gemini = false
          nextMasked.gemini = ''
        } else {
          nextConfigured.gemini = true
          nextMasked.gemini = `••••••••${v.slice(-4)}`
        }
        setConfigured(nextConfigured)
        setMasked(nextMasked)
      }

      setKeyDrafts({ gemini: '' })
      setHandleButtonDisabled(true)

      // If parent save returned models, apply them (no extra GET).
      const config = settingsResponse?.api_keys_configuration || {}
      const discovered = config?.available_models || {}
      if (discovered?.gemini) {
        setAvailableModels({
          gemini:
            Array.isArray(discovered?.gemini) || (discovered?.gemini && typeof discovered.gemini === 'object')
              ? discovered.gemini
              : [],
        })
      }
    },
  }), [keyDrafts, models, configured, masked, normalizeApiKey])

  async function SaveSettings({ resetProvider } = {}) {
    try {
      const run = persistApiKeys({ resetProvider }).catch((error) => {
        if (error?.message) {
          throw new Error(error.message)
        }
        throw new Error(__("Something went wrong", 'translate-words'))
      })

      const showToast = !embedded || resetProvider
      if (showToast) {
        toast.promise(run, {
          loading: resetProvider
            ? __('API key resetting…', 'translate-words')
            : __('Saving Settings', 'translate-words'),
          success: resetProvider
            ? __('API key reset', 'translate-words')
            : __('Settings Saved', 'translate-words'),
          error: (error) => error.message,
        })
      } else {
        await run
      }
    } catch (error) {
      toast.error(error?.message || __("Something went wrong", "translate-words"))
    }
  }

  // Keep provider visibility in sync with Translation Config + Wizard toggles (both persist to ai_translation_configuration.provider)
  const wpAiClientAvailable = Boolean(window?.lmat_settings?.wp_ai_client_available || window?.lmat_setup?.wp_ai_client_available)
  const providerConfig = data?.ai_translation_configuration?.provider
  const visibleProviders = providerMeta.filter((p) => {
    if (!wpAiClientAvailable) return false
    // Embedded under AI Translation: parent shows this only when Gemini is toggled on;
    // saved `data` may still have gemini off until "Save Settings", so always show fields.
    if (embedded) return true
    // If provider settings aren't present yet, default to showing the inputs.
    if (!providerConfig) return true
    // If provider settings exist, show only the providers that are explicitly enabled.
    return Boolean(providerConfig?.[p.key])
  })

  return (
    <Container
      className={
        embedded
          ? 'bg-transparent p-0 shadow-none'
          : 'bg-white p-10 rounded-lg shadow-sm'
      }
      cols="1"
      containerType='grid'
    >
      <Container cols="1" containerType="grid" className="gap-8">
        {visibleProviders.map((p) => {
          const isConfigured = configured[p.key]
          const draft = keyDrafts[p.key] || ''
          const displayValue = draft === '' && isConfigured ? (masked[p.key] || '') : draft
          const selectedModel = (models?.[p.modelKey] || '').trim()
          const listRaw = availableModels?.[p.key]
          const listFromApi = Array.isArray(listRaw)
            ? listRaw
            : (listRaw && typeof listRaw === 'object' ? Object.keys(listRaw) : [])
          const labelsMap = (!Array.isArray(listRaw) && listRaw && typeof listRaw === 'object') ? listRaw : {}
          const providerModelList = [
            ...listFromApi,
            ...(selectedModel && !listFromApi.includes(selectedModel) ? [selectedModel] : []),
          ]
          const getModelLabel = (id) => {
            if (labelsMap?.[id]) return labelsMap[id]
            // Some providers return version-suffixed ids (e.g. -001). Try a base-id lookup.
            const base = typeof id === 'string' ? id.replace(/-\\d+$/, '') : ''
            if (base && labelsMap?.[base]) return labelsMap[base]
            return id
          }

          return (
            <Container.Item
              key={p.key}
            >
              <h3 className={`m-0 text-base font-semibold mb-3 ${embedded ? 'pt-3' : ''}`}>
                {p.heading}
              </h3>
              <div className="flex items-start gap-3">
                <div className="flex-1" style={{ maxWidth: 400 }}>
                  <Input
                    aria-label={`${p.key}-api-key`}
                    id={`${p.key}-api-key`}
                    size="md"
                    type="text"
                    placeholder={p.placeholder}
                    disabled={isConfigured}
                    value={displayValue}
                    onChange={(v) => {
                      if (isConfigured) return
                      setKeyDrafts((prev) => ({ ...prev, [p.key]: v }))
                    }}
                  />
                  {providerKeyLinks[p.key] ? (
                    <div className="mt-2 text-sm text-gray-600">
                      {sprintf(__('Get your %s API key from ', 'translate-words'), providerKeyLabels[p.key] || p.key)}{' '}
                      <a href={providerKeyLinks[p.key]} target="_blank" rel="noopener noreferrer">
                        {__('here', 'translate-words')}
                      </a>
                    </div>
                  ) : null}
                </div>

                <div
                  className="flex-shrink-0"
                  style={embedded ? { paddingRight: '3em' } : undefined}
                >
                  <Button
                    size="md"
                    tag="button"
                    type="button"
                    variant="primary"
                    onClick={() => SaveSettings({ resetProvider: p.key })}
                    disabled={!isConfigured}
                  >
                    {__('Reset', 'translate-words')}
                  </Button>
                </div>
              </div>

              {isConfigured ? (
                <div className="mt-8">
                  <Label size="sm" className="font-medium mb-2 block">{p.modelHeading}</Label>
                  <div className="mt-0" style={{ maxWidth: 320 }}>
                    <select
                      className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm bg-white"
                      value={models[p.modelKey] || ''}
                      onChange={(e) => setModels((prev) => ({ ...prev, [p.modelKey]: e.target.value }))}
                    >
                      {providerModelList.map((id) => (
                        <option key={id} value={id}>{getModelLabel(id)}</option>
                      ))}
                    </select>
                  </div>
                </div>
              ) : null}
            </Container.Item>
          )
        })}
      </Container>
    </Container>
  )
})

ApiKey.displayName = 'ApiKey'

export default ApiKey
