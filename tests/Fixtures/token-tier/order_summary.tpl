<div class="summary-card" id="order-summary">
  <h2>{{ t('summary.header') }}</h2>
  {% if order.status == 'open' %}
    <p class="state-note">{{ t('summary.open_note') }}</p>
  {% endif %}
  <table>
    <tr><th>{{ t('summary.label_total') }}</th><td>{{ order.total|currency }}</td></tr>
    <tr><th>{{ t('summary.label_items') }}</th><td>{{ order.item_count }}</td></tr>
  </table>
</div>
