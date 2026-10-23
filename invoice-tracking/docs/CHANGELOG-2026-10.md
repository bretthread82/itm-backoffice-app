# Invoice Approval & Tracking: Changes since the 16 October 2026 meeting

Prepared 22 October 2026 for Maloney Group.

## Summary

Since the meeting we have added teams on approval routes, a managed supplier list with supplier reports, separate Processed and Paid stages for invoices, supporting documents, high priority invoice types, long-term analytics, and a new Workflow Manager access level.

## Approval routes and teams

* Approval route steps are now assigned to **teams** instead of shared mailboxes.
* A team can have an optional **group mailbox**. It is notified alongside all of the team's members.
* The routing import, escalation reminders and notification emails all work with teams.

## Suppliers

* Administrators can now keep a **managed supplier list**, with a supplier code for each supplier.
* Suppliers can be loaded in bulk from a **CSV file**, with a preview to check before anything is saved.
* The supplier field on the invoice form is now a **dropdown** of this list.
* New **supplier reports** show invoice activity by supplier.
* The invoice register has new **Paid, Unpaid and Total** summary cards.
* Fixed: uploading an invoice could fail after the file had been received.

## Processed and Paid

* The old "Completed" status is replaced by two stages:
  * **Processed**: the invoice has been processed and is filed to SharePoint.
  * **Paid**: payment has been recorded.
* Routes can now include an optional **Pay step** (at most one, after all approval and process steps). It records the **payment reference and date** and marks the invoice as paid.
* A route without a Pay step ends at Processed.
* Existing completed invoices are converted automatically. Reports, the register and the API still accept "completed" as "processed or paid".

## Supporting documents

* Extra files can now be **attached to an invoice** by the submitter or anyone who can act on it. A group-level user must give a reason.
* Attachments are recorded in the invoice history and cannot be edited or deleted.
* They are filed to SharePoint after the invoice PDF.

## High priority invoice types

* Managers, administrators and Workflow Managers can mark an invoice type as **high priority**.
* Invoices of that type raise high-priority alerts, and approvers are **always emailed**, whatever their notification preferences.
* Setting the flag on a default type updates its copies.

## Long-term analytics

* A new **Reports & Analytics** area with three views: **Volume**, **Cycle time** and **Backlog**.
* It is built from a separate analytics database that refreshes **nightly**, so it does not slow down day-to-day use. If that database is unavailable, the rest of the application is not affected.
* Users see only the companies they already have access to.
* Available to Managers and Administrators.

## New access level: Workflow Manager

* A new level, granted only by an Administrator, for people who maintain the workflow without wider admin rights.
* It gives access to **Invoice Types, Suppliers, Approval Routes and Route Variants**, and nothing else.
* It cannot be combined with the Manager level.

## Sign-in page

* The login page now has a branded photo background and a "Powered by ITM" footer.

## Notes for go-live

* The database needs updating (`php spark migrate --all`), and the analytics database needs its own setup (`php spark analytics:migrate`) and a nightly load job. See `docs/DEPLOYMENT.md`.
* Automated tests were written for each change. The full suite has not been re-run for this summary.
* Still to be confirmed with the client: the "- Yes" route variants, the updated routing list with emails, SharePoint site and app registration, Entra tenant and licence, default admin details, and the sub-group structure and first group-level users.
