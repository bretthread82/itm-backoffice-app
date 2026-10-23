<?php

declare(strict_types=1);

/**
 * The text of the in-app manual. Edit copy here; the page, search and role filtering are generic.
 *
 * Each topic: id (anchor), title, icon (Bootstrap Icons name), needs, summary (one line, plain text),
 * body (trusted static HTML), optional body_extra, faqs, and optionally 'events' => true to append
 * the notification-event table.
 *
 * "needs" is a list of capabilities (submitter | approver | group | admin). Content is shown when the
 * user has ANY of them; no list means everyone. It can be set on:
 *   - a topic:       'needs' => ['approver']
 *   - extra parts:   'body_extra' => [['html' => '...', 'needs' => [...]], ...] shown after 'body'
 *   - an FAQ item:   [question, answer-html] or [question, answer-html, ['needs' => [...]]]
 * Anything the user lacks the capability for is never sent to the browser.
 *
 * Nothing here may contain secrets. Describe configuration, never real values.
 *
 * @return list<array<string, mixed>>
 */
return [
    // ------------------------------------------------------------------ everyone
    [
        'id' => 'getting-started', 'title' => 'Getting started', 'icon' => 'bi-door-open',
        'summary' => 'Signing in, the screen layout, searching and the bell.',
        'body' => '
            <p>The app tracks every invoice from upload, through its approval steps, to a saved copy in SharePoint.
            What you can see and do depends on your companies and on the steps you are named on.</p>
            <ul>
                <li><strong>Signing in.</strong> Use your email and password. If you were invited, use the link in the invitation
                    email to set your password the first time. Microsoft sign-in appears on the login page only when an administrator has switched it on,
                    and it never creates an account for you: an administrator must already have added you.</li>
                <li><strong>Change your password</strong> from your name (top right) &gt; Change password.</li>
                <li><strong>Company switcher.</strong> If you belong to several companies you can narrow the screens to one of them.</li>
                <li><strong>Search.</strong> The box in the top bar finds invoices by number, supplier and similar details. Press <kbd>Ctrl</kbd> + <kbd>K</kbd> to jump to it.</li>
                <li><strong>Invoice Register totals.</strong> The cards above the register show the <em>paid</em>, <em>unpaid</em> and <em>total</em> amounts for the filters you have set (the status filter is ignored). Paid means the invoice reached its Pay step and was marked paid; unpaid means still outstanding, including an invoice that is processed and waiting to be paid. Amounts are shown per currency.</li>
                <li><strong>The bell</strong> shows your alerts. Click an alert to open the invoice.</li>
                <li><strong>Sidebar.</strong> The Dashboard, invoice lists by status, and reports.</li>
            </ul>',
        'body_extra' => [
            ['needs' => ['approver'], 'html' => '<p><strong>My Tasks</strong> in the sidebar is your queue of steps waiting on you, and <strong>My Delegations</strong> is where you arrange cover while you are away.</p>'],
            ['needs' => ['group'], 'html' => '<p>You can see invoices for <strong>every company</strong>, not only your own.</p>'],
            ['needs' => ['admin'], 'html' => '<p>You also have the <strong>Administration</strong>, <strong>Workflow</strong> and <strong>System</strong> sections in the sidebar.</p>'],
            ['needs' => ['workflow'], 'html' => '<p>As a <strong>Workflow Manager</strong> you also have a <strong>Workflow</strong> section in the sidebar for Invoice Types, Suppliers, Approval Routes and Route Variants. An administrator gives you this access.</p>'],
            ['needs' => ['manager'], 'html' => '<p>As a manager you also have <strong>Administration</strong> (users, teams, companies) and <strong>Workflow</strong> (routes, variants, delegations, imports). You can also open the <strong>Long-term analysis</strong> tab on Reports &amp; Analytics. System settings, SharePoint, API tokens and group-level users are for administrators only.</p>'],
        ],
        'faqs' => [
            ['I cannot sign in.', 'Check the email address and password. After too many attempts (10 in a minute) you are asked to wait a minute before trying again. If you still cannot get in, ask an administrator to send you a password reset.'],
            ['Why can I not see an invoice a colleague mentioned?', 'You only see invoices for companies you belong to. Ask an administrator to add you to that company. Sub-groups are only for reporting and do not give access.'],
        ],
    ],
    [
        'id' => 'submitting', 'title' => 'Submitting an invoice', 'icon' => 'bi-upload', 'needs' => ['submitter'],
        'summary' => 'Create a draft, attach a PDF or photos of the paper invoice, submit it, and what to do if it comes back.',
        'body' => '
            <ol>
                <li>Go to <strong>Invoices &gt; New</strong> and choose the company and invoice type. You can only submit for companies where you are allowed to.</li>
                <li>Fill in the details. These are <strong>required</strong>, even to save a draft: <strong>company, invoice type, invoice number, invoice date, due date, amount</strong> (more than zero) and <strong>currency</strong>. The due date cannot be before the invoice date. The currency is a list and starts on <strong>Barbados dollar (BBD)</strong>; change it if the invoice is in another currency. The <strong>supplier</strong> is chosen from a list kept by managers (Admin &gt; Suppliers) and is needed before you submit; the PO / reference is optional. Required fields are marked with a red <span class="text-danger">*</span>, and the form shows what is missing before it lets you continue.</li>
                <li>Add the invoice document in one of two ways:<ul><li><strong>A PDF:</strong> drag it onto the box, or click it to browse.</li><li><strong>Photos of a paper invoice:</strong> press <strong>Take photo of paper invoice</strong>. A camera preview opens; if your device has more than one camera, choose the one to use from the <strong>Camera</strong> list, then press <strong>Capture page</strong> for each page. Use <strong>Add another page</strong> for more, and the &times; on a thumbnail to remove one (up to 20 pages). The pages are combined into one PDF when you save. You can also pick existing photos (JPEG, PNG or WebP) from your device.</li></ul>Each file can be up to the size set by an administrator. If your browser asks, <strong>allow camera access</strong>. If the camera cannot be used (for example it is blocked or in use), choose <strong>Use the device camera app instead</strong>.</li>
                <li>Save as a draft, check it, then press <strong>Submit</strong>. The app picks the approval route for that company and invoice type. If the amount or an "extra approval" flag triggers a stricter route, that one is used.</li>
            </ol>
            <p>The route is <strong>fixed at the moment you submit</strong>. If an administrator edits the route afterwards, invoices already in approval keep the steps they started with.</p>
            <h3 class="h6 mt-3">Statuses</h3>
            <dl class="row small">
                <dt class="col-sm-3">Draft</dt><dd class="col-sm-9">Saved but not submitted. Only you can edit it.</dd>
                <dt class="col-sm-3">In approval</dt><dd class="col-sm-9">Waiting for the person or team on the current step.</dd>
                <dt class="col-sm-3">Approved</dt><dd class="col-sm-9">All approvals are done and it is at the process step.</dd>
                <dt class="col-sm-3">Processed</dt><dd class="col-sm-9">The process step is done. If the route has a pay step the invoice now waits to be paid; otherwise this is the end. A copy is sent to SharePoint.</dd>
                <dt class="col-sm-3">Paid</dt><dd class="col-sm-9">The pay step is done: the payment reference and date are recorded. This is the end of a route with a pay step.</dd>
                <dt class="col-sm-3">Returned</dt><dd class="col-sm-9">An approver sent it back for changes. Edit it and resubmit.</dd>
                <dt class="col-sm-3">Rejected</dt><dd class="col-sm-9">An approver declined it. You can still edit and resubmit with an explanation.</dd>
                <dt class="col-sm-3">Cancelled</dt><dd class="col-sm-9">Withdrawn by the submitter.</dd>
            </dl>',
        'faqs' => [
            ['Why will Submit not work?', 'The page lists what is missing: a required field, an amount of zero, a due date before the invoice date, or no document (PDF or photos). If it says there is no active route, an administrator needs to switch one on for that company and invoice type.'],
            ['My invoice was returned or rejected. What now?', 'Open it and read the approver\'s note, fix what they asked for, and press Resubmit. A note explaining what you changed is required. Resubmitting starts the approval again from the first step.'],
            ['Can I replace the PDF?', 'Yes: upload a new PDF (or take new photos) while editing a draft, or when fixing a returned or rejected invoice. The newest file is the current one; earlier files are kept until the clean-up job removes them. Files are virus-checked. Photos are turned into a PDF, so approvers and SharePoint always get a PDF.'],
            ['The camera will not open.', 'The browser camera only works on a secure (https) address and needs your permission: allow it when asked, or in the browser\'s site settings. Close other apps that may be using the camera. You can always press <strong>Use the device camera app instead</strong>, or upload a PDF.'],
            ['A photo was refused.', 'Photos must be JPEG, PNG or WebP and not larger than the upload limit. Make sure the page is flat, well lit and fills the frame so it is readable for the approver.'],
            ['Why can I not save my draft?', 'Company, invoice type, invoice number, both dates, amount and currency are required even for a draft. The message at the top lists what is missing.'],
            ['Can I cancel an invoice?', 'The submitter can cancel an invoice that is not finished. It is then closed and no longer chased.'],
        ],
    ],
    [
        'id' => 'approving', 'title' => 'Approving invoices', 'icon' => 'bi-check2-square', 'needs' => ['approver', 'group'],
        'summary' => 'Approve, return or reject, and the rules that apply.',
        'body' => '
            <p>Open an invoice to read the PDF, the notes and the history, then act:</p>
            <ul>
                <li><strong>Approve</strong> moves it to the next step (or completes it). A note is optional.</li>
                <li><strong>Return for changes</strong> sends it back to the submitter, who edits it and resubmits from the first step. When there is an earlier approver on the route you can instead choose <strong>Return to previous approver</strong>: the invoice goes back one step and stays in approval. A <strong>note is required</strong> either way so they know what to fix.</li>
                <li><strong>Reject</strong> declines it. A <strong>note is required</strong>.</li>
                <li><strong>Process</strong> marks the invoice processed (handled, for example entered in the accounts system).</li>
                <li><strong>Pay</strong> marks it paid. A <strong>payment reference</strong> and <strong>payment date</strong> are required.</li>
            </ul>
            <p>Need more evidence? On the <strong>Documents</strong> tab choose <strong>Attach a supporting document</strong> (a quote, purchase order, email or spreadsheet) and say why it is needed.
            PDF, JPG, PNG, DOCX, XLSX, EML and MSG files are accepted; files with macros or embedded objects are not. The submitter is told, it shows in the history, and it is saved to SharePoint next to the invoice.
            The submitter can attach documents too.</p>
            <p>Notes, attached documents and the action history cannot be edited or deleted afterwards. That is deliberate, so there is always a trustworthy record.</p>',
        'body_extra' => [
            ['needs' => ['approver'], 'html' => '
            <h3 class="h6 mt-3">Finding your work</h3>
            <p><strong>My Tasks</strong> lists everything waiting on you, oldest first, with how many days it has waited and whether it is past its due date.</p>
            <h3 class="h6 mt-3">Team steps</h3>
            <p>Some steps are assigned to a team rather than a person. Every team member is told. One member presses <strong>Accept</strong>, and from then on only that
            person can act on it. If they cannot, they press <strong>Release</strong> and the rest of the team can pick it up.</p>
            <h3 class="h6 mt-3">Delegation (cover while you are away)</h3>
            <p>Under <strong>My Delegations</strong> choose a colleague, the dates and optionally a company. During that period they can act on the steps named for you, and the history shows
            they acted on your behalf. You can end a delegation early.</p>'],
        ],
        'faqs' => [
            ['Why do I see "You are not an approver for this step"?', 'The invoice has moved on, or the current step is not assigned to you (or to someone who delegated to you). Refresh My Tasks.', ['needs' => ['approver']]],
            ['An invoice is waiting on someone who is away.', 'Ask them to set up a delegation, or ask an administrator to transfer their work.', ['needs' => ['approver']]],
            ['Can I act on a step that is not mine?', 'Yes, as a group-level user, but you must give a reason. The named approver and the submitter are told, and it is recorded as a group-level override.', ['needs' => ['group']]],
            ['Why is a note required to return or reject?', 'So the submitter always knows what to change. The rule is enforced by the system and cannot be skipped.'],
            ['I am on a team step but cannot approve.', 'You need to press Accept first. Only the member who accepted can act.', ['needs' => ['approver']]],
        ],
    ],
    [
        'id' => 'reports', 'title' => 'Dashboard and reports', 'icon' => 'bi-bar-chart-line',
        'summary' => 'Outstanding, overdue and critical, totals and CSV export.',
        'body' => '
            <p>The <strong>Dashboard</strong> opens with to-do tiles (things waiting for you, returned invoices, and anything badly overdue), then totals, ageing, by-company and most-overdue lists.
            <strong>Reports &amp; Analytics</strong> adds sub-group totals and month-by-month trends, a by-supplier tab, and (for managers and administrators) long-term analysis tabs.</p>
            <ul>
                <li><strong>Outstanding</strong> means not yet finished (in approval, approved, returned or draft).</li>
                <li><strong>Overdue</strong> counts days past the invoice <em>due date</em>. 1 day or more is overdue; <strong>61 days or more is Critical</strong>.</li>
                <li><strong>Days waiting</strong> is how long an invoice has been with its current approver.</li>
                <li>Money totals are shown <strong>per currency</strong>; different currencies are never added together.</li>
                <li>Invoice lists and the outstanding report can be exported to CSV and opened in Excel.</li>
            </ul>',
        'faqs' => [
            ['What is the difference between overdue and waiting?', 'Overdue is about the supplier\'s due date. Waiting is about how long the invoice has sat with the current approver. An invoice can be waiting only a day and already be overdue.'],
            ['The numbers differ from a colleague\'s.', 'Totals only include the companies you can see, so people in different companies see different numbers.'],
        ],
    ],
    [
        'id' => 'analytics', 'title' => 'Long-term analytics', 'icon' => 'bi-graph-up-arrow', 'needs' => ['admin', 'manager'],
        'summary' => 'Trends over months and years: volume, spend, cycle time and backlog.',
        'body' => '
            <p><strong>Reports &amp; Analytics</strong> has three tabs: <strong>Outstanding &amp; ageing</strong> and <strong>By supplier</strong> (live figures, for everyone) and <strong>Long-term analysis</strong> (managers and administrators). Long-term analysis shows how things change over time, going back further than the live reports. Pick it, then choose one of its three views from the buttons underneath:</p>
            <ul>
                <li><strong>Volume &amp; spend</strong>: invoices submitted, processed and paid, and the amounts, by month, quarter or year, with the same period a year earlier alongside. Largest suppliers are listed per currency.</li>
                <li><strong>Cycle time</strong>: average days from submission to processed and to paid, how many invoices were returned, resubmitted, rejected or approved by a group-level user, and where invoices spend their time (by step type, by role or team, or by the person who acted).</li>
                <li><strong>Backlog history</strong>: how many invoices were outstanding in each age band over time, per currency.</li>
            </ul>
            <p>You see the companies you belong to (group-level users see every company), and you can narrow by company, sub-group, invoice type, supplier and dates. Each view can be downloaded as CSV.</p>
            <ul>
                <li>The figures come from a separate copy of the data that is refreshed <strong>every night</strong>, so today&rsquo;s activity appears tomorrow. The page shows when it was last updated.</li>
                <li>Amounts are shown <strong>per currency</strong> and never added across currencies.</li>
                <li>Backlog points marked <strong>(est.)</strong> were rebuilt from submit and finish dates for days before nightly recording began, so they can differ slightly from what was on screen then.</li>
                <li>Because it is a different measure, these numbers will not always match the live Reports page, which shows today&rsquo;s state.</li>
            </ul>',
        'body_extra' => [
            ['needs' => ['admin'], 'html' => '
                <h3 class="h6 mt-3">Setting it up (administrators)</h3>
                <ol>
                    <li>Create the separate analytics database and set <code>database.analytics.*</code> in the environment file (the deployment guide has the exact steps).</li>
                    <li>Run <code>php spark analytics:migrate</code> to create its tables. This is <em>not</em> part of <code>migrate --all</code>, so run both on every update.</li>
                    <li>Run <code>php spark analytics:backfill</code> once (try <code>--dry-run</code> first). It rebuilds every invoice and estimates past backlog.</li>
                    <li>Add the nightly <code>analytics:load</code> job (see Scheduled jobs). The backup script also dumps the analytics database when it is configured.</li>
                </ol>
                <p>The analytics database is a copy: nothing in approvals reads it, and it never holds note text, files or SharePoint details. After a fix to how the figures are built, run the backfill again to rebuild them.</p>'],
        ],
        'faqs' => [
            ['I cannot see the Long-term analysis tab.', 'It is only for managers and administrators. Ask an administrator if you need it.'],
            ['The page says no data has been loaded.', 'An administrator needs to run the one-time backfill (<code>php spark analytics:backfill</code>); after that the nightly job keeps it current.'],
            ['The page says the analytics database cannot be reached.', 'Everything else keeps working. Ask an administrator to check the analytics database settings in the deployment guide.'],
            ['Why is a step counted as over its service level?', 'The route set a number of days for that step and the invoice stayed longer. Steps with no limit are never counted.'],
        ],
    ],
    [
        'id' => 'notifications', 'title' => 'Notifications, reminders and escalations', 'icon' => 'bi-bell',
        'summary' => 'What you are told, how to change emails, the daily summary.',
        'body' => '
            <p>Every event below puts an alert in your bell. For each you can choose whether it is also emailed under <strong>your name &gt; Notification settings</strong>.
            Email problems never hold up an approval; a failed email is retried automatically.</p>
            <p><strong>Daily summary.</strong> Each morning (about 07:30) you get one summary of what needs your attention. Nothing is sent if there is nothing to do.</p>',
        'body_extra' => [
            ['needs' => ['approver'], 'html' => '<p><strong>Reminders.</strong> Some approval steps have a time limit. If an invoice sits at your step longer than that, you are reminded once.</p>'],
            ['needs' => ['group'], 'html' => '<p><strong>Escalations.</strong> If an invoice is still at a step after <em>twice</em> its time limit, you and the other group-level users are told, so someone can step in.</p>'],
        ],
        'events' => true,
        'faqs' => [
            ['I am not getting emails.', 'Check Notification settings, check your junk folder, and ask an administrator to confirm your email address is on your account. The bell always works.'],
            ['What is a high priority invoice?', 'Administrators can mark an invoice type as high priority. When an invoice of that type reaches you (new, resubmitted or returned to you), or is waiting too long, you get a bell alert marked HIGH and an email, even if you switched that email off. These emails cannot be turned off.'],
            ['Why did I get a reminder for an invoice I already looked at?', 'Reminders depend on how long the invoice has been at your step, not on whether you opened it. Approve, return or reject it, or ask for it to be reassigned.', ['needs' => ['approver']]],
        ],
    ],

    // ------------------------------------------------------------------ group-level
    [
        'id' => 'group-level', 'title' => 'Group-level users', 'icon' => 'bi-diagram-3', 'needs' => ['group'],
        'summary' => 'Seeing and acting on everything, with full accountability.',
        'body' => '
            <p>Group-level users can see every company and can approve, return or reject any invoice, including steps named for someone else (for example an outside party that cannot sign in).</p>
            <p>Because this is powerful, it is fully accountable:</p>
            <ul>
                <li>A <strong>reason is mandatory</strong> whenever you act on a step that is not yours.</li>
                <li>The action is marked as a <strong>group-level override</strong> on the invoice history.</li>
                <li>The named approver and the submitter are <strong>notified</strong>.</li>
                <li>It appears in the administrators\' <strong>Group-level Activity</strong> report.</li>
            </ul>
            <p>You also receive <strong>escalation</strong> alerts when an invoice has been at a step for twice its time limit.</p>',
        'faqs' => [
            ['When should I step in?', 'When an escalation arrives and the named approver is away or unresponsive. Try the approver or their delegate first where you can.'],
            ['Do I have a personal task queue?', 'No. My Tasks shows only steps named for you. Use the invoice lists and Overdue view to find everything else.'],
        ],
    ],

    [
        'id' => 'api-access', 'title' => 'API access for other systems', 'icon' => 'bi-key',
        'summary' => 'Let a reporting tool or another system read your invoice data.',
        'body' => '
            <p>Another system (a Power BI report, an ERP, a script) can read invoice data through the <strong>API</strong>. It is <strong>read-only</strong>: it cannot approve, change or submit anything.</p>
            <ol>
                <li>Ask an <strong>administrator</strong> to make a token for you (they use <strong>API Tokens (all)</strong> under Administration and name it after the system that will use it).</li>
                <li>Copy the token straight away. It is shown <strong>once</strong>; we only keep a scrambled copy.</li>
                <li>Give it to the system, which sends it as <code>Authorization: Bearer &lt;token&gt;</code> over https.</li>
            </ol>
            <p>A token sees <strong>exactly what you can see</strong> and nothing more. Treat it like a password: revoke it on the <strong>API Tokens</strong> page if it leaks or the system is retired. It stops working if your account is made inactive. The full reference, with a try-it page, is at <a href="' . site_url('api/docs') . '">API documentation</a>.</p>',
        'faqs' => [
            ['Can the API approve invoices?', 'No. This version only reads invoices, their history and PDFs, your approval queue and the outstanding report.'],
            ['I lost the token.', 'It cannot be shown again. Revoke it on the API Tokens page and ask an administrator to make a new one.'],
        ],
    ],

    // ------------------------------------------------------------------ administrators
    [
        'id' => 'admin-setup', 'title' => 'Setting up the system', 'icon' => 'bi-gear', 'needs' => ['admin', 'manager'],
        'summary' => 'The order to configure things for a new company.',
        'body' => '
            <ol>
                <li><strong>Companies</strong> (Administration &gt; Companies) and, if useful for reporting, <strong>Sub-groups</strong>. Sub-groups never affect who can see what.</li>
                <li><strong>Users</strong>: invite them one at a time, or use <strong>bulk upload</strong> (download the template, preview, then commit). Link each user to their companies and mark who may submit and who may approve (people must be allowed to approve before they can be named on a route).</li>
                <li><strong>Teams</strong> (optional) for steps any of several people can pick up.</li>
                <li><strong>Default invoice types</strong> give every company a standard list of types.</li>
                <li><strong>Approval routes</strong>: build or import them, then switch each one on (see the next section).</li>
                <li><strong>Group-level users</strong>: grant sparingly; each grant is logged.</li>
                <li><strong>SharePoint</strong> and <strong>Settings</strong> (email sender, upload size, default currency, Microsoft sign-in).</li>
            </ol>',
        'faqs' => [
            ['How do I reset someone\'s password?', 'Administration &gt; Users &gt; the user &gt; Reset password. They get a single-use emailed link and must choose a new password.'],
            ['Someone has left. What about their invoices?', 'Use <strong>Transfer work</strong> on the user to hand their waiting steps to someone else, then deactivate them. Their history is kept.'],
            ['A user\'s email is missing.', 'Imported people may be flagged "no email yet". Add the address before they can be invited or receive notifications.'],
        ],
    ],
    [
        'id' => 'admin-routes', 'title' => 'Routes, time limits and escalation', 'icon' => 'bi-signpost-split', 'needs' => ['admin', 'manager', 'workflow'],
        'summary' => 'Building approval chains, importing the routing list, setting step time limits.',
        'body' => '
            <p>A <strong>route</strong> is the ordered chain of steps for one company and invoice type. Open <strong>Workflow &gt; Approval Routes</strong>, choose a route and <strong>Edit</strong>.</p>
            <ul>
                <li><strong>Step types:</strong> Submit (the person who raises it), Approve, Process (the invoice is handled), Pay (the payment is made; needs a payment reference and date), Notify (a copy to someone; no action).</li>
                <li><strong>Assigned to:</strong> a user, a team or an outside party. A team notifies every member and its group mailbox (if it has one); an outside party is email-only, and group-level users act for it.</li>
                <li>Drag to reorder. A route must have at least one approve, process or pay step before it can be switched on. It can have one pay step, after every approve and process step.</li>
                <li><strong>Extra-approval routes</strong> apply instead of the standard one when their trigger is met (a manual flag or an amount threshold). They must have a trigger before being switched on.</li>
                <li>Editing a route <strong>does not change invoices already in approval</strong>; they keep the steps captured when submitted.</li>
            </ul>
            <h3 class="h6 mt-3">Time limit (days)</h3>
            <p>On approve, process and pay steps you can set a <strong>Time limit (days)</strong> (1 to 365). Leave it blank for no reminders.</p>
            <ul>
                <li>After the limit the approver (or the team member who accepted) gets one <strong>reminder</strong>.</li>
                <li>After <strong>twice</strong> the limit every active group-level user gets one <strong>escalation</strong>.</li>
                <li>Days are calendar days. The clock starts when the invoice reached the step; accepting or releasing a team step does not restart it. A resubmitted invoice starts afresh.</li>
                <li>Routes already switched on have <strong>no limits</strong> until you add them, and invoices already in approval are not chased.</li>
                <li>This needs the scheduled job <code>notifications:escalate</code> (see Scheduled jobs).</li>
            </ul>',
        'body_extra' => [
            ['needs' => ['admin', 'manager'], 'html' => '<p><strong>Import Routing</strong> loads the customer\'s routing list: upload, review the issues, then commit. Nothing is created until you commit.</p>'],
            ['needs' => ['workflow'], 'html' => '<p>As a <strong>Workflow Manager</strong> you can maintain <strong>Invoice Types</strong> (including the High Priority switch), <strong>Suppliers</strong>, <strong>Approval Routes</strong> and <strong>Route Variants</strong>. Users, teams, companies, importing, delegations and system settings are for managers and administrators.</p>'],
        ],
        'faqs' => [
            ['I switched a route on but invoices say "no active route".', 'A route must be active for that exact company and invoice type. Check the variant too: a standard route must exist even if you also have routes for other variants.'],
            ['How do I rename a variant or add a new one?', 'Open Route Variants in the Workflow menu. Rename a variant, change its order, switch it off, or add a new one. A new variant is used by creating a route for it and setting when it applies (the trigger). The key shown next to a variant is fixed once created.'],
            ['Why did I not get an escalation for a slow invoice?', 'Check the step has a time limit, the invoice was submitted after the limit was added, the job is scheduled, and the step is an approve, process or pay step.'],
            ['What are the High Value routes (the "- Yes" routes in the old list)?', 'They add the extra approval needed for higher-value invoices. They are imported switched off with no trigger. To use one, open it in Routes, choose "Invoice amount at or above…", enter the amount, and switch the route on. From then on any invoice of that company and type at or above the amount follows the High Value route instead of the standard one. Check the steps first, then turn it on.'],
        ],
    ],
    [
        'id' => 'admin-sharepoint', 'title' => 'SharePoint', 'icon' => 'bi-cloud-arrow-up', 'needs' => ['admin'],
        'summary' => 'Connections, testing, the sync queue and retries.',
        'body' => '
            <p>Processed invoices are sent to SharePoint. Set this up under <strong>System &gt; SharePoint Connections</strong>.</p>
            <ul>
                <li>There is a <strong>default</strong> connection and optional per-company ones. A company with its own connection <strong>never falls back</strong> to the default.</li>
                <li>A connection is used only when it is <strong>enabled and complete</strong>, and it can only be enabled after a <strong>successful test</strong> of its current settings. If you change the settings, test again.</li>
                <li>While disabled the app makes no outbound calls. Secrets are stored encrypted and are never shown again after saving.</li>
                <li><strong>System &gt; SharePoint Sync</strong> shows the queue, including pending, failed and not-configured items. You can run the queue now or retry a failed item. Failures are retried automatically with back-off, and the admins are alerted.</li>
            </ul>',
        'faqs' => [
            ['An invoice shows "not configured".', 'That company has no usable connection (and no default). Complete and test one, then retry from the sync screen.'],
            ['Sync keeps failing.', 'Open the item and read the error. Common causes: expired credentials, wrong site or folder, or the connection having been changed and not re-tested.'],
        ],
    ],
    [
        'id' => 'admin-system', 'title' => 'Settings, sign-in and email', 'icon' => 'bi-sliders', 'needs' => ['admin'],
        'summary' => 'Email sender, uploads, Microsoft sign-in and the audit log.',
        'body' => '
            <ul>
                <li><strong>Settings:</strong> the email sender name and address (the domain must be authenticated in SendGrid), the maximum upload size (for each PDF or photo), the default currency (Barbados dollar, BBD, unless changed), and Microsoft sign-in.</li>
                <li><strong>Microsoft sign-in (Entra)</strong> is off by default. When on, it only signs in people who already have an account; it never creates one. Local passwords always work, so it cannot lock you out. A user can be unlinked from Microsoft on their user page.</li>
                <li><strong>Email</strong> goes through SendGrid in production. A failed email stays in the <code>notifications</code> database table with its error and is retried (up to 5 attempts); it never blocks an approval.</li>
                <li><strong>Audit Log</strong> records administrative and workflow events (route changes, grants of group-level access, reminders, escalations and so on). It cannot be edited.</li>
                <li><strong>Delegations</strong> (Workflow &gt; Delegations) lets you see and end anyone\'s delegations.</li>
            </ul>',
        'faqs' => [
            ['Where are secrets kept?', 'In the server\'s environment file and, for SharePoint and Microsoft, encrypted in the database. They are never displayed. Do not paste them into chat or tickets.'],
            ['Why is nobody getting email?', 'Check the sender address in Settings, that SendGrid is configured for production, and the <code>notifications</code> table for errors (ask ITM if you cannot reach it). In development emails are only written to a log.'],
        ],
    ],
    [
        'id' => 'admin-jobs', 'title' => 'Scheduled jobs and file clean-up', 'icon' => 'bi-clock-history', 'needs' => ['admin'],
        'summary' => 'The cron entries that keep emails, SharePoint and escalations running.',
        'body' => '
            <p>These run on the server as the web user. Without them emails are not retried, SharePoint is not updated, and no summaries or escalations are sent.</p>
            <div class="table-responsive"><table class="table table-sm align-middle">
                <thead><tr><th>Command</th><th>When</th><th>What it does</th></tr></thead>
                <tbody>
                    <tr><td><code>notifications:send</code></td><td>every 5 minutes</td><td>Retries emails that failed or were left pending.</td></tr>
                    <tr><td><code>sharepoint:sync</code></td><td>every 5 minutes</td><td>Sends processed invoices to SharePoint.</td></tr>
                    <tr><td><code>notifications:digest</code></td><td>daily, 07:30</td><td>One summary per person; safe to run twice.</td></tr>
                    <tr><td><code>notifications:escalate</code></td><td>hourly</td><td>Reminders and escalations for steps past their time limit.</td></tr>
                    <tr><td><code>files:purge</code></td><td>daily, 03:15</td><td>Deletes old server copies of PDFs (try <code>--dry-run</code> first).</td></tr>
                    <tr><td><code>analytics:load</code></td><td>daily, 01:30</td><td>Copies the day&rsquo;s activity into the long-term analytics database and records the backlog (try <code>--dry-run</code>). Run <code>analytics:backfill</code> once after set-up.</td></tr>
                    <tr><td><code>analytics:backfill</code></td><td>once, then only to repair</td><td>Rebuilds every invoice in the analytics database and estimates past backlog days (try <code>--dry-run</code>). Never changes recorded daily snapshots.</td></tr>
                </tbody>
            </table></div>
            <h3 class="h6 mt-3">What file purge will and will not delete</h3>
            <ul>
                <li><strong>Finished</strong> invoices (paid, or processed on a route with no pay step), only once SharePoint has <em>confirmed receipt</em>, and 30 days after the later of completion and sync.</li>
                <li><strong>Rejected or cancelled</strong> invoices, 30 days after they ended.</li>
                <li><strong>Never</strong> invoices that are pending, failed, not configured or still in approval or waiting to be paid.</li>
            </ul>',
        'faqs' => [
            ['How do I know a job is running?', 'Run the command by hand and read its one-line result. For SharePoint, the sync screen shows recent activity.'],
            ['An old PDF is still on the server.', 'It is probably not yet eligible: SharePoint has not confirmed it, or the 30 days have not passed. Run <code>files:purge --dry-run</code> to see what would go.'],
        ],
    ],
    [
        'id' => 'admin-trouble', 'title' => 'Troubleshooting', 'icon' => 'bi-life-preserver', 'needs' => ['admin'],
        'summary' => 'Quick fixes for the problems people most often report.',
        'body' => '
            <div class="table-responsive"><table class="table table-sm align-middle">
                <thead><tr><th>Symptom</th><th>Check</th></tr></thead>
                <tbody>
                    <tr><td>Invoice stuck with someone unavailable</td><td>Transfer their work (Users), ask for a delegation, or have a group-level user act with a reason.</td></tr>
                    <tr><td>"No active approval route"</td><td>Switch a standard route on for that company and invoice type.</td></tr>
                    <tr><td>Approver not told</td><td>Their email address is missing, their user is inactive, or they switched that email off. The bell still works.</td></tr>
                    <tr><td>User locked out</td><td>More than 10 sign-in attempts a minute are blocked; wait a minute or send a password reset.</td></tr>
                    <tr><td>Emails marked failed</td><td>Read the error; fix the sender or SendGrid key; the job retries up to 5 times.</td></tr>
                    <tr><td>SharePoint "failed"</td><td>Open the item for the error, re-test the connection, then Retry.</td></tr>
                    <tr><td>Page shows an error after an update</td><td>Run the database migrations (<code>php spark migrate --all</code>, always with <code>--all</code>, then <code>php spark analytics:migrate</code> for the analytics database).</td></tr>
                    <tr><td>Long-term analysis says no data has been loaded</td><td>Run <code>php spark analytics:backfill</code> once; check the nightly <code>analytics:load</code> job is scheduled.</td></tr>
                    <tr><td>Long-term analysis cannot be reached</td><td>Check the analytics database exists, the <code>database.analytics.*</code> settings and that the app user has access. The rest of the app is unaffected.</td></tr>
                </tbody>
            </table></div>',
        'faqs' => [
            ['Can I edit or delete an action or note on an invoice?', 'No. History and notes are append-only on purpose. Add a new note to correct the record.'],
        ],
    ],
];
