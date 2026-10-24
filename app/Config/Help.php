<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Help content: the user manual and FAQ. Plain text only (views escape it).
 *
 * Every topic, section and FAQ can carry a 'permission': a permission key, a list of keys (any one
 * is enough), or null for everyone. Content a user has no permission for is never shown, and a
 * switched-off module's permissions fail, so its help disappears with it.
 *
 * Topic: title, icon (Bootstrap Icons name), summary, permission, order, sections, faqs.
 * Section: heading, body (list of paragraphs), steps (optional numbered list), permission (optional).
 * FAQ: q, a, permission (optional).
 *
 * To document a new module, add a topic here with the module's permission.
 */
class Help extends BaseConfig
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public array $topics = [
        'getting-started' => [
            'title'      => 'Getting started',
            'icon'       => 'compass',
            'summary'    => 'Signing in, finding your way around and what you can see.',
            'permission' => null,
            'order'      => 10,
            'sections'   => [
                [
                    'heading' => 'Signing in',
                    'body'    => [
                        'Use the email address and password you were given. If your organisation uses Microsoft sign-in, choose the Microsoft button instead. If two-step verification is switched on for you, you will be asked for a code after your password.',
                    ],
                    'steps' => [
                        'Open the back office address and enter your email and password.',
                        'Enter the code from your authenticator app or email if asked.',
                        'You land on the dashboard.',
                    ],
                ],
                [
                    'heading' => 'Finding your way around',
                    'body'    => [
                        'The menu on the left lists only the areas you are allowed to use. Two people with different roles will see different menus. On a phone, open the menu with the button at the top left.',
                        'The top bar holds the company switcher (if you belong to companies), the dark mode button, your notifications and your account menu. If you can manage anything (users, suppliers, approval routes, backups and so on), the account menu also has Administration, which opens a page listing everything you can manage.',
                    ],
                ],
                [
                    'heading' => 'The dashboard',
                    'body'    => [
                        'The dashboard is built around your day. The Today tab shows what needs your attention in each area and your unread notifications.',
                        'Each area you can use has its own tab, for example Payables (what is waiting on you, what was sent back, what is overdue or due soon) and People (profile change requests to decide). A number on a tab means that many items need you. Areas you cannot use, or that are switched off, have no tab.',
                    ],
                ],
                [
                    'heading' => 'Switching company',
                    'body'    => [
                        'If you belong to more than one company, the company switcher in the top bar narrows screens to one company or shows everything you can see. It only narrows what you see. It never gives you extra access.',
                    ],
                ],
                [
                    'heading' => 'Light and dark mode',
                    'body'    => [
                        'Use the moon button in the top bar to switch. Your choice is kept on your profile.',
                    ],
                ],
            ],
            'faqs' => [
                ['q' => 'Why can I not see a menu item a colleague has?', 'a' => 'Menu items depend on your role. If you need an area, ask an administrator to add the right role to your account.'],
                ['q' => 'Who do I ask if something looks wrong?', 'a' => 'Contact your administrator or the IT service desk. Tell them the page address and what you expected to see.'],
                ['q' => 'The page says I do not have permission.', 'a' => 'Your role does not include that area, or the module has been switched off. Ask an administrator.'],
                ['q' => 'Does the system work on a phone?', 'a' => 'Yes. Pages adapt down to phone width, and you can photograph a paper invoice with your phone camera.'],
            ],
        ],

        'my-account' => [
            'title'      => 'My account',
            'icon'       => 'person-circle',
            'summary'    => 'Your profile, password, two-step verification and notifications.',
            'permission' => null,
            'order'      => 20,
            'sections'   => [
                [
                    'heading' => 'Your profile',
                    'body'    => [
                        'Open My profile from your name at the top right. You can change your display name, phone numbers, location, short bio, photo, timezone and colour theme yourself.',
                        'Times across the app are stored in UTC and shown in your timezone. If you leave Timezone on Company default you get the timezone of the company you are working in (set by an administrator on the company), or your first company. Due dates are calendar dates and follow the company of the invoice.',
                        'Job title, department and manager are set by an administrator. Use Send request on your profile to ask for a change. You will be notified when it is decided.',
                    ],
                ],
                [
                    'heading' => 'Changing your password',
                    'body'    => ['Go to Password and security from your account menu.'],
                    'steps'   => [
                        'Enter your current password.',
                        'Enter and confirm the new password.',
                        'Save. You get an email to confirm the change.',
                    ],
                ],
                [
                    'heading' => 'Two-step verification',
                    'body'    => [
                        'Where your organisation uses two-step verification, set it up under Password and security. You can use an authenticator app or a code sent to your email, depending on what is switched on.',
                        'Keep your recovery codes somewhere safe. Each works once if you lose your device.',
                    ],
                ],
                [
                    'heading' => 'Notification settings',
                    'body'    => [
                        'Choose which events reach you in the app and by email. Security notices such as a password change cannot be switched off in the app.',
                    ],
                ],
                [
                    'heading' => 'Reading notifications',
                    'body'    => [
                        'Unread notifications are highlighted. Opening one marks it read. To clear one without opening it, use the tick beside it, in the bell or on the Notifications page. Mark all read clears everything at once.',
                    ],
                ],
            ],
            'faqs' => [
                ['q' => 'I forgot my password.', 'a' => 'On the sign-in page choose Forgot password and enter your email. A reset link is sent and stays valid for a limited time. If no email arrives, ask an administrator to reset it for you.'],
                ['q' => 'I lost my phone and cannot get a verification code.', 'a' => 'Use one of your recovery codes. If you have none left, ask an administrator to reset your two-step verification.'],
                ['q' => 'Why did my job title change request not apply straight away?', 'a' => 'These fields are controlled by administrators. Your request stays pending until someone approves or declines it.'],
                ['q' => 'How do I stop getting emails?', 'a' => 'Open Notification settings from your account menu and switch off email for the events you do not want.'],
            ],
        ],

        'directory' => [
            'title'      => 'Staff directory',
            'icon'       => 'person-lines-fill',
            'summary'    => 'Look up colleagues by name, department or location.',
            'permission' => 'directory.view',
            'order'      => 30,
            'sections'   => [
                [
                    'heading' => 'Searching the directory',
                    'body'    => [
                        'Open Directory from the menu. Type part of a name or email in the search box, or narrow by department or location. Select a person to see their details.',
                    ],
                ],
            ],
            'faqs' => [
                ['q' => 'Someone is missing from the directory.', 'a' => 'Only active staff are listed. If they should be there, ask an administrator to check their account.'],
                ['q' => 'A colleague\'s details are out of date.', 'a' => 'People can edit some of their own details. Job title, department and manager are changed by administrators.'],
            ],
        ],

        'payables' => [
            'title'      => 'Payables',
            'icon'       => 'receipt',
            'summary'    => 'Submit, approve and track invoices for your companies.',
            'permission' => 'payables.view',
            'order'      => 40,
            'sections'   => [
                [
                    'heading' => 'How an invoice moves',
                    'body'    => [
                        'An invoice starts as a draft. When submitted it goes through the approval route for its company and type, one step at a time. After the last approval it is processed and then paid.',
                        'If an approver sends it back it becomes Returned, and if they refuse it, Rejected. Either can be edited and resubmitted. A draft, returned or rejected invoice can also be cancelled.',
                    ],
                ],
                [
                    'heading' => 'What you can see',
                    'body'    => [
                        'You see invoices for the companies you belong to. Whether you can submit or approve in a company is set per company by an administrator, so you may be able to approve in one company and only view in another. Group-level users see every company.',
                    ],
                ],
                [
                    'heading' => 'Creating and submitting an invoice',
                    'body'    => ['Choose New invoice on the Payables page. The form has four steps: details, financial details, documents, review and submit.'],
                    'steps'   => [
                        'Pick the company and invoice type. The approval route for that combination is previewed.',
                        'Choose the supplier and fill in invoice number, invoice date and due date.',
                        'Enter the amount and currency, and a PO or reference if you have one.',
                        'Attach the invoice PDF, or take photos of a paper invoice with your camera.',
                        'Review the summary and choose Submit. You can save a draft and come back later.',
                    ],
                    'permission' => null,
                ],
                [
                    'heading' => 'Approving, returning and rejecting',
                    'body'    => [
                        'My tasks lists every invoice waiting on you. The number beside it in the menu is your waiting count. Open an invoice, check the document and details, then act.',
                    ],
                    'steps' => [
                        'Approve moves the invoice to the next step.',
                        'Return sends it back to the submitter (or the previous approver) for changes. A note is required.',
                        'Reject refuses it. A note is required.',
                    ],
                ],
                [
                    'heading' => 'Team steps',
                    'body'    => [
                        'Some steps belong to a team rather than a person. Any member can accept the invoice, which assigns it to them. If you cannot deal with it, use Release to give it back to the team.',
                    ],
                ],
                [
                    'heading' => 'Notes and supporting documents',
                    'body'    => [
                        'Add a note to leave a message on the invoice history. Use Add document on the invoice page to attach supporting files. People involved in the invoice are notified.',
                    ],
                ],
                [
                    'heading' => 'Delegating while you are away',
                    'body'    => [
                        'Open My delegations and choose a colleague and a date range. They can approve, return or reject on your steps during that time, and every action is recorded as theirs, on behalf of you. They only work in companies they can already see.',
                    ],
                ],
                [
                    'heading' => 'Status shortcuts',
                    'body'    => ['Under Payables in the sidebar, My tasks shows what is waiting on you. Drafts, In approval, Approved, Returned, Rejected, Processed and Paid open the register already filtered to that status. All invoices clears the filter.'],
                ],
                [
                    'heading' => 'Exporting the register',
                    'body'    => ['Filter the Payables page, then use the export button to download the result as a spreadsheet (CSV).'],
                ],
            ],
            'faqs' => [
                ['q' => 'I cannot find an invoice.', 'a' => 'You only see invoices for companies you belong to. Check the company switcher in the top bar is not narrowing the list, and clear any filters.'],
                ['q' => 'Why can I not submit an invoice for a company?', 'a' => 'Submitting is granted per company. Ask an administrator to give you submit rights for it.'],
                ['q' => 'My invoice was returned. What now?', 'a' => 'Read the approver\'s note, edit the invoice, say what you changed and resubmit it.'],
                ['q' => 'Can I change an invoice after submitting it?', 'a' => 'Not while it is in approval. Ask the current approver to return it, edit it, then resubmit. You can cancel a draft, returned or rejected invoice.'],
                ['q' => 'Who is the invoice waiting on?', 'a' => 'Open the invoice. The route shows each step and the current step is highlighted with who or which team holds it.'],
                ['q' => 'I am going on leave. How do my approvals get done?', 'a' => 'Set up a delegation under My delegations before you go. You can end it early at any time.'],
                ['q' => 'The PDF will not upload.', 'a' => 'Check the file is a PDF or an image and not too large. On a phone you can photograph the pages instead.'],
                ['q' => 'Why did I get an email about an invoice I do not know?', 'a' => 'You may be copied on a step, be a delegate, or be in a team that holds the step. Your notification settings control which emails you receive.'],
            ],
        ],

        'payables-reports' => [
            'title'      => 'Invoice reports',
            'icon'       => 'bar-chart-line',
            'summary'    => 'Outstanding invoices, ageing and supplier totals.',
            'permission' => 'payables.reports',
            'order'      => 45,
            'sections'   => [
                [
                    'heading' => 'Outstanding invoices',
                    'body'    => [
                        'Reports shows every outstanding invoice in your view, grouped by how overdue it is, with counts by status and a monthly trend. Filter by company or group. Use the CSV button to download the list.',
                    ],
                ],
                [
                    'heading' => 'Supplier report',
                    'body'    => ['The supplier report totals invoices per supplier for the same filters, with its own CSV export.'],
                ],
            ],
            'faqs' => [
                ['q' => 'Why do my totals differ from a colleague\'s?', 'a' => 'Reports only include the companies each person can see. Different memberships give different totals.'],
                ['q' => 'What counts as critical?', 'a' => 'An invoice that is far past its due date. It is marked in the outstanding list and in the CSV.'],
            ],
        ],

        'payables-setup' => [
            'title'      => 'Invoice set-up',
            'icon'       => 'diagram-2',
            'summary'    => 'Approval routes, route variants, invoice types and teams.',
            'permission' => ['payables.workflow', 'payables.manage'],
            'order'      => 50,
            'sections'   => [
                [
                    'heading' => 'Approval routes',
                    'body'    => [
                        'A route is the ordered list of steps an invoice follows for one company and invoice type. Each step is a submit, approve, process, pay or copy (notify) step, held by a person or a team.',
                        'Changing a route affects invoices submitted afterwards. Invoices already in approval keep the route they started with.',
                    ],
                    'permission' => 'payables.workflow',
                ],
                [
                    'heading' => 'Route variants',
                    'body'    => [
                        'A variant is an alternative route chosen automatically when an invoice meets a condition, for example an amount over a limit. Use variants instead of copying a whole route.',
                    ],
                    'permission' => 'payables.workflow',
                ],
                [
                    'heading' => 'Invoice types',
                    'body'    => [
                        'Invoice types classify invoices and decide which route applies. Set a type\'s priority to mark how urgent it is. Priority invoices always trigger an email.',
                    ],
                    'permission' => 'payables.workflow',
                ],
                [
                    'heading' => 'Teams',
                    'body'    => [
                        'A team is a group of people who share a step. Add members, and optionally a group mailbox for notifications. Switch a team off rather than deleting it so history stays intact.',
                    ],
                    'permission' => 'payables.manage',
                ],
            ],
            'faqs' => [
                ['q' => 'I changed a route but an invoice still follows the old one.', 'a' => 'Invoices keep the route they were submitted with. Only new submissions use the change.'],
                ['q' => 'A route will not save.', 'a' => 'Every route needs at least a submit step and a final step, and each step needs a person or team. The form lists what is missing.', 'permission' => 'payables.workflow'],
                ['q' => 'Someone has left. Who deals with their invoices?', 'a' => 'Remove them from teams and routes, and ask an administrator to reassign their work. Their account should then be deactivated.', 'permission' => 'payables.manage'],
            ],
        ],

        'receivables-setup' => [
            'title'      => 'Customer invoice set-up',
            'icon'       => 'diagram-2',
            'summary'    => 'Approval routes, route variants, invoice types and teams for customer invoices.',
            'permission' => ['receivables.workflow', 'receivables.manage'],
            'order'      => 51,
            'sections'   => [
                [
                    'heading' => 'Approval routes',
                    'body'    => [
                        'A route is the ordered list of steps a customer invoice follows for one company and invoice type. Each step is a submit, approve, issue or copy (notify) step, held by a person, a team or an outside party.',
                        'To switch a route on it needs at least one approve or issue step, exactly one issue step, and the issue step must come after every approve step. The issue step is where someone sends the invoice to the customer and records who it went to.',
                        'Everyone you name on an approve or issue step needs the "Can approve and issue" right for the company, set under Manage company access. The form tells you who is missing it.',
                        'Changing a route affects invoices submitted afterwards. Invoices already in approval keep the route they started with.',
                    ],
                    'permission' => 'receivables.workflow',
                ],
                [
                    'heading' => 'Route variants',
                    'body'    => [
                        'A variant is an alternative route chosen automatically when an invoice meets a condition, for example an amount over a limit. Use variants instead of copying a whole route. A variant route needs a trigger before it can be switched on.',
                    ],
                    'permission' => 'receivables.workflow',
                ],
                [
                    'heading' => 'Customer invoice types',
                    'body'    => [
                        'Invoice types classify customer invoices and decide which route applies. Keep a list of default types and assign them to companies in bulk, or make a custom type when you create a route. A type marked High priority always emails the approvers.',
                    ],
                    'permission' => 'receivables.workflow',
                ],
                [
                    'heading' => 'When someone leaves',
                    'body'    => [
                        'Open Hand over customer invoice work, choose the person leaving and their replacement, and check what would move. Their approval and issue steps on invoices in progress, their steps on routes, the unfinished invoices they raised and anything they had accepted for a team go to the replacement or back to the team, and their delegations end. Issued invoices stay as they are, and the history is never rewritten.',
                        'The replacement must already have the company rights the work needs ("Can approve and issue" for steps, "Can raise" for invoices they raised); the page tells you if one is missing. Rights are not copied. Tick the account option only if the person is leaving for good and you may deactivate accounts: it switches the account off for the whole platform and removes them from every team.',
                    ],
                    'permission' => 'receivables.manage',
                ],
                [
                    'heading' => 'Teams',
                    'body'    => [
                        'A team is a group of people who share a step. Add members, and optionally a group mailbox. One member accepts the invoice and then acts on it. Removing a member returns anything they had accepted to the team. A team used on an active route cannot be switched off.',
                    ],
                    'permission' => 'receivables.manage',
                ],
            ],
            'faqs' => [
                ['q' => 'I changed a route but an invoice still follows the old one.', 'a' => 'Invoices keep the route they were submitted with. Only new submissions use the change.'],
                ['q' => 'I cannot switch a route on.', 'a' => 'It needs an approve or issue step, one issue step after every approve step, and a person or team on every step who has the "Can approve and issue" right for the company. The form lists what is missing.', 'permission' => 'receivables.workflow'],
                ['q' => 'Someone has left. Who deals with their customer invoices?', 'a' => 'Use Hand over customer invoice work to move their steps, routes and unfinished invoices to a replacement, then deactivate the account if they are leaving for good.', 'permission' => 'receivables.manage'],
                ['q' => 'An invoice cannot be submitted: the route is not ready.', 'a' => 'The route for its company and type is missing or has no issue step. Open Customer invoice routes, fix it and switch it on.', 'permission' => 'receivables.workflow'],
            ],
        ],

        'receivables' => [
            'title'      => 'Customer invoices',
            'icon'       => 'cash-coin',
            'summary'    => 'Raise, approve and issue invoices to customers, and record the money you receive.',
            'permission' => 'receivables.view',
            'order'      => 42,
            'sections'   => [
                [
                    'heading' => 'How a customer invoice moves',
                    'body'    => [
                        'A customer invoice starts as a draft. When it is submitted it goes through the approval route for its company and type, one step at a time. The last step is Issue: someone sends the invoice to the customer and records who it went to. After that the invoice is Issued and waits for payment.',
                        'As payments are recorded it becomes Part paid and then Paid. If an approver sends it back it becomes Returned, and if they refuse it, Rejected. Either can be edited and resubmitted. A draft, returned or rejected invoice can also be cancelled.',
                    ],
                ],
                [
                    'heading' => 'What you can see and do',
                    'body'    => [
                        'You see customer invoices for the companies you belong to. Whether you can raise, approve and issue, or record payments in a company is set per company by an administrator, so you may be able to do one in one company and only view in another. These rights are separate from the Invoices (supplier) rights.',
                    ],
                ],
                [
                    'heading' => 'Raising and submitting an invoice',
                    'body'    => ['Choose New customer invoice on the Customer invoices page. Invoices are made in your accounting system; here you record the details and attach the PDF.'],
                    'steps'   => [
                        'Pick the company and invoice type. The approval route is previewed.',
                        'Choose the customer from the list and fill in the invoice number, invoice date and due date. The number must be unique for the company.',
                        'Enter the amount and currency, and the customer\'s PO if there is one.',
                        'Attach the invoice PDF.',
                        'Choose Submit invoice. You can save a draft and come back later.',
                    ],
                ],
                [
                    'heading' => 'Approving, returning, rejecting and issuing',
                    'body'    => ['Open an invoice that is waiting on you. Check the PDF and details, then act.'],
                    'steps'   => [
                        'Approve moves the invoice to the next step.',
                        'Return sends it back to the person who raised it (or the previous approver). A note is required.',
                        'Reject refuses it. A note is required.',
                        'On the Issue step, send the invoice to the customer from your accounting system, then choose Mark as issued and say who it was sent to.',
                    ],
                ],
                [
                    'heading' => 'Recording payments',
                    'body'    => [
                        'When money arrives, open the issued invoice, go to the Payments tab and record the amount, the date received and, if you like, the method and a reference. A payment cannot be more than is still owed. Part payments are fine; the invoice shows what is still owed.',
                        'Payments cannot be edited or deleted. If one was recorded in error or bounced, choose Reverse and give a reason: the invoice owes that amount again and the reversal stays in the history.',
                    ],
                    'permission' => 'receivables.collect',
                ],
                [
                    'heading' => 'Overdue invoices',
                    'body'    => ['An issued or part-paid invoice is overdue from the day after its due date, counted on its company\'s calendar. Overdue is shown on the invoice and in the register, and the person who raised it and the people who record payments for the company are reminded now and then until it is paid.'],
                ],
                [
                    'heading' => 'My tasks and the dashboard',
                    'body'    => [
                        'My customer invoice tasks lists every invoice waiting on you, the overdue invoices you are expected to chase, and your own invoices that were sent back or are still in approval. The number beside it in the menu is how many are waiting on you.',
                        'The Receivables tab on the dashboard shows the same at a glance: what is waiting on you, what came back, what is overdue and what falls due in the next seven days, with the amounts still to collect and what was received in the last week.',
                    ],
                ],
                [
                    'heading' => 'Team steps, notes and documents',
                    'body'    => [
                        'Some steps belong to a team: any member can accept the invoice, which assigns it to them, and Release gives it back. Add a note to leave a message on the history, and use Upload supporting document to attach files. People involved are notified.',
                    ],
                ],
                [
                    'heading' => 'Subscriptions: invoices that repeat',
                    'body'    => [
                        'Use Subscriptions, under Receivables in the sidebar, for a customer you bill again and again for the same service. Each subscription has a company, customer, service, amount, how often it is billed (monthly, quarterly or yearly), a first bill date, payment terms and an optional end date.',
                        'On each bill date a draft customer invoice is created in the name of the person who set the subscription up, with the invoice date, due date, amount and customer filled in. The invoice number and PDF are not known yet: open the draft, add them and submit it for approval as for any other invoice. The same cycle is never billed twice, and later bills fall on the same day of the month (the last day of shorter months).',
                        'Before a bill date the person who set it up, and everyone who can record payments for the company, get a notice 14, 7 and 1 days ahead. They are told again when the draft is created, or if it could not be created, for example because the owner can no longer raise invoices for the company.',
                    ],
                    'steps'   => [
                        'Choose New subscription on the Subscriptions page, fill in the details and save.',
                        'Pause a subscription to stop billing for a while; resuming it skips the bill dates that passed meanwhile.',
                        'End a subscription when the customer cancels. An ended subscription cannot be started again; set up a new one instead.',
                    ],
                ],
                [
                    'heading' => 'Reports',
                    'body'    => [
                        'Customer invoice reports has three tabs. Outstanding and ageing shows what customers still owe, split by how many days past the due date it is (not yet due, 1 to 30, 31 to 60, 61 to 90 and over 90; 61 days and over is critical), by company and by sub-group, with the most overdue invoices and how many invoices were paid on time. By customer lists who owes what and who is furthest behind. Collections shows, month by month, what was invoiced and what was received after reversals.',
                        'What is owed is the balance after payments, not the invoice amount. Amounts are always shown per currency and never added across currencies, so the collections chart shows one currency at a time. Use the company and sub-group filters to narrow a report, and the download button for a spreadsheet (CSV). You only see companies you belong to.',
                    ],
                    'permission' => 'receivables.reports',
                ],
                [
                    'heading' => 'Delegating while you are away',
                    'body'    => [
                        'Open My delegations and choose a colleague and a date range. They can approve, return or reject on your steps during that time, and every action is recorded as theirs, on behalf of you. They only work in companies they can already see and where they have the "Can approve and issue" right. You can end a delegation early.',
                    ],
                ],
                [
                    'heading' => 'Status shortcuts and export',
                    'body'    => ['Under Receivables in the sidebar, Drafts, In approval, To be issued, Outstanding, Overdue, Paid and Returned open the register already filtered. All customer invoices clears the filter. Use the export button to download the filtered list as a spreadsheet (CSV).'],
                ],
            ],
            'faqs' => [
                ['q' => 'I cannot find an invoice.', 'a' => 'You only see customer invoices for companies you belong to. Check the company switcher in the top bar is not narrowing the list, and clear any filters.'],
                ['q' => 'Why can I not raise an invoice for a company?', 'a' => 'Raising is granted per company. Ask an administrator to give you the Can raise right for it.'],
                ['q' => 'It says another invoice already uses that number.', 'a' => 'Invoice numbers must be unique for a company. Check the number, or look for an earlier draft with the same one. A cancelled invoice frees its number.'],
                ['q' => 'My invoice was returned. What now?', 'a' => 'Read the approver\'s note, edit the invoice, say what you changed and resubmit it.'],
                ['q' => 'The customer is not on the list.', 'a' => 'Ask a manager to add them under Account menu, Administration, Customers. You can type a name for a one-off customer.'],
                ['q' => 'A subscription did not create its invoice.', 'a' => 'Open the subscription and check it is Active and its next bill date has arrived. If the owner can no longer raise invoices for the company, or the invoice type was switched off, the draft cannot be created: the owner is told, and it is tried again on the next run. Change the invoice type, or ask an administrator to restore the right.'],
                ['q' => 'I recorded a payment by mistake.', 'a' => 'Open the Payments tab, choose Reverse on that payment and give a reason. Then record the correct one.', 'permission' => 'receivables.collect'],
                ['q' => 'Why can I not record a payment?', 'a' => 'The invoice must be issued, and you need the Can record payments right for its company. A payment cannot be more than is still owed.', 'permission' => 'receivables.collect'],
            ],
        ],

        'suppliers' => [
            'title'      => 'Suppliers',
            'icon'       => 'shop',
            'summary'    => 'The shared supplier list, bulk upload and switching suppliers off.',
            'permission' => 'suppliers.manage',
            'order'      => 55,
            'sections'   => [
                [
                    'heading' => 'Maintaining suppliers',
                    'body'    => [
                        'Suppliers are one list shared by every module. Open Account menu, Administration, Suppliers to add, edit or switch off a supplier. Switching off hides a supplier from pick-lists but keeps it on past invoices.',
                    ],
                ],
                [
                    'heading' => 'Uploading many at once',
                    'body'    => ['Use Upload to add suppliers from a spreadsheet.'],
                    'steps'   => [
                        'Download the template and fill it in.',
                        'Upload the file and review the preview. Problems are listed row by row.',
                        'Confirm to save. The upload is all or nothing, so fix any errors first.',
                    ],
                ],
            ],
            'faqs' => [
                ['q' => 'Can I delete a supplier?', 'a' => 'No. Switch it off instead. Invoices keep the supplier name they were created with.'],
                ['q' => 'A supplier is missing from the invoice form.', 'a' => 'It may be switched off or not yet added. Check the supplier list.'],
            ],
        ],

        'customers' => [
            'title'      => 'Customers',
            'icon'       => 'people',
            'summary'    => 'The shared customer list, their contacts, bulk upload and switching customers off.',
            'permission' => 'customers.manage',
            'order'      => 56,
            'sections'   => [
                [
                    'heading' => 'Maintaining customers',
                    'body'    => [
                        'Customers are one list shared by every module that needs them. Open Account menu, Administration, Customers to add a customer, then open it to add its billing address, payment terms and contacts. Switching a customer off hides it from pick-lists but keeps it on past records.',
                    ],
                ],
                [
                    'heading' => 'Contacts',
                    'body'    => ['Each customer can have several contacts. The first one added is the primary contact; tick Primary contact to change which one it is.', 'One contact can be chosen to receive any notifications sent to the customer. Tick Send notifications to this contact when adding one, or use the bell button on an existing contact. The contact needs an email address, and choosing another contact replaces the first.'],
                ],
                [
                    'heading' => 'Uploading many at once',
                    'body'    => ['Use Bulk upload to add or update customers from a spreadsheet.'],
                    'steps'   => [
                        'Download the template and fill it in.',
                        'Upload the file and review the preview. Problems are listed row by row.',
                        'Confirm to save. The upload is all or nothing, so fix any errors first.',
                    ],
                ],
            ],
            'faqs' => [
                ['q' => 'Can I delete a customer?', 'a' => 'No. Switch it off instead, so past records keep the customer they were created with.'],
                ['q' => 'A customer is missing from a pick-list.', 'a' => 'It may be switched off or not yet added. Check the customer list.'],
            ],
        ],

        'admin-users' => [
            'title'      => 'Managing users',
            'icon'       => 'people',
            'summary'    => 'Creating accounts, roles, resets and profile change requests.',
            'permission' => ['users.view', 'users.edit'],
            'order'      => 60,
            'sections'   => [
                [
                    'heading' => 'Finding and viewing users',
                    'body'    => ['Account menu, Administration, Users lists everyone. Search by name or email and filter by status.'],
                    'permission' => 'users.view',
                ],
                [
                    'heading' => 'Creating a user',
                    'body'    => ['The new person is emailed their sign-in details. They get the default staff role unless you choose others.'],
                    'steps'   => [
                        'Choose New user and enter their name and email.',
                        'Pick department, manager and roles.',
                        'Save. Then use Manage company access if they work with company data.',
                    ],
                    'permission' => 'users.create',
                ],
                [
                    'heading' => 'Roles and access',
                    'body'    => ['Roles decide which areas a person can open. Assign roles when editing a user. The person is told when their roles change.'],
                    'permission' => 'users.assign_roles',
                ],
                [
                    'heading' => 'Passwords and two-step verification',
                    'body'    => ['On a user\'s page you can send a password reset or reset their two-step verification. Both are recorded in the audit log and the person is notified.'],
                    'permission' => 'users.edit',
                ],
                [
                    'heading' => 'Profile change requests',
                    'body'    => ['Change requests lists job title, department and manager changes people have asked for. Approve to apply the change or decline to keep it as it is. The person is notified either way.'],
                    'permission' => 'users.edit',
                ],
                [
                    'heading' => 'Deactivating and deleting',
                    'body'    => ['Deactivate a leaver so they cannot sign in but their history stays. Delete only when an account was created by mistake.'],
                    'permission' => 'users.delete',
                ],
            ],
            'faqs' => [
                ['q' => 'A new user did not get their email.', 'a' => 'Check the address, then ask them to use Forgot password on the sign-in page. Check the mail settings if nobody receives email.', 'permission' => 'users.create'],
                ['q' => 'What is the difference between deactivating and deleting?', 'a' => 'Deactivating blocks sign-in and keeps all history, and can be undone. Deleting removes the account from lists. Prefer deactivating.', 'permission' => 'users.delete'],
                ['q' => 'A user says they cannot see something they need.', 'a' => 'Check their roles for the area, and their company access for company data. Both are needed for invoices.', 'permission' => 'users.view'],
            ],
        ],

        'admin-access' => [
            'title'      => 'Roles, companies and access',
            'icon'       => 'shield-lock',
            'summary'    => 'Roles and permissions, companies, memberships and group-level access.',
            'permission' => ['roles.manage', 'companies.manage', 'tenancy.memberships', 'tenancy.group_level', 'departments.manage'],
            'order'      => 70,
            'sections'   => [
                [
                    'heading' => 'Two layers of access',
                    'body'    => [
                        'Access has two layers. A role (permission) opens a screen. Company membership decides which companies\' data a person sees inside it. Someone with the invoice role but no companies sees an empty register.',
                    ],
                ],
                [
                    'heading' => 'Roles',
                    'body'    => ['Under Account menu, Administration, Roles, create a role and tick the permissions it holds. Changes apply to everyone with that role straight away. System roles can be edited but not removed.'],
                    'permission' => 'roles.manage',
                ],
                [
                    'heading' => 'User and Manager roles',
                    'body'    => [
                        'Each module has a User role (use the module) and a Manager role (everything the User can do, plus managing it). For Payables, a Payables User creates, submits and approves for their companies and runs reports; a Payables Manager also maintains approval routes, route variants, invoice types, teams and suppliers. Assign one role per module.',
                        'Modules with nothing to use day to day (Companies, Roles, Modules, Audit, MFA, Entra) have a Manager role only. Roles Manager is effectively full access, so give it sparingly. Group-level access is never part of a role.',
                    ],
                    'permission' => ['roles.manage', 'users.assign_roles'],
                ],
                [
                    'heading' => 'Companies and groups',
                    'body'    => ['Add each company with a short code and name, and place it in a group if you use groups. Choose the company timezone: it is the default for people who have not set their own, and the calendar used for its invoice due dates. Switch a company off rather than deleting it.'],
                    'permission' => 'companies.manage',
                ],
                [
                    'heading' => 'Company access for a user',
                    'body'    => ['From a user\'s page choose Manage company access. Tick each company they belong to, then tick the rights they hold there. Each module adds its own columns, for example Invoices: Can submit and Can approve. You can also record their job title in that company.'],
                    'permission' => 'tenancy.memberships',
                ],
                [
                    'heading' => 'Group-level users',
                    'body'    => ['A group-level user sees every company and can act on any invoice. Use it sparingly. Actions taken this way notify the people affected.'],
                    'permission' => 'tenancy.group_level',
                ],
                [
                    'heading' => 'Departments',
                    'body'    => ['Departments appear in the directory and on profiles. Add, rename or remove them under Account menu, Administration, Departments.'],
                    'permission' => 'departments.manage',
                ],
            ],
            'faqs' => [
                ['q' => 'A user has the right role but sees no invoices.', 'a' => 'They need company access. Open their user page and use Manage company access.', 'permission' => 'tenancy.memberships'],
                ['q' => 'Can I remove a permission from a system role?', 'a' => 'Yes, but the Super Admin role always holds everything. Run the permission seeder after a release so new permissions reach the standard roles.', 'permission' => 'roles.manage'],
                ['q' => 'Why can a group-level user see everything?', 'a' => 'That is its purpose. Only grant it to people who need an overview or must step in on others\' work.', 'permission' => 'tenancy.group_level'],
            ],
        ],

        'admin-security' => [
            'title'      => 'Security and system settings',
            'icon'       => 'gear',
            'summary'    => 'Audit log, two-step verification policy, Microsoft Entra and modules.',
            'permission' => ['audit.view', 'mfa.manage', 'entra.manage', 'modules.manage', 'cron.view'],
            'order'      => 80,
            'sections'   => [
                [
                    'heading' => 'Audit log',
                    'body'    => ['The audit log records who did what and when: sign-ins, user changes, invoice actions and settings. Use it to answer "who changed this?". Entries cannot be edited.'],
                    'permission' => 'audit.view',
                ],
                [
                    'heading' => 'Two-step verification policy',
                    'body'    => ['Under Multi-factor auth, switch verification on, choose the allowed methods, and choose which roles must use it. Users are asked to enrol at their next sign-in.'],
                    'permission' => 'mfa.manage',
                ],
                [
                    'heading' => 'Microsoft Entra',
                    'body'    => ['Enter the connection details, use Test to check them, then Sync to import people from your directory. Mappings turn Entra groups into roles. Failed syncs notify administrators.'],
                    'permission' => 'entra.manage',
                ],
                [
                    'heading' => 'Cron jobs',
                    'body'    => ['Open Cron jobs from Account menu, Administration. It lists the scheduled jobs (directory sync, reminders, backups) with when each last ran and how it ended, plus the recent run history and the lines to add to the crontab on the server.', 'Each job reports when it starts and ends. On time means it ran when expected. Late means it has not reported within its schedule plus a short allowance. Failed means the last run ended with an error. Stuck means it started but never finished. No runs yet means cron has not run it since it was set up.', 'Administrators are told in the app and by email when a job fails, is late or is stuck, once a day per job. The page cannot see the crontab on the server itself, and if cron stops completely nothing can send an alert, so a warning appears here once the hourly check goes missing.'],
                    'permission' => 'cron.view',
                ],
                [
                    'heading' => 'Modules',
                    'body'    => ['Optional modules such as Payables and the staff directory can be switched off. The module disappears for everyone, including Super Admins, but its data is kept and returns when you switch it back on.'],
                    'permission' => 'modules.manage',
                ],
            ],
            'faqs' => [
                ['q' => 'How long is the audit log kept?', 'a' => 'Entries are kept until your organisation\'s retention process removes them. Ask your system owner for the policy.', 'permission' => 'audit.view'],
                ['q' => 'I switched on two-step verification and locked someone out.', 'a' => 'Reset their verification from their user page. They will enrol again at next sign-in.', 'permission' => 'mfa.manage'],
                ['q' => 'A job says Late but I know cron is running.', 'a' => 'A job counts as late when it has not reported within its schedule plus a short allowance. If you changed how often it runs on the server, the expected schedule in the module registry needs to match. Check the recent runs below for what it last did.', 'permission' => 'cron.view'],
                ['q' => 'A module has vanished from the menu.', 'a' => 'It may have been switched off under Modules. Switching it on restores it and its data.', 'permission' => 'modules.manage'],
            ],
        ],

        'admin-backups' => [
            'title'      => 'Backups',
            'icon'       => 'cloud-arrow-up',
            'summary'    => 'Check that database backups are running and where they are sent.',
            'permission' => ['backups.view', 'backups.manage'],
            'order'      => 85,
            'sections'   => [
                [
                    'heading' => 'Checking backup status',
                    'body'    => ['Open Backups from Account menu, Administration. The cards at the top show the last good backup, the latest run, how many destinations are switched on, and whether encryption is on. A warning appears if there has been no good backup for longer than expected.', 'The table lists every run. Choose Details to see which destinations received the file and why any of them failed.'],
                    'steps'   => ['Result "Success": the backup reached every destination.', 'Result "Partly sent": some destinations failed. The backup exists, but not everywhere.', 'Result "Failed": nothing was sent. Details shows the reason.'],
                    'permission' => 'backups.view',
                ],
                [
                    'heading' => 'Adding a destination',
                    'body'    => ['Choose Add destination, pick SharePoint / OneDrive, an S3-compatible bucket, WebDAV or a folder, and fill in the details. Passwords and keys are stored encrypted and are never shown again. Press Test: it writes a small file, checks it, and deletes it.', 'Backups to keep says how many copies stay at that destination. Older ones are deleted after each run.'],
                    'permission' => 'backups.manage',
                ],
                [
                    'heading' => 'Running a backup now',
                    'body'    => ['Backups normally run on a schedule set by your system administrator. Choose Run now to start one straight away. It runs in the background, so refresh the page after a minute.'],
                    'permission' => 'backups.manage',
                ],
            ],
            'faqs' => [
                ['q' => 'Who is told when a backup fails?', 'a' => 'Everyone who can manage backups gets a notification when a run fails or only partly succeeds, and again if no good backup has been made for over a day.', 'permission' => 'backups.view'],
                ['q' => 'Are the backups encrypted?', 'a' => 'Yes, with a passphrase held by your system administrator. Without that passphrase a backup cannot be restored, so make sure it is stored safely off the server.', 'permission' => 'backups.view'],
                ['q' => 'Run now is greyed out.', 'a' => 'A backup is already running. Wait for it to finish and refresh the page.', 'permission' => 'backups.manage'],
            ],
        ],
    ];
}
