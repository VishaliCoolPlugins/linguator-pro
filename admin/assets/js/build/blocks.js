/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, e) { if ("object" != _typeof(t) || !t) return t; var r; if ("undefined" != typeof Symbol && void 0 !== (r = t[Symbol.toPrimitive])) { var i = r.call(t, e || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === e ? String : Number)(t); }
/* global wp */
(function () {
  var _wp$i18n = wp.i18n,
    __ = _wp$i18n.__,
    _x = _wp$i18n._x;
  var _wp$blocks = wp.blocks,
    registerBlockType = _wp$blocks.registerBlockType,
    createBlock = _wp$blocks.createBlock;
  var Fragment = wp.element.Fragment;
  var _ref = wp.blockEditor || wp.editor,
    InspectorControls = _ref.InspectorControls,
    BlockControls = _ref.BlockControls,
    useBlockProps = _ref.useBlockProps; // fallback for older WP
  var _wp$components = wp.components,
    PanelBody = _wp$components.PanelBody,
    ToggleControl = _wp$components.ToggleControl,
    SelectControl = _wp$components.SelectControl,
    RadioControl = _wp$components.RadioControl,
    RangeControl = _wp$components.RangeControl,
    ToolbarGroup = _wp$components.ToolbarGroup,
    ToolbarButton = _wp$components.ToolbarButton,
    ToolbarDropdownMenu = _wp$components.ToolbarDropdownMenu,
    Disabled = _wp$components.Disabled;
  var UnitControl = wp.components.UnitControl || wp.components.__experimentalUnitControl;
  var el = wp.element.createElement;
  var ServerSideRender = wp.serverSideRender && wp.serverSideRender.default || wp.serverSideRender;
  var addFilter = wp.hooks.addFilter;

  // ---------------------------------------------------------------------------
  // Icon: translation (simple inline SVG)
  // ---------------------------------------------------------------------------
  var TranslationIcon = function TranslationIcon() {
    return wp.element.createElement('span', {
      className: 'linguator-block-icon',
      style: {
        fontFamily: 'linguator',
        fontSize: '20px',
        lineHeight: '1',
        display: 'inline-block'
      }
    }, "\uE900");
  };

  // ---------------------------------------------------------------------------
  // Icon: submenu chevron (used in nav dropdown)
  // ---------------------------------------------------------------------------
  var SubmenuChevron = function SubmenuChevron() {
    return wp.element.createElement('svg', {
      width: 12,
      height: 12,
      viewBox: '0 0 12 12',
      xmlns: 'http://www.w3.org/2000/svg',
      fill: 'none'
    }, wp.element.createElement('path', {
      d: 'M1.5 4L6 8l4.5-4',
      strokeWidth: 1.5,
      stroke: 'currentColor'
    }));
  };

  // ---------------------------------------------------------------------------
  // Regular block: linguator/language-switcher
  // ---------------------------------------------------------------------------
  var CSS_LENGTH_UNITS = [{
    value: 'px',
    label: 'px'
  }, {
    value: 'em',
    label: 'em'
  }, {
    value: 'rem',
    label: 'rem'
  }, {
    value: 'vh',
    label: 'vh'
  }, {
    value: 'vw',
    label: 'vw'
  }];
  var LABEL_SPACING_DEFAULT_VALUE = '0.3em';
  var switcherAttributes = {
    layout: {
      type: 'string',
      enum: ['vertical', 'horizontal', 'dropdown', 'select'],
      default: 'vertical'
    },
    show_labels: {
      type: 'string',
      enum: ['names', 'codes', ''],
      default: 'names'
    },
    show_flags: {
      type: 'boolean',
      default: false
    },
    force_home: {
      type: 'boolean',
      default: false
    },
    hide_current: {
      type: 'boolean',
      default: false
    },
    hide_if_no_translation: {
      type: 'boolean',
      default: false
    },
    flag_aspect_ratio: {
      type: 'string',
      enum: ['3:2', '1:1'],
      default: '3:2'
    },
    flag_border_radius: {
      type: 'number',
      default: 0
    },
    flag_width: {
      type: 'string',
      default: '18px'
    },
    flag_label_spacing: {
      type: 'string',
      default: LABEL_SPACING_DEFAULT_VALUE
    },
    className: {
      type: 'string',
      default: ''
    }
  };
  var switcherDefaults = Object.keys(switcherAttributes).reduce(function (defaults, key) {
    defaults[key] = switcherAttributes[key].default;
    return defaults;
  }, {});

  // Converts blocks saved with the `dropdown` and `show_names` attributes.
  var getDeprecated = function getDeprecated(defaults, dropdownLayout) {
    return [{
      attributes: {
        dropdown: {
          type: 'boolean',
          default: false
        },
        show_names: {
          type: 'boolean',
          default: true
        },
        show_flags: {
          type: 'boolean',
          default: false
        },
        force_home: {
          type: 'boolean',
          default: false
        },
        hide_current: {
          type: 'boolean',
          default: false
        },
        hide_if_no_translation: {
          type: 'boolean',
          default: false
        },
        className: {
          type: 'string',
          default: ''
        }
      },
      isEligible: function isEligible(attributes) {
        return 'dropdown' in attributes || 'show_names' in attributes;
      },
      migrate: function migrate(attributes) {
        return migrateLegacyAttributes(attributes, defaults, dropdownLayout);
      },
      save: function save() {
        return null;
      }
    }];
  };

  // Maps the `dropdown` and `show_names` attributes to the current ones.
  function migrateLegacyAttributes(attributes, defaults, dropdownLayout) {
    var dropdown = attributes.dropdown,
      show_names = attributes.show_names,
      show_flags = attributes.show_flags,
      force_home = attributes.force_home,
      hide_current = attributes.hide_current,
      hide_if_no_translation = attributes.hide_if_no_translation,
      className = attributes.className;
    return _objectSpread(_objectSpread({}, defaults), {}, {
      layout: dropdown ? dropdownLayout : defaults.layout,
      show_labels: show_names ? 'names' : '',
      show_flags: show_names ? !!show_flags : true,
      force_home: !!force_home,
      hide_current: !!hide_current,
      hide_if_no_translation: !!hide_if_no_translation,
      className: className || ''
    });
  }
  var deprecated = getDeprecated(switcherDefaults, 'select');

  // Toolbar and inspector controls of the language switcher block.
  function SwitcherControls(_ref2) {
    var attributes = _ref2.attributes,
      setAttributes = _ref2.setAttributes,
      _ref2$allowedLayouts = _ref2.allowedLayouts,
      allowedLayouts = _ref2$allowedLayouts === void 0 ? null : _ref2$allowedLayouts;
    var layout = attributes.layout,
      show_labels = attributes.show_labels,
      show_flags = attributes.show_flags,
      force_home = attributes.force_home,
      hide_current = attributes.hide_current,
      flag_aspect_ratio = attributes.flag_aspect_ratio,
      flag_border_radius = attributes.flag_border_radius,
      flag_width = attributes.flag_width,
      flag_label_spacing = attributes.flag_label_spacing,
      hide_if_no_translation = attributes.hide_if_no_translation;
    var warn = function warn(message) {
      if (wp.data && wp.notices) {
        wp.data.dispatch(wp.notices.store || 'core/notices').createWarningNotice(message);
      }
    };
    var noLabelsWarning = __('Labels cannot be hidden if flags are not shown.', 'linguator-pro');
    var noFlagsWarning = __('Flags cannot be hidden if labels are not displayed.', 'linguator-pro');
    var layoutOptions = [{
      label: _x('Horizontal', 'layout', 'linguator-pro'),
      value: 'horizontal'
    }, {
      label: _x('Vertical', 'layout', 'linguator-pro'),
      value: 'vertical'
    }, {
      label: __('Dropdown', 'linguator-pro'),
      value: 'dropdown'
    }, {
      label: __('Selector', 'linguator-pro'),
      value: 'select'
    }].filter(function (option) {
      return !allowedLayouts || allowedLayouts.includes(option.value);
    });
    var layoutToolbarControls = layoutOptions.filter(function (option) {
      return 'select' !== option.value;
    }).map(function (option) {
      return {
        title: option.label,
        onClick: function onClick() {
          return setAttributes({
            layout: option.value
          });
        }
      };
    });
    var labelOptions = [{
      label: __('Language names', 'linguator-pro'),
      value: 'names'
    }, {
      label: __('Language codes', 'linguator-pro'),
      value: 'codes'
    }];
    var toolbarLabelControls = [{
      title: __('Language names', 'linguator-pro'),
      onClick: function onClick() {
        return setAttributes({
          show_labels: 'names'
        });
      }
    }, {
      title: __('Language codes', 'linguator-pro'),
      onClick: function onClick() {
        return setAttributes({
          show_labels: 'codes'
        });
      }
    }];
    if (show_flags) {
      labelOptions.push({
        label: _x('None', 'Labels', 'linguator-pro'),
        value: ''
      });
      toolbarLabelControls.push({
        title: _x('None', 'Labels', 'linguator-pro'),
        onClick: function onClick() {
          return setAttributes({
            show_labels: '',
            flag_label_spacing: LABEL_SPACING_DEFAULT_VALUE
          });
        }
      });
    }
    var onLayoutChange = function onLayoutChange(value) {
      var patch = {
        layout: value
      };
      if ('select' === value) {
        patch.show_flags = false;
        if ('' === show_labels) {
          patch.show_labels = 'names';
        }
      }
      setAttributes(patch);
    };
    var onLabelsChange = function onLabelsChange(value) {
      if ('' === value) {
        if (!show_flags) {
          warn(noLabelsWarning);
          return;
        }
        setAttributes({
          show_labels: value,
          flag_label_spacing: LABEL_SPACING_DEFAULT_VALUE
        });
        return;
      }
      setAttributes({
        show_labels: value
      });
    };
    var onFlagsChange = function onFlagsChange(value) {
      if ('' === show_labels && !value) {
        warn(noFlagsWarning);
        return;
      }
      setAttributes({
        show_flags: !!value
      });
    };
    var flagControls = show_flags && [el(RadioControl, {
      key: 'aspect',
      label: __('Aspect', 'linguator-pro'),
      selected: flag_aspect_ratio,
      options: [{
        label: __('Landscape (3:2)', 'linguator-pro'),
        value: '3:2'
      }, {
        label: __('Square (1:1)', 'linguator-pro'),
        value: '1:1'
      }],
      onChange: function onChange(value) {
        return setAttributes({
          flag_aspect_ratio: value
        });
      }
    }), el(RangeControl, {
      key: 'radius',
      label: __('Border radius', 'linguator-pro'),
      initialPosition: 0,
      value: flag_border_radius,
      min: 0,
      max: 100,
      step: 1,
      allowReset: true,
      resetFallbackValue: 0,
      onChange: function onChange(value) {
        return setAttributes({
          flag_border_radius: value || 0
        });
      },
      __next40pxDefaultSize: true
    }), UnitControl && el(UnitControl, {
      key: 'width',
      __next40pxDefaultSize: true,
      label: __('Size', 'linguator-pro'),
      value: flag_width,
      units: CSS_LENGTH_UNITS,
      onChange: function onChange(value) {
        return setAttributes({
          flag_width: value
        });
      }
    }), UnitControl && '' !== show_labels && el(UnitControl, {
      key: 'spacing',
      __next40pxDefaultSize: true,
      label: __('Label spacing', 'linguator-pro'),
      value: flag_label_spacing,
      units: CSS_LENGTH_UNITS,
      onChange: function onChange(value) {
        return setAttributes({
          flag_label_spacing: value
        });
      }
    })];
    return el(Fragment, {}, el(InspectorControls, {}, el(PanelBody, {
      title: __('Display', 'linguator-pro')
    }, el(SelectControl, {
      label: __('Layout', 'linguator-pro'),
      value: layout,
      options: layoutOptions,
      onChange: onLayoutChange,
      __next40pxDefaultSize: true
    }), 'select' !== layout && el(SelectControl, {
      label: __('Labels', 'linguator-pro'),
      value: show_labels,
      options: labelOptions,
      onChange: onLabelsChange,
      __next40pxDefaultSize: true
    })), 'select' !== layout && el(PanelBody, {
      title: __('Flags Settings', 'linguator-pro')
    }, el(ToggleControl, {
      label: __('Display flags', 'linguator-pro'),
      checked: !!show_flags,
      onChange: onFlagsChange
    }), flagControls), el(PanelBody, {
      title: __('Behavior', 'linguator-pro')
    }, el(ToggleControl, {
      label: __('Force link to front page', 'linguator-pro'),
      checked: !!force_home,
      onChange: function onChange(value) {
        return setAttributes({
          force_home: !!value
        });
      }
    }), el(ToggleControl, {
      label: __('Hide the current language', 'linguator-pro'),
      checked: !!hide_current,
      onChange: function onChange(value) {
        return setAttributes({
          hide_current: !!value
        });
      }
    }), el(ToggleControl, {
      label: __('Hide languages with no translation', 'linguator-pro'),
      checked: !!hide_if_no_translation,
      onChange: function onChange(value) {
        return setAttributes({
          hide_if_no_translation: !!value
        });
      }
    }))), el(BlockControls, {}, el(ToolbarGroup, {}, el(ToolbarDropdownMenu, {
      icon: 'layout',
      label: __('Layout', 'linguator-pro'),
      controls: layoutToolbarControls
    }), 'select' !== layout && el(ToolbarButton, {
      icon: el('span', {
        className: 'dashicons dashicons-flag'
      }),
      label: show_flags ? __('Hide flags', 'linguator-pro') : __('Display flags', 'linguator-pro'),
      onClick: function onClick() {
        return onFlagsChange(!show_flags);
      }
    }), 'select' !== layout && el(ToolbarDropdownMenu, {
      icon: el('span', {
        className: 'dashicons dashicons-editor-textcolor'
      }),
      label: __('Labels', 'linguator-pro'),
      controls: toolbarLabelControls
    }))));
  }
  registerBlockType('linguator/language-switcher', {
    apiVersion: 3,
    title: __('Language switcher', 'linguator-pro'),
    description: __('Add a language switcher so visitors can select their preferred language.', 'linguator-pro'),
    icon: TranslationIcon,
    category: 'widgets',
    attributes: switcherAttributes,
    supports: {
      html: false,
      spacing: {
        margin: true,
        padding: true,
        blockGap: true
      },
      align: true,
      color: {
        background: true
      },
      typography: {
        fontSize: true,
        lineHeight: true,
        textAlign: true
      }
    },
    deprecated: deprecated,
    edit: function edit(props) {
      var blockProps = useBlockProps ? useBlockProps() : {};
      return el('div', blockProps, el(SwitcherControls, {
        attributes: props.attributes,
        setAttributes: props.setAttributes
      }), el(Disabled, {}, ServerSideRender ? el(ServerSideRender, {
        block: 'linguator/language-switcher',
        attributes: props.attributes,
        skipBlockSupportAttributes: true
      }) : el('div', {}, __('Language Switcher preview (SSR not available).', 'linguator-pro'))));
    },
    save: function save() {
      return null;
    } // Rendered via PHP
  });

  // ---------------------------------------------------------------------------
  // Navigation child block: linguator/navigation-language-switcher
  // ---------------------------------------------------------------------------
  var NAV_BLOCK = 'linguator/navigation-language-switcher';
  var navAttributes = _objectSpread(_objectSpread({}, switcherAttributes), {}, {
    layout: {
      type: 'string',
      enum: ['horizontal', 'dropdown'],
      default: 'horizontal'
    }
  });
  var navDefaults = _objectSpread(_objectSpread({}, switcherDefaults), {}, {
    layout: 'horizontal'
  });
  var navDeprecated = getDeprecated(navDefaults, 'dropdown');
  registerBlockType(NAV_BLOCK, {
    apiVersion: 3,
    title: __('Language switcher', 'linguator-pro'),
    description: __('Add a language switcher to the Navigation block.', 'linguator-pro'),
    icon: TranslationIcon,
    category: 'widgets',
    parent: ['core/navigation'],
    attributes: navAttributes,
    deprecated: navDeprecated,
    usesContext: ['textColor', 'customTextColor', 'backgroundColor', 'customBackgroundColor', 'overlayTextColor', 'customOverlayTextColor', 'overlayBackgroundColor', 'customOverlayBackgroundColor', 'fontSize', 'customFontSize', 'showSubmenuIcon', 'openSubmenusOnClick', 'style'],
    transforms: {
      from: [{
        type: 'block',
        blocks: ['core/navigation-link'],
        transform: function transform() {
          return createBlock(NAV_BLOCK);
        }
      }]
    },
    edit: function edit(props) {
      var attributes = props.attributes,
        setAttributes = props.setAttributes,
        context = props.context;
      var _ref3 = context || {},
        showSubmenuIcon = _ref3.showSubmenuIcon,
        openSubmenusOnClick = _ref3.openSubmenusOnClick;
      var layout = attributes.layout,
        show_flags = attributes.show_flags,
        flag_aspect_ratio = attributes.flag_aspect_ratio;
      var aspectRatioClass = show_flags && flag_aspect_ratio ? "lmat-aspect-ratio-".concat(flag_aspect_ratio.replace(':', '')) : '';
      var blockProps = useBlockProps ? useBlockProps({
        className: aspectRatioClass || undefined
      }) : {};
      var maybeSubmenuIcon = 'dropdown' === layout && (showSubmenuIcon || openSubmenusOnClick) ? el('span', {
        className: 'wp-block-navigation__submenu-icon'
      }, el(SubmenuChevron)) : null;
      return el('div', blockProps, el(SwitcherControls, {
        attributes: attributes,
        setAttributes: setAttributes,
        allowedLayouts: ['horizontal', 'dropdown']
      }), el(Disabled, {}, el('div', {
        className: 'wp-block-navigation-item'
      }, ServerSideRender ? el(ServerSideRender, {
        block: NAV_BLOCK,
        attributes: attributes,
        className: 'wp-block-navigation__container block-editor-block-list__layout'
      }) : el('div', {}, __('Language Switcher (Navigation) preview (SSR not available).', 'linguator-pro')), maybeSubmenuIcon)));
    },
    save: function save() {
      return null;
    } // Rendered via PHP
  });

  // ---------------------------------------------------------------------------
  // Classic Menu → Navigation conversion hook
  // Replaces a menu item with URL "#lmat_switcher" by our NAV_BLOCK with options from meta._lmat_menu_item
  // WARNING: relies on an unstable filter that may change across WP versions.
  // ---------------------------------------------------------------------------
  function mapBlockTree(blocks, menuItems, blocksMapping, mapper) {
    var _convert = function convert(block) {
      var replaced = mapper(block, menuItems, blocksMapping);
      var innerBlocks = (replaced.innerBlocks || []).map(function (b) {
        return _convert(b);
      });
      return _objectSpread(_objectSpread({}, replaced), {}, {
        innerBlocks: innerBlocks
      });
    };
    return blocks.map(_convert);
  }
  function blocksFilter(block, menuItems, blocksMapping) {
    if (block.name === 'core/navigation-link' && block.attributes && block.attributes.url === '#lmat_switcher') {
      var menuItem = (menuItems || []).find(function (m) {
        return m && m.url === '#lmat_switcher';
      });
      var meta = menuItem && menuItem.meta && menuItem.meta._lmat_menu_item || {};
      var attrs = 'layout' in meta || !('dropdown' in meta || 'show_names' in meta) ? meta : migrateLegacyAttributes(meta, navDefaults, 'dropdown');
      var newBlock = createBlock(NAV_BLOCK, attrs);
      if (menuItem && typeof menuItem.id !== 'undefined') {
        blocksMapping[menuItem.id] = newBlock.clientId;
      }
      return newBlock;
    }
    return block;
  }
  function menuItemsToBlocksFilter(blocks, menuItems) {
    return _objectSpread(_objectSpread({}, blocks), {}, {
      innerBlocks: mapBlockTree(blocks.innerBlocks || [], menuItems || [], blocks.mapping || {}, blocksFilter)
    });
  }
  addFilter('blocks.navigation.__unstableMenuItemsToBlocks', 'linguator/include-language-switcher', menuItemsToBlocksFilter);
})();
/******/ })()
;