import { __ } from "@wordpress/i18n";
import Providers from "./providers.js";
import TranslateService from "../component/translate-provider/index.js";

const SettingModalBody = (props) => {
    const { hasProviders = true } = props;
    const ServiceProviders = TranslateService();
    const settingsUrl = (window.lmatPageTranslationGlobal?.admin_url || '') + 'admin.php?page=lmat_settings&tab=translation';

    return (
        <div className="lmat-page-translation-setting-modal-body">
            {hasProviders ? (
                <div className="lmat-page-translation-provider-cards">
                    {Object.keys(ServiceProviders).map((provider) => (
                        <Providers
                            key={provider}
                            {...props}
                            Service={provider}
                        />
                    ))}
                </div>
            ) : (
                <div className="lmat-page-translation-no-provider-message">
                    <p className="lmat-page-translation-no-provider-text">
                        {__('You have not enabled any translation provider. Please enable at least one service provider to use automatic translation. Go to the', 'translate-words')}{' '}
                        <a href={settingsUrl} target="_blank" rel="noopener noreferrer">
                            {__('Translation Settings', 'translate-words')}
                        </a>
                        {' '}{__('to configure a translation provider.', 'translate-words')}
                    </p>
                </div>
            )}
        </div>
    );
}

export default SettingModalBody;
