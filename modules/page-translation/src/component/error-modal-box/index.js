import CopyClipboard from "../copy-clipboard/index.js";
import { useEffect } from "@wordpress/element";
import DOMPurify from 'dompurify';
import { __ } from "@wordpress/i18n";

const ErrorModalBox = ({ message, onClose, Title }) => {

    let dummyElement = jQuery('<div>').append(message);
    const stringifiedMessage = dummyElement.html();
    dummyElement.remove();
    dummyElement = null;

    useEffect(() => {
        const clipboardElements = document.querySelectorAll('.chrome-ai-translator-flags');

        if (clipboardElements.length > 0) {
            clipboardElements.forEach(element => {

                element.classList.add('lmat-page-translation-tooltip-element');

                element.addEventListener('click', (e) => {
                    e.preventDefault();
                    const toolTipExists = element.querySelector('.lmat-page-translation-tooltip');
                    
                    if(toolTipExists){
                        return;
                    }

                    let toolTipElement = document.createElement('span');
                    toolTipElement.textContent = "Text to be copied.";
                    toolTipElement.className = 'lmat-page-translation-tooltip';
                    element.appendChild(toolTipElement);

                    CopyClipboard({ text: element.getAttribute('data-clipboard-text'), startCopyStatus: () => {
                        toolTipElement.classList.add('lmat-page-translation-tooltip-active');
                    }, endCopyStatus: () => {
                        setTimeout(() => {
                            toolTipElement.remove();
                        }, 800);
                    } });
                });
            });

            return () => {
                clipboardElements.forEach(element => {
                    element.removeEventListener('click', () => { });
                });
            };
        }
    }, []);

    return (
        <div className="lmat-page-translation-error-modal-box-container">
            <div className="lmat-page-translation-error-modal-box">
                <div className="lmat-page-translation-error-modal-box-header">
                    {Title && <h3>{Title}</h3>}
                    <button type="button" aria-label={__('Close', 'linguator-pro')} className="lmat-page-translation-error-modal-box-close" onClick={onClose}>&times;</button>
                </div>
                <div className="lmat-page-translation-error-modal-box-body">
                    <p dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(stringifiedMessage) }} />
                </div>
                <div className="lmat-page-translation-error-modal-box-footer">
                    <button type="button" className="lmat-page-translation-error-modal-box-close button button-primary" onClick={onClose}>&#8592; {__('Back', 'linguator-pro')}</button>
                </div>
            </div>
        </div>
    );
};

export default ErrorModalBox;
