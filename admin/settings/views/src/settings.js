import SettingPage from "./pages/setting-page.jsx";
import domReady from '@wordpress/dom-ready';
import { createRoot } from 'react-dom/client';
import Scan from './components/scan.jsx';
import { Toaster } from 'sonner';

//Settings Page Component
const SettingsPage = () =>{
    return(
        <>
        <SettingPage />
        </>
    )
}



domReady(() => {
    const scanElement = document.getElementById('lmat-site-scan');
    if (scanElement) {
        createRoot(scanElement).render(<><Toaster richColors position='top-right' /><Scan /></>);
        return;
    }
    const element = document.getElementById('lmat-settings');
        if (element) {
            const root = createRoot(element);
            root.render(<SettingsPage/>);
        }

});


