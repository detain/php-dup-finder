<div class="receipt-card" id="invoice-summary">
  <h2>{{ t('summary.receipt_heading') }}</h2>
  {% if invoice.state == 'due' %}
    <p class="note-text">{{ t('summary.due_note') }}</p>
  {% endif %}
  <table>
    <tr><th>{{ t('summary.paid_label') }}</th><td>{{ invoice.amount|currency }}</td></tr>
    <tr><th>{{ t('summary.line_label') }}</th><td>{{ invoice.line_count }}</td></tr>
  </table>
</div>
