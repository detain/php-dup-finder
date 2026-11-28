"use strict";

// checkout page bootstrap
function renderCheckoutSummary(cartState, couponCode) {
    var items = cartState.items || [];
    var subtotal = 0;
    var discount = 0;
    var lines = [];
    for (var i = 0; i < items.length; i++) {
        var lineTotal = items[i].price * items[i].qty;
        subtotal = subtotal + lineTotal;
        lines.push({
            name: items[i].name,
            total: lineTotal,
            qty: items[i].qty
        });
    }
    if (couponCode && couponCode.length > 2) {
        discount = subtotal * 0.1;
        if (discount > 50) {
            discount = 50;
        }
    }
    var shipping = subtotal > 100 ? 0 : 7.5;
    var tax = (subtotal - discount) * 0.0825;
    var grandTotal = subtotal - discount + shipping + tax;
    return {
        lines: lines,
        subtotal: subtotal,
        discount: discount,
        shipping: shipping,
        tax: tax,
        grandTotal: grandTotal,
        currency: "USD"
    };
}

function formatMoney(value, currency) {
    var symbols = { USD: "$", EUR: "€", GBP: "£" };
    var symbol = symbols[currency] || currency;
    var text = value.toFixed(2);
    if (symbol.length === 1) {
        return symbol + text;
    }
    return text + " " + symbol;
}

module.exports = { renderCheckoutSummary, formatMoney };
