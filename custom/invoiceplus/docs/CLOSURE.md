# InvoiceClosure response compatibility

InvoicePlus treats the installed native invoice API as the only owner of the closure payload. `InvoicePlusInvoiceService::buildInvoiceApiResponse()` invokes `Invoices::get()`, whose installed cleanup adds `invoiceclosure` only when all of these conditions are true:

1. InvoiceClosure is enabled.
2. The current API user has `invoiceclosure.read`.
3. The InvoiceClosure class loads successfully.
4. Its public business method returns without a database error.

No placeholder property is created when these conditions are false.

The installed native property contains:

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

Values above are illustrative; labels, timestamps, users, and notes always come from the installed native API.

`properties` is applied after the native response and optional warehouse metadata are ready. Consequently, `properties=id,ref` removes `invoiceclosure`, matching native list filtering. Include `invoiceclosure` explicitly to retain it in a restricted response.

`INVOICEPLUS_LOAD_CLOSURE_DATA=0` deliberately removes `invoiceclosure` from InvoicePlus only. This configuration is documented as a chosen difference from an enriched native API.

`status=closed` and `status=paid_not_closed` are available only while InvoiceClosure is active and the user can read closure information. Both start with Dolibarr paid invoices (`fk_statut=2`); the service then calls the public `InvoiceClosure::getClosureStatus()` for the exact business state before counting and paging.
