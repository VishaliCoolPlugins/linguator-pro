import StringPopUpNotice from "./notice.js";
import { sprintf, __ } from "@wordpress/i18n";
import FormatNumberCount from "../component/format-number-count/index.js";

const StringPopUpFooter = (props) => {

    return (
        <div className="modal-footer" key={props.modalRender}>
            <div className="lmat-page-translation-string-footer-container">
                {!props.translatePendingStatus && !props.hasTranslateError && !props.updateContentDisabled && <StringPopUpNotice className="lmat_page_translation_string_count"><p>{__('Wahooo! You have saved your valuable time via auto translating', 'linguator-pro')} <strong><FormatNumberCount number={props.characterCount} /></strong> {__('characters using', 'linguator-pro')} <strong>{props.serviceLabel}</strong>.{__(' Please share your feedback —', 'linguator-pro')}<a href="https://wordpress.org/support/plugin/translate-words/reviews/#new-post" target="_blank" rel="noopener">{__('leave a quick review', 'linguator-pro')}</a>!</p></StringPopUpNotice>}
                <div className="save_btn_cont">
                    <button className="notranslate save_it button button-primary" disabled={props.updateContentDisabled} onClick={props.updatePostData}>{props.translateButtonStatus ? <><span className="updating-text">{__("Updating", 'linguator-pro')}<span className="dot" style={{"--i": 0}}></span><span className="dot" style={{"--i": 1}}></span><span className ="dot" style={{"--i": 2}}></span></span></> : __("Update Content", 'linguator-pro')}</button>
                </div>
            </div>
        </div>
    );
}

export default StringPopUpFooter;
