import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Input, Switch } from '@bsf/force-ui';
import React, { useContext, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { setupContext } from '../pages/setup-page';
import SetupContinueButton, { SetupBackButton } from './setup-continue-button';
import { getNonce } from '../utils';

import { ChromeIcon } from '../../../../assets/js/src/icons/chrome';
import { EdgeIcon } from '../../../../assets/js/src/icons/edge';
import { GeminiIcon } from '../../../../assets/js/src/icons/gemini';
import { GoogleIcon } from '../../../../assets/js/src/icons/google';
import { ChromeLocalAINotice } from '../../../../admin/settings/views/src/components/chrome-local-ai-notice.jsx';

const AiTranslation = () => {
	const { setSetupProgress, data, setData } = useContext(setupContext);
	const aiTranslation = data?.ai_translation_configuration ?? {};
	const provider = aiTranslation?.provider ?? {};

	const wpAiClientAvailable = Array.isArray(window?.lmat_setup?.allowed_providers)
		? window.lmat_setup.allowed_providers.includes('gemini')
		: Boolean(window?.lmat_setup?.wp_ai_client_available);

	const browserType = (() => {
		let type = 'Other';
		if (navigator && navigator.userAgentData && navigator.userAgentData.brands) {
			navigator.userAgentData.brands.forEach(data => {
				if (data.brand === 'Google Chrome') {
					type = 'Chrome';
				} else if (data.brand === 'Microsoft Edge') {
					type = 'Edge';
				}
			});
		} else {
			if (navigator.userAgent && navigator.userAgent.includes('Edg')) {
				type = 'Edge';
			} else if (window.hasOwnProperty('chrome')) {
				type = 'Chrome';
			}
		}
		return type;
	})();

	const showChromeRow = browserType !== 'Edge';
	const showEdgeRow = browserType !== 'Chrome';

	const [googleMachineTranslation, setGoogleMachineTranslation] = useState(Boolean(provider?.google));
	const [chromeLocalAITranslation, setChromeLocalAITranslation] = useState(Boolean(provider?.chrome_local_ai));
	const [edgeLocalAITranslation, setEdgeLocalAITranslation] = useState(Boolean(provider?.edge_local_ai));
	const [geminiTranslation, setGeminiTranslation] = useState(Boolean(provider?.gemini) && wpAiClientAvailable);
	const [geminiApiKeyDraft, setGeminiApiKeyDraft] = useState('');
	const geminiMaskedKey = (data?.api_keys_configuration?.keys?.gemini || '').toString();
	const hasGeminiSavedKey = geminiMaskedKey.trim() !== '';
	const geminiApiKeyDisplayValue =
		(geminiApiKeyDraft || '') === '' && hasGeminiSavedKey ? geminiMaskedKey : geminiApiKeyDraft;
	const geminiApiKeyInputDisabled = hasGeminiSavedKey;

	const lastSavedRef = useRef({
		chrome_local_ai: Boolean(provider?.chrome_local_ai),
		edge_local_ai: Boolean(provider?.edge_local_ai),
		google: Boolean(provider?.google),
		gemini: Boolean(provider?.gemini),
	});

	useEffect(() => {
		setGoogleMachineTranslation(Boolean(provider?.google));
		setChromeLocalAITranslation(Boolean(provider?.chrome_local_ai));
		setEdgeLocalAITranslation(Boolean(provider?.edge_local_ai));
		setGeminiTranslation(Boolean(provider?.gemini));

		lastSavedRef.current = {
			chrome_local_ai: Boolean(provider?.chrome_local_ai),
			edge_local_ai: Boolean(provider?.edge_local_ai),
			google: Boolean(provider?.google),
			gemini: Boolean(provider?.gemini),
		};
	}, [provider]);

	const handleBack = () => {
		const mediaEnabled = window?.lmat_setup?.media === '1';

		if (mediaEnabled) {
			setSetupProgress('media');
			localStorage.setItem('setupProgress', 'media');
			return;
		}

		setSetupProgress('url');
		localStorage.setItem('setupProgress', 'url');
	};

	const saveAITranslation = async () => {
		try {
			// If Gemini is enabled and user provided an API key in the wizard,
			// validate it using the same endpoint/settings-panel logic before continuing.
			const normalizedGeminiKey = (geminiApiKeyDraft || '').toString().replace(/\s+/g, '').trim();
			if (wpAiClientAvailable && geminiTranslation && '' !== normalizedGeminiKey) {
				const resp = await apiFetch({
					path: 'lmat/v1/settings',
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': getNonce(),
					},
					body: JSON.stringify({
						keys: {
							gemini: normalizedGeminiKey,
						},
					}),
				});
				// Sync masked key back into wizard state.
				if (resp) {
					setData((prev) => ({ ...(prev || {}), ...(resp || {}) }));
				}
				setGeminiApiKeyDraft('');
			}

			const nextProvider = {
				chrome_local_ai: chromeLocalAITranslation,
				edge_local_ai: edgeLocalAITranslation,
				google: googleMachineTranslation,
				...(wpAiClientAvailable
					? {
						gemini: geminiTranslation,
					}
					: {}),
			};

			const prevProvider = lastSavedRef.current ?? {};
			const hasChanges =
				prevProvider.google !== nextProvider.google ||
				prevProvider.chrome_local_ai !== nextProvider.chrome_local_ai ||
				prevProvider.edge_local_ai !== nextProvider.edge_local_ai ||
				(wpAiClientAvailable && prevProvider.gemini !== nextProvider.gemini);

			if (hasChanges) {
				const response = await apiFetch({
					path: 'lmat/v1/settings',
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': getNonce(),
					},
					body: JSON.stringify({
						ai_translation_configuration: {
							provider: nextProvider,
						},
					}),
				});

				lastSavedRef.current = { ...nextProvider };
				setData((prev) => ({ ...(prev || {}), ...(response || {}) }));
			}

			setSetupProgress('language_switcher');
			localStorage.setItem('setupProgress', 'language_switcher');
		} catch (error) {
			toast.error(error?.message || __('Please try again later', 'translate-words'));
		}
	};

	return (
		<div className="mx-auto p-10 max-w-[600px] min-h-[40vh] bg-white shadow-sm flex flex-col">
			<div className="flex-grow">
				<h2>{__('Translation Configuration', 'translate-words')}</h2>
				<p className="text-justify text-sm/6">
					{__(
						'Turn on AI translation if you need to translate the content of your website using AI. If not, you can leave it off.',
						'translate-words'
					)}
				</p>

				<div
					className="flex justify-between items-center p-6 rounded-lg"
					style={{ border: '1px solid #e5e7eb', marginBottom: '10px' }}
				>
					<div className="flex items-center gap-2">
						<GoogleIcon className="w-4 h-4" />
						<p className="text-sm/6">{__('Google Machine Translation', 'translate-words')}</p>
					</div>
					<Switch
						aria-label={__('Google Machine Translation', 'translate-words')}
						id="google-machine-translation"
						onChange={() => setGoogleMachineTranslation((prev) => !prev)}
						size="sm"
						value={googleMachineTranslation}
					/>
				</div>

				{showChromeRow && (
					<div className="p-6 rounded-lg" style={{ border: '1px solid #e5e7eb' }}>
						<div className="flex justify-between items-center">
							<div className="flex items-center gap-2">
								<ChromeIcon className="w-4 h-4" />
								<p className="text-sm/6">{__('Chrome Local AI Translation', 'translate-words')}</p>
							</div>
							<Switch
								aria-label={__('Chrome Local AI Translation', 'translate-words')}
								id="chrome-local-ai-translation"
								onChange={() => setChromeLocalAITranslation((prev) => !prev)}
								size="sm"
								value={chromeLocalAITranslation}
							/>
						</div>
						{chromeLocalAITranslation && <ChromeLocalAINotice />}
					</div>
				)}

				{showEdgeRow && (
					<div className="p-6 rounded-lg" style={{ border: '1px solid #e5e7eb', marginTop: '10px' }}>
						<div className="flex justify-between items-center">
							<div className="flex items-center gap-2">
								<EdgeIcon className="w-4 h-4" />
								<p className="text-sm/6">{__('Edge Local AI Translation', 'translate-words')}</p>
							</div>
							<Switch
								aria-label={__('Edge Local AI Translation', 'translate-words')}
								id="edge-local-ai-translation"
								onChange={() => setEdgeLocalAITranslation((prev) => !prev)}
								size="sm"
								value={edgeLocalAITranslation}
							/>
						</div>
						{edgeLocalAITranslation && <ChromeLocalAINotice isEdge={true} />}
					</div>
				)}

				{wpAiClientAvailable && (
					<>
						<div className="p-6 rounded-lg" style={{ border: '1px solid #e5e7eb', marginTop: '10px' }}>
							<div className="flex justify-between items-center">
								<div className="flex items-center gap-2">
									<GeminiIcon className="w-4 h-4" />
									<p className="text-sm/6">{__('Google Gemini AI', 'translate-words')}</p>
								</div>
								<Switch
									aria-label={__('Google Gemini AI', 'translate-words')}
									id="gemini-translation"
									onChange={() => setGeminiTranslation((prev) => !prev)}
									size="sm"
									value={geminiTranslation}
								/>
							</div>

							{geminiTranslation && (
								<div className="mt-4 flex flex-col gap-4">
									{hasGeminiSavedKey ? (
										<p className="text-base font-semibold m-0">
											{__('Gemini API key', 'translate-words')}
										</p>
									) : (
										<div
											className="p-4 rounded-lg"
											style={{
												border: '1px solid #e5e7eb',
												background: '#fffbeb',
											}}
										>
											<p className="text-base font-semibold m-0">
												{__('Gemini API key (optional)', 'translate-words')}
											</p>
											<p className="text-sm/6 m-0 mt-2" style={{ color: '#92400e' }}>
												{__(
													'You can skip adding an API key for now and continue with the setup. To use Gemini translation features, you\'ll need to add a valid API key later from the ',
													'translate-words'
												)}
												<a
													className="underline"
													href={`${window?.lmat_setup?.admin_url || ''}admin.php?page=lmat_settings&tab=translation`}
													target="_blank"
													rel="noreferrer noopener"
												>
													{__('Settings panel.', 'translate-words')}
												</a>
											</p>
										</div>
									)}

									<div className="flex flex-col gap-2 w-full" style={{ width: '100%' }}>
										<div style={{ width: '100%' }}>
											<Input
												aria-label={__('Gemini API key', 'translate-words')}
												id="gemini-api-key"
												size="md"
												type="text"
												placeholder={__('Enter your API key', 'translate-words')}
												disabled={geminiApiKeyInputDisabled}
												value={geminiApiKeyDisplayValue}
												onChange={(v) => {
													if (geminiApiKeyInputDisabled) {
														return;
													}
													setGeminiApiKeyDraft(v);
												}}
											/>
										</div>

										{hasGeminiSavedKey ? (
											<p className="text-xs/5 text-gray-600 m-0" style={{ wordBreak: 'break-word' }}>
												{__('Manage your Gemini API key in the ', 'translate-words')}
												<a
													className="underline"
													href={`${window?.lmat_setup?.admin_url || ''}admin.php?page=lmat_settings&tab=translation`}
													target="_blank"
													rel="noreferrer noopener"
												>
													{__('Settings panel.', 'translate-words')}
												</a>
											</p>
										) : (
											<p className="text-xs/5 text-gray-600 m-0" style={{ wordBreak: 'break-word' }}>
												<a
													className="underline"
													href="https://aistudio.google.com/app/api-keys"
													target="_blank"
													rel="noreferrer noopener"
												>
													{__('Get Gemini API key', 'translate-words')}
												</a>
											</p>
										)}
									</div>
								</div>
							)}
						</div>
					</>
				)}
			</div>

			<div className="flex justify-between" style={{ marginTop: '14px' }}>
				<SetupBackButton handleClick={handleBack} />
				<SetupContinueButton SaveSettings={saveAITranslation} />
			</div>
		</div>
	);
};

export default AiTranslation;