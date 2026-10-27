import React, { useState, useEffect, useRef } from 'react'
import { Button, Checkbox, Container, Input, Label, RadioButton, Badge } from '@bsf/force-ui'
import { Languages, Link } from 'lucide-react';
import { RiDraftLine } from "react-icons/ri";
import { __, sprintf } from '@wordpress/i18n'
import apiFetch from "@wordpress/api-fetch"
import { getNonce } from '../utils'
import { toast } from 'sonner'
import ApiKey from './api-key';
import ProviderList from './provider-list.jsx';

const TranslationConfig = ({ data, setData }) => {

    const aiTranslation = data?.ai_translation_configuration; //store the media option
    const provider = aiTranslation?.provider;
    const browserType = (() => {
        let type = 'Other';
        if (navigator && navigator.userAgentData && navigator.userAgentData.brands) {
            // Edge is Chromium-based, so its brands list holds both "Microsoft Edge" and
            // "Google Chrome"; check Edge first so it is not mistaken for Chrome.
            const brands = navigator.userAgentData.brands;
            if (brands.some(data => data.brand === 'Microsoft Edge')) {
                type = 'Edge';
            } else if (brands.some(data => data.brand === 'Google Chrome')) {
                type = 'Chrome';
            }
        } else {
            if (navigator.userAgent && navigator.userAgent.includes('Edg')) {
                type = 'Edge';
            } else if (window.hasOwnProperty('chrome')) {
                type = 'Chrome';
            }
        }
        return type;
    })();

    const allowedProviders = Array.isArray(window?.lmat_settings?.allowed_providers)
        ? window.lmat_settings.allowed_providers
        : ['chrome_local_ai', 'google'];
    const wpAiClientAvailable = allowedProviders.includes('gemini');
    const [googleMachineTranslation, setGoogleMachineTranslation] = useState(provider?.google)
    const [chromeLocalAITranslation, setChromeLocalAITranslation] = useState(provider?.chrome_local_ai)
    const [edgeLocalAITranslation, setEdgeLocalAITranslation] = useState(provider?.edge_local_ai)
    const [geminiTranslation, setGeminiTranslation] = useState(Boolean(provider?.gemini) && wpAiClientAvailable)
    // Provider the translation popups pre-select. Older installs have no saved value, which means Google.
    // A default that is switched off cannot be pre-selected, so it counts as no default.
    const savedDefaultSetting = aiTranslation?.default_provider ?? 'google'
    const savedDefaultProvider = (savedDefaultSetting && provider?.[savedDefaultSetting]) ? savedDefaultSetting : ''
    const [defaultProvider, setDefaultProvider] = useState(savedDefaultProvider)
    const geminiConfigured = Boolean(data?.api_keys_configuration?.keys?.gemini)
    const [lastUpdatedValue, setLastUpdatedValue] = useState({ googleMachineTranslation, chromeLocalAITranslation, edgeLocalAITranslation, geminiTranslation, defaultProvider })
    const [bulkTranslationPostStatus, setBulkTranslationPostStatus] = useState(aiTranslation?.bulk_translation_post_status || 'draft')
    const [slugTranslationOption, setSlugTranslationOption] = useState(aiTranslation?.slug_translation_option || 'title_translate')
    const [handleButtonDisabled, setHandleButtonDisabled] = useState(true)
    const [isSaving, setIsSaving] = useState(false)
    const [apiKeyDirty, setApiKeyDirty] = useState(false)
    const geminiApiKeyRef = useRef(null)

    useEffect(() => {
        if (!geminiTranslation) {
            setApiKeyDirty(false)
        }
    }, [geminiTranslation])

    const hasChanges = () => {
        return googleMachineTranslation !== provider?.google ||
            chromeLocalAITranslation !== provider?.chrome_local_ai ||
            edgeLocalAITranslation !== provider?.edge_local_ai ||
            geminiTranslation !== (Boolean(provider?.gemini) && wpAiClientAvailable) ||
            defaultProvider !== savedDefaultProvider ||
            bulkTranslationPostStatus !== (aiTranslation?.bulk_translation_post_status || 'draft') ||
            slugTranslationOption !== (aiTranslation?.slug_translation_option || 'title_translate');
    };

    useEffect(() => {
        const geminiSectionOpen = wpAiClientAvailable && geminiTranslation
        setHandleButtonDisabled(!hasChanges() && !(geminiSectionOpen && apiKeyDirty))
    }, [chromeLocalAITranslation, edgeLocalAITranslation, googleMachineTranslation, geminiTranslation, defaultProvider, bulkTranslationPostStatus, slugTranslationOption, wpAiClientAvailable, apiKeyDirty])

    const providerStates = {
        google: [googleMachineTranslation, setGoogleMachineTranslation],
        chrome_local_ai: [chromeLocalAITranslation, setChromeLocalAITranslation],
        edge_local_ai: [edgeLocalAITranslation, setEdgeLocalAITranslation],
        gemini: [geminiTranslation, setGeminiTranslation],
    }

    // The default provider has to stay enabled, so picking one switches it on.
    const handleDefaultProviderChange = (providerKey) => {
        setDefaultProvider(providerKey)
        providerStates[providerKey]?.[1](true)
    }

    //Save Setting Function 
    async function SaveSettings() {
        try {
            if (isSaving) return
            setIsSaving(true)
            let apiBody;
            const apiKeyPayload =
                wpAiClientAvailable && geminiTranslation && geminiApiKeyRef.current?.getPendingPayload
                    ? geminiApiKeyRef.current.getPendingPayload()
                    : null;

            // Require Gemini API key when enabling Gemini.
            if (wpAiClientAvailable && geminiTranslation) {
                const pendingGeminiKey = (apiKeyPayload?.keys?.gemini || '').toString().trim()
                const hasConfiguredGeminiKey = Boolean(geminiApiKeyRef.current?.hasConfiguredKey?.('gemini'))
                if (!hasConfiguredGeminiKey && pendingGeminiKey === '') {
                    throw new Error(__('Please add a Gemini API key to continue.', 'linguator-pro'))
                }
            }

            apiBody = {
                ai_translation_configuration: {
                    provider: {
                        google: googleMachineTranslation,
                        chrome_local_ai: chromeLocalAITranslation,
                        edge_local_ai: edgeLocalAITranslation,
                        ...(wpAiClientAvailable ? {
                            gemini: geminiTranslation,
                        } : {}),
                    },
                    default_provider: defaultProvider,
                    bulk_translation_post_status: bulkTranslationPostStatus,
                    slug_translation_option: slugTranslationOption
                }
            }
            if (apiKeyPayload?.keys) {
                apiBody.keys = apiKeyPayload.keys
            }
            if (apiKeyPayload?.models) {
                apiBody.models = apiKeyPayload.models
            }

            setLastUpdatedValue({ googleMachineTranslation, chromeLocalAITranslation, edgeLocalAITranslation, geminiTranslation, defaultProvider, bulkTranslationPostStatus, slugTranslationOption })
            if (aiTranslation && (
                lastUpdatedValue.googleMachineTranslation !== googleMachineTranslation ||
                lastUpdatedValue.chromeLocalAITranslation !== chromeLocalAITranslation ||
                lastUpdatedValue.edgeLocalAITranslation !== edgeLocalAITranslation ||
                (wpAiClientAvailable && lastUpdatedValue.geminiTranslation !== geminiTranslation) ||
                lastUpdatedValue.defaultProvider !== defaultProvider ||
                lastUpdatedValue.bulkTranslationPostStatus !== bulkTranslationPostStatus ||
                lastUpdatedValue.slugTranslationOption !== slugTranslationOption
            )) {
                setData(prev => ({
                    ...prev,
                    ...apiBody
                }))
            }
            //API Call (single settings request includes Gemini keys/models when present)
            const response = apiFetch({
                path: 'lmat/v1/settings',
                method: 'POST',
                'headers': {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': getNonce()
                },
                body: JSON.stringify(apiBody)
            })
                .then((settingsResponse) => {
                    setData(prev => ({ ...prev, ...settingsResponse }))
                    if (apiKeyPayload && geminiApiKeyRef.current?.syncAfterParentSave) {
                        geminiApiKeyRef.current.syncAfterParentSave({
                            keys: apiBody.keys,
                            models: apiBody.models,
                        }, settingsResponse)
                        setApiKeyDirty(false)
                    }
                    return settingsResponse
                })
                .catch(error => {
                    // Handle general API errors
                    if (error?.message) {
                        throw new Error(error.message);
                    }
                    throw new Error(__("Something went wrong", 'linguator-pro'));
                });

            toast.promise(response, {
                loading: __('Saving Settings', 'linguator-pro'),
                success: __('Settings Saved', 'linguator-pro'),
                error: (error) => error.message
            })
            await response
                .then(() => {
                    setHandleButtonDisabled(true)
                })
                // Avoid double notifications: toast.promise already shows the error message.
                .catch(() => { })
                .finally(() => {
                    setIsSaving(false)
                })

        } catch (error) {
            setIsSaving(false)
            // Handle domain validation errors
            if (error.message.includes(__("Linguator was unable to access", "linguator-pro"))) {
                toast.error(error.message);
            } else {
                toast.error(error.message || __("Something went wrong", "linguator-pro"));
            }
        }
    }

    const postStatusOptions = [
        {
            heading: __('Published', 'linguator-pro'),
            value: 'publish'
        },
        {
            heading: __('Draft', 'linguator-pro'),
            value: 'draft'
        }
    ]

    const slugTranslationOptions = [
        {
            heading: __('Use Translated Title', 'linguator-pro'),
            value: 'title_translate'
        },
        {
            heading: __('Translate Original Slug', 'linguator-pro'),
            value: 'slug_translate'
        },
        {
            heading: __('Keep Original Slug', 'linguator-pro'),
            value: 'slug_keep'
        }
    ]

    return (
        <Container className='bg-white p-10 rounded-lg' cols="1" containerType='grid'>
            <Container className='flex items-center'>
                <Container.Item className='flex flex-wrap items-center w-full justify-between px-4 gap-6'>
                    <h1 className='font-bold'>{__('Translation Settings', 'linguator-pro')}</h1>
                    <Button
                        disabled={handleButtonDisabled || isSaving}
                        className=""
                        iconPosition="left"
                        size="md"
                        tag="button"
                        type="button"
                        onClick={SaveSettings}
                        variant="primary"
                    >
                        {__('Save Settings', 'linguator-pro')}
                    </Button>
                </Container.Item>
            </Container>
            <hr className="w-full border-b-0 border-x-0 border-t border-solid border-t-border-subtle" />
            <Container.Item className='p-6 rounded-lg' style={{ border: "1px solid #e5e7eb" }}>
                <Label size='md' className='font-bold flex items-center gap-2'>
                    <Languages className="flex-shrink-0 size-5 text-icon-secondary" />
                    {__('Service Provider', 'linguator-pro')}
                </Label>
                <Label variant='help'>
                    {__('Select at least one translation service provider below. You can enable multiple providers and choose the one that best fits your needs.', 'linguator-pro')}
                </Label>
                <ProviderList
                    browserType={browserType}
                    defaultProvider={defaultProvider}
                    providers={{
                        google: { enabled: googleMachineTranslation, setEnabled: setGoogleMachineTranslation },
                        chrome_local_ai: { enabled: chromeLocalAITranslation, setEnabled: setChromeLocalAITranslation },
                        edge_local_ai: { enabled: edgeLocalAITranslation, setEnabled: setEdgeLocalAITranslation },
                        gemini: { enabled: geminiTranslation, setEnabled: setGeminiTranslation },
                    }}
                    geminiAvailable={wpAiClientAvailable}
                    geminiConfigured={geminiConfigured}
                    onDefaultChange={handleDefaultProviderChange}
                    geminiExtra={(
                        <div className="pt-0">
                            <ApiKey
                                ref={geminiApiKeyRef}
                                data={data}
                                setData={setData}
                                embedded
                                onPendingChange={setApiKeyDirty}
                            />
                        </div>
                    )}
                />
            </Container.Item>
            <hr className="w-full border-b-0 border-x-0 border-t border-solid border-t-border-subtle" />
            <Container.Item>
                <Label size='md' className='font-bold flex items-center gap-2'>
                    <RiDraftLine className="flex-shrink-0 size-5 text-icon-secondary" />
                    {__('Bulk Translation – Default Post and Page Status', 'linguator-pro')}
                </Label>
                <Label variant='help'>
                    {__('This is the default post and page status for bulk translation.', 'linguator-pro')}
                </Label>
                <div style={{ marginTop: "20px" }}>
                    <RadioButton.Group className="flex flex-col sm:flex-row gap-4 flex-wrap">
                        {postStatusOptions.map((postStatus, index) => (
                            <RadioButton.Button
                                badgeItem={<Badge className="mr-2" size="sm" type="rounded" variant="green" />}
                                label={{
                                    heading: postStatus.heading,
                                }}
                                reversePosition={true}
                                value={postStatus.value}
                                key={index}
                                checked={bulkTranslationPostStatus === postStatus.value}
                                onChange={() => {
                                    setBulkTranslationPostStatus(postStatus.value);
                                }}
                            />

                        ))}
                    </RadioButton.Group>
                </div>
            </Container.Item>
            <hr className="w-full border-b-0 border-x-0 border-t border-solid border-t-border-subtle" />
            <Container.Item>
                <Label size='md' className='font-bold flex items-center gap-2'>
                    <Link className="flex-shrink-0 size-5 text-icon-secondary" />
                    {__('Slug Translation Settings', 'linguator-pro')}
                </Label>
                <Label variant='help'>{__('Choose how post slugs (URLs) are generated when content is translated.', 'linguator-pro')}</Label>
                <div style={{ marginTop: "20px" }}>
                    <RadioButton.Group className="flex flex-col sm:flex-row gap-4 flex-wrap">
                        {slugTranslationOptions.map((slugOption, index) => (
                            <RadioButton.Button
                                badgeItem={<Badge className="mr-2" size="sm" type="rounded" variant="green" />}
                                label={{
                                    heading: slugOption.heading,
                                }}
                                reversePosition={true}
                                value={slugOption.value}
                                key={index}
                                checked={slugTranslationOption === slugOption.value}
                                onChange={() => {
                                    setSlugTranslationOption(slugOption.value)
                                }}
                            />
                        ))}

                    </RadioButton.Group>
                </div>
            </Container.Item>
            <hr className="w-full border-b-0 border-x-0 border-t border-solid border-t-border-subtle" />
            <Container className='flex items-center justify-end'>
                <Container.Item className='flex gap-6'>
                    <Button
                        disabled={handleButtonDisabled || isSaving}
                        className=""
                        iconPosition="left"
                        size="md"
                        tag="button"
                        type="button"
                        onClick={SaveSettings}
                        variant="primary"
                    >
                        {__('Save Settings', 'linguator-pro')}
                    </Button>

                </Container.Item>
            </Container>
        </Container>
    )
}

export default TranslationConfig;