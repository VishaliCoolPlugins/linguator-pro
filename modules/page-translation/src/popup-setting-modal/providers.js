import TranslateService, { getBrowserType } from "../component/translate-provider/index.js";
import ChromeLocalAiTranslator from "../component/translate-provider/local-ai-translator/local-ai-translator.js";
import { __, sprintf } from "@wordpress/i18n";
import { useEffect } from "@wordpress/element";

const BUILT_IN_AI_SERVICES = ['localAiTranslator', 'edgeLocalAiTranslator'];

const Providers = (props) => {
    const prefix = 'lmat-page-translation';
    const service = props.Service;
    const buttonDisable = props[service + "Disabled"];
    const ActiveService = TranslateService({ Service: service, [service + "ButtonDisabled"]: buttonDisable, openErrorModalHandler: props.openErrorModalHandler });

    const downloadState = service === 'localAiTranslator' ? props.chromeAiDownload : (service === 'edgeLocalAiTranslator' ? props.edgeAiDownload : null);
    const setDownloadState = service === 'localAiTranslator' ? props.setChromeAiDownload : (service === 'edgeLocalAiTranslator' ? props.setEdgeAiDownload : null);

    const handleDownload = async (e) => {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }

        if (!downloadState || !setDownloadState) return;
        if (downloadState.status === 'checking') return;
        if (downloadState.status === 'downloading' && downloadState.downloadStarted) return;
        if (!downloadState.source || !downloadState.target) return;

        setDownloadState(prev => prev ? ({ ...prev, status: 'downloading', progress: prev.progress || 0, downloadStarted: true }) : null);

        try {
            const result = await ChromeLocalAiTranslator.startLanguagePackDownload(
                downloadState.source,
                downloadState.target,
                (evt) => {
                    const pct = Math.round((evt && evt.loaded ? evt.loaded : 0) * 100);
                    setDownloadState(prev => prev ? ({ ...prev, progress: pct }) : null);
                }
            );

            if (result && result.ok) {
                // Download successful - reset download state so the card becomes selectable.
                setDownloadState(null);
            } else {
                setDownloadState(prev => prev ? ({ ...prev, status: 'failed', downloadStarted: false }) : null);
            }
        } catch (err) {
            console.error("Language pack download failed:", err);
            setDownloadState(prev => prev ? ({ ...prev, status: 'failed', downloadStarted: false }) : null);
        }
    };

    useEffect(() => {
        if (!downloadState) return;
        if (downloadState.status === 'downloadable' || (downloadState.status === 'downloading' && !downloadState.downloadStarted)) {
            handleDownload();
        }
    }, [downloadState]);

    if (!ActiveService) {
        return null;
    }

    const isSelected = props.activeProvider === service;
    const isDisabled = Boolean(ActiveService.ButtonDisabled || buttonDisable);
    const hasErrorDetails = Boolean(props.serviceModalErrors && props.serviceModalErrors[service]);
    const isBusy = Boolean(downloadState);
    const isLoading = isBusy && ['checking', 'downloading'].includes(downloadState.status);
    const isActionable = !isBusy && (isDisabled ? hasErrorDetails : true);

    let classNames = `${prefix}-provider-card`;
    if (isDisabled || isBusy) {
        classNames += ` ${prefix}-provider-card-disabled`;
    }
    if (isSelected) {
        classNames += ` ${prefix}-provider-card-selected`;
    }
    if (BUILT_IN_AI_SERVICES.includes(service) && getBrowserType() === 'Other') {
        classNames += ` ${prefix}-provider-browser-other`;
    }

    const handleCardClick = () => {
        if (isBusy) {
            return;
        }

        if (isDisabled) {
            if (hasErrorDetails) {
                props.openErrorModalHandler(service);
            }
            return;
        }

        if (props.onSelectProvider) {
            props.onSelectProvider(service);
        }
    };

    const renderDownloadAction = () => {
        if (isLoading) {
            return (
                <div className={`${prefix}-provider-card-loading`}>
                    <span className={`${prefix}-provider-loading-spinner`}></span>
                    <p>{__('Loading…', 'translate-words')}</p>
                </div>
            );
        }

        const btnText = downloadState.status === 'failed'
            ? __('Retry Download', 'translate-words')
            : `${__('Download', 'translate-words')} ${props.targetLangName}`;

        return (
            <button type="button" className={`${prefix}-provider-card-download`} onClick={handleDownload}>
                {btnText}
            </button>
        );
    };

    return (
        <div
            className={classNames}
            data-service={service}
            onClick={handleCardClick}
            onKeyDown={(e) => { if ((e.key === "Enter" || e.key === " ") && e.target === e.currentTarget && isActionable) { e.preventDefault(); handleCardClick(); } }}
            role="button"
            tabIndex={isActionable ? 0 : -1}
            aria-pressed={isSelected}
            id={`${prefix}-provider-card-${service}`}
        >
            <div className={`${prefix}-provider-card-body`}>
                <span className={`${prefix}-provider-card-icon`} aria-hidden="true">
                    {ActiveService.Logo}
                </span>
                <span className={`${prefix}-provider-card-name`}>{ActiveService.title}</span>
                <span className={`${prefix}-provider-card-check`} aria-hidden="true" />
            </div>
            <div className={`${prefix}-provider-card-actions`}>
                <a
                    href={ActiveService.Docs}
                    target="_blank"
                    rel="noopener noreferrer"
                    className={`${prefix}-provider-card-docs`}
                    title={sprintf(__("View %s Documentation", "translate-words"), ActiveService.serviceLabel)}
                    onClick={(e) => e.stopPropagation()}
                >
                    {__('Docs', 'translate-words')}
                </a>
                {isBusy && renderDownloadAction()}
                {!isBusy && isDisabled && (
                    <div className={`${prefix}-provider-card-error`}>{ActiveService.ErrorMessage}</div>
                )}
            </div>
        </div>
    );
}

export default Providers;
