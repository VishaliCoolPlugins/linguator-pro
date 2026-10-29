import domReady from '@wordpress/dom-ready';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';
import Scan from './components/scan.jsx';

domReady(() => {
  const element = document.getElementById('lmat-site-scan');
  if (!element) {
    return;
  }

  createRoot(element).render(
    <>
      <Toaster richColors position="top-right" />
      <Scan />
    </>
  );
});
