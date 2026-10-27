import { __ } from "@wordpress/i18n";
const StringPopUpHeader = (props) => {

    /**
     * Function to close the popup modal.
     */
    const closeModal = () => {
        props.setPopupVisibility(false);
    }

    return (
        <div className="modal-header" key={props.modalRender}>
            <div className="modal-header-inner">
                <span className="step-label notranslate">
                    {__("STEP 2 OF 2", 'linguator-pro')}
                </span>
                <h2 className="notranslate">{__("Start Automatic Translation Process", 'linguator-pro')}</h2>
            </div>
            <button type="button" aria-label={__('Close', 'linguator-pro')} className="modal-close" onClick={closeModal}>&times;</button>
        </div>
    );
}

export default StringPopUpHeader;
