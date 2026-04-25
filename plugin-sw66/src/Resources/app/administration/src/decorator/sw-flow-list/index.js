/**
 * Decorator to hide the raw technical event ID from the Flow Builder list.
 *
 * Shopware 6.6 core template renders the eventName column with two lines:
 *   <strong>{{ getTranslatedEventName(item.eventName) }}</strong>
 *   <p>{{ item.eventName }}</p>
 *
 * The second <p> dumps the raw trigger id (e.g. "Mondu Payments.order.Cancelled")
 * right under the localized label ("Mondu: Order Cancelled") — visually redundant.
 * Shopware 6.7 dropped that extra line; we align 6.6 with 6.7 by overriding the
 * sw_flow_list_grid_columns_event_name block and re-emitting it without the raw <p>.
 */

// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
Shopware.Component.override('sw-flow-list', {
    template: `
        {% block sw_flow_list_grid_columns_event_name %}
        <template #column-eventName="{ item }">
            <div v-if="isValidTrigger(item.eventName)" class="sw-flow-list__event-name">
                <strong class="sw-flow-list__event-name-label">
                    {{ getTranslatedEventName(item.eventName) }}
                </strong>
            </div>
            <div v-else>
                <p>{{ $tc('sw-flow.list.unknownTrigger') }}</p>
            </div>
        </template>
        {% endblock %}
    `,
});
