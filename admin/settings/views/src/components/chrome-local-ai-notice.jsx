import React, { useMemo, useState, useRef } from 'react';
import { __, sprintf } from '@wordpress/i18n';

function CopyableCode({ text }) {
	const [copied, setCopied] = useState(false);
	const timeoutRef = useRef(null);

	const handleCopy = () => {
		const onSuccess = () => {
			setCopied(true);
			if (timeoutRef.current) clearTimeout(timeoutRef.current);
			timeoutRef.current = setTimeout(() => setCopied(false), 2000);
		};

		if (navigator && navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(onSuccess).catch(() => fallbackCopyTextToClipboard(text, onSuccess));
		} else {
			fallbackCopyTextToClipboard(text, onSuccess);
		}
	};

	const fallbackCopyTextToClipboard = (text, onSuccess) => {
		const textArea = document.createElement("textarea");
		textArea.value = text;
		
		// Avoid scrolling to bottom
		textArea.style.top = "0";
		textArea.style.left = "0";
		textArea.style.position = "fixed";

		document.body.appendChild(textArea);
		textArea.focus();
		textArea.select();

		try {
			const successful = document.execCommand('copy');
			if (successful) {
				onSuccess();
			}
		} catch (err) {
			console.error('Fallback: Oops, unable to copy', err);
		}

		document.body.removeChild(textArea);
	};

	return (
		<span style={{ position: 'relative', display: 'inline-block' }}>
			<code
				onClick={handleCopy}
				style={{ cursor: 'pointer', transition: 'opacity 0.2s', padding: '2px 6px', background: '#e5e7eb', borderRadius: '4px', display: 'inline-flex', alignItems: 'center', gap: '6px' }}
				onMouseEnter={(e) => (e.currentTarget.style.opacity = '0.7')}
				onMouseLeave={(e) => (e.currentTarget.style.opacity = '1')}
				title={__('Click to copy', 'translate-words')}
			>
				<span>{text}</span>
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ flexShrink: 0, opacity: 0.8 }}>
					<rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
					<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
				</svg>
			</code>
			{copied && (
				<span style={{
					position: 'absolute',
					bottom: '120%',
					left: '50%',
					transform: 'translateX(-50%)',
					backgroundColor: '#1f2937',
					color: '#fff',
					padding: '4px 8px',
					borderRadius: '4px',
					fontSize: '12px',
					whiteSpace: 'nowrap',
					zIndex: 9999,
					boxShadow: '0 4px 6px rgba(0,0,0,0.1)'
				}}>
					{__('Copied to clipboard', 'translate-words')}
					<span style={{
						position: 'absolute',
						top: '100%',
						left: '50%',
						transform: 'translateX(-50%)',
						borderWidth: '5px',
						borderStyle: 'solid',
						borderColor: '#1f2937 transparent transparent transparent'
					}}></span>
				</span>
			)}
		</span>
	);
}

/**
 * Chrome Translator API compatibility notice (shared by settings + setup wizard).
 *
 * @param {Object} [props]
 * @param {string} [props.className] Extra classes on the outer card.
 * @param {Object} [props.style]     Extra inline styles (merged after base card styles).
 */
export function ChromeLocalAINotice({ className = '', style: extraStyle = {}, isEdge = false }) {
	const noticeType = useMemo(() => {
		const isHttps = window?.location?.protocol === 'https:';
		const isSecureContext = Boolean(window?.isSecureContext);

		const apiAvailable =
			('translation' in window?.self && 'createTranslator' in window?.self?.translation) ||
			('ai' in window?.self && 'translator' in window?.self?.ai) ||
			('Translator' in window?.self && 'create' in window?.self?.Translator);

		const hasChromeObject = Object.prototype.hasOwnProperty.call(window ?? {}, 'chrome');
		const userAgent = navigator?.userAgent ?? '';
		
		let actualIsEdge = false;
		let actualIsChrome = false;

		if (navigator?.userAgentData?.brands) {
			navigator.userAgentData.brands.forEach((data) => {
				if (data.brand === 'Google Chrome') {
					actualIsChrome = true;
				} else if (data.brand === 'Microsoft Edge') {
					actualIsEdge = true;
				}
			});
		} else {
			if (userAgent.includes('Edg')) {
				actualIsEdge = true;
			} else if (hasChromeObject) {
				actualIsChrome = true;
			}
		}

		if (isEdge) {
			if (!actualIsEdge) {
				return 'browser';
			}
		} else {
			if (!actualIsChrome) {
				return 'browser';
			}
		}

		if (!apiAvailable && !isHttps && !isSecureContext) {
			return 'secure';
		}

		if (!apiAvailable) {
			return 'api';
		}

		return null;
	}, [isEdge]);

	if (!noticeType) {
		return null;
	}

	const browserName = isEdge ? 'Edge' : 'Chrome';
	const scheme = isEdge ? 'edge' : 'chrome';
	const docUrl = isEdge ? 'https://microsoftedge.github.io/Demos/built-in-ai/playgrounds/translator-api/' : 'https://developer.chrome.com/docs/ai/translator-api';

	const outerClass = ['flex', 'flex-col', 'gap-4', 'p-6', 'rounded-lg', className].filter(Boolean).join(' ');

	return (
		<div
			className={outerClass}
			style={{ border: '1px solid #e5e7eb', background: '#fff5f5', ...extraStyle }}
		>
			<div className="text-red-600 text-sm leading-6">
				<h3 className="font-semibold">
					{noticeType === 'browser' && __('Important Notice: Browser Compatibility', 'translate-words')}
					{noticeType === 'secure' && __('Important Notice: Secure Connection Required', 'translate-words')}
					{noticeType === 'api' && __('Important Notice: API Availability', 'translate-words')}
				</h3>

				{noticeType === 'browser' && (
					<ul className="list-disc ml-5 mt-2">
						<li>
							{__('The ', 'translate-words')}<strong>{__('Translator API', 'translate-words')}</strong>{__(', which uses ', 'translate-words')}{browserName}{__(' Local AI Models, is designed exclusively for use with the ', 'translate-words')}<strong>{browserName}{__(' browser', 'translate-words')}</strong>.
						</li>
						<li>
							{__(
								'If you are using a different browser (such as Firefox, or Safari), the API may not function correctly.',
								'translate-words'
							)}
						</li>
						<li>
							{__('Learn more in the ', 'translate-words')}
							<a
								className="underline text-blue-600"
								href={docUrl}
								rel="noreferrer noopener"
								target="_blank"
							>
								{__('official documentation', 'translate-words')}
							</a>.
						</li>
					</ul>
				)}

				{noticeType === 'secure' && (
					<>
						<ul className="list-disc ml-5 mt-2">
							<li>
								{__('The Translator API requires a secure (HTTPS) connection to function properly.', 'translate-words')}
							</li>
							<li>{__('If you are on an insecure connection (HTTP), the API will not work.', 'translate-words')}</li>
						</ul>

						<p className="mt-2">{__('How to fix this:', 'translate-words')}</p>
						<ol className="list-decimal ml-5 mt-2">
							<li>
								{__('Use a secure connection (https://).', 'translate-words')}
							</li>
							<li>
								{sprintf(__('Or add your site to %s’s “insecure origins treated as secure”.', 'translate-words'), browserName)}{' '}
								<CopyableCode text={`${scheme}://flags/#unsafely-treat-insecure-origin-as-secure`} />
							</li>
						</ol>
					</>
				)}

				{noticeType === 'api' && (
					<>
						<ol className="list-decimal ml-5 mt-2">
							<li>
								{isEdge 
                                    ? __('Ensure your Edge browser is updated to version 140 or newer.', 'translate-words') 
                                    : __('Ensure your Chrome browser is updated to version 131 or newer.', 'translate-words')}
							</li>
							<li>
								{sprintf(__('Open this URL in a new %s tab:', 'translate-words'), browserName)}{' '}
								<CopyableCode text={`${scheme}://flags/#translation-api`} />
							</li>
							<li>
								{__('Set “Experimental translation API” to Enabled.', 'translate-words')}
							</li>
							<li>
								{__('Click Relaunch to apply changes.', 'translate-words')}
							</li>
                            <li>
                                {isEdge
                                    ? __('Visit the Edge settings to install the required language packs.', 'translate-words')
                                    : (
                                        <>
                                            {__('To install the language pack, open a new tab and paste this URL:', 'translate-words')}{' '}
                                            <CopyableCode text={`${scheme}://on-device-translation-internals`} />
                                        </>
                                    )}
                            </li>
							<li>
								{__('The Translator AI option should now be enabled.', 'translate-words')}
							</li>
						</ol>
						<p className="mt-2">
							<a
								className="underline text-blue-600"
								href={docUrl}
								rel="noreferrer noopener"
								target="_blank"
							>
								{__('Documentation', 'translate-words')}
							</a>
						</p>
						<p>
							{sprintf(__('If the issue persists, please ensure %s is up to date and restart the browser.', 'translate-words'), browserName)}
						</p>
						<p>
							<a
								className="underline text-blue-600"
								href="https://my.coolplugins.net/account/support-tickets/"
								rel="noreferrer noopener"
								target="_blank"
							>
								{__('Open a support ticket', 'translate-words')}
							</a>
						</p>
					</>
				)}
			</div>
		</div>
	);
}
