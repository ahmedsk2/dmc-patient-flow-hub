<?php

/*
 * Patient-facing privacy notice (PDPL) — ENGLISH.
 *
 * DRAFT — for review by the hospital's legal / data-protection officer; not legal advice.
 *
 * Rendered by resources/js/Pages/Legal/Privacy.vue via GET /privacy (LegalController::privacy).
 * The Markdown twin at docs/compliance/PRIVACY-NOTICE.en.md carries the SAME text — update both
 * together, and keep the section ids / block order aligned with resources/lang/ar/privacy.php
 * (tests/Feature/PrivacyNoticeTest.php enforces that the two languages have the same shape).
 *
 * Block types: 'p' (paragraph), 'ul' (bullet list), 'table' (head + rows). Square-bracketed
 * tokens such as [VERIFY ARTICLE], [NEEDS LEGAL CONFIRMATION] and [PLACEHOLDER] are review markers —
 * the page highlights them so reviewers can find every open item; remove them on approval.
 */

return [
    'code' => 'en',
    'dir' => 'ltr',
    'title' => 'Privacy Notice',
    'subtitle' => 'DMC Internal Medicine Patient-Flow Hub',
    'draft_banner' => "DRAFT — for review by the hospital's legal / data-protection officer; not legal advice.",

    'meta' => [
        'version' => '0.1-draft',
        'drafted' => '2026-09-03',
        'effective' => '[EFFECTIVE DATE — on approval]',
        'controller' => '[HOSPITAL LEGAL NAME]',
    ],

    'labels' => [
        'language' => 'Language',
        'version' => 'Version',
        'drafted' => 'Drafted',
        'effective' => 'Effective date',
        'controller' => 'Controller',
        'contents' => 'Contents',
        'back_login' => 'Back to sign in',
        'back_app' => 'Back to the hub',
        'review_marker' => 'Open review item — resolve before publication',
    ],

    'sections' => [
        [
            'id' => 'about',
            'heading' => '1. About this notice',
            'blocks' => [
                ['type' => 'p', 'text' => 'This notice explains how [HOSPITAL LEGAL NAME] (“the hospital”, “we”) uses information about you in the DMC Internal Medicine Patient-Flow Hub (“the hub”). The hub is an internal software tool used only by hospital staff of the Internal Medicine unit to coordinate your care while you are admitted under that unit.'],
                ['type' => 'p', 'text' => 'The hub is not the hospital’s main medical record (the hospital information system) and does not replace it. It holds only the working information the unit needs to run your admission from day to day. The hospital’s general patient privacy notice [REFERENCE / LINK — PLACEHOLDER] also applies to you; this notice adds the details that are specific to the hub.'],
                ['type' => 'p', 'text' => 'Patients do not log in to the hub and do not use it directly. This notice is written for you because information about you is processed in it, and because the Personal Data Protection Law of the Kingdom of Saudi Arabia (the “PDPL”) [VERIFY CITATION — Royal Decree M/19 of 1443H, as amended] gives you the right to know how your personal data is used.'],
            ],
        ],
        [
            'id' => 'controller',
            'heading' => '2. Who is responsible for your data',
            'blocks' => [
                ['type' => 'p', 'text' => 'The controller of your personal data is: [HOSPITAL LEGAL NAME], [HOSPITAL ADDRESS], [COMMERCIAL REGISTRATION / MOH LICENCE NUMBER — PLACEHOLDER].'],
                ['type' => 'p', 'text' => 'The hub is operated by the hospital’s Internal Medicine department. Questions about this notice can be sent to the hospital’s Data Protection Officer: [DPO NAME], [DPO EMAIL], [DPO PHONE], [DPO POSTAL ADDRESS].'],
            ],
        ],
        [
            'id' => 'data',
            'heading' => '3. What information the hub holds about you',
            'blocks' => [
                ['type' => 'p', 'text' => 'The hub holds the following categories of personal data. Because it concerns your health, most of it is “sensitive” personal data under the PDPL and is given extra protection.'],
                ['type' => 'ul', 'items' => [
                    'Identity: your medical record number (MRN), name, age, gender and nationality.',
                    'Admission episode: the dates you were admitted and discharged, your bed and current location in the hospital (emergency department, ward or intensive care), the consultant responsible for you, your admission diagnoses recorded as ICD-10 codes, and the outcome of the admission (for example discharged, transferred or died).',
                    'Consultations: requests for another specialty to see you, the reason for the request, and the responding service’s notes.',
                    'Handover notes: short clinical notes written by the unit’s doctors when they hand your care over between shifts.',
                    'Access trail: a technical log of which staff member viewed, changed or exported your record, and when. It is kept to protect you, not to profile you.',
                ]],
                ['type' => 'p', 'text' => 'The hub does not hold your contact details, national identity or residence number, insurance, payment or other financial information, biometric or genetic data, or photographs.'],
            ],
        ],
        [
            'id' => 'source',
            'heading' => '4. Where the information comes from',
            'blocks' => [
                ['type' => 'p', 'text' => 'The information is entered by the unit’s clinical staff from the hospital’s medical record and from their care of you during the admission. Nothing is collected from you directly through the hub, and nothing is obtained from third parties, advertisers or data brokers.'],
            ],
        ],
        [
            'id' => 'purpose',
            'heading' => '5. Why we use it, and our legal basis',
            'blocks' => [
                ['type' => 'p', 'text' => 'We use the information for one purpose only: coordinating your clinical care within the Internal Medicine unit. In practice this means:'],
                ['type' => 'ul', 'items' => [
                    'managing admissions to the unit and assigning each patient to a responsible consultant;',
                    'tracking where you are in the hospital and your progress towards discharge;',
                    'keeping track of consultation requests to and from other specialties, and their responses;',
                    'handing over your care safely between shifts; and',
                    'producing statistics about the unit’s activity (for example numbers of admissions, length of stay, readmissions and mortality). These figures are aggregated and used to run and improve the service.',
                ]],
                ['type' => 'p', 'text' => 'The hub is not used for marketing, advertising, behavioural analytics, tracking, profiling, automated decision-making or artificial-intelligence features, and your data is never sold, rented or shared for any commercial purpose.'],
                ['type' => 'p', 'text' => 'Legal basis. Under the PDPL, health data may be processed without your consent where the processing is carried out by a provider of health services and is necessary to provide those services, limited to what the services require and to the staff who need it [VERIFY ARTICLE]. We rely on this basis for the clinical purposes above. We also process some of the data to comply with the hospital’s legal duties to keep medical records and to allow lawful supervision by the competent health authorities [VERIFY ARTICLE] [NEEDS LEGAL CONFIRMATION]. We do not rely on your consent for the core clinical purpose, so withdrawing consent does not apply to it [NEEDS LEGAL CONFIRMATION].'],
            ],
        ],
        [
            'id' => 'recipients',
            'heading' => '6. Who can see your information',
            'blocks' => [
                ['type' => 'ul', 'items' => [
                    'Hospital staff of the Internal Medicine unit — consultants, registrars, residents and observer roles — each limited by role and by individually granted permissions to what their work requires. Every user is bound by professional and contractual duties of confidentiality.',
                    'The hospital’s system administrators, who maintain the hub and can reach records only through audited, multi-factor-protected accounts.',
                    'The hospital’s medical-records (health information management), quality and audit functions, where needed to answer your requests, investigate incidents or meet regulatory obligations.',
                    'Public authorities — such as the Ministry of Health, a regulator or a court — only where the law requires or permits disclosure [NEEDS LEGAL CONFIRMATION].',
                ]],
                ['type' => 'p', 'text' => 'We do not share your data through the hub with insurers, employers, researchers or marketers.'],
            ],
        ],
        [
            'id' => 'processors',
            'heading' => '7. Service providers and where your data is kept',
            'blocks' => [
                ['type' => 'p', 'text' => 'Your patient data is stored and processed on servers located in the Kingdom of Saudi Arabia. Like most software, the hub relies on a small number of specialist providers (“processors”) who act only on the hospital’s instructions and under written contracts. They are:'],
                ['type' => 'table', 'head' => ['Provider', 'What they do', 'Where', 'Patient data?'], 'rows' => [
                    ['Oracle Cloud Infrastructure (OCI)', 'Hosts the application, its database and its backups.', 'Riyadh region, Saudi Arabia.', 'Yes — encrypted in transit; encryption of stored data is being introduced.'],
                    ['Cloudflare', 'Sits in front of the website to block attacks and to provide the secure (TLS) connection. It sees traffic momentarily as it passes through its edge servers.', 'A non-Saudi provider. The edge servers observed serving the hub are located in the Kingdom [NEEDS LEGAL CONFIRMATION — contractual localisation].', 'In transit only — nothing is stored by Cloudflare for the hub’s purpose.'],
                    ['Transactional email relay', 'Sends one-time codes, password-reset and username-reminder emails to staff, and a monthly statistics PDF that contains aggregate numbers only.', 'Currently hosted in the United States.', 'No — staff email addresses and aggregate figures only; never patient-level data.'],
                    ['GitHub', 'Stores the hub’s source code.', 'Outside the Kingdom.', 'No — code only, no patient data.'],
                ]],
                ['type' => 'p', 'text' => 'Transfers outside the Kingdom. We do not transfer your patient data outside the Kingdom to store or process it. The only provider outside Saudi Arabia that ever handles traffic containing patient data is the network-security provider above, and only in encrypted transit. Where the PDPL treats this as a transfer or disclosure outside the Kingdom, the hospital applies the safeguards the PDPL and its Data Transfer Regulations require: the transfer must be for a purpose the law permits, limited to the minimum necessary, backed by appropriate safeguards such as standard contractual clauses or binding rules approved by the competent authority (or a destination recognised as offering an adequate level of protection), and preceded by a risk assessment [VERIFY ARTICLE] [NEEDS LEGAL CONFIRMATION]. The hospital is reviewing options to keep this traffic wholly within the Kingdom.'],
            ],
        ],
        [
            'id' => 'retention',
            'heading' => '8. How long we keep it',
            'blocks' => [
                ['type' => 'table', 'head' => ['Information', 'Kept for'], 'rows' => [
                    ['Admission, consultation and handover records', 'As long as the hospital’s medical-record retention rules require, because the hub’s records form part of your clinical history [NEEDS LEGAL CONFIRMATION — applicable retention period].'],
                    ['Access trail (audit log)', 'Six years inside the hub; a tamper-evident copy is also kept in immutable storage inside the Kingdom for seven years [NEEDS LEGAL CONFIRMATION].'],
                    ['Backups', 'Ninety days, then overwritten [NEEDS LEGAL CONFIRMATION].'],
                    ['Staff accounts', 'While the person needs access, then as required for the access trail.'],
                ]],
                ['type' => 'p', 'text' => 'When a record is deleted from the hub it is first moved to a “recently deleted” area from which an administrator can restore it, and every deletion is recorded in the access trail.'],
            ],
        ],
        [
            'id' => 'security',
            'heading' => '9. How we protect it',
            'blocks' => [
                ['type' => 'ul', 'items' => [
                    'Every user must sign in with a password and a second factor (an authenticator app). There are no shared or password-only accounts.',
                    'Access is limited by role and by per-user permissions, so staff see only what their work requires.',
                    'Every time a staff member opens a patient record or exports data, the event is written to a tamper-evident audit log that the hospital’s administrators can review.',
                    'Patient identifiers never appear in web addresses, so they are not left behind in browser histories or proxy logs.',
                    'All connections are encrypted in transit (TLS 1.2 or higher). Encryption of stored data at rest is being introduced.',
                    'Idle sessions are locked automatically, and the hub is designed for shared clinical workstations so that nothing patient-related remains in the browser after sign-out.',
                    'The hospital tests, patches and monitors the hub, and keeps backups so that records can be restored after a failure.',
                ]],
            ],
        ],
        [
            'id' => 'cookies',
            'heading' => '10. Cookies',
            'blocks' => [
                ['type' => 'p', 'text' => 'Patients do not use the hub, so no cookies are set on patients. For staff users, the hub uses only what it needs in order to work: a session cookie that keeps the user signed in, a security token that protects forms against forgery, and — only if the staff member chooses it at sign-in — a “trusted device” cookie that skips the second-factor code on that browser for a fixed period set by the administrators. A display preference (light or dark theme) is stored in the browser. There are no analytics, advertising or tracking cookies of any kind.'],
            ],
        ],
        [
            'id' => 'rights',
            'heading' => '11. Your rights',
            'blocks' => [
                ['type' => 'p', 'text' => 'The PDPL gives you rights over your personal data. Subject to the conditions and exceptions in the law [VERIFY ARTICLE], you have the right to:'],
                ['type' => 'ul', 'items' => [
                    'be informed about how your data is used — which this notice is intended to do;',
                    'access your personal data and obtain a copy of it in a clear, readable form;',
                    'ask for data that is inaccurate, incomplete or out of date to be corrected;',
                    'ask for your data to be destroyed when it is no longer needed for the purpose it was collected for — noting that clinical records must usually be kept for the period the medical-records rules require, so this right may be limited [NEEDS LEGAL CONFIRMATION];',
                    'withdraw consent where consent is the legal basis (this does not apply to the core clinical purpose described above) [NEEDS LEGAL CONFIRMATION]; and',
                    'complain to the competent authority (see section 12).',
                ]],
                ['type' => 'p', 'text' => 'How to exercise them. Requests are handled by the hospital’s Medical Records / Health Information Management (HIM) office, which holds your full record and can verify your identity: [HIM OFFICE — LOCATION, EMAIL, PHONE, OPENING HOURS]. Please tell them that your request concerns the Internal Medicine Patient-Flow Hub so that the hub’s data is included. We will respond within the time the PDPL Implementing Regulations allow [VERIFY PERIOD], and normally free of charge [VERIFY]. If you cannot act for yourself, your legal guardian or authorised representative may act on your behalf on presenting proof of authority [NEEDS LEGAL CONFIRMATION].'],
            ],
        ],
        [
            'id' => 'complaints',
            'heading' => '12. Complaints',
            'blocks' => [
                ['type' => 'p', 'text' => 'If you are unhappy with how we handle your data or your request, please contact our Data Protection Officer first (details in section 2). You also have the right to lodge a complaint with the competent authority for personal-data protection in the Kingdom, the Saudi Data & Artificial Intelligence Authority (SDAIA) [VERIFY — confirm the current competent authority and its complaint channel]: [SDAIA COMPLAINT CHANNEL — PLACEHOLDER].'],
            ],
        ],
        [
            'id' => 'staff',
            'heading' => '13. If you are a member of staff',
            'blocks' => [
                ['type' => 'p', 'text' => 'If you use the hub as a member of staff, it holds your name, username, work email address, role and permissions, your multi-factor-authentication enrolment, sign-in events (including IP address and browser) and an audit trail of the actions you take on patient records. This is processed to run the hub securely, to attribute clinical actions to the right person and to meet the hospital’s audit obligations. Staff have the same rights as patients under the PDPL and may exercise them through the Data Protection Officer.'],
            ],
        ],
        [
            'id' => 'changes',
            'heading' => '14. Changes to this notice',
            'blocks' => [
                ['type' => 'p', 'text' => 'We may update this notice when the hub, the law or our providers change. The current version is always available at /privacy inside the hub and from the hospital’s [PATIENT INFORMATION CHANNEL — PLACEHOLDER]. Material changes will be announced [HOW — PLACEHOLDER].'],
                ['type' => 'p', 'text' => 'Version history: 0.1-draft — 2026-09-03 — first draft for legal / DPO review.'],
            ],
        ],
    ],
];
