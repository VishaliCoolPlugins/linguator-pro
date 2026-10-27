import { __ } from "@wordpress/i18n";
import Providers from "./providers.js";
import TranslateService from "../components/translate-provider/index.js";

const SettingModalEngines = ({
    prefix,
    activeProvider,
    onSelectProvider,
    errorModalHandler,
    localAiModalError,
    edgeLocalAiModalError,
}) => {
    const serviceProviders = TranslateService();
    const providerKeys = Object.keys(serviceProviders);
    const settingsUrl = `${lmatBulkTranslationGlobal.admin_url || ''}admin.php?page=lmat_settings&tab=translation`;

    return (
        <section className={`${prefix}-setup-column ${prefix}-setup-engines`}>
            <div className={`${prefix}-setup-column-head`}>
                <span className={`${prefix}-setup-step-badge`} aria-hidden="true">2</span>
                <h3>{__('Select Translation Provider', 'translate-words')}</h3>
            </div>
            <p className={`${prefix}-setup-column-desc`}>
                {__('Select an AI provider to automatically translate your content.', 'translate-words')}
            </p>

            <div className={`${prefix}-provider-cards`}>
                {providerKeys.length > 0 ? providerKeys.map((provider) => (
                    <Providers
                        key={provider}
                        Service={provider}
                        prefix={prefix}
                        activeProvider={activeProvider}
                        onSelectProvider={onSelectProvider}
                        openErrorModalHandler={errorModalHandler}
                        localAiTranslatorDisabled={localAiModalError}
                        localAiModalError={localAiModalError}
                        edgeLocalAiTranslatorDisabled={edgeLocalAiModalError}
                        edgeLocalAiModalError={edgeLocalAiModalError}
                    />
                )) : (
                    <div className={`${prefix}-bulk-translate-empty ${prefix}-provider-empty`}>
                        <strong>{__('No AI providers available', 'translate-words')}</strong>
                        <br />
                        <span className={`${prefix}-provider-empty-actions`}>
                            {__('Go to the', 'translate-words')}{' '}
                            <a href={settingsUrl} target="_blank" rel="noopener noreferrer">
                                {__('Translation Settings', 'translate-words')}
                            </a>{' '}
                            {__('to enable a provider.', 'translate-words')}
                        </span>
                    </div>
                )}
            </div>
        </section>
    );
}

export default SettingModalEngines;
