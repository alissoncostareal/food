<?php

return [
    /** Pedidos pendentes mais antigos que isso não disparam alerta sonoro. */
    'actionable_pending_hours' => (int) env('ORDER_ACTIONABLE_PENDING_HOURS', 24),

    'merchant_list_per_page' => (int) env('ORDER_MERCHANT_LIST_PER_PAGE', 15),
];
