jQuery(function ($) {
  // Constants
  const SELECTORS = {
    refreshBtn: ".lmatp-refresh-btn",
    licenseContainer: ".lmatp-license-card",
    deactivateBtn: ".lmatp-submit-button",
    validityStrong: ".lmatp-validity:has(strong) strong",
    validitySpan: ".lmatp-validity:not(:has(strong))",
    licenseType: ".lmatp-license-type",
    licenseDetails: ".lmatp-license-details",
    errorNotice: "div.lmatp-newLicense.notice.notice-error",
    noticeSlot: ".lmatp-license-notices",
  };

  const CSS_CLASSES = {
    valid: "valid",
    invalid: "invalid",
    expired: "expired",
    supportExpired: "support-expired",
    noSupport: "no-support"
  };

  const MESSAGES = {
    validLicense: "Valid",
    invalidLicense: "Invalid",
    expiredLicense: "License Expired",
    expiredSupport: "Support Expired",
    genericError: "An error occurred while refreshing the license. Please try again.",
    checkingStatus: "Checking....."
  };

  const URLS = {
    renewLink: "https://my.coolplugins.net/account/subscriptions/"
  };

  const createNoticeElement = (type, message, isError = false, description = '') => {
    const noticeClass = isError ? "notice-error" : "notice-success";
    const messageHtml = description
      ? `<p><strong>${message}</strong></p><p>${description}</p>`
      : `<p>${message}</p>`;
    return $(`<div class="notice ${noticeClass} is-dismissible">${messageHtml}</div>`);
  };

  const createRenewalNotice = (message) => {
    return $(`<div class="lmatp-newLicense notice notice-error is-dismissible">${message}</div>`);
  };

  const isDateExpired = (dateString) => {
    if (!dateString || dateString.toLowerCase() === "no expiry" || dateString.toLowerCase() === "unlimited") {
      return false;
    }
    return new Date(dateString).getTime() < Date.now();
  };

  const updateValidityStatus = ($element, status, cssClass) => {
    $element.html(status);
    // The badge colour lives on the wrapping .lmatp-validity, not on the <strong>.
    const $badge = $element.closest(".lmatp-validity");
    $badge.removeClass(Object.values(CSS_CLASSES).join(" ")).addClass(cssClass);
    $element.removeClass(Object.values(CSS_CLASSES).join(" ")).addClass(cssClass);
  };

  const getRenewalMessage = (isLicenseExpired, market, versionMessage = '', pluginName = '') => {
    const productPrefix = pluginName ? `${pluginName} ` : '';
    // This plugin ships translated renewal copy; fall back to the English default.
    const localizedExpired = (typeof lmatp_ajax !== 'undefined' && lmatp_ajax.license_expired_msg)
      ? lmatp_ajax.license_expired_msg : null;
    const localizedSupport = (typeof lmatp_ajax !== 'undefined' && lmatp_ajax.support_expired_msg)
      ? lmatp_ajax.support_expired_msg : null;
    const baseMessage = isLicenseExpired
      ? (localizedExpired || `${productPrefix}license has expired, `)
      : (localizedSupport || `${productPrefix}support has expired, `);
    const versionPrefix = versionMessage ? versionMessage + ' ' : '';

    if (market === "E") {
      return versionPrefix + baseMessage + '<a href="https://codecanyon.net/downloads/" target="_blank" rel="noopener noreferrer">Renew now</a> to continue receiving updates and priority support.';
    }

    const linkText = isLicenseExpired ? "Renew now" :
      '<a style="color: #0073aa; text-decoration: underline;" href="' + URLS.renewLink + '" target="_blank">Renew now</a>';

    return isLicenseExpired
      ? versionPrefix + baseMessage + `<a href="${URLS.renewLink}" target="_blank">Renew now</a> to continue receiving updates and priority support.`
      : versionPrefix + baseMessage + linkText + " to continue receiving updates and priority support";
  };

  const getFormattedDate = (dateString) => {
    if (!dateString) {
      return "";
    }
    // Match PHP lmatp_pro_formatLicenseDate(): leave non-date tokens as-is.
    const lower = String(dateString).toLowerCase();
    if (lower === "no expiry" || lower === "unlimited" || lower === "no support") {
      return dateString;
    }
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) {
      return dateString;
    }
    return date.toLocaleDateString('en-GB', {
      day: '2-digit',
      month: 'short',
      year: 'numeric'
    });
  };

  const showNoticeMessage = (element, delay = 2000) => {
    // The slot lives above .tab-content, so a notice pushes the card and the
    // sidebar down together. Remove on fade so the empty slot collapses again.
    const $slot = $(SELECTORS.noticeSlot);
    if ($slot.length) {
      $slot.append(element);
    } else {
      element.insertBefore(SELECTORS.licenseContainer);
    }
    element.delay(delay).fadeOut(function () {
      $(this).remove();
    });
  };

  const $refreshBtn = $(SELECTORS.refreshBtn);

  $refreshBtn.on("click", function (e) {
    e.preventDefault();

    const $btn = $(this);
    const $card = $btn.closest(SELECTORS.licenseContainer);
    const originalText = $btn.text();
    $btn.prop("disabled", true).text(MESSAGES.checkingStatus);

    $.ajax({
      url: lmatp_ajax.ajaxurl,
      type: "POST",
      data: {
        action: "lmatp_refresh_license_ajax",
        nonce: lmatp_ajax.nonce,
      },
      success: function (response) {
        if (response.success) {
          const successNotice = createNoticeElement("success", response.data.message);
          showNoticeMessage(successNotice);

          if (response.data.license_info) {
            const versionMessage = response.data.version_available_message || '';
            updateLicenseInfo(response.data.license_info, versionMessage, $card);
          }
        } else {
          const errorNotice = createNoticeElement(
            "error",
            response.data.title || response.data.message,
            true,
            response.data.description || ""
          );
          showNoticeMessage(errorNotice);
          setTimeout(() => location.reload(), 2000);
        }
      },
      error: function () {
        const errorNotice = createNoticeElement("error", MESSAGES.genericError, true);
        showNoticeMessage(errorNotice, 5000);
      },
      complete: function () {
        $btn.prop("disabled", false).text(originalText);
      },
    });
  });

  function updateLicenseInfo(licenseInfo, versionMessage = '', $card = null) {
    updateLicenseValidity(licenseInfo);
    updateLicenseType(licenseInfo);
    updateExpireDate(licenseInfo);
    updateStatusBanner(licenseInfo, $card);
    handleRenewalMessages(licenseInfo, versionMessage);
    handleRefreshButtonVisibility(licenseInfo);
  }

  // Repaint the header banner and its icon so they agree with the new status badge.
  function updateStatusBanner(licenseInfo, $card = null) {
    const $root = $card && $card.length ? $card : $(document);
    const $banner = $root.find(".lmatp-notice").not(".lmatp-notice-info").first();
    const $headerIcon = $root.find(".lmatp-license-icon").first();

    if (!$banner.length) {
      return;
    }

    const pluginName = ($root.find(".lmatp-license-header h2").first().text() || "")
      .replace(/\s*-\s*License & Updates\s*$/i, "")
      .trim() || "plugin";

    const supportEnd = (licenseInfo.support_end || "").toLowerCase();
    let isNegative = true;
    let title;
    let desc;

    if (!licenseInfo.is_valid) {
      title = "Your " + pluginName + " license is invalid.";
      desc = "Please check your license key or contact support for help.";
    } else if (licenseInfo.is_valid === "license_expired") {
      title = pluginName + " license has expired.";
      desc = "Renew now to continue receiving automatic updates and support.";
    } else if (
      supportEnd === "no support" ||
      licenseInfo.is_valid === "support_expired" ||
      (supportEnd && supportEnd !== "unlimited" && isDateExpired(licenseInfo.support_end))
    ) {
      title = pluginName + " support has expired.";
      desc = "Renew now to continue receiving automatic updates and support.";
    } else {
      isNegative = false;
      title = "Your " + pluginName + " license is active.";
      desc = "Premium updates and support are enabled for this website.";
    }

    $banner.find("strong").first().text(title);
    $banner.find("p").first().text(desc);

    if (isNegative) {
      $banner.removeClass("lmatp-notice-success").addClass("lmatp-notice-danger");
      $banner.find(".lmatp-notice-icon").html("!");
      $headerIcon
        .removeClass("lmatp-license-icon-success")
        .addClass("lmatp-license-icon-danger")
        .html("!");
    } else {
      $banner.removeClass("lmatp-notice-danger").addClass("lmatp-notice-success");
      $banner.find(".lmatp-notice-icon").html('<span class="dashicons dashicons-yes"></span>');
      $headerIcon
        .removeClass("lmatp-license-icon-danger")
        .addClass("lmatp-license-icon-success")
        .html('<span class="dashicons dashicons-yes"></span>');
    }
  }

  function updateLicenseValidity(licenseInfo) {
    const $validity = $(SELECTORS.validityStrong);

    if (!licenseInfo.is_valid) {
      updateValidityStatus($validity, MESSAGES.invalidLicense, CSS_CLASSES.invalid);
      return;
    }

    if (licenseInfo.is_valid === "license_expired") {
      updateValidityStatus($validity, MESSAGES.expiredLicense, CSS_CLASSES.expired);
    } else if (licenseInfo.support_end.toLowerCase() === "no support") {
      updateValidityStatus($validity, MESSAGES.expiredSupport, CSS_CLASSES.noSupport);
    } else if (licenseInfo.is_valid === "support_expired" &&
         isDateExpired(licenseInfo.support_end)) {
      updateValidityStatus($validity, MESSAGES.expiredSupport, CSS_CLASSES.supportExpired);
    } else {
      updateValidityStatus($validity, MESSAGES.validLicense, CSS_CLASSES.valid);
    }
  }

  function updateLicenseType(licenseInfo) {
    if (!licenseInfo.license_title) return;

    const $licenseType = $(SELECTORS.licenseType);

    if ($licenseType.length > 0) {
      $licenseType.text(licenseInfo.license_title);
    } else {
      const $details = $(SELECTORS.licenseDetails);
      const $statusRow = $details.find(".lmatp-detail-row").first();
      const $newLicenseTypeRow = $(`
        <div class="lmatp-detail-row">
          <div class="lmatp-detail-label"><strong>License Type</strong></div>
          <div class="lmatp-detail-value"><span class="lmatp-license-type">${licenseInfo.license_title}</span></div>
        </div>
      `);
      $statusRow.after($newLicenseTypeRow);
    }
  }

  function updateExpireDate(licenseInfo) {
    if (!licenseInfo.expire_date) return;

    const $expireDateSpan = $(SELECTORS.validitySpan);
    const displayDate = getDisplayDate(licenseInfo);

    if ($expireDateSpan.length > 0) {
      $expireDateSpan.text(displayDate);
    } else {
      createExpireDateElement(displayDate);
    }
  }

  function getDisplayDate(licenseInfo) {
    const supportEnd = (licenseInfo.support_end || "").toLowerCase();

    if (supportEnd === "no support") {
      return "No Support";
    }

    const expireDateExpired = isDateExpired(licenseInfo.expire_date);
    const supportEndExpired = isDateExpired(licenseInfo.support_end);

    if (expireDateExpired) {
      return getFormattedDate(licenseInfo.expire_date);
    }
    if (supportEndExpired) {
      return getFormattedDate(licenseInfo.support_end);
    }

    // Active license: show support end when it is a real date, otherwise expire date.
    if (supportEnd && supportEnd !== "unlimited") {
      return getFormattedDate(licenseInfo.support_end);
    }

    return getFormattedDate(licenseInfo.expire_date);
  }

  function createExpireDateElement(displayDate) {
    const $details = $(SELECTORS.licenseDetails);
    const $licenseTypeRow = $details.find(".lmatp-detail-row:has(.lmatp-license-type)");

    if ($licenseTypeRow.length > 0) {
      const $newExpireDateRow = $(`
        <div class="lmatp-detail-row">
          <div class="lmatp-detail-label"><strong>Updates &amp; Support</strong></div>
          <div class="lmatp-detail-value"><span class="lmatp-validity">${displayDate}</span></div>
        </div>
      `);
      $licenseTypeRow.after($newExpireDateRow);
    }
  }

  function removeRenewalMessages() {
    $(SELECTORS.errorNotice).remove();
  }

  function handleRenewalMessages(licenseInfo, versionMessage = '') {
    const isLicenseExpired = licenseInfo.is_valid === "license_expired";
    const isSupportExpired = licenseInfo.is_valid === "support_expired" ||
               (licenseInfo.support_end.toLowerCase() === "no support" &&
              isDateExpired(licenseInfo.support_end));

    if (isLicenseExpired || isSupportExpired) {
      updateRenewalMessage(licenseInfo, isLicenseExpired, versionMessage);
    } else {
      removeRenewalMessages();
    }
  }

  function updateRenewalMessage(licenseInfo, isLicenseExpired, versionMessage = '') {
    const renewalMessage = getRenewalMessage(isLicenseExpired, licenseInfo.market, versionMessage, licenseInfo.plugin_name || '');
    const $existingLink = $(SELECTORS.errorNotice);
    if ($existingLink.length) {
      // Keep WP's dismiss button, which .html() would otherwise drop.
      $existingLink.each(function () {
        const $notice = $(this);
        const $dismiss = $notice.children(".notice-dismiss").detach();
        $notice.html(renewalMessage);
        if ($dismiss.length) {
          $notice.append($dismiss);
        }
      });
    } else {
      const $renewalNotice = createRenewalNotice(renewalMessage);
      const $slot = $(SELECTORS.noticeSlot);
      if ($slot.length) {
        $slot.append($renewalNotice);
      } else {
        $(SELECTORS.deactivateBtn).closest(".lmatp-deactivate-row").after($renewalNotice);
      }
    }
  }

  function handleRefreshButtonVisibility(licenseInfo) {
    const isValidLicense = licenseInfo.is_valid &&
              licenseInfo.is_valid !== "license_expired" &&
              licenseInfo.is_valid !== "support_expired" &&
              (licenseInfo.support_end.toLowerCase() === "unlimited" ||
               !isDateExpired(licenseInfo.support_end));

    if (isValidLicense) {
      $(SELECTORS.refreshBtn).hide();
    }
  }
});
