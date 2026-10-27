/******/ (() => { // webpackBootstrap
/******/ 	"use strict";

;// ./assets/js/src/frontend-dropdown.js
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function _classCallCheck(a, n) { if (!(a instanceof n)) throw new TypeError("Cannot call a class as a function"); }
function _defineProperties(e, r) { for (var t = 0; t < r.length; t++) { var o = r[t]; o.enumerable = o.enumerable || !1, o.configurable = !0, "value" in o && (o.writable = !0), Object.defineProperty(e, _toPropertyKey(o.key), o); } }
function _createClass(e, r, t) { return r && _defineProperties(e.prototype, r), t && _defineProperties(e, t), Object.defineProperty(e, "prototype", { writable: !1 }), e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, e) { if ("object" != _typeof(t) || !t) return t; var r; if ("undefined" != typeof Symbol && void 0 !== (r = t[Symbol.toPrimitive])) { var i = r.call(t, e || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === e ? String : Number)(t); }
/**
 * Allows to open/close the language switcher's submenus when the "dropdown" layout is used.
 */
var Dropdown = /*#__PURE__*/function () {
  /**
   * Attaches events to the dropdown's elements.
   *
   * @param {HTMLElement} button The button that opens/closes the dropdown.
   */
  function Dropdown(button) {
    _classCallCheck(this, Dropdown);
    this.button = button;
    this.closeOnClickOutsideBinded = this.closeOnClickOutside.bind(this);
    button.setAttribute('aria-expanded', 'false');
    button.parentNode.addEventListener('mouseenter', this.open.bind(this));
    button.parentNode.addEventListener('mouseleave', this.close.bind(this));
    button.addEventListener('click', this.toggle.bind(this));
    button.nextElementSibling.addEventListener('keydown', this.closeOnListKeydown.bind(this));
    // Escape also closes the submenu while focus is on the toggle button.
    button.addEventListener('keydown', this.closeOnListKeydown.bind(this));
    button.nextElementSibling.querySelector('li:last-child > a').addEventListener('blur', this.closeOnLastLinkBlur.bind(this));
  }

  /**
   * Opens the submenu.
   */
  return _createClass(Dropdown, [{
    key: "open",
    value: function open() {
      this.button.setAttribute('aria-expanded', 'true');
      document.addEventListener('click', this.closeOnClickOutsideBinded);
    }

    /**
     * Closes the submenu.
     */
  }, {
    key: "close",
    value: function close() {
      this.button.setAttribute('aria-expanded', 'false');
      document.removeEventListener('click', this.closeOnClickOutsideBinded);
    }

    /**
     * Toggles the submenu.
     */
  }, {
    key: "toggle",
    value: function toggle() {
      var expanded = this.button.getAttribute('aria-expanded');
      if ('true' === expanded) {
        this.close();
      } else {
        this.open();
      }
    }

    /**
     * Event callback that closes the submenu when pressing the Escape key while in the list or on the toggle button.
     *
     * @param {Event} event The event.
     */
  }, {
    key: "closeOnListKeydown",
    value: function closeOnListKeydown(event) {
      if (event.key === 'Escape') {
        this.button.focus();
        this.close();
      }
    }

    /**
     * Event callback that closes the submenu when the last link in the list looses focus (to a target outside the list).
     *
     * @param {Event} event The event.
     */
  }, {
    key: "closeOnLastLinkBlur",
    value: function closeOnLastLinkBlur(event) {
      if (!this.button.parentNode.contains(event.relatedTarget)) {
        this.close();
      }
    }

    /**
     * Event callback that closes the submenu when the user clicks outside the dropdown.
     *
     * @param {Event} event The event.
     */
  }, {
    key: "closeOnClickOutside",
    value: function closeOnClickOutside(event) {
      if (!this.button.parentNode.contains(event.target)) {
        this.close();
      }
    }
  }]);
}();

;// ./assets/js/src/language-switcher.js
function _createForOfIteratorHelper(r, e) { var t = "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (!t) { if (Array.isArray(r) || (t = _unsupportedIterableToArray(r)) || e && r && "number" == typeof r.length) { t && (r = t); var _n = 0, F = function F() {}; return { s: F, n: function n() { return _n >= r.length ? { done: !0 } : { done: !1, value: r[_n++] }; }, e: function e(r) { throw r; }, f: F }; } throw new TypeError("Invalid attempt to iterate non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); } var o, a = !0, u = !1; return { s: function s() { t = t.call(r); }, n: function n() { var r = t.next(); return a = r.done, r; }, e: function e(r) { u = !0, o = r; }, f: function f() { try { a || null == t.return || t.return(); } finally { if (u) throw o; } } }; }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
/**
 * Allows to open/close the language switcher's submenus on click when the "dropdown" layout is used.
 */

;
var lmatSwitcher = {
  /**
   * Init.
   */
  init: function init() {
    if (document.readyState !== 'loading') {
      lmatSwitcher.ready();
    } else {
      document.addEventListener('DOMContentLoaded', lmatSwitcher.ready);
    }
  },
  /**
   * Called when the DOM is ready. Attaches the events to the buttons.
   */
  ready: function ready() {
    // Dropdowns.
    var buttons = document.getElementsByClassName('lmat-submenu-toggle');
    var _iterator = _createForOfIteratorHelper(buttons),
      _step;
    try {
      for (_iterator.s(); !(_step = _iterator.n()).done;) {
        var button = _step.value;
        new Dropdown(button);
      }

      // Selects.
    } catch (err) {
      _iterator.e(err);
    } finally {
      _iterator.f();
    }
    var selects = document.getElementsByClassName('lmat-switcher-select');
    var _iterator2 = _createForOfIteratorHelper(selects),
      _step2;
    try {
      for (_iterator2.s(); !(_step2 = _iterator2.n()).done;) {
        var select = _step2.value;
        select.addEventListener('change', function (event) {
          if (event.currentTarget.value) {
            window.location.href = event.currentTarget.value; // Already escaped in `the select switcher element`.
          }
        });
      }
    } catch (err) {
      _iterator2.e(err);
    } finally {
      _iterator2.f();
    }
  }
};
lmatSwitcher.init();
/******/ })()
;