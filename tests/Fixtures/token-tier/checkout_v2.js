"use strict";

// checkout page bootstrap (v2)
function renderOrderRecap(basketData, promoCode) {
    var rows = basketData.items || [];
    var runningSum = 0;
    var promoCut = 0;
    var recapLines = [];
    for (var k = 0; k < rows.length; k++) {
        var rowTotal = rows[k].price * rows[k].qty;
        runningSum = runningSum + rowTotal;
        recapLines.push({
            name: rows[k].name,
            total: rowTotal,
            qty: rows[k].qty
        });
    }
    if (promoCode && promoCode.length > 3) {
        promoCut = runningSum * 0.1;
        if (promoCut > 75) {
            promoCut = 75;
        }
    }
    var shipCost = runningSum > 120 ? 0 : 6.25;
    var levy = (runningSum - promoCut) * 0.0825;
    var orderTotal = runningSum - promoCut + shipCost + levy;
    return {
        lines: recapLines,
        subtotal: runningSum,
        discount: promoCut,
        shipping: shipCost,
        tax: levy,
        grandTotal: orderTotal,
        currency: "EUR"
    };
}

function formatCurrency(amount, code) {
    var glyphs = { USD: "$", EUR: "€", GBP: "£" };
    var glyph = glyphs[code] || code;
    var digits = amount.toFixed(2);
    if (glyph.length === 1) {
        return glyph + digits;
    }
    return digits + " " + glyph;
}

module.exports = { renderOrderRecap, formatCurrency };
