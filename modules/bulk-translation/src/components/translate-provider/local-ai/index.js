import ChromeAiTranslator from "./local-ai-translate.js";
import { updateProgressStatus, updateTranslatePostInfo, unsetPendingPost } from "../../../redux-store/features/actions.js";
import { selectProgressStatus, selectTargetContent, selectTranslatePostInfo, selectGlossaryTerms } from "../../../redux-store/features/selectors.js";
import { store } from "../../../redux-store/store.js";
import storeTranslateString from "../../store-translate-strings/index.js";
import { __ } from "@wordpress/i18n";

/** postId_lang → { resolve, reject, sourceLanguage, targetLanguage, isEdge } */
const packInstallWaiters = new Map();

/**
 * Called from the status modal when the user clicks "Install language pack".
 * Starts the download under that click, then resumes automatic translation.
 */
export async function installLanguagePackFromStatus(postId, targetLang, storeDispatch) {
    const key = `${postId}_${targetLang}`;
    const waiter = packInstallWaiters.get(key);
    if (!waiter) {
        return false;
    }

    const preservedErrorHtml = false;
    const preservedErrorMessage = '';

    storeDispatch(updateTranslatePostInfo({
        [key]: {
            status: 'running',
            messageClass: '',
            errorMessage: __('Loading language…', 'translate-words'),
            errorHtml: false,
            needsLanguagePack: false,
        }
    }));

    try {
        const result = await ChromeAiTranslator.installLanguagePackFromClick(
            waiter.sourceLanguage,
            waiter.targetLanguage
        );

        if (!result.ok) {
            storeDispatch(updateTranslatePostInfo({
                [key]: {
                    status: 'needs-pack',
                    messageClass: '',
                    errorMessage: preservedErrorMessage,
                    errorHtml: preservedErrorHtml,
                    needsLanguagePack: true,
                }
            }));
            return false;
        }

        packInstallWaiters.delete(key);
        waiter.resolve(true);
        return true;
    } catch (err) {
        console.error('Install language pack failed:', err);
        storeDispatch(updateTranslatePostInfo({
            [key]: {
                status: 'needs-pack',
                messageClass: '',
                errorMessage: preservedErrorMessage,
                errorHtml: preservedErrorHtml,
                needsLanguagePack: true,
            }
        }));
        return false;
    }
}

// Define a class for LocalAiTranslate
class LocalAiTranslate {
    constructor({sourceLang = 'en', targetLangs = false, updateContent, totalPosts, storeDispatch, postId, prefix, updateDestoryHandler}) {
        this.textContentObject = selectTargetContent(store.getState(), postId);

        this.totalTranslatedLength=Object.keys(this.textContentObject).length;
        this.sourceLang = sourceLang;
        this.targetLangs = targetLangs;
        this.localAiTranslator = null;
        this.textContentObjectKeys=Object.keys(this.textContentObject);
        this.glossaryTerms = selectGlossaryTerms(store.getState(), sourceLang);
        this.activeLanguageGlossaryTerms={};
        this.translateKeysLength=this.textContentObjectKeys.length;
        this.updateContent=updateContent;
        this.totalPosts=totalPosts;
        this.storeDispatch=storeDispatch;
        this.completedPostStatus=0;
        this.postId=postId;
        this.activeTargetLangs='';
        this.prefix=prefix;
        this.serviceProvider=store.getState().serviceProvider;
        this.isEdge = this.serviceProvider === 'edgeLocalAiTranslator';
        updateDestoryHandler(()=>{
            this.destroy();
        });
        this.stopTranslation=false;
    }

    destroy=()=>{
        this.stopTranslation=true;
        // Reject any waiting pack installs so the chain can unwind.
        packInstallWaiters.forEach((waiter, key) => {
            if (key.startsWith(this.postId + '_')) {
                waiter.resolve(false);
                packInstallWaiters.delete(key);
            }
        });
        if(this.localAiTranslator && this.localAiTranslator.hasOwnProperty('stopTranslation')){
            this.localAiTranslator.stopTranslation();
        }
    }

    /**
     * Pause translation until the user clicks Install language pack in the status table.
     */
    onLanguagePackRequired = (packInfo) => {
        const key = `${this.postId}_${this.activeTargetLangs}`;

        return new Promise((resolve) => {
            packInstallWaiters.set(key, {
                resolve,
                sourceLanguage: packInfo.sourceLanguage,
                targetLanguage: packInfo.targetLanguage,
                isEdge: packInfo.isEdge,
            });

            this.storeDispatch(updateTranslatePostInfo({
                [key]: {
                    status: 'needs-pack',
                    messageClass: '',
                    errorMessage: '',
                    errorHtml: false,
                    needsLanguagePack: true,
                }
            }));
        });
    }

    // Function to create Local AI Translator
    async createLocalAiTranslator(targetLang, index) {
        this.completedTranslateIndex=0;
        this.localAiTranslator = null;
        this.activeLanguageGlossaryTerms={};

        if(this.stopTranslation) return;

        const languageObject=lmatBulkTranslationGlobal.languageObject;
        this.completedPostStatus=selectProgressStatus(store.getState());

        this.activeTargetLangs=targetLang;
        this.storeDispatch(updateTranslatePostInfo({
            [this.postId+'_'+targetLang]: {
                status: 'running',
                messageClass: '',
                errorMessage: '',
                errorHtml: false,
                needsLanguagePack: false,
            }
        }));

        this.localAiTranslator = await ChromeAiTranslator.Object({
            sourceLanguage: this.sourceLang,
            targetLanguage: targetLang,
            sourceLanguageLabel: languageObject?.[this.sourceLang]?.name || this.sourceLang,
            targetLanguageLabel: languageObject?.[targetLang]?.name || targetLang,
            onAfterTranslate: this.onAfterTranslate,
            onBeforeTranslate: this.onBeforeTranslate,
            onComplete: this.onComplete,
            onLanguageError: this.onLanguageError,
            onLanguageLoading: this.onLanguageLoading,
            onLanguagePackRequired: this.onLanguagePackRequired,
            isEdge: this.isEdge
        });

        if(this.localAiTranslator.hasOwnProperty('init')){
            this.activeLanguageGlossaryTerms[targetLang]={};
            if(this.glossaryTerms && Object.values(this.glossaryTerms).length > 0){
                Object.values(this.glossaryTerms).forEach(term => {
                    if(term.translations && term.translations[targetLang]){
                        this.activeLanguageGlossaryTerms[targetLang][term.original_term]=term.translations[targetLang];
                    }
                })
            }

            this.storeDispatch(updateTranslatePostInfo({[this.postId+'_'+targetLang]: { status: 'running', messageClass: '', errorMessage: '', needsLanguagePack: false}}));
            await this.translateContent(0);

            if (!this.stopTranslation) {
                await this.updateContent(targetLang);
            }
        }
        
        if(index < this.targetLangs.length - 1 && !this.stopTranslation){
           await this.createLocalAiTranslator(this.targetLangs[index + 1], index + 1);
        }
    }

    onLanguageError = (data) => {
        let html = false;
        const message = (data && data.message) ? data.message : __('Language error', 'translate-words');

        if (data && data.html) {
            // jQuery object or DOM node
            if (data.html[0] && data.html[0].outerHTML) {
                html = data.html[0].outerHTML;
            } else if (typeof data.html === 'string') {
                html = data.html;
            }
        }

        // Pending/download marker spans have no visible text — don't use them as the modal body.
        const htmlText = html ? String(html).replace(/<[^>]*>/g, '').trim() : '';
        if (!htmlText) {
            html = `<p>${message}</p>`;
        }

        this.storeDispatch(unsetPendingPost(this.postId+'_'+this.activeTargetLangs));
        this.storeDispatch(updateProgressStatus(100 / this.totalPosts));
        this.storeDispatch(updateTranslatePostInfo({
            [this.postId+'_'+this.activeTargetLangs]: {
                status: 'error',
                messageClass: 'error',
                errorMessage: message,
                errorHtml: html,
                needsLanguagePack: false,
            }
        }));
    }

    onLanguageLoading = (isLoading) => {
        if(this.stopTranslation) return;

        this.storeDispatch(updateTranslatePostInfo({
            [this.postId+'_'+this.activeTargetLangs]: {
                status: 'running',
                messageClass: '',
                errorMessage: isLoading ? __('Loading language…', 'translate-words') : '',
                errorHtml: false,
                needsLanguagePack: false,
            }
        }));
    }

    onBeforeTranslate = (ele) => {
        if(ele && this.activeLanguageGlossaryTerms && this.activeLanguageGlossaryTerms[this.activeTargetLangs] && Object.keys(this.activeLanguageGlossaryTerms[this.activeTargetLangs]).length > 0){
            const glossaryTermsSpan=ele.querySelectorAll('span[data-glossary-term]');
            glossaryTermsSpan.forEach(glossarySpan => {
                const glossaryTermKey=glossarySpan.dataset?.glossaryTerm;
                const glossaryTermValue=this.activeLanguageGlossaryTerms[this.activeTargetLangs]?.[glossaryTermKey];

                if(glossaryTermValue && '' !== glossaryTermValue){
                    glossarySpan.innerHTML=glossarySpan.innerText.replace(glossaryTermKey, glossaryTermValue);
                }
            });
        }
    }

    onAfterTranslate = (key, value) => {
        if(this.stopTranslation) return;
        storeTranslateString(this.postId, key, this.activeTargetLangs, value, this.serviceProvider, this.activeTargetLangs, this.storeDispatch);
        
        this.completedTranslateIndex++;
        let completedPercentage=this.completedTranslateIndex / this.totalTranslatedLength * 100;
        completedPercentage=completedPercentage.toFixed(2);
        completedPercentage=Math.min(completedPercentage, 100);
        
        let completedPostStatus=completedPercentage;
        completedPostStatus=Math.round(completedPostStatus);
        completedPostStatus=Math.min(completedPostStatus, 100);

        const progressBarCircular=document.querySelector(`.${this.prefix}-progress-bar-circular[data-id="${this.postId}_${this.activeTargetLangs}"]`);

        if(progressBarCircular){
            progressBarCircular.querySelector(`.${this.prefix}-percentage`).innerHTML=completedPostStatus + '%';
            progressBarCircular.querySelector(`.${this.prefix}-progress`).style.strokeDasharray=completedPostStatus + ', 100';
            
        }

        let totalProgress=this.completedPostStatus + (completedPercentage / this.totalPosts);
        const totalProgressBar=document.querySelector(`.${this.prefix}-overall-progress .${this.prefix}-progress`);
        if(totalProgressBar){

            totalProgress=totalProgress.toFixed(2);
            totalProgress=Math.min(totalProgress, 100);
            totalProgressBar.style.width=totalProgress + '%';
            totalProgressBar.innerHTML=totalProgress + '%';
        }
    }

    onComplete = () => {
        if(this.stopTranslation) return;

        if(this.completedTranslateIndex === this.totalTranslatedLength){
            const endTime=new Date();
            const duration=endTime-this.startTime;

            const previousDuration=selectTranslatePostInfo(store.getState(), this.postId+'_'+this.activeTargetLangs).duration || 0;

            this.storeDispatch(updateTranslatePostInfo({[this.postId+'_'+this.activeTargetLangs]: { duration: previousDuration + duration}}));

            this.storeDispatch(updateProgressStatus(100 / this.totalPosts));
        }
    }

    // Function to translate content
    async translateContent(index) {
        const textObject=JSON.parse(JSON.stringify(this.textContentObject));

        if(Object.keys(textObject).length > 0 && !this.stopTranslation){
            this.startTime=new Date();
            await this.localAiTranslator.init(textObject);
            await this.localAiTranslator.startTranslation();
        }
    }

    // Function to initialize translation if conditions are met
    async initTranslation() {
        if(this.textContentObject && Object.keys(this.textContentObject).length > 0 && this.targetLangs && this.targetLangs.length > 0 && !this.stopTranslation){
            await this.createLocalAiTranslator(this.targetLangs[0], 0);
        }else if(this.targetLangs && this.targetLangs.length > 0 && !this.stopTranslation){
            this.targetLangs.forEach(lang=>{
                this.storeDispatch(unsetPendingPost(this.postId+'_'+lang));
                this.storeDispatch(updateProgressStatus(100 / this.totalPosts));
                this.storeDispatch(updateTranslatePostInfo({[this.postId+'_'+lang]: { status: 'error', messageClass: 'error', errorMessage: __('No content to translate', 'translate-words'), errorHtml: false}}));
            });
        }
    }
}

export default LocalAiTranslate;
