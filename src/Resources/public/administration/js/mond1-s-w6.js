!function (e) { var t = {}; function n(r) { if (t[r]) return t[r].exports; var o = t[r] = { i: r, l: !1, exports: {} }; return e[r].call(o.exports, o, o.exports, n), o.l = !0, o.exports } n.m = e, n.c = t, n.d = function (e, t, r) { n.o(e, t) || Object.defineProperty(e, t, { enumerable: !0, get: r }) }, n.r = function (e) { "undefined" != typeof Symbol && Symbol.toStringTag && Object.defineProperty(e, Symbol.toStringTag, { value: "Module" }), Object.defineProperty(e, "__esModule", { value: !0 }) }, n.t = function (e, t) { if (1 & t && (e = n(e)), 8 & t) return e; if (4 & t && "object" == typeof e && e && e.__esModule) return e; var r = Object.create(null); if (n.r(r), Object.defineProperty(r, "default", { enumerable: !0, value: e }), 2 & t && "string" != typeof e) for (var o in e) n.d(r, o, function (t) { return e[t] }.bind(null, o)); return r }, n.n = function (e) { var t = e && e.__esModule ? function () { return e.default } : function () { return e }; return n.d(t, "a", t), t }, n.o = function (e, t) { return Object.prototype.hasOwnProperty.call(e, t) }, n.p = "/bundles/mond1sw6/", n(n.s = "SVUv") }({ "P4+m": function (e, t) { e.exports = "{% block sw_order_document_card_grid_action_download %}\n    {% parent %}\n\n      <sw-context-menu-item v-if=\"item.documentType.technicalName == 'invoice'\" @click=\"onCancelInvoice(item.id, item.orderId)\">\n        {% block sw_order_document_card_grid_action_cancel_invoice_label %}\n          {{ $tc('sw-order-mondu.documentCard.labelCancelInvoice') }}\n        {% endblock %}\n      </sw-context-menu-item>\n\n\n      <sw-context-menu-item v-if=\"item.documentType.technicalName == 'credit_note'\" @click=\"onCancelCreditNote(item.id, item.orderId)\">\n        {% block sw_order_document_card_grid_action_cancel_credit_note_label %}\n          {{ $tc('sw-order-mondu.documentCard.labelCancelCreditNote') }}\n        {% endblock %}\n      </sw-context-menu-item>\n{% endblock %}\n" }, SVUv: function (e, t, n) { "use strict"; n.r(t); var r = n("P4+m"), o = n.n(r); function c(e) { return (c = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (e) { return typeof e } : function (e) { return e && "function" == typeof Symbol && e.constructor === Symbol && e !== Symbol.prototype ? "symbol" : typeof e })(e) } function i(e, t) { if (!(e instanceof t)) throw new TypeError("Cannot call a class as a function") } function u(e, t) { for (var n = 0; n < t.length; n++) { var r = t[n]; r.enumerable = r.enumerable || !1, r.configurable = !0, "value" in r && (r.writable = !0), Object.defineProperty(e, r.key, r) } } function a(e, t) { return (a = Object.setPrototypeOf || function (e, t) { return e.__proto__ = t, e })(e, t) } function s(e) { var t = function () { if ("undefined" == typeof Reflect || !Reflect.construct) return !1; if (Reflect.construct.sham) return !1; if ("function" == typeof Proxy) return !0; try { return Boolean.prototype.valueOf.call(Reflect.construct(Boolean, [], (function () { }))), !0 } catch (e) { return !1 } }(); return function () { var n, r = l(e); if (t) { var o = l(this).constructor; n = Reflect.construct(r, arguments, o) } else n = r.apply(this, arguments); return f(this, n) } } function f(e, t) { return !t || "object" !== c(t) && "function" != typeof t ? function (e) { if (void 0 === e) throw new ReferenceError("this hasn't been initialised - super() hasn't been called"); return e }(e) : t } function l(e) { return (l = Object.setPrototypeOf ? Object.getPrototypeOf : function (e) { return e.__proto__ || Object.getPrototypeOf(e) })(e) } Shopware.Component.override("sw-order-document-card", { template: o.a, inject: ["invoiceApiService", "creditNoteApiService"], mixins: ["notification"], methods: { onCancelInvoice: function (e, t) { var n = this; this.invoiceApiService.cancel(t, e).then((function (e) { n.createNotificationSuccess({ title: n.$tc("sw-order-mondu.documentCard.cancelSuccessTitle"), message: n.$tc("sw-order-mondu.documentCard.cancelSuccessMessage") }) })).catch((function (e) { "0" != e.error && n.createNotificationError({ message: n.$tc("sw-order-mondu.documentCard.cancelErrorMessage") }) })) }, onCancelCreditNote: function (e, t) { var n = this; this.creditNoteApiService.cancel(t, e).then((function (e) { n.createNotificationSuccess({ title: n.$tc("sw-order-mondu.documentCard.cancelSuccessTitle"), message: n.$tc("sw-order-mondu.documentCard.cancelSuccessMessage") }) })).catch((function (e) { "0" != e.error && n.createNotificationError({ message: n.$tc("sw-order-mondu.documentCard.cancelErrorMessage") }) })) } } }), Shopware.Component.override("sw-order-state-history-card", { methods: { createStateChangeErrorNotification: function (e) { var t = JSON.parse(e.response.request.response).errors.pop(); e.response && e.response.data && e.response.data.errors && t && "MONDU__ERROR" === t.code ? this.createNotificationError({ message: t.detail }) : this.$super("createStateChangeErrorNotification", e) } } }); var p = function (e) { !function (e, t) { if ("function" != typeof t && null !== t) throw new TypeError("Super expression must either be null or a function"); e.prototype = Object.create(t && t.prototype, { constructor: { value: e, writable: !0, configurable: !0 } }), t && a(e, t) }(c, Shopware.Classes.ApiService); var t, n, r, o = s(c); function c(e, t) { var n = arguments.length > 2 && void 0 !== arguments[2] ? arguments[2] : "mondus"; return i(this, c), o.call(this, e, t, n) } return t = c, (n = [{ key: "cancel", value: function (e, t) { return this.httpClient.post("/mondu/orders/".concat(e, "/").concat(t, "/cancel"), { headers: this.getBasicHeaders() }).then((function (e) { return e.data })) } }]) && u(t.prototype, n), r && u(t, r), c }(); function d(e) { return (d = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (e) { return typeof e } : function (e) { return e && "function" == typeof Symbol && e.constructor === Symbol && e !== Symbol.prototype ? "symbol" : typeof e })(e) } function m(e, t) { if (!(e instanceof t)) throw new TypeError("Cannot call a class as a function") } function y(e, t) { for (var n = 0; n < t.length; n++) { var r = t[n]; r.enumerable = r.enumerable || !1, r.configurable = !0, "value" in r && (r.writable = !0), Object.defineProperty(e, r.key, r) } } function h(e, t) { return (h = Object.setPrototypeOf || function (e, t) { return e.__proto__ = t, e })(e, t) } function b(e) { var t = function () { if ("undefined" == typeof Reflect || !Reflect.construct) return !1; if (Reflect.construct.sham) return !1; if ("function" == typeof Proxy) return !0; try { return Boolean.prototype.valueOf.call(Reflect.construct(Boolean, [], (function () { }))), !0 } catch (e) { return !1 } }(); return function () { var n, r = S(e); if (t) { var o = S(this).constructor; n = Reflect.construct(r, arguments, o) } else n = r.apply(this, arguments); return v(this, n) } } function v(e, t) { return !t || "object" !== d(t) && "function" != typeof t ? function (e) { if (void 0 === e) throw new ReferenceError("this hasn't been initialised - super() hasn't been called"); return e }(e) : t } function S(e) { return (S = Object.setPrototypeOf ? Object.getPrototypeOf : function (e) { return e.__proto__ || Object.getPrototypeOf(e) })(e) } Shopware.Service().register("invoiceApiService", (function (e) { var t = Shopware.Application.getContainer("init"); return new p(t.httpClient, Shopware.Service("loginService")) })); var _ = function (e) { !function (e, t) { if ("function" != typeof t && null !== t) throw new TypeError("Super expression must either be null or a function"); e.prototype = Object.create(t && t.prototype, { constructor: { value: e, writable: !0, configurable: !0 } }), t && h(e, t) }(c, Shopware.Classes.ApiService); var t, n, r, o = b(c); function c(e, t) { var n = arguments.length > 2 && void 0 !== arguments[2] ? arguments[2] : "mondus"; return m(this, c), o.call(this, e, t, n) } return t = c, (n = [{ key: "cancel", value: function (e, t) { return this.httpClient.post("/mondu/orders/".concat(e, "/credit_notes/").concat(t, "/cancel"), { headers: this.getBasicHeaders() }).then((function (e) { return e.data })) } }]) && y(t.prototype, n), r && y(t, r), c }(); Shopware.Service().register("creditNoteApiService", (function (e) { var t = Shopware.Application.getContainer("init"); return new _(t.httpClient, Shopware.Service("loginService")) })) } });
//# sourceMappingURL=mond1-s-w6.js.map