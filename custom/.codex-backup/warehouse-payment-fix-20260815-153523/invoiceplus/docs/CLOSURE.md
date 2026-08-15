# InvoiceClosure response compatibility

InvoicePlus treats InvoiceClosure's public business class as the source of closure state. `InvoicePlusInvoiceService::buildInvoiceApiResponse()` first invokes native `Invoices::get()`, then adds `invoiceclosure` inside the custom module only when all of these conditions are true:

1. InvoiceClosure is enabled.
2. The current API user has `invoiceclosure.read`.
3. The InvoiceClosure class loads successfully.
4. Its public business method returns without a database error.

No placeholder property is created when these conditions are false.

The InvoicePlus-owned property contains:

```json
{
  "invoiceclosure": {
    "business_status": 1,
    "business_status_code": "closed",
    "business_status_label": "Closed",
    "locked": 1,
    "closed_at": 1786047240,
    "closed_at_iso": "2026-08-06T22:14:00+02:00",
    "closed_by": { "id": 15, "login": "user" },
    "closure_note": "Verified",
    "reopened_at": null,
    "reopened_at_iso": null,
    "reopened_by": null,
    "reopen_note": ""
  }
}
```

Values above are illustrative; labels, timestamps, users, and notes come from InvoiceClosure through the module-owned response builder.

`properties` is applied after the native response, closure block, and optional warehouse metadata are ready. Consequently, `properties=id,ref` removes `invoiceclosure`. Include `invoiceclosure` explicitly to retain it in a restricted response.

`INVOICEPLUS_LOAD_CLOSURE_DATA=0` deliberately omits `invoiceclosure` from InvoicePlus. Native `/invoices` routes always retain their stock Dolibarr 20.0.4 behavior and never receive this module-owned property.

On `GET /invoiceplus/warehouse/{warehouse_id}` only, `status=closed` and `status=paid_not_closed` are available while InvoiceClosure is active and the user can read closure information. Both start with Dolibarr paid invoices (`fk_statut=2`); the service then applies an indexed `EXISTS`/`NOT EXISTS` predicate to InvoiceClosure's module-owned status table before counting and paging. The root and `/byaccounts` lists retain Dolibarr's native status values.
