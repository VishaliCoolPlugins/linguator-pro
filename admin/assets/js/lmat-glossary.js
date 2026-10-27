jQuery(function($) {
    if (!window.LmatGlossary) return;
    LmatGlossary.Ui && LmatGlossary.Ui.init();
    LmatGlossary.Filters && LmatGlossary.Filters.init();
    LmatGlossary.Crud && LmatGlossary.Crud.init();
    LmatGlossary.ImportExport && LmatGlossary.ImportExport.init();
});
