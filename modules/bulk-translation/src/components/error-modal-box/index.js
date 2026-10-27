import CopyClipboard from "../copy-clipboard/index.js";
import { useEffect } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import DOMPurify from 'dompurify';

const PURIFY_OPTS = { ADD_ATTR: ['target', 'rel', 'data-clipboard-text'] };

const ErrorModalBox = ({ message, onClose, Title, prefix, children, beforeClose }) => {

    let stringifiedMessage = '';
    if (message) {
        let dummyElement = jQuery('<div>').append(message);
        stringifiedMessage = dummyElement.html() || (typeof message === 'string' ? message : '');
        dummyElement.remove();
        dummyElement = null;
    }

    if (!stringifiedMessage || !String(stringifiedMessage).replace(/<[^>]*>/g, '').trim()) {
        stringifiedMessage = __('An unexpected error occurred during bulk translation.', 'linguator-pro');
    }

    useEffect(() => {
        const clipboardElements = document.querySelectorAll('.chrome-ai-translator-flags');

        const onClipboardClick = (e) => {
            e.preventDefault();
            const element = e.currentTarget;
            const toolTipExists = element.querySelector(`.${prefix}-tooltip`);

            if (toolTipExists) {
                return;
            }

            let toolTipElement = document.createElement('span');
            toolTipElement.textContent = "Text to be copied.";
            toolTipElement.className = `${prefix}-tooltip`;
            element.appendChild(toolTipElement);

            CopyClipboard({
                text: element.getAttribute('data-clipboard-text'),
                startCopyStatus: () => {
                    toolTipElement.classList.add(`${prefix}-tooltip-active`);
                },
                endCopyStatus: () => {
                    setTimeout(() => {
                        toolTipElement.remove();
                    }, 800);
                }
            });
        };

        if (clipboardElements.length > 0) {
            clipboardElements.forEach(element => {
                element.classList.add(`${prefix}-tooltip-element`);
                element.addEventListener('click', onClipboardClick);
            });
        }

        const onReloadClick = (e) => {
            const btn = e.target.closest(`.${prefix}-error-reload-btn`);
            if (!btn) {
                return;
            }
            e.preventDefault();
            window.location.reload();
        };

        document.addEventListener('click', onReloadClick);

        return () => {
            clipboardElements.forEach(element => {
                element.removeEventListener('click', onClipboardClick);
            });
            document.removeEventListener('click', onReloadClick);
        };
    }, [prefix, stringifiedMessage]);

    return (
        <div className={`${prefix}-error-modal-box-container`}>
            <div className={`${prefix}-error-modal-box`}>
                <div className={`${prefix}-header`}>
                    <div className={`${prefix}-modal-header-inner`}>
                        {Title && <h2>{Title}</h2>}
                    </div>
                    <button
                        type="button"
                        className={`${prefix}-modal-close`}
                        onClick={onClose}
                        title={__('Close', 'linguator-pro')}
                        aria-label={__('Close', 'linguator-pro')}
                    >
                        &times;
                    </button>
                </div>
                <div className={`${prefix}-error-modal-box-body`}>
                    <p dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(stringifiedMessage, PURIFY_OPTS) }} />
                    {children}
                </div>
                <div className={`${prefix}-footer`}>
                    {beforeClose}
                    <button
                        type="button"
                        className={`${prefix}-footer-button button button-primary`}
                        onClick={onClose}
                    >
                        {__('Back', 'linguator-pro')}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default ErrorModalBox;
