import React from 'react'
import { setupContext } from "../pages/setup-page"
import { sprintf, __ } from '@wordpress/i18n'
import { Switch } from '@bsf/force-ui'
import { toast } from 'sonner'
import { languageSwitcherOptions } from '../utils'
import apiFetch from "@wordpress/api-fetch"
import { getNonce } from '../utils'
import SetupContinueButton, { SetupBackButton } from './setup-continue-button'

const LanguageSwitcher = () => {
    const { setupProgress, setSetupProgress, data, setData } = React.useContext(setupContext) // get the context
    const [selectedLanguageSwitchers, setSelectedLanguageSwitchers] = React.useState(data.lmat_language_switcher_options || ['default']);
    const [lmatFeedbackData, setLmatFeedbackData] = React.useState(
        data?.lmat_setup_complete && typeof data?.lmat_feedback_data === 'boolean' ? data.lmat_feedback_data : true
    );
    const [showTerms, setShowTerms] = React.useState(false);

    // Handle Checkboxes of Language Switcher
    const handleLanguageSwitcherChange = (switcher) => {
        setSelectedLanguageSwitchers(prev => {
            if (prev.includes(switcher)) {
                return prev.filter(item => item !== switcher);
            } else {
                return [...prev, switcher];
            }
        });
    };

    function saveLanguageSwitcherSettings() {
        try {
            let apiBody = {
                lmat_language_switcher_options : selectedLanguageSwitchers,
                lmat_feedback_data: lmatFeedbackData
            }

            const response = apiFetch({
                path: 'lmat/v1/settings',
                method: 'POST',
                'headers': {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': getNonce()
                },
                body: JSON.stringify(apiBody)
            }).then((response) => {
                setData(prev => ({ ...prev, ...response, lmat_feedback_data: lmatFeedbackData }))
                // Dynamically move to next page
                setSetupProgress("ready")
                localStorage.setItem("setupProgress", "ready");
            })

        } catch (error) {
            toast.error(error.message || __("Something went wrong", "translate-words"));
        }
    }

    return (
        <div className='mx-auto p-10 max-w-[600px] min-h-[40vh] bg-white shadow-sm flex flex-col'>
            <div className='flex-grow'>
                <h2>{__('Language Switcher Widget Configuration', 'translate-words')}</h2>
                <p className='text-justify text-sm/6'>{__('Linguator allows you to choose which language switcher should be displayed to users.', 'translate-words')}</p>

                <div className='flex flex-col gap-4'>
                    {
                        languageSwitcherOptions.map((switcher, index) => (
                            <div key={index} className='p-6 rounded-lg' style={{ border: "1px solid #e5e7eb" }}>
                                <div className='flex justify-between items-center'>
                                    <p className="text-sm/6">{__(switcher.label, 'translate-words')}</p>
                                    <Switch
                                        aria-label={`Switch for ${switcher.label}`}
                                        id={`lmat_language_switcher_${switcher.value}`}
                                        onChange={() => handleLanguageSwitcherChange(switcher.value)}
                                        size="sm"
                                        value={selectedLanguageSwitchers.includes(switcher.value)}
                                    />
                                </div>
                            </div>
                        ))
                    }

                    <div className='p-6 rounded-lg' style={{ border: "1px solid #e5e7eb" }}>
                        <div className='flex justify-between items-center'>
                            <div className='pr-4'>
                                <p className="text-sm font-semibold text-gray-900 m-0">{__('Help Improve Linguator', 'translate-words')}</p>
                                <p className="text-xs text-gray-600 m-0 mt-1">
                                    {__('Help us make this plugin more compatible with your site by sharing non-sensitive site data.', 'translate-words')}{' '}
                                    <a
                                        href="#"
                                        className="text-blue-600 hover:underline cursor-pointer"
                                        onClick={(e) => {
                                            e.preventDefault();
                                            setShowTerms(!showTerms);
                                        }}
                                    >
                                        {showTerms ? `[${__('Hide terms', 'translate-words')}]` : `[${__('See terms', 'translate-words')}]`}
                                    </a>
                                </p>
                            </div>
                            <Switch
                                aria-label="Switch for Usage Data Sharing"
                                id="lmat_feedback_data"
                                onChange={() => setLmatFeedbackData(!lmatFeedbackData)}
                                size="sm"
                                value={lmatFeedbackData}
                            />
                        </div>
                        {showTerms && (
                            <div className="mt-3 pt-3 border-t border-gray-100 text-xs text-gray-500">
                                <p className="m-0 mb-1">
                                    {__("Opt in to receive email updates about security improvements, new features, helpful tutorials, and occasional special offers. We'll collect:", 'translate-words')}{' '}
                                    <a href="https://my.coolplugins.net/terms/usage-tracking/" target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">
                                        {__('Click Here', 'translate-words')}
                                    </a>
                                </p>
                                <ul className="list-disc pl-4 space-y-1 m-0">
                                    <li>{__("Your website home URL and WordPress admin email.", 'translate-words')}</li>
                                    <li>{__("To check plugin compatibility, we will collect: list of active plugins and themes, server type, MySQL version, WordPress version, memory limit, site language, database prefix, and setup wizard configuration.", 'translate-words')}</li>
                                </ul>
                            </div>
                        )}
                    </div>
                </div>
            </div>
            <div className='flex justify-between ' style={{ marginTop: "14px" }}>
                <SetupBackButton handleClick={() => { setSetupProgress("translation_configuration"); localStorage.setItem("setupProgress", "url"); }} />
                <SetupContinueButton SaveSettings={saveLanguageSwitcherSettings} />
            </div>
        </div>
    )
}

export default LanguageSwitcher