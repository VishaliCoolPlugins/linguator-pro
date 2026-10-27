import TranslateService, { getBrowserType } from "../components/translate-provider/index.js";
import { __, sprintf } from "@wordpress/i18n";

const BUILT_IN_AI_SERVICES = ['localAiTranslator', 'edgeLocalAiTranslator'];

const Providers = (props) => {
    const service = props.Service;
    const prefix = props.prefix;
    const activeProvider = props.activeProvider;
    const buttonDisable = props[service + "Disabled"];
    const ActiveService = TranslateService({ Service: service, [service + "ButtonDisabled"]: buttonDisable, openErrorModalHandler: props.openErrorModalHandler, prefix });

    if (!ActiveService) {
        return null;
    }

    const isSelected = activeProvider === service;
    const isDisabled = Boolean(ActiveService.ButtonDisabled || buttonDisable);
    const errorToShow = {
        localAiTranslator: props.localAiModalError,
        edgeLocalAiTranslator: props.edgeLocalAiModalError,
    }[service];
    const isActionable = isDisabled ? Boolean(errorToShow) : true;

    let classNames = `${prefix}-provider-card`;
    if (isDisabled) {
        classNames += ` ${prefix}-provider-card-disabled`;
    }
    if (isSelected) {
        classNames += ` ${prefix}-provider-card-selected`;
    }
    if (BUILT_IN_AI_SERVICES.includes(service) && getBrowserType() === 'Other') {
        classNames += ` ${prefix}-provider-browser-other`;
    }

    const handleCardClick = () => {
        if (isDisabled) {
            if (errorToShow) {
                props.openErrorModalHandler(errorToShow);
            }
            return;
        }

        if (props.onSelectProvider) {
            props.onSelectProvider(service);
        }
    };

    return (
        <div
            className={classNames}
            data-service={service}
            onClick={handleCardClick}
            onKeyDown={(e) => { if ((e.key === "Enter" || e.key === " ") && isActionable) { e.preventDefault(); handleCardClick(); } }}
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
                    title={sprintf(__("View %s Documentation", "linguator-pro"), ActiveService.serviceLabel)}
                    onClick={(e) => e.stopPropagation()}
                >
                    {__('Docs', 'linguator-pro')}
                </a>
                {isDisabled && (
                    <div className={`${prefix}-provider-card-error`}>{ActiveService.ErrorMessage}</div>
                )}
            </div>
        </div>
    );
};

export default Providers;
