/* global wp */
(function () {
  const { __, _x } = wp.i18n;
  const { registerBlockType, createBlock } = wp.blocks;
  const { Fragment } = wp.element;
  const {
    InspectorControls,
    BlockControls,
    useBlockProps,
  } = wp.blockEditor || wp.editor; // fallback for older WP
  const {
    PanelBody,
    ToggleControl,
    SelectControl,
    RadioControl,
    RangeControl,
    ToolbarGroup,
    ToolbarButton,
    ToolbarDropdownMenu,
    Disabled,
  } = wp.components;
  const UnitControl = wp.components.UnitControl || wp.components.__experimentalUnitControl;
  const el = wp.element.createElement;
  const ServerSideRender = (wp.serverSideRender && wp.serverSideRender.default) || wp.serverSideRender;
  const { addFilter } = wp.hooks;

  // ---------------------------------------------------------------------------
  // Icon: translation (simple inline SVG)
  // ---------------------------------------------------------------------------
  const TranslationIcon = function () {
    return wp.element.createElement('span', {
      className: 'linguator-block-icon',
      style: {
        fontFamily: 'linguator',
        fontSize: '20px',
        lineHeight: '1',
        display: 'inline-block'
      }
    }, '\ue900');
  };

  // ---------------------------------------------------------------------------
  // Icon: submenu chevron (used in nav dropdown)
  // ---------------------------------------------------------------------------
  const SubmenuChevron = function () {
    return wp.element.createElement(
      'svg',
      { width: 12, height: 12, viewBox: '0 0 12 12', xmlns: 'http://www.w3.org/2000/svg', fill: 'none' },
      wp.element.createElement('path', { d: 'M1.5 4L6 8l4.5-4', strokeWidth: 1.5, stroke: 'currentColor' })
    );
  };

  // ---------------------------------------------------------------------------
  // Regular block: linguator/language-switcher
  // ---------------------------------------------------------------------------
  const CSS_LENGTH_UNITS = [
    { value: 'px', label: 'px' },
    { value: 'em', label: 'em' },
    { value: 'rem', label: 'rem' },
    { value: 'vh', label: 'vh' },
    { value: 'vw', label: 'vw' },
  ];
  const LABEL_SPACING_DEFAULT_VALUE = '0.3em';

  const switcherAttributes = {
    layout: { type: 'string', enum: ['vertical', 'horizontal', 'dropdown', 'select'], default: 'vertical' },
    show_labels: { type: 'string', enum: ['names', 'codes', ''], default: 'names' },
    show_flags: { type: 'boolean', default: false },
    force_home: { type: 'boolean', default: false },
    hide_current: { type: 'boolean', default: false },
    hide_if_no_translation: { type: 'boolean', default: false },
    flag_aspect_ratio: { type: 'string', enum: ['3:2', '1:1'], default: '3:2' },
    flag_border_radius: { type: 'number', default: 0 },
    flag_width: { type: 'string', default: '18px' },
    flag_label_spacing: { type: 'string', default: LABEL_SPACING_DEFAULT_VALUE },
    className: { type: 'string', default: '' },
  };

  const switcherDefaults = Object.keys(switcherAttributes).reduce((defaults, key) => {
    defaults[key] = switcherAttributes[key].default;
    return defaults;
  }, {});

  // Converts blocks saved with the `dropdown` and `show_names` attributes.
  const getDeprecated = (defaults, dropdownLayout) => [
    {
      attributes: {
        dropdown: { type: 'boolean', default: false },
        show_names: { type: 'boolean', default: true },
        show_flags: { type: 'boolean', default: false },
        force_home: { type: 'boolean', default: false },
        hide_current: { type: 'boolean', default: false },
        hide_if_no_translation: { type: 'boolean', default: false },
        className: { type: 'string', default: '' },
      },
      isEligible: (attributes) => 'dropdown' in attributes || 'show_names' in attributes,
      migrate: (attributes) => migrateLegacyAttributes(attributes, defaults, dropdownLayout),
      save: () => null,
    },
  ];

  // Maps the `dropdown` and `show_names` attributes to the current ones.
  function migrateLegacyAttributes(attributes, defaults, dropdownLayout) {
    const { dropdown, show_names, show_flags, force_home, hide_current, hide_if_no_translation, className } = attributes;
    return {
      ...defaults,
      layout: dropdown ? dropdownLayout : defaults.layout,
      show_labels: show_names ? 'names' : '',
      show_flags: show_names ? !!show_flags : true,
      force_home: !!force_home,
      hide_current: !!hide_current,
      hide_if_no_translation: !!hide_if_no_translation,
      className: className || '',
    };
  }

  const deprecated = getDeprecated(switcherDefaults, 'select');

  // Toolbar and inspector controls of the language switcher block.
  function SwitcherControls({ attributes, setAttributes, allowedLayouts = null }) {
    const {
      layout,
      show_labels,
      show_flags,
      force_home,
      hide_current,
      flag_aspect_ratio,
      flag_border_radius,
      flag_width,
      flag_label_spacing,
      hide_if_no_translation,
    } = attributes;

    const warn = (message) => {
      if (wp.data && wp.notices) {
        wp.data.dispatch(wp.notices.store || 'core/notices').createWarningNotice(message);
      }
    };
    const noLabelsWarning = __('Labels cannot be hidden if flags are not shown.', 'linguator-pro');
    const noFlagsWarning = __('Flags cannot be hidden if labels are not displayed.', 'linguator-pro');

    const layoutOptions = [
      { label: _x('Horizontal', 'layout', 'linguator-pro'), value: 'horizontal' },
      { label: _x('Vertical', 'layout', 'linguator-pro'), value: 'vertical' },
      { label: __('Dropdown', 'linguator-pro'), value: 'dropdown' },
      { label: __('Selector', 'linguator-pro'), value: 'select' },
    ].filter((option) => !allowedLayouts || allowedLayouts.includes(option.value));
    const layoutToolbarControls = layoutOptions
      .filter((option) => 'select' !== option.value)
      .map((option) => ({ title: option.label, onClick: () => setAttributes({ layout: option.value }) }));

    const labelOptions = [
      { label: __('Language names', 'linguator-pro'), value: 'names' },
      { label: __('Language codes', 'linguator-pro'), value: 'codes' },
    ];
    const toolbarLabelControls = [
      { title: __('Language names', 'linguator-pro'), onClick: () => setAttributes({ show_labels: 'names' }) },
      { title: __('Language codes', 'linguator-pro'), onClick: () => setAttributes({ show_labels: 'codes' }) },
    ];
    if (show_flags) {
      labelOptions.push({ label: _x('None', 'Labels', 'linguator-pro'), value: '' });
      toolbarLabelControls.push({
        title: _x('None', 'Labels', 'linguator-pro'),
        onClick: () => setAttributes({ show_labels: '', flag_label_spacing: LABEL_SPACING_DEFAULT_VALUE }),
      });
    }

    const onLayoutChange = (value) => {
      const patch = { layout: value };
      if ('select' === value) {
        patch.show_flags = false;
        if ('' === show_labels) {
          patch.show_labels = 'names';
        }
      }
      setAttributes(patch);
    };

    const onLabelsChange = (value) => {
      if ('' === value) {
        if (!show_flags) {
          warn(noLabelsWarning);
          return;
        }
        setAttributes({ show_labels: value, flag_label_spacing: LABEL_SPACING_DEFAULT_VALUE });
        return;
      }
      setAttributes({ show_labels: value });
    };

    const onFlagsChange = (value) => {
      if ('' === show_labels && !value) {
        warn(noFlagsWarning);
        return;
      }
      setAttributes({ show_flags: !!value });
    };

    const flagControls = show_flags && [
      el(RadioControl, {
        key: 'aspect',
        label: __('Aspect', 'linguator-pro'),
        selected: flag_aspect_ratio,
        options: [
          { label: __('Landscape (3:2)', 'linguator-pro'), value: '3:2' },
          { label: __('Square (1:1)', 'linguator-pro'), value: '1:1' },
        ],
        onChange: (value) => setAttributes({ flag_aspect_ratio: value }),
      }),
      el(RangeControl, {
        key: 'radius',
        label: __('Border radius', 'linguator-pro'),
        initialPosition: 0,
        value: flag_border_radius,
        min: 0,
        max: 100,
        step: 1,
        allowReset: true,
        resetFallbackValue: 0,
        onChange: (value) => setAttributes({ flag_border_radius: value || 0 }),
        __next40pxDefaultSize: true,
      }),
      UnitControl &&
      el(UnitControl, {
        key: 'width',
        __next40pxDefaultSize: true,
        label: __('Size', 'linguator-pro'),
        value: flag_width,
        units: CSS_LENGTH_UNITS,
        onChange: (value) => setAttributes({ flag_width: value }),
      }),
      UnitControl &&
      '' !== show_labels &&
      el(UnitControl, {
        key: 'spacing',
        __next40pxDefaultSize: true,
        label: __('Label spacing', 'linguator-pro'),
        value: flag_label_spacing,
        units: CSS_LENGTH_UNITS,
        onChange: (value) => setAttributes({ flag_label_spacing: value }),
      }),
    ];

    return el(
      Fragment,
      {},
      el(
        InspectorControls,
        {},
        el(
          PanelBody,
          { title: __('Display', 'linguator-pro') },
          el(SelectControl, {
            label: __('Layout', 'linguator-pro'),
            value: layout,
            options: layoutOptions,
            onChange: onLayoutChange,
            __next40pxDefaultSize: true,
          }),
          'select' !== layout &&
          el(SelectControl, {
            label: __('Labels', 'linguator-pro'),
            value: show_labels,
            options: labelOptions,
            onChange: onLabelsChange,
            __next40pxDefaultSize: true,
          })
        ),
        'select' !== layout &&
        el(
          PanelBody,
          { title: __('Flags Settings', 'linguator-pro') },
          el(ToggleControl, {
            label: __('Display flags', 'linguator-pro'),
            checked: !!show_flags,
            onChange: onFlagsChange,
          }),
          flagControls
        ),
        el(
          PanelBody,
          { title: __('Behavior', 'linguator-pro') },
          el(ToggleControl, {
            label: __('Force link to front page', 'linguator-pro'),
            checked: !!force_home,
            onChange: (value) => setAttributes({ force_home: !!value }),
          }),
          el(ToggleControl, {
            label: __('Hide the current language', 'linguator-pro'),
            checked: !!hide_current,
            onChange: (value) => setAttributes({ hide_current: !!value }),
          }),
          el(ToggleControl, {
            label: __('Hide languages with no translation', 'linguator-pro'),
            checked: !!hide_if_no_translation,
            onChange: (value) => setAttributes({ hide_if_no_translation: !!value }),
          })
        )
      ),
      el(
        BlockControls,
        {},
        el(
          ToolbarGroup,
          {},
          el(ToolbarDropdownMenu, {
            icon: 'layout',
            label: __('Layout', 'linguator-pro'),
            controls: layoutToolbarControls,
          }),
          'select' !== layout &&
          el(ToolbarButton, {
            icon: el('span', { className: 'dashicons dashicons-flag' }),
            label: show_flags ? __('Hide flags', 'linguator-pro') : __('Display flags', 'linguator-pro'),
            onClick: () => onFlagsChange(!show_flags),
          }),
          'select' !== layout &&
          el(ToolbarDropdownMenu, {
            icon: el('span', { className: 'dashicons dashicons-editor-textcolor' }),
            label: __('Labels', 'linguator-pro'),
            controls: toolbarLabelControls,
          })
        )
      )
    );
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
      spacing: { margin: true, padding: true, blockGap: true },
      align: true,
      color: { background: true },
      typography: { fontSize: true, lineHeight: true, textAlign: true },
    },
    deprecated,
    edit: (props) => {
      const blockProps = useBlockProps ? useBlockProps() : {};
      return el(
        'div',
        blockProps,
        el(SwitcherControls, { attributes: props.attributes, setAttributes: props.setAttributes }),
        el(
          Disabled,
          {},
          ServerSideRender
            ? el(ServerSideRender, {
              block: 'linguator/language-switcher',
              attributes: props.attributes,
              skipBlockSupportAttributes: true,
            })
            : el('div', {}, __('Language Switcher preview (SSR not available).', 'linguator-pro'))
        )
      );
    },
    save: () => null, // Rendered via PHP
  });

  // ---------------------------------------------------------------------------
  // Navigation child block: linguator/navigation-language-switcher
  // ---------------------------------------------------------------------------
  const NAV_BLOCK = 'linguator/navigation-language-switcher';
  const navAttributes = {
    ...switcherAttributes,
    layout: { type: 'string', enum: ['horizontal', 'dropdown'], default: 'horizontal' },
  };
  const navDefaults = { ...switcherDefaults, layout: 'horizontal' };
  const navDeprecated = getDeprecated(navDefaults, 'dropdown');
  registerBlockType(NAV_BLOCK, {
    apiVersion: 3,
    title: __('Language switcher', 'linguator-pro'),
    description: __('Add a language switcher to the Navigation block.', 'linguator-pro'),
    icon: TranslationIcon,
    category: 'widgets',
    parent: ['core/navigation'],
    attributes: navAttributes,
    deprecated: navDeprecated,
    usesContext: [
      'textColor',
      'customTextColor',
      'backgroundColor',
      'customBackgroundColor',
      'overlayTextColor',
      'customOverlayTextColor',
      'overlayBackgroundColor',
      'customOverlayBackgroundColor',
      'fontSize',
      'customFontSize',
      'showSubmenuIcon',
      'openSubmenusOnClick',
      'style',
    ],
    transforms: {
      from: [
        {
          type: 'block',
          blocks: ['core/navigation-link'],
          transform: () => createBlock(NAV_BLOCK),
        },
      ],
    },
    edit: (props) => {
      const { attributes, setAttributes, context } = props;
      const { showSubmenuIcon, openSubmenusOnClick } = context || {};
      const { layout, show_flags, flag_aspect_ratio } = attributes;
      const aspectRatioClass = show_flags && flag_aspect_ratio ? `lmat-aspect-ratio-${flag_aspect_ratio.replace(':', '')}` : '';
      const blockProps = useBlockProps ? useBlockProps({ className: aspectRatioClass || undefined }) : {};

      const maybeSubmenuIcon =
        'dropdown' === layout && (showSubmenuIcon || openSubmenusOnClick)
          ? el('span', { className: 'wp-block-navigation__submenu-icon' }, el(SubmenuChevron))
          : null;

      return el(
        'div',
        blockProps,
        el(SwitcherControls, { attributes, setAttributes, allowedLayouts: ['horizontal', 'dropdown'] }),
        el(
          Disabled,
          {},
          el(
            'div',
            { className: 'wp-block-navigation-item' },
            ServerSideRender
              ? el(ServerSideRender, {
                block: NAV_BLOCK,
                attributes,
                className: 'wp-block-navigation__container block-editor-block-list__layout',
              })
              : el('div', {}, __('Language Switcher (Navigation) preview (SSR not available).', 'linguator-pro')),
            maybeSubmenuIcon
          )
        )
      );
    },
    save: () => null, // Rendered via PHP
  });

  // ---------------------------------------------------------------------------
  // Classic Menu → Navigation conversion hook
  // Replaces a menu item with URL "#lmat_switcher" by our NAV_BLOCK with options from meta._lmat_menu_item
  // WARNING: relies on an unstable filter that may change across WP versions.
  // ---------------------------------------------------------------------------
  function mapBlockTree(blocks, menuItems, blocksMapping, mapper) {
    const convert = (block) => {
      const replaced = mapper(block, menuItems, blocksMapping);
      const innerBlocks = (replaced.innerBlocks || []).map((b) => convert(b));
      return { ...replaced, innerBlocks };
    };
    return blocks.map(convert);
  }

  function blocksFilter(block, menuItems, blocksMapping) {
    if (block.name === 'core/navigation-link' && block.attributes && block.attributes.url === '#lmat_switcher') {
      const menuItem = (menuItems || []).find((m) => m && m.url === '#lmat_switcher');
      const meta = (menuItem && menuItem.meta && menuItem.meta._lmat_menu_item) || {};
      const attrs = 'layout' in meta || !('dropdown' in meta || 'show_names' in meta)
        ? meta
        : migrateLegacyAttributes(meta, navDefaults, 'dropdown');
      const newBlock = createBlock(NAV_BLOCK, attrs);
      if (menuItem && typeof menuItem.id !== 'undefined') {
        blocksMapping[menuItem.id] = newBlock.clientId;
      }
      return newBlock;
    }
    return block;
  }

  function menuItemsToBlocksFilter(blocks, menuItems) {
    return {
      ...blocks,
      innerBlocks: mapBlockTree(blocks.innerBlocks || [], menuItems || [], blocks.mapping || {}, blocksFilter),
    };
  }

  addFilter(
    'blocks.navigation.__unstableMenuItemsToBlocks',
    'linguator/include-language-switcher',
    menuItemsToBlocksFilter
  );
})();

